<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\service\OidcLogoutTokenCipher;
use plugin\sandadmin\exception\ApiException;
use plugin\SandIam\app\admin\controller\OidcSigningKeyController;
use plugin\SandIam\app\middleware\OidcSigningKeySensitiveMiddleware;
use support\Request;
use Webman\Config;

function oidcRotationFail(string $message): never { fwrite(STDERR, "OIDC signing-key rotation non-PG test failed: {$message}\n"); exit(1); }
function oidcRotationAssert(bool $condition, string $message): void { if (!$condition) oidcRotationFail($message); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
$plugin = $root . '/plugin/sand-iam';
if (!is_file($hostRoot . '/vendor/autoload.php')) oidcRotationFail('SandAdmin dependencies are unavailable');
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $plugin . '/app/functions.php';

final class OidcRotationScopeRequest extends Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $form @param array<string,mixed> $input */
    public function __construct(private array $query, private array $form, private array $input) { parent::__construct("POST / HTTP/1.1\r\nHost: test\r\n\r\n"); }
    public function get(?string $name = null, mixed $default = null): mixed { return $name === null ? $this->query : ($this->query[$name] ?? $default); }
    public function post(?string $name = null, mixed $default = null): mixed { return $name === null ? $this->form : ($this->form[$name] ?? $default); }
    public function input(string $name, mixed $default = null): mixed { return $this->input[$name] ?? $default; }
}

$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY=' . $keyV1);
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION=v1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS={}');
Config::clear();
Config::load($plugin . '/config', ['route'], 'plugin.sand-iam');
$cipher = new OidcLogoutTokenCipher();
$pem = "-----BEGIN PRIVATE KEY-----\nrotation-test-private-material\n-----END PRIVATE KEY-----\n";
$encrypted = $cipher->encrypt($pem);
oidcRotationAssert(str_starts_with($encrypted, 'v1.') && !str_contains($encrypted, 'rotation-test-private-material') && $cipher->decrypt($encrypted) === $pem, 'private-key envelope round trip leaked or failed');

$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY=' . $keyV2);
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION=v2');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear();
Config::load($plugin . '/config', ['route'], 'plugin.sand-iam');
oidcRotationAssert((new OidcLogoutTokenCipher())->decrypt($encrypted) === $pem, 'keyring did not retain the previous OIDC envelope key');
try { (new OidcLogoutTokenCipher())->decrypt('v2.invalid*base64'); oidcRotationFail('malformed envelope was accepted'); } catch (ApiException $exception) { oidcRotationAssert(str_contains($exception->getMessage(), 'SAND_IAM_OIDC_LOGOUT_TOKEN_UNAVAILABLE'), 'malformed envelope used an unstable error'); }

$migration = $root . '/migrations/031_oidc_signing_key_rotation.pgsql';
$packageMigration = $plugin . '/migrations/031_oidc_signing_key_rotation.pgsql';
oidcRotationAssert(is_file($migration) && hash_file('sha256', $migration) === hash_file('sha256', $packageMigration), '031 root/plugin migration mirrors differ');
$migrationSource = (string) file_get_contents($migration);
foreach (['encrypted_private_key', 'encryption_version', 'uk_sand_iam_oidc_signing_key_one_active', "WHERE state = 'active'", "algorithm = 'RS256'"] as $needle) oidcRotationAssert(str_contains($migrationSource, $needle), "031 migration lacks {$needle}");
oidcRotationAssert(str_contains((string) file_get_contents($root . '/tools/build-lifecycle.php'), '031_oidc_signing_key_rotation.pgsql'), 'lifecycle builder omits 031');
$freshSchema = (string) file_get_contents($root . '/migrations/005_oauth_oidc.pgsql');
foreach (['encrypted_private_key text NULL', 'encryption_version varchar(32) NULL', "algorithm varchar(16) NOT NULL DEFAULT 'RS256'", 'Write-only versioned private-key envelope'] as $needle) {
    oidcRotationAssert(str_contains($freshSchema, $needle), "fresh OIDC signing-key schema lacks {$needle}");
}

$service = (string) file_get_contents($plugin . '/app/service/OidcSigningKeyService.php');
$oauth = (string) file_get_contents($plugin . '/app/service/OAuthOidcService.php');
$controller = (string) file_get_contents($plugin . '/app/admin/controller/OidcSigningKeyController.php');
$routes = (string) file_get_contents($plugin . '/config/route.php');
$catalog = (string) file_get_contents($plugin . '/app/developer/ManagementApiCatalog.php');
$logoutService = (string) file_get_contents($plugin . '/app/service/OidcBackchannelLogoutService.php');
$eventCatalog = (string) file_get_contents($plugin . '/app/webhook/EventCatalog.php');
$eventPublisher = (string) file_get_contents($plugin . '/app/service/AuditEventPublisher.php');
$middleware = (string) file_get_contents($plugin . '/app/middleware/OidcSigningKeySensitiveMiddleware.php');
foreach (['lock(true)', "'verify_only'", 'graceSeconds', 'MAX_SIGNED_TOKEN_TTL', 'oidc.signing_key.rotate', 'oidc.signing_key.retire', "'owner_scope' => 'issuer'"] as $needle) oidcRotationAssert(str_contains($service, $needle), "rotation service lacks {$needle}");
foreach (['activeDatabaseSigningKey', 'OidcLogoutTokenCipher', 'encrypted_private_key', "['active', 'verify_only', 'retiring']"] as $needle) oidcRotationAssert(str_contains($oauth, $needle), "OAuth signer/JWKS path lacks {$needle}");
foreach (["OidcSigningKey::count() > 0", 'SAND_IAM_OIDC_SIGNING_UNAVAILABLE'] as $needle) oidcRotationAssert(str_contains($oauth, $needle), "legacy fallback does not fail closed after managed-key history: {$needle}");
foreach (['MAX_DELIVERY_WINDOW', "'exp' => \$issuedAt + OidcBackchannelLogoutService::MAX_DELIVERY_WINDOW", 'oidcClockSkewSeconds'] as $needle) oidcRotationAssert(str_contains($oauth, $needle), "backchannel logout token/grace lacks {$needle}");
foreach (['RETRY_DELAYS', 'MAX_DELIVERY_WINDOW = 9360'] as $needle) oidcRotationAssert(str_contains($logoutService, $needle), "backchannel retry window lacks {$needle}");
foreach (['oidc.signingkey.changed', 'oidc.signing_key.'] as $needle) oidcRotationAssert(str_contains($eventCatalog, $needle), "OIDC signing-key event catalog lacks {$needle}");
require_once $plugin . '/app/webhook/EventCatalog.php';
oidcRotationAssert(\plugin\SandIam\app\webhook\EventCatalog::fromAudit('oidc.signing_key.rotate', 'succeeded') === 'oidc.signingkey.changed', 'OIDC signing-key rotation did not publish its state-change event');
oidcRotationAssert(\plugin\SandIam\app\webhook\EventCatalog::fromAudit('oidc.signing_key.retire', 'succeeded') === 'oidc.signingkey.changed', 'OIDC signing-key retirement did not publish its state-change event');
oidcRotationAssert(\plugin\SandIam\app\webhook\EventCatalog::fromAudit('oidc.signing_key.list', 'succeeded') === null && \plugin\SandIam\app\webhook\EventCatalog::fromAudit('oidc.signing_key.status', 'succeeded') === null, 'OIDC signing-key reads must not publish state-change events');
foreach (['publishGlobalEvent', "if (\$applicationId === null)"] as $needle) oidcRotationAssert(str_contains($eventPublisher, $needle), "global audit event publisher lacks {$needle}");
foreach (["withHeader('Cache-Control', 'no-store')", "withHeader('Pragma', 'no-cache')"] as $needle) oidcRotationAssert(str_contains($middleware, $needle), "OIDC signing-key error middleware lacks {$needle}");
foreach (['RequestId::fromRequestCached', 'SAND_IAM_OIDC_SIGNING_KEY_SCOPE_UNSUPPORTED', 'IdempotencyService', 'Cache-Control', 'assertSuperAdmin'] as $needle) oidcRotationAssert(str_contains($controller, $needle), "admin rotation controller lacks {$needle}");
foreach (['/oidc-signing-key/index', '/oidc-signing-key/status', '/oidc-signing-key/rotate', '/oidc-signing-key/retire'] as $needle) {
    oidcRotationAssert(str_contains($routes, $needle) && str_contains($catalog, $needle), "route/catalog lacks {$needle}");
}

$scopeGuard = new ReflectionMethod(OidcSigningKeyController::class, 'assertIssuerScope');
$scopeGuard->setAccessible(true);
$scopeController = (new ReflectionClass(OidcSigningKeyController::class))->newInstanceWithoutConstructor();
foreach ([
    new OidcRotationScopeRequest(['application_id' => '12'], [], []),
    new OidcRotationScopeRequest([], ['environment_id' => '9'], []),
    new OidcRotationScopeRequest([], [], ['application_id' => '3']),
] as $request) {
    try { $scopeGuard->invoke($scopeController, $request); oidcRotationFail('issuer scope guard accepted a non-empty query/post/input scope'); }
    catch (ApiException $exception) { oidcRotationAssert(str_contains($exception->getMessage(), 'SAND_IAM_OIDC_SIGNING_KEY_SCOPE_UNSUPPORTED'), 'issuer scope guard used an unstable error'); }
}
$scopeGuard->invoke($scopeController, new OidcRotationScopeRequest([], [], []));

$failure = (new OidcSigningKeySensitiveMiddleware())->process(
    new OidcRotationScopeRequest([], [], []),
    static function (): \support\Response { throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_GRACE_NOT_ELAPSED', 409); },
);
oidcRotationAssert($failure->getStatusCode() === 409 && $failure->getHeader('Cache-Control') === 'no-store' && $failure->getHeader('Pragma') === 'no-cache', 'OIDC signing-key exception response is cacheable');

echo "OIDC signing-key rotation non-PG tests passed\n";
