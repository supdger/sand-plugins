<?php

declare(strict_types=1);

use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\OidcSigningKey;
use plugin\SandIam\app\model\SecurityOperation;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\OAuthOidcService;
use plugin\SandIam\app\service\OidcSigningKeyService;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

if ((string) getenv('SAND_IAM_OIDC_KEY_ROTATION_PG_ENABLED') !== '1') {
    fwrite(STDOUT, "SKIP: set SAND_IAM_OIDC_KEY_ROTATION_PG_ENABLED=1 for the disposable OIDC signing-key PostgreSQL fixture\n");
    exit(0);
}

function oidcRotationPgFail(string $message): never { throw new RuntimeException("OIDC signing-key PostgreSQL fixture failed: {$message}"); }
function oidcRotationPgAssert(bool $condition, string $message): void { if (!$condition) oidcRotationPgFail($message); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) oidcRotationPgFail('SandAdmin dependencies are unavailable');
putenv('SAND_IAM_OIDC_ISSUER=https://iam.example.test/api/sand-iam/v1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION=pg-v1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS={}');
putenv('SAND_IAM_OIDC_PRIVATE_KEY_BASE64=');
putenv('SAND_IAM_OIDC_KID=');
putenv('SAND_IAM_AUTH_PEPPER=oidc-key-rotation-pg-pepper');
putenv('SAND_IAM_OIDC_SUBJECT_KEY=' . bin2hex(random_bytes(32)));
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $root . '/plugin/sand-iam/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

if (OidcSigningKey::count() !== 0) oidcRotationPgFail('fixture requires a disposable database with no existing OIDC signing keys');
$suffix = bin2hex(random_bytes(8));
$firstRequestId = 'oidc-key-rotate-' . $suffix;
$secondRequestId = 'oidc-key-rotate-next-' . $suffix;
$operations = new IdempotencyService();
$service = new OidcSigningKeyService();
$firstKeyId = $secondKeyId = null;

try {
    $firstCallbackCalls = 0;
    $first = $operations->execute('admin', '1', 'oidc.signing_key.rotate', $firstRequestId, IdempotencyService::fingerprint(['owner_scope' => 'issuer']), 'oidc_signing_key', function () use (&$firstCallbackCalls, $service, $firstRequestId): array {
        $firstCallbackCalls++;
        $result = $service->rotate('1', $firstRequestId);
        return ['resource_id' => (int) $result['id'], 'result' => $result];
    });
    $replay = $operations->execute('admin', '1', 'oidc.signing_key.rotate', $firstRequestId, IdempotencyService::fingerprint(['owner_scope' => 'issuer']), 'oidc_signing_key', static fn (): array => oidcRotationPgFail('idempotency replay invoked rotation callback'));
    $firstKeyId = (int) $first['result']['id'];
    oidcRotationPgAssert($firstCallbackCalls === 1 && $replay['replayed'] === true && ($replay['result']['secret_available'] ?? true) === false, 'rotation idempotency did not produce a secret-free replay');
    oidcRotationPgAssert(OidcSigningKey::where('state', 'active')->count() === 1, 'first rotation did not leave exactly one active signer');
    $firstRecord = OidcSigningKey::find($firstKeyId);
    $storedEnvelope = Db::table('sand_iam_oidc_signing_key')->where('id', $firstKeyId)->value('encrypted_private_key');
    $modelEnvelope = $firstRecord?->getData('encrypted_private_key');
    oidcRotationPgAssert(is_string($storedEnvelope) && $storedEnvelope !== '' && !str_contains($storedEnvelope, 'BEGIN PRIVATE KEY'), 'OIDC signing key database column is not a private-key envelope');
    oidcRotationPgAssert(is_string($modelEnvelope) && $modelEnvelope !== '' && !str_contains($modelEnvelope, 'BEGIN PRIVATE KEY'), 'OIDC signing key model did not retain the private-key envelope');
    $firstJwks = (new OAuthOidcService())->jwks();
    oidcRotationPgAssert(count($firstJwks['keys'] ?? []) === 1 && (string) $firstJwks['keys'][0]['kid'] === (string) $firstRecord->kid, 'JWKS did not publish the active rotated public key');

    $second = $operations->execute('admin', '1', 'oidc.signing_key.rotate', $secondRequestId, IdempotencyService::fingerprint(['owner_scope' => 'issuer', 'sequence' => 2]), 'oidc_signing_key', function () use ($service, $secondRequestId): array {
        $result = $service->rotate('1', $secondRequestId);
        return ['resource_id' => (int) $result['id'], 'result' => $result];
    });
    $secondKeyId = (int) $second['result']['id'];
    $retiring = OidcSigningKey::find($firstKeyId);
    oidcRotationPgAssert($retiring !== null && (string) $retiring->state === 'verify_only' && $retiring->retire_time !== null && OidcSigningKey::where('state', 'active')->count() === 1, 'second rotation did not atomically retain the old verification key');
    oidcRotationPgAssert(count((new OAuthOidcService())->jwks()['keys'] ?? []) === 2, 'old key was removed from JWKS before the grace window ended');
    try { $service->retire($firstKeyId, '1', 'oidc-key-retire-early-' . $suffix); oidcRotationPgFail('early key retirement was accepted'); } catch (ApiException $exception) { oidcRotationPgAssert(str_contains($exception->getMessage(), 'SAND_IAM_OIDC_SIGNING_KEY_GRACE_NOT_ELAPSED'), 'early key retirement did not fail closed'); }
    $retiring->save(['retire_time' => date('Y-m-d H:i:s', time() - 1)]);
    $retired = $service->retire($firstKeyId, '1', 'oidc-key-retire-' . $suffix);
    oidcRotationPgAssert(($retired['state'] ?? null) === 'retired' && count((new OAuthOidcService())->jwks()['keys'] ?? []) === 1, 'expired key retirement did not stop JWKS publication');
    oidcRotationPgAssert(AuditLog::whereIn('request_id', [$firstRequestId, $secondRequestId])->where('action', 'oidc.signing_key.rotate')->count() === 2, 'rotation audit/event source records were not written exactly once per rotation');
} finally {
    AuditLog::where('request_id', 'like', 'oidc-key-%' . $suffix . '%')->delete();
    SecurityOperation::whereIn('request_id', [$firstRequestId, $secondRequestId])->delete();
    if ($firstKeyId !== null || $secondKeyId !== null) OidcSigningKey::whereIn('id', array_filter([$firstKeyId, $secondKeyId]))->delete();
}

fwrite(STDOUT, "OIDC signing-key PostgreSQL fixture passed\n");
