<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) {
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
}
require_once dirname(__DIR__) . '/app/admin/support/AccessMatrixInput.php';

use plugin\SandIam\app\admin\support\AccessMatrixInput;
use plugin\sandadmin\exception\ApiException;

function amxAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

function amxThrows(callable $fn, string $message): void
{
    try { $fn(); } catch (ApiException) { return; }
    fwrite(STDERR, $message . PHP_EOL); exit(1);
}

$plugin = dirname(__DIR__);
$service = (string) file_get_contents($plugin . '/app/service/AccessMatrixService.php');
$controller = (string) file_get_contents($plugin . '/app/admin/controller/AccessMatrixController.php');
$routes = (string) file_get_contents($plugin . '/config/route.php');
$catalog = (string) file_get_contents($plugin . '/app/developer/ManagementApiCatalog.php');
amxAssert($service !== '' && $controller !== '' && $routes !== '' && $catalog !== '', 'access matrix sources unreadable');

// --- Input normalization ---
$normalized = AccessMatrixInput::normalizeGrant([
    'application' => ['code' => 'lvxu', 'name' => '律序'],
    'action_codes' => ['sand_ai.chat.complete', 'sand_ai.document_parse'],
    'issue_credential' => true,
]);
amxAssert($normalized['organization_id'] === null, 'organization_id must default to null');
amxAssert($normalized['application']['code'] === 'lvxu' && $normalized['application']['name'] === '律序', 'application payload not normalized');
amxAssert($normalized['service_code'] === 'sand_ai', 'service_code must default to sand_ai');
amxAssert($normalized['action_codes'] === ['sand_ai.chat.complete', 'sand_ai.document_parse'], 'action codes not normalized');
amxAssert($normalized['environment_code'] === 'prod', 'environment_code must default to prod');
amxAssert($normalized['issue_credential'] === true, 'issue_credential not parsed');

$withIds = AccessMatrixInput::normalizeGrant([
    'organization_id' => '7',
    'application' => ['id' => '12'],
    'action_codes' => ['sand_ai.chat.complete', 'sand_ai.chat.complete'],
]);
amxAssert($withIds['organization_id'] === 7 && $withIds['application']['id'] === 12, 'positive id fields not normalized');
amxAssert(count($withIds['action_codes']) === 1, 'duplicate action codes must be deduplicated');
amxAssert($withIds['issue_credential'] === false, 'issue_credential must default to false');

// Invalid inputs are rejected
amxThrows(fn () => AccessMatrixInput::normalizeGrant(['application' => ['code' => 'lvxu'], 'action_codes' => ['sand_ai.chat.complete']]), 'application without name must be rejected');
amxThrows(fn () => AccessMatrixInput::normalizeGrant(['application' => ['code' => 'BAD CODE', 'name' => 'x'], 'action_codes' => ['sand_ai.chat.complete']]), 'invalid application code must be rejected');
amxThrows(fn () => AccessMatrixInput::normalizeGrant(['application' => ['code' => 'lvxu', 'name' => 'x'], 'action_codes' => []]), 'empty action list must be rejected');
amxThrows(fn () => AccessMatrixInput::normalizeGrant(['application' => ['code' => 'lvxu', 'name' => 'x'], 'action_codes' => ['sand_ai.bad code!']]), 'invalid action code must be rejected');
amxThrows(fn () => AccessMatrixInput::normalizeGrant(['application' => ['code' => 'lvxu', 'name' => 'x'], 'service_code' => 'other_service', 'action_codes' => ['sand_ai.chat.complete']]), 'non-whitelisted service_code must be rejected');
amxThrows(fn () => AccessMatrixInput::normalizeGrant(['application' => ['code' => 'lvxu', 'name' => 'x'], 'organization_id' => 'abc', 'action_codes' => ['sand_ai.chat.complete']]), 'non-numeric organization_id must be rejected');

// audience is never accepted from input — it is not part of the normalized contract
amxAssert(!array_key_exists('audience', $normalized), 'audience must not be an accepted input field');

// --- Whitelist clipping ---
$clip = AccessMatrixInput::clipActionCodes(
    ['sand_ai.chat.complete', 'sand_ai.unknown', 'sand_ai.audio.transcription'],
    ['sand_ai.chat.complete', 'sand_ai.document_parse'],
);
amxAssert($clip['granted'] === ['sand_ai.chat.complete'], 'whitelist clip keeps only existing actions');
amxAssert($clip['skipped'] === ['sand_ai.unknown', 'sand_ai.audio.transcription'], 'whitelist clip reports skipped actions');

// --- Static guarantees in the service ---
foreach ([
    'pg_advisory_xact_lock',           // advisory lock serializes concurrent grants
    'SERIALIZABLE',                    // serializable isolation
    'access_matrix.grant',             // idempotency operation name
    'IdempotencyService',              // idempotent replay
    'waitForCompletedReplay',          // replay short-circuit before work
    'CredentialIssuanceService',       // reuse of audited one-time issuer
    "'audience' => AccessMatrixInput::DEFAULT_AUDIENCE", // fixed server-side audience
    'AUDIENCE_MISMATCH',               // existing client audience sanity check
    'AuditWriter',                     // per-write audit
    'organization.create',
    'application.create',
    'environment.create',
    'workload_client.create',
    'service_grant.create',
    'service_grant.reactivate',
    'Db::rollback()',                  // atomic rollback on failure
    'childRequestId',                  // credential audit carries derived request id
] as $needle) {
    amxAssert(str_contains($service, $needle), "AccessMatrixService missing {$needle}");
}

// matrix() must never select secret material
amxAssert(!str_contains($service, 'secret_hash'), 'matrix must not read secret_hash');
amxAssert(!str_contains($service, 'password_hash'), 'matrix must not read password material');
amxAssert(str_contains($service, "->field('id,workload_client_id,name,key_prefix,expire_time,status')"), 'credential projection must be metadata only');
amxAssert(str_contains($service, 'isSuperAdmin') && str_contains($service, 'organizationIds') && str_contains($service, 'applicationIds'), 'matrix must apply admin access scope');

// Grant-only semantics: no delete/revoke inside the matrix flow
amxAssert(!str_contains($service, '->delete()'), 'access matrix must never delete rows');
amxAssert(!str_contains($service, "'revoked_time' => date"), 'access matrix must not revoke grants');

// --- Controller guarantees ---
foreach ([
    "sand_iam:grant:index",            // read permission reuse
    "sand_iam:grant:save",             // write permission reuse
    "sand_iam:credential:issue",       // secondary check for issuance
    'no-store',                        // one-time secret response
    'UserAuthCache',                   // host-style permission check
    'assertPermission',
] as $needle) {
    amxAssert(str_contains($controller, $needle), "AccessMatrixController missing {$needle}");
}

// --- Route + catalog registration ---
foreach (["'/access-matrix/index'", "'/access-matrix/grant'", 'AccessMatrixController::class'] as $needle) {
    amxAssert(str_contains($routes, $needle), "route registry missing {$needle}");
}
foreach (["'/access-matrix/index'", "'/access-matrix/grant'"] as $needle) {
    amxAssert(str_contains($catalog, $needle), "management catalog missing {$needle}");
}
amxAssert(str_contains($catalog, "'GET /access-matrix/index' => 'sand_iam:grant:index'"), 'catalog permission override for matrix index');
amxAssert(str_contains($catalog, "'POST /access-matrix/grant' => 'sand_iam:grant:save'"), 'catalog permission override for matrix grant');

echo "access matrix non-PG checks passed\n";
