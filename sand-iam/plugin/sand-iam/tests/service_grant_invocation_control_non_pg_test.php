<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException {}');
require_once dirname(__DIR__) . '/app/security/ServiceGrantConstraintNormalizer.php';
require_once dirname(__DIR__) . '/app/runtime/ServiceInvocationFactResolver.php';
require_once dirname(__DIR__) . '/app/runtime/ServiceInvocationFactResolverRegistry.php';
require_once dirname(__DIR__) . '/app/runtime/ResolvedInvocationFacts.php';

use plugin\SandIam\app\runtime\ResolvedInvocationFacts;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolverRegistry;
use plugin\SandIam\app\security\ServiceGrantConstraintNormalizer;

final class InvocationControlResolver implements ServiceInvocationFactResolver
{
    /**
     * @param array{
     *     organization_id:int,
     *     application_id:int,
     *     environment_id:int,
     *     workload_client_id:int,
     *     data_class:?string,
     *     resource_type:string,
     *     resource_ref:string
     * } $facts
     */
    public function __construct(private readonly array $facts) {}
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array { return $this->facts; }
}

function invocationControlAssert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
function invocationControlRejects(callable $callback, string $code): void { try { $callback(); invocationControlAssert(false, "expected {$code}"); } catch (Throwable $exception) { invocationControlAssert(str_contains($exception->getMessage(), $code), "wrong error for {$code}"); } }

invocationControlAssert(ServiceGrantConstraintNormalizer::quota(null) === [], 'null quota must mean unlimited');
invocationControlAssert(ServiceGrantConstraintNormalizer::quota([]) === [], 'empty quota must mean unlimited');
invocationControlAssert(ServiceGrantConstraintNormalizer::quota((object) []) === [], 'empty JSON object quota must mean unlimited');
invocationControlAssert(ServiceGrantConstraintNormalizer::quota(['max_invocation_attempts' => 2, 'window_seconds' => 60]) === ['max_invocation_attempts' => 2, 'window_seconds' => 60], 'quota schema changed values');
invocationControlRejects(static fn () => ServiceGrantConstraintNormalizer::quota(['max_invocation_attempts' => 2, 'window_seconds' => 60, 'burst' => 1]), 'SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID');
invocationControlRejects(static fn () => ServiceGrantConstraintNormalizer::quota(['max_invocation_attempts' => '2', 'window_seconds' => 60]), 'SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID');
invocationControlAssert(ServiceGrantConstraintNormalizer::dataClass(null) === null && ServiceGrantConstraintNormalizer::dataClass('law.case') === 'law.case', 'data class normalization is not exact');
invocationControlRejects(static fn () => ServiceGrantConstraintNormalizer::dataClass('Law.Case'), 'SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID');
ServiceGrantConstraintNormalizer::assertExactDataClass('law.case', 'law.case');
invocationControlRejects(static fn () => ServiceGrantConstraintNormalizer::assertExactDataClass('law.case', 'law.public'), 'SAND_IAM_DATA_CLASS_FORBIDDEN');
$trustedScope = ['organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 44];
$registry = new ServiceInvocationFactResolverRegistry(['law-source' => new InvocationControlResolver($trustedScope + ['data_class' => 'law.case', 'resource_type' => 'source_block', 'resource_ref' => '42'])]);
$facts = ResolvedInvocationFacts::resolve($registry, 'law-source', 'svc-law', 'quota.invoke', '42');
invocationControlAssert(
    $facts->organizationId === 11
    && $facts->applicationId === 22
    && $facts->environmentId === 33
    && $facts->workloadClientId === 44
    && $facts->dataClass === 'law.case'
    && $facts->resourceType === 'source_block',
    'server facts lost trusted values or ownership scope',
);
invocationControlRejects(static fn () => ResolvedInvocationFacts::resolve($registry, 'missing', 'svc-law', 'quota.invoke', '42'), 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED');
$invalidRegistry = new ServiceInvocationFactResolverRegistry(['invalid' => new InvocationControlResolver($trustedScope + ['data_class' => 'Law.Case', 'resource_type' => 'source_block', 'resource_ref' => '42'])]);
invocationControlRejects(static fn () => ResolvedInvocationFacts::resolve($invalidRegistry, 'invalid', 'svc-law', 'quota.invoke', '42'), 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED');
$missingScopeRegistry = new ServiceInvocationFactResolverRegistry(['missing-scope' => new InvocationControlResolver(['organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 0, 'data_class' => 'law.case', 'resource_type' => 'source_block', 'resource_ref' => '42'])]);
invocationControlRejects(static fn () => ResolvedInvocationFacts::resolve($missingScopeRegistry, 'missing-scope', 'svc-law', 'quota.invoke', '42'), 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED');
$constructor = new ReflectionMethod(ResolvedInvocationFacts::class, '__construct');
invocationControlAssert($constructor->isPrivate(), 'resolved invocation facts can be directly deserialized by a public constructor');

$root = dirname(__DIR__, 3);
$provider = (string) file_get_contents($root . '/plugin/sand-iam/app/runtime/IdentityContextProvider.php');
$authorizer = (string) file_get_contents($root . '/plugin/sand-iam/app/runtime/ServiceInvocationAuthorizer.php');
$normalizer = (string) file_get_contents($root . '/plugin/sand-iam/app/security/ServiceGrantConstraintNormalizer.php');
$controller = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/ServiceGrantController.php');
$routes = (string) file_get_contents($root . '/plugin/sand-iam/config/route.php');
$pgIntegration = (string) file_get_contents($root . '/plugin/sand-iam/tests/service_grant_invocation_control_pg_integration_test.php');
foreach (["service.code AS service_code", "hash_equals((string) \$client->audience, \$audience)", 'verifyForService', "subject_scope_trust' => 'caller_asserted'", 'action_grants', 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 'NetworkPolicy::allows'] as $needle) invocationControlAssert(str_contains($provider, $needle), "context provider missing {$needle}");
foreach (['ServiceInvocationOperation', 'ServiceInvocationFactResolverRegistry', 'ResolvedInvocationFacts::resolve', 'ON CONFLICT (grant_id,window_seconds,window_start)', 'service.invoke.authorize', 'service.invoke.revalidate', 'SAND_IAM_SERVICE_QUOTA_EXCEEDED', 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 'SAND_IAM_INVOCATION_SCOPE_FORBIDDEN', "'context_id' =>", "'credential_id' =>", "'grant_ids' =>", 'grantSet($claims)', 'assertFactsScope($claims, $facts)', 'facts->organizationId', 'facts->applicationId', 'facts->environmentId', 'facts->workloadClientId', "operation->context_id", "operation->credential_id", "operation->resource_type"] as $needle) invocationControlAssert(str_contains($authorizer, $needle), "invocation authorizer missing {$needle}");
$scopeCheckPosition = strpos($authorizer, '$this->assertFactsScope($claims, $facts);');
$fingerprintPosition = strpos($authorizer, '$fingerprint = $this->fingerprint([');
invocationControlAssert(is_int($scopeCheckPosition) && is_int($fingerprintPosition) && $scopeCheckPosition < $fingerprintPosition, 'cross-tenant facts are not rejected before idempotent replay');
invocationControlAssert(str_contains($authorizer, "!hash_equals((string) \$existing->context_id") && str_contains($authorizer, '(int) $existing->credential_id !=='), 'operation replay does not enforce the stored context and credential binding');
invocationControlAssert(preg_match('/public function authorizeInvocation\((.*?)\): array/s', $authorizer, $authorizeMatch) === 1 && str_contains($authorizeMatch[1], 'string $resolverCode') && str_contains($authorizeMatch[1], 'string $resourceKey') && str_contains($authorizeMatch[1], 'string $trustedSourceIp') && !str_contains($authorizeMatch[1], 'ResolvedInvocationFacts'), 'public authorize boundary still accepts caller-constructed facts');
invocationControlAssert(preg_match('/public function revalidateInvocation\((.*?)\): array/s', $authorizer, $revalidateMatch) === 1 && str_contains($revalidateMatch[1], 'string $context') && str_contains($revalidateMatch[1], 'string $expectedAudience') && str_contains($revalidateMatch[1], 'string $trustedSourceIp') && str_contains($revalidateMatch[1], 'string $resourceKey'), 'revalidation boundary does not require current context, audience, source IP, and resource key');
invocationControlAssert(!str_contains((string) file_get_contents($root . '/plugin/sand-iam/app/runtime/ResolvedInvocationFacts.php'), 'fromServerResource'), 'arbitrary public fact factory still exists');
invocationControlAssert(str_contains($normalizer, 'SAND_IAM_DATA_CLASS_FORBIDDEN'), 'data class exact-match error is missing');
invocationControlAssert(str_contains($controller, 'SAND_IAM_SERVICE_GRANT_IMMUTABLE') && str_contains($controller, 'ServiceGrantConstraintNormalizer::quota') && str_contains($controller, 'ServiceGrantConstraintNormalizer::dataClass'), 'grant management does not enforce immutable strict constraints');
invocationControlAssert(!str_contains($routes, 'ServiceInvocationAuthorizer'), 'host-local invocation authorizer was exposed as public HTTP');
invocationControlAssert(
    str_contains($pgIntegration, 'function invocationPgCloseChildren(array &$children, bool $terminate): void')
    && str_contains($pgIntegration, 'invocationPgCloseChildren($children, false);')
    && str_contains($pgIntegration, 'finally {')
    && str_contains($pgIntegration, 'invocationPgCloseChildren($children, true);'),
    'quota concurrency test does not join or terminate workers before fixture cleanup',
);
invocationControlAssert(
    str_contains($pgIntegration, '\\Dotenv\\Dotenv::createUnsafeImmutable($hostRoot)->load();')
    && str_contains($pgIntegration, "putenv('SAND_IAM_CONTEXT_SIGNING_KEY=' . \$key);")
    && str_contains($pgIntegration, "\$_ENV['SAND_IAM_CONTEXT_SIGNING_KEY'] = \$key;")
    && str_contains($pgIntegration, "\$_SERVER['SAND_IAM_CONTEXT_SIGNING_KEY'] = \$key;")
    && str_contains($pgIntegration, "Db::query('SELECT current_database() AS name')")
    && str_contains($pgIntegration, 'refusing fixture writes outside expected database')
    && str_contains($pgIntegration, 'did not load from the authoritative plugin source')
    && strpos($pgIntegration, "if ((\$argv[1] ?? '') === '--quota-worker')") > strpos($pgIntegration, "Db::query('SELECT current_database() AS name')"),
    'quota concurrency parent and worker do not share fail-closed configuration, source, and database guards',
);
foreach ([
    'fixture cleanup left audit rows',
    'fixture cleanup left quota rows',
    'fixture cleanup left invocation rows',
    'fixture cleanup left grant rows',
    'fixture cleanup left rows in {$table}',
] as $cleanupProof) {
    invocationControlAssert(str_contains($pgIntegration, $cleanupProof), "quota concurrency test is missing cleanup proof: {$cleanupProof}");
}
$selfTestOutput = [];
$selfTestStatus = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugin/sand-iam/tests/service_grant_invocation_control_pg_integration_test.php') . ' --signing-key-self-test',
    $selfTestOutput,
    $selfTestStatus,
);
invocationControlAssert(
    $selfTestStatus === 0 && $selfTestOutput === ['service invocation signing key self-test passed'],
    'quota concurrency signing key repositories diverged when configuration was empty',
);

$migration = $root . '/migrations/030_service_grant_invocation_control.pgsql';
$pluginMigration = $root . '/plugin/sand-iam/migrations/030_service_grant_invocation_control.pgsql';
invocationControlAssert(is_file($migration) && hash_file('sha256', $migration) === hash_file('sha256', $pluginMigration), '030 root/plugin migrations differ');
$sql = (string) file_get_contents($migration);
foreach (['sand_iam_service_invocation_operation', 'sand_iam_service_quota_bucket', 'UNIQUE (workload_client_id, operation_id)', 'NOT VALID', "conrelid = 'sand_iam_service_grant'::regclass"] as $needle) invocationControlAssert(str_contains($sql, $needle), "030 migration missing {$needle}");
foreach (['install.sql'] as $file) invocationControlAssert(str_contains((string) file_get_contents($root . '/' . $file), 'lifecycle source: migrations/030_service_grant_invocation_control.pgsql') && str_contains((string) file_get_contents($root . '/plugin/sand-iam/' . $file), 'lifecycle source: migrations/030_service_grant_invocation_control.pgsql'), "{$file} missing migration 030");
foreach ([$root . '/uninstall.sql', $root . '/plugin/sand-iam/uninstall.sql', $root . '/lifecycle/remove.pgsql'] as $file) invocationControlAssert(str_contains((string) file_get_contents($file), 'DROP TABLE IF EXISTS sand_iam_service_invocation_operation') && str_contains((string) file_get_contents($file), 'DROP TABLE IF EXISTS sand_iam_service_quota_bucket'), "{$file} missing invocation cleanup");
invocationControlAssert(str_contains((string) file_get_contents($root . '/tools/build-lifecycle.php'), '030_service_grant_invocation_control.pgsql'), 'lifecycle builder missing migration 030');

echo "service grant invocation control non-PG checks passed\n";
