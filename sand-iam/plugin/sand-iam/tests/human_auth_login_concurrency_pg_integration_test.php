<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\AuthRateLimit;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\SecurityOperation;
use plugin\SandIam\app\service\HumanAuthService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;
use think\facade\Db;

function loginRaceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param array{organization_code:string,application_code:string,identifier:string,password:string,ip:string,request_id:string,barrier:string,ready:string} $payload */
function loginRaceWorker(array $payload, string $resultFile): void
{
    Db::connect(null, true);
    file_put_contents($payload['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($payload['barrier'])) {
        if (microtime(true) >= $deadline) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'error' => 'worker barrier timeout'], JSON_THROW_ON_ERROR));
            return;
        }
        usleep(10_000);
    }
    try {
        (new HumanAuthService())->login([
            'organization_code' => $payload['organization_code'],
            'application_code' => $payload['application_code'],
            'identifier' => $payload['identifier'],
            'password' => $payload['password'],
        ], $payload['ip'], $payload['request_id']);
        file_put_contents($resultFile, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
    } catch (ApiException $exception) {
        file_put_contents($resultFile, json_encode([
            'ok' => false,
            'error' => $exception->getMessage(),
            'status' => $exception->getCode(),
        ], JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
    }
}

/** @param array<string,string> $payload @return list<array<string,mixed>> */
function competeLogin(array $payload): array
{
    if (!function_exists('proc_open')) throw new RuntimeException('SKIP: proc_open is required for independent PostgreSQL login workers');
    $directory = sys_get_temp_dir() . '/sand-iam-login-race-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('cannot create login worker barrier');
    $barrier = $directory . '/release';
    $resultFiles = [$directory . '/result-0.json', $directory . '/result-1.json'];
    $readyFiles = [$directory . '/ready-0', $directory . '/ready-1'];
    $children = [];
    try {
        foreach ([0, 1] as $worker) {
            $workerPayload = $payload + ['barrier' => $barrier, 'ready' => $readyFiles[$worker]];
            $workerFile = $directory . '/worker-' . $worker . '.json';
            file_put_contents($workerFile, json_encode($workerPayload, JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, '--login-worker', $workerFile, $resultFiles[$worker]], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('cannot start login worker process');
            foreach ($pipes as $pipe) fclose($pipe);
            $children[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (!is_file($readyFiles[0]) || !is_file($readyFiles[1])) {
            if (microtime(true) >= $deadline) throw new RuntimeException('login workers did not reach the barrier');
            usleep(10_000);
        }
        touch($barrier);
        foreach ($children as $process) proc_close($process);
        $results = [];
        foreach ($resultFiles as $resultFile) {
            $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;
            if (!is_array($result)) throw new RuntimeException('login worker did not return a result');
            $results[] = $result;
        }
        return $results;
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
}

function cleanupLoginRace(int $organizationId, int $applicationId, int $identityId): void
{
    $sessionIds = Db::table('sand_iam_auth_session')->where('application_id', $applicationId)->column('id');
    if ($sessionIds !== []) Db::table('sand_iam_auth_refresh_token')->whereIn('session_id', $sessionIds)->delete();
    Db::table('sand_iam_auth_session')->where('application_id', $applicationId)->delete();
    Db::table('sand_iam_audit_log')->where('organization_id', $organizationId)->where('application_id', $applicationId)->delete();
    Db::table('sand_iam_security_operation')->where('actor_type', 'application_user')->where('actor_ref', 'like', 'login:' . $applicationId . ':%')->delete();
    Db::table('sand_iam_auth_rate_limit')->where('application_id', $applicationId)->delete();
    Db::table('sand_iam_identity_auth')->where('application_id', $applicationId)->where('identity_id', $identityId)->delete();
    Db::table('sand_iam_identity')->where('application_id', $applicationId)->where('id', $identityId)->delete();
    Db::table('sand_iam_auth_policy')->where('application_id', $applicationId)->delete();
    Db::table('sand_iam_application')->where('organization_id', $organizationId)->where('id', $applicationId)->delete();
    Db::table('sand_iam_organization')->where('id', $organizationId)->delete();
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$packageRoot = dirname(__DIR__);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || !is_file($hostRoot . '/vendor/autoload.php')) {
    echo "SKIP human auth login concurrency PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1 and provide SandAdmin host dependencies\n";
    exit(0);
}
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $packageRoot . '/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($packageRoot . '/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

if (($argv[1] ?? null) === '--login-worker') {
    $workerFile = $argv[2] ?? '';
    $resultFile = $argv[3] ?? '';
    $payload = is_file($workerFile) ? json_decode((string) file_get_contents($workerFile), true) : null;
    if (!is_array($payload) || $resultFile === '') throw new RuntimeException('invalid login worker payload');
    loginRaceWorker($payload, $resultFile);
    exit(0);
}

$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$organizationCode = 'loginrace' . $suffix;
$applicationCode = 'app' . $suffix;
$email = 'login-race-' . $suffix . '@example.test';
$validPassword = 'Race!ValidPassword1';
$wrongPassword = 'Race!WrongPassword1';
$requestId = 'login-race-' . $suffix;
$organization = Organization::create(['code' => $organizationCode, 'name' => 'Login race organization', 'status' => 1]);
$application = Application::create(['organization_id' => (int) $organization->id, 'code' => $applicationCode, 'name' => 'Login race application', 'status' => 1]);
AuthPolicy::create(['application_id' => (int) $application->id, 'registration_enabled' => 1, 'status' => 1]);
$registered = (new HumanAuthService())->register([
    'organization_code' => $organizationCode,
    'application_code' => $applicationCode,
    'username' => 'login-race-' . $suffix,
    'display_name' => 'Login race user',
    'email' => $email,
    'password' => $validPassword,
], '127.0.0.61', 'login-race-register-' . $suffix);
$identityId = (int) ($registered['identity']['id'] ?? 0);

try {
    $results = competeLogin([
        'organization_code' => $organizationCode,
        'application_code' => $applicationCode,
        'identifier' => $email,
        'password' => $wrongPassword,
        'ip' => '127.0.0.62',
        'request_id' => $requestId,
    ]);
    loginRaceAssert(count($results) === 2, 'two login workers did not both return');
    foreach ($results as $index => $result) {
        loginRaceAssert(($result['ok'] ?? null) === false, "worker {$index} unexpectedly authenticated");
        loginRaceAssert(str_starts_with((string) ($result['error'] ?? ''), 'SAND_IAM_AUTHENTICATION_FAILED'), "worker {$index} returned a different error");
        loginRaceAssert((int) ($result['status'] ?? 0) === 401, "worker {$index} returned a different status");
    }
    $auth = IdentityAuth::where('application_id', (int) $application->id)->where('identity_id', $identityId)->find();
    loginRaceAssert($auth !== null && (int) $auth->failed_login_count === 1, 'concurrent duplicate login incremented failure count more than once');
    loginRaceAssert((int) AuthRateLimit::where('application_id', (int) $application->id)->where('action', 'login')->sum('attempt_count') === 1, 'concurrent duplicate login consumed rate limit more than once');
    loginRaceAssert(AuditLog::where('request_id', $requestId)->where('action', 'identity.login')->count() === 1, 'concurrent duplicate login wrote more than one failure audit');
    loginRaceAssert(SecurityOperation::where('request_id', $requestId)->where('operation', 'identity.login.rate_limit')->count() === 1, 'concurrent duplicate login wrote more than one rate operation');
    loginRaceAssert(SecurityOperation::where('request_id', $requestId)->where('operation', 'identity.login.failure')->count() === 1, 'concurrent duplicate login wrote more than one failure operation');
    $operationJson = json_encode(SecurityOperation::where('request_id', $requestId)->select()->toArray(), JSON_UNESCAPED_SLASHES);
    loginRaceAssert(is_string($operationJson) && !str_contains($operationJson, $email) && !str_contains($operationJson, $wrongPassword), 'login operation leaked plaintext identity or password');
    echo "human auth login concurrency PostgreSQL integration passed\n";
} finally {
    cleanupLoginRace((int) $organization->id, (int) $application->id, $identityId);
}
