<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\service\OnboardingService;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;
use think\facade\Db;

function onboardingPgAssert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

/** @param list<array<string,mixed>> $race */
function onboardingPgRaceSummary(array $race): string
{
    $summary = [];
    foreach ($race as $index => $worker) {
        if (($worker['ok'] ?? false) === true) {
            $summary[] = "worker{$index}=ok(replayed=" . (($worker['result']['replayed'] ?? null) === true ? 'true' : 'false') . ')';
            continue;
        }
        $message = (string) ($worker['error'] ?? 'missing worker result');
        $message = preg_replace('/siam_[A-Za-z0-9]+/', '[redacted]', $message) ?? 'worker error unavailable';
        $summary[] = "worker{$index}=failed(code=" . (string) ($worker['code'] ?? 'unknown') . ', error=' . substr($message, 0, 240) . ')';
    }
    return implode('; ', $summary);
}

/** @return list<string> */
function onboardingPgRequestIds(string $operationId, string $staleOperationId): array
{
    $credentialRequestId = static fn (string $operation): string => 'onb_' . substr(hash('sha256', $operation . "\0credential.issue"), 0, 48);
    return [$operationId, $staleOperationId, $credentialRequestId($operationId), $credentialRequestId($staleOperationId)];
}

/** Removes only this fixture's request ids and random-prefix resources. */
function onboardingPgCleanup(int $organizationId, string $organizationCode, string $applicationCode, string $serviceCode, string $operationId, string $staleOperationId): void
{
    $requestIds = onboardingPgRequestIds($operationId, $staleOperationId);
    $organization = Db::table('sand_iam_organization')->where('id', $organizationId)->where('code', $organizationCode)->find();
    $application = $organization === null ? null : Db::table('sand_iam_application')->where('organization_id', $organizationId)->where('code', $applicationCode)->find();
    if ($application !== null) {
        $applicationId = (int) $application['id'];
        Db::table('sand_iam_audit_log')->where('organization_id', $organizationId)->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_sync_outbox')->where('application_id', $applicationId)->delete();
        $environmentIds = Db::table('sand_iam_environment')->where('application_id', $applicationId)->column('id');
        $clientIds = $environmentIds === [] ? [] : Db::table('sand_iam_workload_client')->whereIn('environment_id', $environmentIds)->column('id');
        if ($clientIds !== []) {
            Db::table('sand_iam_credential')->whereIn('workload_client_id', $clientIds)->delete();
            Db::table('sand_iam_service_grant')->whereIn('workload_client_id', $clientIds)->delete();
            Db::table('sand_iam_workload_client')->whereIn('id', $clientIds)->delete();
        }
        Db::table('sand_iam_api_route_binding')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_api_resource')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_policy')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_initialization_binding')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_initialization_run')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_environment')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_application_business_action')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_role')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_resource')->where('application_id', $applicationId)->delete();
        Db::table('sand_iam_application')->where('id', $applicationId)->delete();
    }
    Db::table('sand_iam_audit_log')->where('organization_id', $organizationId)->whereNull('application_id')->whereIn('request_id', $requestIds)->delete();
    Db::table('sand_iam_security_operation')->where('actor_type', 'admin')->where('actor_ref', '1')->where('operation', 'onboarding.apply')->whereIn('request_id', [$operationId, $staleOperationId])->delete();
    if ($organization !== null) Db::table('sand_iam_organization')->where('id', $organizationId)->where('code', $organizationCode)->delete();
    $service = Db::table('sand_iam_service')->where('code', $serviceCode)->find();
    if ($service !== null) {
        $serviceId = (int) $service['id'];
        $actionIds = Db::table('sand_iam_service_action')->where('service_id', $serviceId)->column('id');
        if ($actionIds !== []) Db::table('sand_iam_service_grant')->whereIn('service_action_id', $actionIds)->delete();
        Db::table('sand_iam_service_action')->where('service_id', $serviceId)->delete();
        Db::table('sand_iam_service')->where('id', $serviceId)->where('code', $serviceCode)->delete();
    }
}

/** @param array{manifest:array<string,mixed>,preview_hash:string,admin_id:int,request_id:string,barrier:string,ready:string} $payload */
function onboardingPgApplyWorker(array $payload, string $resultFile): void
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
        $result = (new OnboardingService())->apply($payload['manifest'], $payload['preview_hash'], $payload['admin_id'], $payload['request_id']);
        file_put_contents($resultFile, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $exception->getMessage(), 'code' => (string) $exception->getCode()], JSON_THROW_ON_ERROR));
    }
}

/** @param array<string,mixed> $manifest @return list<array<string,mixed>> */
function onboardingPgCompete(array $manifest, string $previewHash, int $adminId, string $requestId): array
{
    $directory = sys_get_temp_dir() . '/sand-iam-onboarding-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('cannot create onboarding worker barrier');
    $barrier = $directory . '/release';
    $payloadFile = $directory . '/payload.json';
    $payload = ['manifest' => $manifest, 'preview_hash' => $previewHash, 'admin_id' => $adminId, 'request_id' => $requestId, 'barrier' => $barrier];
    file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR));
    $resultFiles = [$directory . '/result-0.json', $directory . '/result-1.json'];
    $readyFiles = [$directory . '/ready-0', $directory . '/ready-1'];
    $children = [];
    try {
        foreach ([0, 1] as $worker) {
            $workerPayload = $payload + ['ready' => $readyFiles[$worker]];
            $workerFile = $directory . '/worker-' . $worker . '.json';
            file_put_contents($workerFile, json_encode($workerPayload, JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, '--onboarding-worker', $workerFile, $resultFiles[$worker]], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('cannot start onboarding worker process');
            foreach ($pipes as $pipe) fclose($pipe);
            // A forked child inherits the parent's prepared PDO statements.
            // Use a clean PHP process so each worker owns its database handle.
            $children[] = ['process' => $process, 'payload' => $workerFile];
        }
        $deadline = microtime(true) + 15;
        while (!is_file($readyFiles[0]) || !is_file($readyFiles[1])) {
            if (microtime(true) >= $deadline) throw new RuntimeException('onboarding workers did not reach the barrier');
            usleep(10_000);
        }
        touch($barrier);
        foreach ($children as $child) {
            proc_close($child['process']);
        }
        $results = [];
        foreach ($resultFiles as $file) {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (!is_array($decoded)) throw new RuntimeException('onboarding worker did not return a result');
            $results[] = $decoded;
        }
        return $results;
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
}

/** @param array{environment_id:int,name:string} $payload */
function onboardingPgMutateWorker(array $payload, string $resultFile): void
{
    try {
        Db::connect(null, true);
        $changed = Db::table('sand_iam_environment')->where('id', $payload['environment_id'])->update(['name' => $payload['name']]);
        file_put_contents($resultFile, json_encode(['ok' => $changed === 1], JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
    }
}

function onboardingPgMutateFromAnotherProcess(int $environmentId): void
{
    if (!function_exists('proc_open')) throw new RuntimeException('SKIP: proc_open is required for the independent PostgreSQL state-mutation worker');
    $directory = sys_get_temp_dir() . '/sand-iam-onboarding-mutate-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('cannot create onboarding mutation worker directory');
    $payloadFile = $directory . '/payload.json'; $resultFile = $directory . '/result.json';
    try {
        file_put_contents($payloadFile, json_encode(['environment_id' => $environmentId, 'name' => '预检后独立管理连接变更'], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __FILE__, '--onboarding-mutator', $payloadFile, $resultFile], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot start onboarding mutation worker');
        foreach ($pipes as $pipe) fclose($pipe);
        if (proc_close($process) !== 0) throw new RuntimeException('independent onboarding mutation worker failed');
        $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;
        if (!is_array($result) || ($result['ok'] ?? false) !== true) throw new RuntimeException('independent onboarding mutation did not update the fixture state');
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$packageRoot = dirname(__DIR__);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || !is_file($hostRoot . '/vendor/autoload.php')) {
    echo "SKIP onboarding PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1 and provide SandAdmin host dependencies\n";
    exit(0);
}
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $packageRoot . '/app/functions.php';
Config::clear(); support\App::loadAllConfig(['route']); Config::load($packageRoot . '/config', ['route'], 'plugin.sand-iam'); ThinkOrm::start(null);
if (($argv[1] ?? null) === '--onboarding-worker') {
    $workerFile = $argv[2] ?? '';
    $resultFile = $argv[3] ?? '';
    $payload = is_file($workerFile) ? json_decode((string) file_get_contents($workerFile), true) : null;
    if (!is_array($payload) || $resultFile === '') throw new RuntimeException('invalid onboarding worker payload');
    onboardingPgApplyWorker($payload, $resultFile);
    exit(0);
}
if (($argv[1] ?? null) === '--onboarding-mutator') {
    $workerFile = $argv[2] ?? '';
    $resultFile = $argv[3] ?? '';
    $payload = is_file($workerFile) ? json_decode((string) file_get_contents($workerFile), true) : null;
    if (!is_array($payload) || $resultFile === '') throw new RuntimeException('invalid onboarding mutation payload');
    onboardingPgMutateWorker($payload, $resultFile);
    exit(0);
}
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$organizationCode = 'onb' . $suffix;
$serviceCode = 'onbsvc' . $suffix;
$applicationCode = 'app' . $suffix;
$organization = Organization::create(['code' => $organizationCode, 'name' => 'Onboarding PG 组织', 'status' => 1]);
$service = Service::create(['code' => $serviceCode, 'name' => 'Onboarding PG 服务', 'status' => 1]);
$action = ServiceAction::create(['service_id' => (int) $service->id, 'code' => 'context.issue', 'name' => '签发上下文', 'status' => 1]);
$operationId = 'onboarding-pg-' . $suffix;
$staleOperationId = 'onboarding-stale-' . $suffix;
$manifest = ['format' => 'sand-iam.onboarding/v1', 'operation_id' => $operationId, 'organization' => ['code' => $organizationCode], 'initialization' => ['format' => 'sand-iam.initialization/v1', 'package_code' => 'onb' . $suffix, 'organization_code' => $organizationCode, 'application' => ['code' => $applicationCode, 'name' => 'Onboarding PG 应用'], 'roles' => [['code' => 'operator', 'name' => '操作员']], 'user_types' => [], 'resources' => [['code' => 'matter', 'name' => '案件', 'owner_field' => 'owner_id', 'organization_field' => 'organization_id']], 'business_actions' => [['code' => 'matter.read', 'name' => '查看案件', 'description' => 'PG fixture']], 'identity_providers' => [], 'policies' => [['key' => 'read', 'resource_code' => 'matter', 'role_code' => 'operator', 'action' => 'matter.read', 'effect' => 'allow', 'condition' => [], 'scope' => []]]], 'environment' => ['code' => 'test', 'name' => '测试'], 'api_resources' => [['code' => 'matter.detail', 'name' => '案件详情', 'resource_code' => 'matter', 'action' => 'matter.read', 'operation' => 'read', 'api_version' => 'v1', 'audience' => 'onb-api', 'risk_level' => 'medium']], 'routes' => [['method' => 'GET', 'path' => '/onboarding/' . $suffix . '/matters/{id}', 'sand_iam' => ['api_code' => 'matter.detail']]], 'workload_client' => ['code' => 'client' . $suffix, 'name' => 'Onboarding PG Client', 'audience' => 'onb-api'], 'service_grants' => [['service_code' => $serviceCode, 'action_code' => (string) $action->code]]];
try {
    $onboarding = new OnboardingService(); $preview = $onboarding->preview($manifest);
    $race = onboardingPgCompete($manifest, (string) $preview['preview_hash'], 1, $operationId);
    onboardingPgAssert(count($race) === 2 && $race[0]['ok'] === true && $race[1]['ok'] === true, 'two onboarding workers did not both return a result: ' . onboardingPgRaceSummary($race));
    $firstRuns = array_values(array_filter($race, static fn (array $item): bool => ($item['result']['replayed'] ?? null) === false));
    $replays = array_values(array_filter($race, static fn (array $item): bool => ($item['result']['replayed'] ?? null) === true));
    onboardingPgAssert(count($firstRuns) === 1 && count($replays) === 1, 'same operation_id did not produce one apply and one completed replay');
    $first = $firstRuns[0]['result']; $replay = $replays[0]['result'];
    onboardingPgAssert(str_starts_with((string) ($first['result']['credential'] ?? ''), 'siam_'), 'winning worker did not issue a credential');
    onboardingPgAssert(($replay['result']['secret_available'] ?? null) === false && !isset($replay['result']['credential']), 'losing worker replay exposed secret');
    onboardingPgAssert(Credential::where('workload_client_id', (int) $first['result']['workload_client_id'])->count() === 1, 'callback count produced duplicate credential');
    onboardingPgAssert(AuditLog::where('action', 'credential.issue')->where('resource_id', (int) $first['result']['credential_id'])->count() === 1, 'credential audit count is not one');

    $staleManifest = $manifest; $staleManifest['operation_id'] = $staleOperationId;
    $statePreview = $onboarding->preview($staleManifest);
    onboardingPgMutateFromAnotherProcess((int) $first['result']['environment_id']);
    try { $onboarding->apply($staleManifest, (string) $statePreview['preview_hash'], 1, 'onboarding-stale-' . $suffix); throw new RuntimeException('expected stale preview'); } catch (\plugin\sandadmin\exception\ApiException $exception) { onboardingPgAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ONBOARDING_PREVIEW_STALE'), 'concurrent state drift did not stale preview'); }
    echo "onboarding PostgreSQL integration passed\n";
} finally {
    onboardingPgCleanup((int) $organization->id, $organizationCode, $applicationCode, $serviceCode, $operationId, $staleOperationId);
}
