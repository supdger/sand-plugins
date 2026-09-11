<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolverRegistry;
use plugin\SandIam\app\runtime\ServiceInvocationAuthorizer;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

if (!interface_exists(ServiceInvocationFactResolver::class)) require_once dirname(__DIR__) . '/app/runtime/ServiceInvocationFactResolver.php';

function invocationPgAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

/** @param list<array<string,mixed>> $race */
function invocationPgRaceSummary(array $race): string
{
    $summary = [];
    foreach ($race as $index => $worker) {
        if (($worker['ok'] ?? false) === true) {
            $summary[] = "worker{$index}=allowed";
            continue;
        }
        $message = preg_replace('/(?:context|siam_)[A-Za-z0-9._:-]+/i', '[redacted]', (string) ($worker['error'] ?? 'missing worker result')) ?? 'worker error unavailable';
        $summary[] = "worker{$index}=failed(code=" . (string) ($worker['code'] ?? 'unknown') . ', error=' . substr($message, 0, 240) . ')';
    }
    return implode('; ', $summary);
}

final class InvocationPgResolver implements ServiceInvocationFactResolver
{
    public function __construct(private readonly string $storeFile) {}

    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        $rows = json_decode((string) file_get_contents($this->storeFile), true);
        $facts = is_array($rows) ? ($rows[$resourceKey] ?? null) : null;
        if (!is_array($facts)) throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED', 403);
        return $facts;
    }
}

function invocationPgAuthorizer(string $storeFile): ServiceInvocationAuthorizer
{
    return new ServiceInvocationAuthorizer(
        new IdentityContextProvider(),
        new AuditWriter(),
        new ServiceInvocationFactResolverRegistry(['pg-resource' => new InvocationPgResolver($storeFile)]),
    );
}

/** @param array<string,mixed> $payload */
function invocationPgWorker(array $payload, string $resultFile): void
{
    Db::connect(null, true);
    file_put_contents($payload['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($payload['barrier'])) { if (microtime(true) >= $deadline) { file_put_contents($resultFile, json_encode(['ok' => false, 'error' => 'barrier timeout'], JSON_THROW_ON_ERROR)); return; } usleep(10_000); }
    try {
        $result = invocationPgAuthorizer((string) $payload['facts_store'])->authorizeInvocation((string) $payload['context'], (string) $payload['service_code'], (string) $payload['audience'], (string) $payload['action_code'], 'pg-resource', (string) $payload['resource_key'], (string) $payload['trusted_source_ip'], (string) $payload['operation_id'], (string) $payload['request_id']);
        file_put_contents($resultFile, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => $exception->getMessage(), 'code' => (int) $exception->getCode()], JSON_THROW_ON_ERROR));
    }
}

/** @param array<string,mixed> $base @return list<array<string,mixed>> */
function invocationPgCompete(array $base): array
{
    if (!function_exists('proc_open')) throw new RuntimeException('proc_open is required for quota concurrency');
    $directory = sys_get_temp_dir() . '/sand-iam-invocation-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('cannot create worker directory');
    $barrier = $directory . '/release'; $children = []; $resultFiles = [];
    try {
        foreach ([0, 1] as $index) {
            $payload = $base + ['operation_id' => 'quota-race-' . $base['suffix'] . '-' . $index, 'request_id' => 'quota-race-request-' . $base['suffix'] . '-' . $index, 'resource_key' => (string) (100 + $index), 'barrier' => $barrier, 'ready' => $directory . '/ready-' . $index];
            $payloadFile = $directory . '/payload-' . $index . '.json'; $resultFile = $directory . '/result-' . $index . '.json';
            file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR)); $resultFiles[] = $resultFile;
            $process = proc_open([PHP_BINARY, __FILE__, '--quota-worker', $payloadFile, $resultFile], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('cannot start quota worker');
            foreach ($pipes as $pipe) fclose($pipe); $children[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (!is_file($directory . '/ready-0') || !is_file($directory . '/ready-1')) { if (microtime(true) >= $deadline) throw new RuntimeException('quota workers did not reach barrier'); usleep(10_000); }
        touch($barrier);
        foreach ($children as $process) proc_close($process);
        $results = [];
        foreach ($resultFiles as $file) { $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null; if (!is_array($decoded)) throw new RuntimeException('quota worker result missing'); $results[] = $decoded; }
        return $results;
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file); @rmdir($directory);
    }
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || !is_file($hostRoot . '/vendor/autoload.php')) { echo "SKIP service invocation PostgreSQL integration; use a disposable installed SandIAM database\n"; exit(0); }
if ((string) getenv('SAND_IAM_CONTEXT_SIGNING_KEY') === '') putenv('SAND_IAM_CONTEXT_SIGNING_KEY=' . bin2hex(random_bytes(32)));
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
Config::clear(); support\App::loadAllConfig(['route']); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); ThinkOrm::start(null);
if (($argv[1] ?? '') === '--quota-worker') { $payload = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true); if (!is_array($payload)) throw new RuntimeException('invalid quota worker payload'); invocationPgWorker($payload, (string) ($argv[3] ?? '')); exit(0); }

$suffix = bin2hex(random_bytes(6));
$organization = $application = $environment = $client = $credential = $rotatedCredential = $service = $action = $grant = null;
$foreignOrganization = $foreignApplication = $foreignEnvironment = null;
$otherService = $otherAction = $otherGrant = null;
$otherClient = $otherCredential = $otherClientGrant = null;
$factsStore = tempnam(sys_get_temp_dir(), 'sand-iam-invocation-facts-');
if ($factsStore === false) throw new RuntimeException('cannot create invocation fact store');
try {
    $organization = Organization::create(['code' => 'q' . $suffix, 'name' => 'Quota PG 组织', 'status' => 1]);
    $application = Application::create(['organization_id' => (int) $organization->id, 'code' => 'a' . $suffix, 'name' => 'Quota PG 应用', 'status' => 1]);
    $environment = Environment::create(['application_id' => (int) $application->id, 'code' => 'test', 'name' => '测试', 'status' => 1]);
    $client = WorkloadClient::create(['environment_id' => (int) $environment->id, 'code' => 'c' . $suffix, 'name' => 'Quota PG Client', 'audience' => 'quota-test', 'status' => 1]);
    $plain = 'siam_' . bin2hex(random_bytes(24));
    $credential = Credential::create(['workload_client_id' => (int) $client->id, 'name' => 'Quota PG credential', 'key_prefix' => substr($plain, 0, 16), 'secret_hash' => password_hash($plain, PASSWORD_DEFAULT), 'status' => 1]);
    $rotatedPlain = 'siam_' . bin2hex(random_bytes(24));
    $rotatedCredential = Credential::create(['workload_client_id' => (int) $client->id, 'name' => 'Quota PG rotated credential', 'key_prefix' => substr($rotatedPlain, 0, 16), 'secret_hash' => password_hash($rotatedPlain, PASSWORD_DEFAULT), 'status' => 1]);
    $service = Service::create(['code' => 'svc' . $suffix, 'name' => 'Quota PG service', 'status' => 1]);
    $action = ServiceAction::create(['service_id' => (int) $service->id, 'code' => 'quota.invoke', 'name' => 'Quota invoke', 'status' => 1]);
    $grant = ServiceGrant::create(['workload_client_id' => (int) $client->id, 'service_action_id' => (int) $action->id, 'audience' => 'quota-test', 'quota_policy' => ['max_invocation_attempts' => 2, 'window_seconds' => 3600], 'data_class' => 'law.case', 'network_policy' => ['allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => []], 'status' => 1]);
    $otherClient = WorkloadClient::create(['environment_id' => (int) $environment->id, 'code' => 'x' . $suffix, 'name' => 'Other PG Client', 'audience' => 'quota-test-2', 'status' => 1]);
    $otherPlain = 'siam_' . bin2hex(random_bytes(24));
    $otherCredential = Credential::create(['workload_client_id' => (int) $otherClient->id, 'name' => 'Other PG credential', 'key_prefix' => substr($otherPlain, 0, 16), 'secret_hash' => password_hash($otherPlain, PASSWORD_DEFAULT), 'status' => 1]);
    $otherClientGrant = ServiceGrant::create(['workload_client_id' => (int) $otherClient->id, 'service_action_id' => (int) $action->id, 'audience' => 'quota-test-2', 'quota_policy' => (object) [], 'data_class' => 'law.case', 'network_policy' => ['allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => []], 'status' => 1]);
    $otherService = Service::create(['code' => 'other' . $suffix, 'name' => 'Other PG service', 'status' => 1]);
    $otherAction = ServiceAction::create(['service_id' => (int) $otherService->id, 'code' => 'quota.invoke', 'name' => 'Other quota invoke', 'status' => 1]);
    $otherGrant = ServiceGrant::create(['workload_client_id' => (int) $client->id, 'service_action_id' => (int) $otherAction->id, 'audience' => 'quota-test', 'quota_policy' => (object) [], 'data_class' => 'law.case', 'network_policy' => [], 'status' => 1]);
    $foreignOrganization = Organization::create(['code' => 'fq' . $suffix, 'name' => 'Foreign PG 组织', 'status' => 1]);
    $foreignApplication = Application::create(['organization_id' => (int) $foreignOrganization->id, 'code' => 'fa' . $suffix, 'name' => 'Foreign PG 应用', 'status' => 1]);
    $foreignEnvironment = Environment::create(['application_id' => (int) $foreignApplication->id, 'code' => 'test', 'name' => '外部测试', 'status' => 1]);
    $scope = [
        'organization_id' => (int) $organization->id,
        'application_id' => (int) $application->id,
        'environment_id' => (int) $environment->id,
        'workload_client_id' => (int) $client->id,
    ];
    file_put_contents($factsStore, json_encode([
        '42' => $scope + ['data_class' => 'law.case', 'resource_type' => 'source_block', 'resource_ref' => '42'],
        '43' => $scope + ['data_class' => 'law.public', 'resource_type' => 'source_block', 'resource_ref' => '43'],
        '44' => [
            'organization_id' => (int) $foreignOrganization->id,
            'application_id' => (int) $foreignApplication->id,
            'environment_id' => (int) $foreignEnvironment->id,
            'workload_client_id' => (int) $client->id,
            'data_class' => 'law.case',
            'resource_type' => 'source_block',
            'resource_ref' => '44',
        ],
        '100' => $scope + ['data_class' => 'law.case', 'resource_type' => 'source_block', 'resource_ref' => '100'],
        '101' => $scope + ['data_class' => 'law.case', 'resource_type' => 'source_block', 'resource_ref' => '101'],
    ], JSON_THROW_ON_ERROR));
    invocationPgAssert(Db::query("SELECT to_regclass('sand_iam_service_invocation_operation') AS operation_table, to_regclass('sand_iam_service_quota_bucket') AS bucket_table")[0]['operation_table'] !== null, 'migration 030 is not installed');

    try { (new IdentityContextProvider())->issue($plain, 'quota-test', ['quota.invoke'], null, 'quota-ambiguous-' . $suffix, '127.0.0.1'); throw new RuntimeException('cross-service action ambiguity accepted'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN'), 'cross-service ambiguity used wrong error'); }
    $issued = (new IdentityContextProvider())->issue($plain, 'quota-test', ['quota.invoke'], ['caller_note' => 'not authoritative'], 'quota-issue-' . $suffix, '127.0.0.1', (string) $service->code);
    $renewedIssued = (new IdentityContextProvider())->issue($plain, 'quota-test', ['quota.invoke'], null, 'quota-renewed-issue-' . $suffix, '127.0.0.1', (string) $service->code);
    $rotatedIssued = (new IdentityContextProvider())->issue($rotatedPlain, 'quota-test', ['quota.invoke'], null, 'quota-rotated-issue-' . $suffix, '127.0.0.1', (string) $service->code);
    $otherIssued = (new IdentityContextProvider())->issue($otherPlain, 'quota-test-2', ['quota.invoke'], null, 'quota-other-issue-' . $suffix, '127.0.0.1', (string) $service->code);
    $authorizer = invocationPgAuthorizer($factsStore);
    $first = $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-first-' . $suffix, 'quota-first-request-' . $suffix);
    $replay = $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-first-' . $suffix, 'quota-replay-request-' . $suffix);
    invocationPgAssert($first['replayed'] === false && $replay['replayed'] === true && (int) $first['authorization_id'] === (int) $replay['authorization_id'], 'same operation did not replay one authorization');
    invocationPgAssert((int) Db::table('sand_iam_service_quota_bucket')->where('grant_id', (int) $grant->id)->value('used') === 1, 'idempotent replay consumed quota twice');
    $crossScopeFacts = json_decode((string) file_get_contents($factsStore), true, 512, JSON_THROW_ON_ERROR);
    $crossScopeFacts['42']['organization_id'] = (int) $foreignOrganization->id;
    $crossScopeFacts['42']['application_id'] = (int) $foreignApplication->id;
    $crossScopeFacts['42']['environment_id'] = (int) $foreignEnvironment->id;
    file_put_contents($factsStore, json_encode($crossScopeFacts, JSON_THROW_ON_ERROR));
    try { $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-first-' . $suffix, 'quota-replay-cross-scope-request-' . $suffix); throw new RuntimeException('cross-tenant replay returned the old authorization'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_INVOCATION_SCOPE_FORBIDDEN') && (int) $exception->getCode() === 403, 'cross-tenant replay did not fail with stable 403'); }
    $crossScopeFacts['42'] = $scope + ['data_class' => 'law.case', 'resource_type' => 'source_block', 'resource_ref' => '42'];
    file_put_contents($factsStore, json_encode($crossScopeFacts, JSON_THROW_ON_ERROR));
    try { $authorizer->authorizeInvocation((string) $renewedIssued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-first-' . $suffix, 'quota-new-context-request-' . $suffix); throw new RuntimeException('new context replay returned the old authorization'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT') && (int) $exception->getCode() === 409, 'new context operation conflict was not stable 409'); }
    try { $authorizer->authorizeInvocation((string) $rotatedIssued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-first-' . $suffix, 'quota-new-credential-request-' . $suffix); throw new RuntimeException('new credential replay returned the old authorization'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT') && (int) $exception->getCode() === 409, 'new credential operation conflict was not stable 409'); }

    try { $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '43', '127.0.0.1', 'quota-class-' . $suffix, 'quota-class-request-' . $suffix); throw new RuntimeException('data class mismatch accepted'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_DATA_CLASS_FORBIDDEN'), 'data class mismatch used wrong error'); }
    try { $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '44', '127.0.0.1', 'quota-cross-scope-' . $suffix, 'quota-cross-scope-request-' . $suffix); throw new RuntimeException('cross-tenant resolver facts were accepted'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_INVOCATION_SCOPE_FORBIDDEN') && (int) $exception->getCode() === 403, 'cross-tenant resolver facts did not use stable 403'); }
    try { $authorizer->revalidateInvocation((int) $first['authorization_id'], (string) $otherIssued['context'], (string) $service->code, 'quota-test-2', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-cross-client-' . $suffix); throw new RuntimeException('other client revalidated foreign authorization id'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN'), 'cross-client authorization id used wrong error'); }
    try { $authorizer->revalidateInvocation((int) $first['authorization_id'], (string) $renewedIssued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-new-context-revalidate-' . $suffix); throw new RuntimeException('renewed context revalidated the old authorization'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN') && (int) $exception->getCode() === 403, 'revalidation did not require the original context'); }
    $authorizer->revalidateInvocation((int) $first['authorization_id'], (string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-revalidate-initial-' . $suffix);

    $facts = json_decode((string) file_get_contents($factsStore), true, 512, JSON_THROW_ON_ERROR);
    $facts['42']['data_class'] = 'law.secret';
    file_put_contents($factsStore, json_encode($facts, JSON_THROW_ON_ERROR));
    try { $authorizer->revalidateInvocation((int) $first['authorization_id'], (string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-revalidate-upgraded-deny-' . $suffix); throw new RuntimeException('resource data class upgrade was not rechecked'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_DATA_CLASS_FORBIDDEN'), 'resource data class upgrade used wrong error'); }
    $grant->save(['data_class' => 'law.secret']);
    $upgraded = $authorizer->revalidateInvocation((int) $first['authorization_id'], (string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-revalidate-upgraded-allow-' . $suffix);
    invocationPgAssert(($upgraded['data_class'] ?? null) === 'law.secret', 'revalidation did not return current data class');
    $grant->save(['data_class' => 'law.case']);
    $facts['42']['data_class'] = 'law.case';
    file_put_contents($factsStore, json_encode($facts, JSON_THROW_ON_ERROR));

    $grant->save(['network_policy' => ['allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => ['127.0.0.1/32']]]);
    try { $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-network-update-' . $suffix, 'quota-network-update-request-' . $suffix); throw new RuntimeException('updated network deny was ignored'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN'), 'updated network deny used wrong error'); }
    $grant->save(['network_policy' => ['allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => []]]);
    try { $authorizer->authorizeInvocation((string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '192.0.2.1', 'quota-deny-ip-' . $suffix, 'quota-deny-ip-request-' . $suffix); throw new RuntimeException('source ip outside allow list was accepted'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN'), 'deny source ip used wrong error'); }

    $race = invocationPgCompete(['suffix' => $suffix, 'context' => (string) $issued['context'], 'service_code' => (string) $service->code, 'audience' => 'quota-test', 'action_code' => 'quota.invoke', 'facts_store' => $factsStore, 'trusted_source_ip' => '127.0.0.1']);
    $allowed = array_filter($race, static fn (array $item): bool => ($item['ok'] ?? false) === true);
    $denied = array_filter($race, static fn (array $item): bool => ($item['ok'] ?? true) === false && str_contains((string) ($item['error'] ?? ''), 'SAND_IAM_SERVICE_QUOTA_EXCEEDED'));
    invocationPgAssert(count($allowed) === 1 && count($denied) === 1, 'last quota unit was not atomic across two connections: ' . invocationPgRaceSummary($race));
    invocationPgAssert((int) Db::table('sand_iam_service_quota_bucket')->where('grant_id', (int) $grant->id)->value('used') === 2, 'quota bucket exceeded or lost the configured limit');

    $grant->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
    try { $authorizer->revalidateInvocation((int) $first['authorization_id'], (string) $issued['context'], (string) $service->code, 'quota-test', 'quota.invoke', 'pg-resource', '42', '127.0.0.1', 'quota-revalidate-' . $suffix); throw new RuntimeException('revoked grant revalidated'); } catch (ApiException $exception) { invocationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN'), 'revoked grant used wrong revalidation error'); }
    echo "service grant invocation PostgreSQL integration passed\n";
} finally {
    if ($application !== null) Db::table('sand_iam_audit_log')->where('application_id', (int) $application->id)->delete();
    if ($grant !== null) { Db::table('sand_iam_service_quota_bucket')->where('grant_id', (int) $grant->id)->delete(); Db::table('sand_iam_service_invocation_operation')->where('grant_id', (int) $grant->id)->delete(); Db::table('sand_iam_service_grant')->where('id', (int) $grant->id)->delete(); }
    if ($otherGrant !== null) { Db::table('sand_iam_service_quota_bucket')->where('grant_id', (int) $otherGrant->id)->delete(); Db::table('sand_iam_service_invocation_operation')->where('grant_id', (int) $otherGrant->id)->delete(); Db::table('sand_iam_service_grant')->where('id', (int) $otherGrant->id)->delete(); }
    if ($otherClientGrant !== null) { Db::table('sand_iam_service_quota_bucket')->where('grant_id', (int) $otherClientGrant->id)->delete(); Db::table('sand_iam_service_invocation_operation')->where('grant_id', (int) $otherClientGrant->id)->delete(); Db::table('sand_iam_service_grant')->where('id', (int) $otherClientGrant->id)->delete(); }
    if ($credential !== null) Db::table('sand_iam_credential')->where('id', (int) $credential->id)->delete();
    if ($rotatedCredential !== null) Db::table('sand_iam_credential')->where('id', (int) $rotatedCredential->id)->delete();
    if ($otherCredential !== null) Db::table('sand_iam_credential')->where('id', (int) $otherCredential->id)->delete();
    if ($client !== null) Db::table('sand_iam_workload_client')->where('id', (int) $client->id)->delete();
    if ($otherClient !== null) Db::table('sand_iam_workload_client')->where('id', (int) $otherClient->id)->delete();
    if ($environment !== null) Db::table('sand_iam_environment')->where('id', (int) $environment->id)->delete();
    if ($application !== null) Db::table('sand_iam_application')->where('id', (int) $application->id)->delete();
    if ($organization !== null) Db::table('sand_iam_organization')->where('id', (int) $organization->id)->delete();
    if ($foreignEnvironment !== null) Db::table('sand_iam_environment')->where('id', (int) $foreignEnvironment->id)->delete();
    if ($foreignApplication !== null) Db::table('sand_iam_application')->where('id', (int) $foreignApplication->id)->delete();
    if ($foreignOrganization !== null) Db::table('sand_iam_organization')->where('id', (int) $foreignOrganization->id)->delete();
    if ($action !== null) Db::table('sand_iam_service_action')->where('id', (int) $action->id)->delete();
    if ($otherAction !== null) Db::table('sand_iam_service_action')->where('id', (int) $otherAction->id)->delete();
    if ($service !== null) Db::table('sand_iam_service')->where('id', (int) $service->id)->delete();
    if ($otherService !== null) Db::table('sand_iam_service')->where('id', (int) $otherService->id)->delete();
    @unlink($factsStore);
}
