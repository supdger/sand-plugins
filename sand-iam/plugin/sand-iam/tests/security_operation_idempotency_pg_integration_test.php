<?php

declare(strict_types=1);

use plugin\SandIam\app\model\SecurityOperation;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function securityOperationPgFail(string $message): never
{
    fwrite(STDERR, "security operation PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function securityOperationPgAssert(bool $condition, string $message): void
{
    if (!$condition) securityOperationPgFail($message);
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) securityOperationPgFail('SandAdmin dependencies are unavailable');

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$requestId = 'pg-idempotency-' . bin2hex(random_bytes(12));
$service = new IdempotencyService();
$fingerprint = IdempotencyService::fingerprint(['credential_id' => 42, 'operation' => 'rotate']);
$calls = 0;
$callback = static function () use (&$calls): array {
    $calls++;
    return ['resource_id' => 42, 'result' => ['id' => 42, 'key_prefix' => 'siam_pg_test', 'credential' => 'siam_must_not_persist']];
};

try {
    $first = $service->execute('pg_test', '42', 'credential.rotate', $requestId, $fingerprint, 'credential', $callback);
    securityOperationPgAssert($first['replayed'] === false && ($first['result']['credential'] ?? null) === 'siam_must_not_persist', 'first operation did not return the one-time result');
    $replayed = $service->execute('pg_test', '42', 'credential.rotate', $requestId, $fingerprint, 'credential', $callback);
    securityOperationPgAssert($replayed['replayed'] === true && $calls === 1, 'same request was executed twice');
    securityOperationPgAssert(!isset($replayed['result']['credential']) && ($replayed['result']['secret_available'] ?? true) === false, 'replay exposed the persisted secret');
    $record = SecurityOperation::where('request_id', $requestId)->find();
    securityOperationPgAssert($record !== null && !str_contains((string) json_encode($record->result), 'siam_must_not_persist'), 'security operation stored a plaintext secret');
    try {
        $service->execute('pg_test', '42', 'credential.rotate', $requestId, IdempotencyService::fingerprint(['credential_id' => 99]), 'credential', $callback);
        securityOperationPgFail('different request fingerprint was accepted');
    } catch (ApiException $exception) {
        securityOperationPgAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT'), 'different request fingerprint did not return the stable conflict');
    }

    $concurrencyDsn = getenv('SAND_IAM_IDEMPOTENCY_CONCURRENCY_DSN');
    if ($concurrencyDsn !== false && $concurrencyDsn !== '') {
        $concurrencyUser = getenv('SAND_IAM_IDEMPOTENCY_CONCURRENCY_USER') ?: '';
        $concurrencyPassword = getenv('SAND_IAM_IDEMPOTENCY_CONCURRENCY_PASSWORD') ?: '';
        $connectionA = new PDO($concurrencyDsn, $concurrencyUser, $concurrencyPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connectionB = new PDO($concurrencyDsn, $concurrencyUser, $concurrencyPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $concurrencyRequestId = 'pg-concurrency-' . bin2hex(random_bytes(12));
        $fingerprint = IdempotencyService::fingerprint(['concurrency' => true]);
        try {
            $connectionA->beginTransaction();
            $insert = $connectionA->prepare("INSERT INTO sand_iam_security_operation (actor_type, actor_ref, operation, request_id, request_fingerprint, resource_type, state, result) VALUES ('pg_test', 'concurrent', 'credential.issue', :request_id, :fingerprint, 'credential', 'pending', '{}'::jsonb)");
            $insert->execute(['request_id' => $concurrencyRequestId, 'fingerprint' => $fingerprint]);
            $connectionB->exec("SET lock_timeout = '1000ms'");
            try {
                $duplicate = $connectionB->prepare("INSERT INTO sand_iam_security_operation (actor_type, actor_ref, operation, request_id, request_fingerprint, resource_type, state, result) VALUES ('pg_test', 'concurrent', 'credential.issue', :request_id, :fingerprint, 'credential', 'pending', '{}'::jsonb)");
                $duplicate->execute(['request_id' => $concurrencyRequestId, 'fingerprint' => $fingerprint]);
                securityOperationPgFail('second PostgreSQL connection inserted a concurrent idempotency record');
            } catch (PDOException $exception) {
                securityOperationPgAssert(in_array((string) $exception->getCode(), ['55P03', '23505'], true), 'second connection did not observe the unique-key competition');
            }
            $connectionA->commit();
            $count = (int) $connectionB->query("SELECT count(*) FROM sand_iam_security_operation WHERE request_id = " . $connectionB->quote($concurrencyRequestId))->fetchColumn();
            securityOperationPgAssert($count === 1, 'concurrent idempotency request created more than one record');
        } finally {
            if ($connectionA->inTransaction()) $connectionA->rollBack();
            $connectionB->prepare('DELETE FROM sand_iam_security_operation WHERE request_id = :request_id')->execute(['request_id' => $concurrencyRequestId]);
        }
    } else {
        fwrite(STDOUT, "security operation PostgreSQL two-connection competition skipped: set SAND_IAM_IDEMPOTENCY_CONCURRENCY_DSN\n");
    }
} finally {
    SecurityOperation::where('request_id', $requestId)->delete();
}

fwrite(STDOUT, "security operation PostgreSQL integration passed\n");
