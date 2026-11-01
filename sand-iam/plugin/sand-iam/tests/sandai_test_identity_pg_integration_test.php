<?php

declare(strict_types=1);

use app\Api\Support\ApiProblem;
use app\Api\Controller\ModelsController;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Onboarding\DeploymentProcessingPolicy;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\ModelCatalogAccessGuard;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\SandIam\app\service\CredentialIssuanceService;
use think\facade\Db;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

// Real PostgreSQL and both authoritative runtime adapters; no mocked IAM port.
// Writes only random-prefix IAM and AI catalog fixtures in one transaction.
// This does not claim an installed HTTP host, provider execution, or AI audit.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') {
    echo "SKIP SandAI test identity PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1\n";
    exit(0);
}
$host = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_demo/server';
$expectedDatabase = getenv('SAND_IAM_PG_EXPECTED_DATABASE');
if (!is_string($expectedDatabase) || $expectedDatabase === '' || !is_file($host . '/vendor/autoload.php')) {
    throw new RuntimeException('An explicitly authorized database and installed host dependencies are required');
}
$repository = dirname(__DIR__, 4);
$iam = dirname(__DIR__);
$ai = $repository . '/sand-ai';
chdir($host);
require $host . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($iam, $ai): void {
    foreach (['plugin\\SandIam\\' => $iam . '/', 'app\\' => $ai . '/app/'] as $prefix => $root) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
        return;
    }
}, true, true);
\Dotenv\Dotenv::createUnsafeImmutable($host)->load();
$signingKey = bin2hex(random_bytes(32));
putenv('SAND_IAM_CONTEXT_SIGNING_KEY=' . $signingKey);
$_ENV['SAND_IAM_CONTEXT_SIGNING_KEY'] = $_SERVER['SAND_IAM_CONTEXT_SIGNING_KEY'] = $signingKey;
require $iam . '/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($iam . '/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);
$databaseConfig = Db::getConfig();
if (!is_array($databaseConfig['connections']['pgsql'] ?? null)) {
    throw new RuntimeException('The host must provide its PostgreSQL connection');
}
$databaseConfig['default'] = 'pgsql';
$databaseConfig['connections']['pgsql']['database'] = $expectedDatabase;
Db::setConfig($databaseConfig);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$assert((Db::query('SELECT current_database() AS name')[0]['name'] ?? null) === $expectedDatabase, 'Database identity differs from explicit fixture target');
// Both model families must join the same PostgreSQL transaction. Abort before
// any fixture write if a host bootstrap supplies a separate AI connection.
$fixtureConnection = Db::connect();
if ((new Provider())->db()->getConnection() !== $fixtureConnection
    || (new Organization())->db()->getConnection() !== $fixtureConnection) {
    throw new RuntimeException('IAM and AI fixtures must share one transaction connection');
}
foreach ([
    IdentityContextProvider::class => $iam . '/app/runtime/IdentityContextProvider.php',
    ModelCatalogAccessGuard::class => $ai . '/app/Identity/Infrastructure/ModelCatalogAccessGuard.php',
    HostSandIamInvocationAuthorizer::class => $ai . '/app/Identity/Infrastructure/HostSandIamInvocationAuthorizer.php',
    ModelsController::class => $ai . '/app/Api/Controller/ModelsController.php',
] as $class => $file) {
    $assert(realpath((new ReflectionClass($class))->getFileName()) === realpath($file), 'Runtime did not load authoritative source: ' . $class);
}
$tables = [
    'sand_iam_credential', 'sand_iam_service_invocation_operation', 'sand_iam_service_quota_bucket', 'sand_iam_audit_log',
    'sand_ai_provider', 'sand_ai_model', 'sand_ai_model_deployment', 'sand_ai_config_revision', 'sand_ai_capability_profile', 'sand_ai_capability_route',
];
foreach ($tables as $table) {
    $assert(Db::query('SELECT to_regclass(?) AS name', [$table])[0]['name'] !== null, 'Install the existing IAM schema before running this test: ' . $table);
}
$suffix = bin2hex(random_bytes(6));
$prefix = 'iam080_ai_' . $suffix;
$organizationId = $applicationId = $clientId = 0;
$aiFixtureIds = [];
$request = static function (string $token, string $requestId, string $sourceIp = '127.0.0.1'): support\Request {
    return new class($token, $requestId, $sourceIp) extends support\Request {
        public function __construct(string $token, string $requestId, private readonly string $sourceIp)
        {
            parent::__construct("GET /api/sand-ai/v1/models HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\nX-Request-ID: {$requestId}\r\n\r\n");
        }
        public function getRemoteIp(): string { return $this->sourceIp; }
    };
};
$reject = static function (callable $operation, string $code) use ($assert): void {
    try { $operation(); }
    catch (ApiProblem $problem) {
        $assert($problem->errorCode === $code, 'Consumer rejected with unexpected code: ' . $problem->errorCode);
        return;
    }
    throw new RuntimeException('Consumer accepted a forbidden call: ' . $code);
};
Db::startTrans();
try {
    echo "SandAI consumer: create temporary test client and issue one-time credential\n";
    $organization = Organization::create(['code' => $prefix, 'name' => 'SandAI 测试组织', 'status' => 1]);
    $organizationId = (int) $organization->id;
    $application = Application::create(['organization_id' => $organizationId, 'code' => $prefix, 'name' => 'SandAI 测试应用', 'status' => 1]);
    $applicationId = (int) $application->id;
    // Existing AI profiles may refer to historical IAM IDs, even after the
    // corresponding IAM environment disappeared. Keep those rows untouched;
    // obtain a fresh sequence-generated IAM ID with an unused AI scope.
    $environment = null;
    for ($attempt = 0; $attempt < 32; ++$attempt) {
        $candidate = Environment::create([
            'application_id' => $applicationId, 'code' => 'test_' . $attempt,
            'name' => '测试', 'status' => 1,
        ]);
        if (Db::table('sand_ai_capability_profile')->where('environment_id', (int) $candidate->id)->count() === 0) {
            $environment = $candidate;
            break;
        }
    }
    if ($environment === null) throw new RuntimeException('No unused AI environment scope found within the bounded fixture allocation');
    $client = WorkloadClient::create(['environment_id' => (int) $environment->id, 'code' => $prefix, 'name' => 'SandAI 测试身份', 'audience' => 'sand-ai', 'status' => 1]);
    $clientId = (int) $client->id;
    $credential = (new CredentialIssuanceService())->issue($clientId, $prefix, null, $prefix . '_credential', '1', $organizationId, $applicationId);
    $service = Service::where('code', 'sand_ai')->where('status', 1)->find();
    if ($service === null) $service = Service::create(['code' => 'sand_ai', 'name' => 'SandAI', 'status' => 1]);
    $action = ServiceAction::where('service_id', (int) $service->id)->where('code', 'sand_ai.model.read')->where('status', 1)->find();
    if ($action === null) $action = ServiceAction::create(['service_id' => (int) $service->id, 'code' => 'sand_ai.model.read', 'name' => '读取模型', 'status' => 1]);
    $grant = ServiceGrant::create([
        'workload_client_id' => $clientId, 'service_action_id' => (int) $action->id,
        'audience' => 'sand-ai', 'quota_policy' => (object) [], 'data_class' => null,
        'network_policy' => ['allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => []], 'status' => 1,
    ]);
    $issued = (new IdentityContextProvider())->issue($credential['credential'], 'sand-ai', ['sand_ai.model.read'], null, $prefix . '_issue', '127.0.0.1', 'sand_ai');
    $authenticator = new SandIamContextAuthenticator();
    $guard = new ModelCatalogAccessGuard();
    $allowedRequest = $request($issued['context'], $prefix . '_allowed');
    $identity = $authenticator->authenticate($allowedRequest, 'sand_ai.model.read')['context'];
    $guard->authorize($allowedRequest, $identity, $prefix . '_allowed');
    $assert($identity->workloadClientId === (string) $clientId && $identity->applicationId === (string) $applicationId, 'Consumer identity scope changed');
    $operation = Db::table('sand_iam_service_invocation_operation')->where('workload_client_id', $clientId)->find();
    $assert($operation !== null && $operation['outcome'] === 'allowed', 'Actual SandAI guard did not persist an allowed IAM invocation');
    echo "SandAI consumer: read a real published test model through ModelsController\n";
    $provider = Provider::create(['code' => $prefix, 'name' => 'SandAI 测试供应商', 'adapter' => 'fake', 'encrypted_config' => [], 'status' => 1]);
    $aiFixtureIds['sand_ai_provider'] = (int) $provider->id;
    $model = AiModel::create(['code' => $prefix, 'name' => 'SandAI 测试模型', 'type' => 'chat', 'capabilities' => ['chat'], 'status' => 1]);
    $aiFixtureIds['sand_ai_model'] = (int) $model->id;
    $deployment = ModelDeployment::create(['model_id' => (int) $model->id, 'provider_id' => (int) $provider->id, 'remote_model' => $prefix, 'config' => [], 'revision' => 1, 'status' => 1]);
    $aiFixtureIds['sand_ai_model_deployment'] = (int) $deployment->id;
    $processing = (new DeploymentProcessingPolicy())->create('local_runtime');
    $revision = ConfigRevision::create([
        'resource_type' => 'model_deployment', 'resource_id' => (int) $deployment->id, 'revision' => 1,
        'payload' => ['processing_policy' => $processing, 'published_processing_policy' => $processing],
        'state' => 'published', 'status' => 1,
    ]);
    $aiFixtureIds['sand_ai_config_revision'] = (int) $revision->id;
    $profile = CapabilityProfile::create(['environment_id' => (int) $environment->id, 'name' => $prefix, 'data_egress_policy' => 'local_only', 'revision' => 1, 'state' => 'published', 'status' => 1]);
    $aiFixtureIds['sand_ai_capability_profile'] = (int) $profile->id;
    $route = CapabilityRoute::create(['profile_id' => (int) $profile->id, 'capability_code' => 'chat', 'route_kind' => 'model_deployment', 'deployment_id' => (int) $deployment->id, 'priority' => 100, 'config' => [], 'status' => 1]);
    $aiFixtureIds['sand_ai_capability_route'] = (int) $route->id;
    $controller = new ModelsController();
    $response = $controller->index($request($issued['context'], $prefix . '_catalog'));
    $body = json_decode($response->rawBody(), true, flags: JSON_THROW_ON_ERROR);
    $assert($response->getStatusCode() === 200 && ($body['code'] ?? null) === 200, 'Consumer controller did not return success');
    $assert(($body['data']['data'] ?? null) === [[
        'id' => (int) $model->id, 'code' => $prefix, 'name' => 'SandAI 测试模型', 'type' => 'chat', 'capabilities' => ['chat'],
    ]], 'Consumer did not return the actual test model or exposed another environment');
    $assert(($body['request_id'] ?? null) === $prefix . '_catalog', 'Consumer response lost request correlation');
    echo "SandAI consumer: actual IAM authorization allowed; reject raw credential and disallowed source IP\n";
    $reject(static fn () => $authenticator->authenticate($request($credential['credential'], $prefix . '_raw'), 'sand_ai.model.read'), 'SAND_AI_AUTHENTICATION_FAILED');
    $reject(static fn () => $authenticator->authenticate($request($issued['context'], $prefix . '_network', '192.0.2.1'), 'sand_ai.model.read'), 'SAND_AI_SERVICE_NETWORK_FORBIDDEN');
    $networkResponse = $controller->index($request($issued['context'], $prefix . '_network_response', '192.0.2.1'));
    $networkBody = json_decode($networkResponse->rawBody(), true, flags: JSON_THROW_ON_ERROR);
    $assert($networkResponse->getStatusCode() === 400 && ($networkBody['data']['error_code'] ?? null) === 'SAND_AI_SERVICE_NETWORK_FORBIDDEN', 'Consumer controller did not reject the forbidden network');
    $assert(!isset($networkBody['data']['data']), 'Denied consumer response disclosed the model catalog');
    $authorizer = new HostSandIamInvocationAuthorizer();
    $verified = $authorizer->revalidate((int) $operation['id'], $issued['context'], $identity, 'sand_ai.model.read', 'sand_iam.workload_client', (string) $clientId, '127.0.0.1', $prefix . '_recheck');
    $assert($verified['authorization_id'] === (int) $operation['id'], 'Consumer revalidation lost original authorization');
    $reject(static fn () => $authorizer->revalidate((int) $operation['id'], $issued['context'], $identity, 'sand_ai.model.read', 'sand_iam.workload_client', (string) ($clientId + 1_000_000_000), '127.0.0.1', $prefix . '_wrong_resource'), 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    $grant->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
    $reject(static fn () => $authenticator->authenticate($request($issued['context'], $prefix . '_revoked'), 'sand_ai.model.read'), 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    $reject(static fn () => $authorizer->revalidate((int) $operation['id'], $issued['context'], $identity, 'sand_ai.model.read', 'sand_iam.workload_client', (string) $clientId, '127.0.0.1', $prefix . '_revoked_recheck'), 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    $revokedResponse = $controller->index($request($issued['context'], $prefix . '_revoked_response'));
    $revokedBody = json_decode($revokedResponse->rawBody(), true, flags: JSON_THROW_ON_ERROR);
    $assert($revokedResponse->getStatusCode() === 400 && ($revokedBody['data']['error_code'] ?? null) === 'SAND_AI_SERVICE_ACTION_FORBIDDEN', 'Consumer controller did not reject the revoked grant');
    $assert(!isset($revokedBody['data']['data']), 'Revoked consumer response disclosed the model catalog');
    $audits = Db::table('sand_iam_audit_log')->where('application_id', $applicationId)->select()->toArray();
    foreach ([
        ['credential.issue', 'succeeded'], ['context.issue', 'succeeded'],
        ['context.verify', 'allowed'], ['context.verify', 'denied'],
        ['service.invoke.authorize', 'allowed'], ['service.invoke.revalidate', 'allowed'],
        ['service.invoke.revalidate', 'denied'],
    ] as [$auditAction, $outcome]) {
        $matching = array_filter($audits, static fn (array $row): bool => $row['action'] === $auditAction && $row['outcome'] === $outcome);
        $assert($matching !== [], 'Missing real IAM audit: ' . $auditAction . '/' . $outcome);
    }
    // Revocation is rejected by context verification before invocation lookup.
    // Its denial must still be traceable to this particular consumer recheck.
    $revokedAuditPrefix = substr($prefix . '_revoked_recheck', 0, 32) . ':ai:';
    $revocationAudits = array_filter($audits, static fn (array $row): bool =>
        $row['action'] === 'context.verify' && $row['outcome'] === 'denied'
        && str_starts_with($row['request_id'], $revokedAuditPrefix));
    $assert($revocationAudits !== [], 'Original-context revocation lacks its actual IAM denial audit');
    echo "SandAI consumer: grant revocation rejects original context and queued revalidation; IAM audit verified\n";
} finally {
    Db::rollback();
    if ($organizationId > 0) $assert(Organization::where('id', $organizationId)->count() === 0, 'Fixture organization remained after rollback');
    if ($applicationId > 0) $assert(Db::table('sand_iam_audit_log')->where('application_id', $applicationId)->count() === 0, 'Fixture audit remained after rollback');
    if ($clientId > 0) $assert(Db::table('sand_iam_service_invocation_operation')->where('workload_client_id', $clientId)->count() === 0, 'Fixture invocation remained after rollback');
    foreach ($aiFixtureIds as $table => $id) {
        $assert(Db::table($table)->where('id', $id)->count() === 0, 'Fixture AI catalog row remained after rollback: ' . $table);
    }
    echo "SandAI consumer: rollback complete; no task fixture rows retained\n";
}
echo "SandAI test identity real PostgreSQL consumer checks={$checks}/{$checks}; installed HTTP/provider/AI audit not covered\n";
