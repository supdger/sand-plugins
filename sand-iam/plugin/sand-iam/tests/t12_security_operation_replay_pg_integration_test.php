<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\SecurityOperation;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\WebhookSecretCipher;
use plugin\SandIam\app\service\WebhookService;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

if ((string) getenv('SAND_IAM_T12_PG_ENABLED') !== '1') {
    fwrite(STDOUT, "SKIP: set SAND_IAM_T12_PG_ENABLED=1 for the T12 PostgreSQL fixture\n");
    exit(0);
}

function t12PgFail(string $message): never { throw new RuntimeException("T12 PostgreSQL idempotency fixture failed: {$message}"); }
function t12PgAssert(bool $condition, string $message): void { if (!$condition) t12PgFail($message); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t12PgFail('SandAdmin dependencies are unavailable');

putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION=t12-v1');
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEYS={}');
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

final class T12NoopWebhookHttpAdapter implements \plugin\SandIam\app\webhook\WebhookHttpAdapter
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array { return ['status' => 204, 'body' => '']; }
}

$suffix = bin2hex(random_bytes(8));
$requestId = 't12-webhook-' . $suffix;
$organization = Organization::create(['code' => 't12-org-' . $suffix, 'name' => 'T12 PG organization', 'status' => 1]);
$application = Application::create(['organization_id' => (int) $organization->id, 'code' => 't12-app-' . $suffix, 'name' => 'T12 PG application', 'status' => 1]);
$operations = new IdempotencyService();
$webhooks = new WebhookService(new WebhookSecretCipher(), new T12NoopWebhookHttpAdapter(), new AuditWriter());
$fingerprint = IdempotencyService::fingerprint(['application_id' => (int) $application->id, 'code' => 'security-events', 'event_types' => ['identity.updated'], 'timeout_seconds' => 5, 'max_attempts' => 3]);
$calls = 0;

try {
    $create = static function () use (&$calls, $webhooks, $application, $requestId): array {
        $calls++;
        $created = $webhooks->createEndpoint((int) $application->id, 'security-events', '安全事件', 'https://receiver.example.test/t12', ['identity.updated'], 5, 3, $requestId);
        return ['resource_id' => $created['id'], 'result' => $created];
    };
    $first = $operations->execute('admin', '1', 'webhook.create', $requestId, $fingerprint, 'webhook_endpoint', $create);
    $replay = $operations->execute('admin', '1', 'webhook.create', $requestId, $fingerprint, 'webhook_endpoint', $create);
    t12PgAssert($calls === 1 && $first['replayed'] === false && $replay['replayed'] === true, 'webhook create replay invoked the production callback twice');
    t12PgAssert(!isset($replay['result']['secret']) && ($replay['result']['secret_available'] ?? true) === false, 'webhook create replay exposed the signing secret');
    $endpoint = WebhookEndpoint::find((int) $first['result']['id']);
    t12PgAssert($endpoint !== null && (int) $endpoint->secret_version === 1, 'webhook create did not persist exactly one endpoint');

    $rotateRequestId = 't12-rotate-' . $suffix;
    $rotateFingerprint = IdempotencyService::fingerprint(['id' => (int) $endpoint->id, 'application_id' => (int) $application->id]);
    $rotateCalls = 0;
    $rotate = static function () use (&$rotateCalls, $webhooks, $endpoint, $application, $rotateRequestId): array {
        $rotateCalls++;
        $result = $webhooks->rotateSecret((int) $endpoint->id, (int) $application->id, $rotateRequestId);
        return ['resource_id' => (int) $endpoint->id, 'result' => $result];
    };
    $rotated = $operations->execute('admin', '1', 'webhook.secret_rotate', $rotateRequestId, $rotateFingerprint, 'webhook_endpoint', $rotate);
    $rotatedReplay = $operations->execute('admin', '1', 'webhook.secret_rotate', $rotateRequestId, $rotateFingerprint, 'webhook_endpoint', $rotate);
    $endpoint = WebhookEndpoint::find((int) $endpoint->id);
    t12PgAssert($rotateCalls === 1 && (int) $endpoint?->secret_version === 2, 'webhook secret rotation executed more than once');
    t12PgAssert(!isset($rotatedReplay['result']['secret']) && ($rotatedReplay['result']['secret_available'] ?? true) === false, 'webhook rotation replay exposed the signing secret');
    t12PgAssert(AuditLog::where('request_id', $requestId)->where('action', 'webhook.create')->count() === 1, 'webhook create replay duplicated its audit record');
    t12PgAssert(AuditLog::where('request_id', $rotateRequestId)->where('action', 'webhook.secret_rotate')->count() === 1, 'webhook rotation replay duplicated its audit record');

    $dsn = (string) getenv('SAND_IAM_IDEMPOTENCY_CONCURRENCY_DSN');
    if ($dsn === '') {
        fwrite(STDOUT, "T12 two-connection security-operation race skipped: set SAND_IAM_IDEMPOTENCY_CONCURRENCY_DSN\n");
    } else {
        $user = (string) getenv('SAND_IAM_IDEMPOTENCY_CONCURRENCY_USER');
        $password = (string) getenv('SAND_IAM_IDEMPOTENCY_CONCURRENCY_PASSWORD');
        $a = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $b = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $raceId = 't12-race-' . $suffix;
        try {
            $a->beginTransaction();
            $insert = $a->prepare("INSERT INTO sand_iam_security_operation (actor_type, actor_ref, operation, request_id, request_fingerprint, resource_type, state, result) VALUES ('admin', '1', 'webhook.secret_rotate', :request_id, :fingerprint, 'webhook_endpoint', 'pending', '{}'::jsonb)");
            $insert->execute(['request_id' => $raceId, 'fingerprint' => $rotateFingerprint]);
            $b->exec("SET lock_timeout = '1000ms'");
            try {
                $b->prepare("INSERT INTO sand_iam_security_operation (actor_type, actor_ref, operation, request_id, request_fingerprint, resource_type, state, result) VALUES ('admin', '1', 'webhook.secret_rotate', :request_id, :fingerprint, 'webhook_endpoint', 'pending', '{}'::jsonb)")->execute(['request_id' => $raceId, 'fingerprint' => $rotateFingerprint]);
                t12PgFail('second PostgreSQL connection created a duplicate webhook security operation');
            } catch (PDOException $exception) {
                t12PgAssert(in_array((string) $exception->getCode(), ['55P03', '23505'], true), 'two-connection webhook race returned an unexpected PostgreSQL error');
            }
            $a->commit();
            t12PgAssert((int) $b->query('SELECT count(*) FROM sand_iam_security_operation WHERE request_id = ' . $b->quote($raceId))->fetchColumn() === 1, 'two-connection webhook race stored more than one security operation');
        } finally {
            if ($a->inTransaction()) $a->rollBack();
            $b->prepare('DELETE FROM sand_iam_security_operation WHERE request_id = :request_id')->execute(['request_id' => $raceId]);
        }
    }
} finally {
    AuditLog::whereIn('request_id', [$requestId, 't12-rotate-' . $suffix])->delete();
    SecurityOperation::whereIn('request_id', [$requestId, 't12-rotate-' . $suffix])->delete();
    WebhookEndpoint::where('application_id', (int) $application->id)->delete();
    Application::where('id', (int) $application->id)->delete();
    Organization::where('id', (int) $organization->id)->delete();
}

fwrite(STDOUT, "T12 security-operation PostgreSQL fixture passed\n");
