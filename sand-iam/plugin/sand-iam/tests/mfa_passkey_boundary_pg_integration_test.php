<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationExperience;
use plugin\SandIam\app\model\ApplicationNetworkPolicy;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\WebauthnCredential;
use plugin\SandIam\app\service\MfaService;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function passkeyBoundaryAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

/** @param array{application:array<string,string>,ip:string,ready:string} $payload */
function passkeyBoundaryWorker(array $payload, string $resultFile): void
{
    // A separate PHP process forces a separately acquired ThinkORM/PDO connection.
    Db::connect(null, true);
    file_put_contents($payload['ready'], 'ready');
    try {
        (new MfaService())->passkeyAuthenticationFinish($payload['application'] + [
            'challenge_token' => 'siam_mc_missing_challenge',
            'rawId' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'response' => [],
        ], $payload['ip'], 'passkey-boundary-worker-' . bin2hex(random_bytes(4)));
        file_put_contents($resultFile, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
    } catch (ApiException $exception) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => get_class($exception)], JSON_THROW_ON_ERROR));
    }
}

/** @param array<string,string> $application */
function passkeyBoundaryRace(int $organizationId, callable $mutate, array $application, string $ip): array
{
    if (!function_exists('proc_open')) throw new RuntimeException('proc_open is required for the independent passkey boundary connection');
    $directory = sys_get_temp_dir() . '/sand-iam-passkey-boundary-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('cannot create passkey boundary worker directory');
    $payloadFile = $directory . '/payload.json'; $resultFile = $directory . '/result.json'; $ready = $directory . '/ready';
    $transactionOpen = false;
    try {
        file_put_contents($payloadFile, json_encode(['application' => $application, 'ip' => $ip, 'ready' => $ready], JSON_THROW_ON_ERROR));
        Db::startTrans();
        $transactionOpen = true;
        if (Organization::where('id', $organizationId)->lock(true)->find() === null) throw new RuntimeException('passkey boundary organization is unavailable');
        $mutate();
        $process = proc_open([PHP_BINARY, __FILE__, '--passkey-boundary-worker', $payloadFile, $resultFile], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot start passkey boundary worker');
        foreach ($pipes as $pipe) fclose($pipe);
        $deadline = microtime(true) + 15;
        while (!is_file($ready)) { if (microtime(true) >= $deadline) throw new RuntimeException('passkey boundary worker did not become ready'); usleep(10_000); }
        usleep(100_000); // Worker resolves the old committed state, then blocks on this organization lock.
        $status = proc_get_status($process);
        if (($status['running'] ?? false) !== true) throw new RuntimeException('passkey boundary worker did not block on the parent organization lock');
        Db::commit();
        $transactionOpen = false;
        if (proc_close($process) !== 0) throw new RuntimeException('passkey boundary worker process failed');
        $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;
        if (!is_array($result)) throw new RuntimeException('passkey boundary worker did not return a result');
        return $result;
    } catch (Throwable $exception) {
        if ($transactionOpen) Db::rollback();
        throw $exception;
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$packageRoot = dirname(__DIR__);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || !is_file($hostRoot . '/vendor/autoload.php')) {
    echo "SKIP passkey boundary PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1 with a disposable installed SandIAM database\n";
    exit(0);
}
if ((string) getenv('SAND_IAM_AUTH_PEPPER') === '') putenv('SAND_IAM_AUTH_PEPPER=' . bin2hex(random_bytes(32)));
putenv('SAND_IAM_APPLICATION_EXPERIENCE_ENABLED=1');
putenv('SAND_IAM_APPLICATION_NETWORK_POLICY_ENABLED=1');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $packageRoot . '/app/functions.php';
Config::clear(); support\App::loadAllConfig(['route']); Config::load($packageRoot . '/config', ['route'], 'plugin.sand-iam'); ThinkOrm::start(null);
if (($argv[1] ?? '') === '--passkey-boundary-worker') {
    $payload = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true);
    if (!is_array($payload)) throw new RuntimeException('invalid passkey boundary worker payload');
    passkeyBoundaryWorker($payload, (string) ($argv[3] ?? ''));
    exit(0);
}

$suffix = bin2hex(random_bytes(6));
$organization = $application = $experience = $network = $policy = null;
try {
    $organization = Organization::create(['code' => 'pk' . $suffix, 'name' => 'Passkey boundary PG 组织', 'status' => 1]);
    $application = Application::create(['organization_id' => (int) $organization->id, 'code' => 'pk' . $suffix, 'name' => 'Passkey boundary PG 应用', 'status' => 1]);
    $policy = AuthPolicy::create(['application_id' => (int) $application->id, 'registration_enabled' => 1, 'webauthn_rp_id' => 'passkey-boundary.example.test', 'webauthn_allowed_origins' => ['https://passkey-boundary.example.test'], 'webauthn_user_verification' => 'required', 'status' => 1]);
    $experience = ApplicationExperience::create(['application_id' => (int) $application->id, 'brand_name' => 'Passkey boundary', 'primary_color' => '#1677ff', 'theme_mode' => 'system', 'default_locale' => 'zh-CN', 'registration_mode' => 'disabled', 'login_methods' => ['password', 'passkey'], 'registration_fields' => ['username', 'email'], 'status' => 1]);
    $network = ApplicationNetworkPolicy::create(['application_id' => (int) $application->id, 'allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => [], 'status' => 1]);
    $reference = ['organization_code' => (string) $organization->code, 'application_code' => (string) $application->code];

    $removed = passkeyBoundaryRace((int) $organization->id, static function () use ($experience): void { $experience->save(['login_methods' => ['password']]); }, $reference, '127.0.0.1');
    passkeyBoundaryAssert(($removed['ok'] ?? true) === false && str_contains((string) ($removed['error'] ?? ''), 'SAND_IAM_AUTH_METHOD_DISABLED'), 'concurrent passkey removal allowed authentication finish');
    passkeyBoundaryAssert(WebauthnCredential::where('application_id', (int) $application->id)->count() === 0 && Db::table('sand_iam_auth_session')->where('application_id', (int) $application->id)->count() === 0, 'passkey-removal race wrote a credential or session');

    $experience->save(['login_methods' => ['password', 'passkey']]);
    $networkDenied = passkeyBoundaryRace((int) $organization->id, static function () use ($network): void { $network->save(['deny_cidrs' => ['127.0.0.1/32']]); }, $reference, '127.0.0.1');
    passkeyBoundaryAssert(($networkDenied['ok'] ?? true) === false && str_contains((string) ($networkDenied['error'] ?? ''), 'SAND_IAM_NETWORK_ACCESS_DENIED'), 'concurrent network update allowed authentication finish');
    passkeyBoundaryAssert(WebauthnCredential::where('application_id', (int) $application->id)->count() === 0 && Db::table('sand_iam_auth_session')->where('application_id', (int) $application->id)->count() === 0, 'network-change race wrote a credential or session');

    $network->save(['deny_cidrs' => []]);
    $disabled = passkeyBoundaryRace((int) $organization->id, static function () use ($organization): void { $organization->save(['status' => 2]); }, $reference, '127.0.0.1');
    passkeyBoundaryAssert(($disabled['ok'] ?? true) === false && str_contains((string) ($disabled['error'] ?? ''), 'SAND_IAM_AUTHENTICATION_FAILED'), 'concurrent organization disable allowed authentication finish');
    passkeyBoundaryAssert(WebauthnCredential::where('application_id', (int) $application->id)->count() === 0 && Db::table('sand_iam_auth_session')->where('application_id', (int) $application->id)->count() === 0, 'organization-disable race wrote a credential or session');
    echo "passkey boundary PostgreSQL integration passed\n";
} finally {
    if ($application !== null) {
        Db::table('sand_iam_auth_rate_limit')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_auth_challenge')->where('application_id', (int) $application->id)->delete();
        $sessionIds = Db::table('sand_iam_auth_session')->where('application_id', (int) $application->id)->column('id');
        if ($sessionIds !== []) Db::table('sand_iam_auth_refresh_token')->whereIn('session_id', $sessionIds)->delete();
        Db::table('sand_iam_auth_session')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_webauthn_credential')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_audit_log')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_application_network_policy')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_application_experience')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_auth_policy')->where('application_id', (int) $application->id)->delete();
        Db::table('sand_iam_application')->where('id', (int) $application->id)->delete();
    }
    if ($organization !== null) Db::table('sand_iam_organization')->where('id', (int) $organization->id)->delete();
}
