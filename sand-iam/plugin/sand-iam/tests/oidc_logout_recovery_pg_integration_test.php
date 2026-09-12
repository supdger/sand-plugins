<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\model\OidcLogoutDelivery;
use plugin\SandIam\app\model\OidcSigningKey;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\OAuthOidcService;
use plugin\SandIam\app\service\OidcLogoutTokenCipher;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;
use think\facade\Db;

function oidcRecoveryPgAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function oidcRecoveryPgExpect(callable $operation, string $code): void
{
    try {
        $operation();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $code)) return;
        throw new RuntimeException("expected {$code}, received {$exception->getMessage()}");
    }
    throw new RuntimeException("expected {$code}, but no exception was thrown");
}

/** @return array{header:array<string,mixed>,claims:array<string,mixed>} */
function oidcRecoveryPgJwt(string $token): array
{
    [$header, $claims] = array_pad(explode('.', $token, 3), 3, '');
    $decode = static function (string $value): array {
        $padding = (4 - strlen($value) % 4) % 4;
        $plain = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        $decoded = is_string($plain) ? json_decode($plain, true) : null;
        if (!is_array($decoded)) throw new RuntimeException('logout JWT segment is invalid');
        return $decoded;
    };
    return ['header' => $decode($header), 'claims' => $decode($claims)];
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$packageRoot = dirname(__DIR__);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || !is_file($hostRoot . '/vendor/autoload.php')) {
    echo "SKIP OIDC logout recovery PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1 and provide SandAdmin host dependencies\n";
    exit(0);
}

$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($rsa === false || !openssl_pkey_export($rsa, $privatePem)) throw new RuntimeException('cannot create temporary OIDC signing key');
$encryptionKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_AUTH_PEPPER=oidc-recovery-pg-pepper-that-is-longer-than-32-bytes');
putenv('SAND_IAM_AUTH_PEPPER_VERSION=oidc-recovery-pg-v1');
putenv('SAND_IAM_OIDC_ISSUER=https://iam.example.test/api/sand-iam/v1');
putenv('SAND_IAM_OIDC_PRIVATE_KEY_BASE64=' . base64_encode($privatePem));
putenv('SAND_IAM_OIDC_KID=oidc-recovery-pg');
putenv('SAND_IAM_OIDC_SUBJECT_KEY=oidc-recovery-pg-subject-key-over-32-bytes');
putenv('SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED=1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY=' . $encryptionKey);
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION=oidc-recovery-pg-v1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS={}');

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $packageRoot . '/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($packageRoot . '/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

if (OidcSigningKey::count() !== 0) {
    echo "SKIP OIDC logout recovery PostgreSQL integration; use an isolated schema without managed signing-key history\n";
    exit(0);
}

$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$organizationCode = 'oidc-recovery-' . $suffix;
Db::startTrans();
try {
    $organization = Organization::create(['code' => $organizationCode, 'name' => 'OIDC recovery fixture', 'status' => 1]);
    $application = Application::create(['organization_id' => (int) $organization->id, 'code' => 'recovery-app-' . $suffix, 'name' => 'OIDC recovery app', 'status' => 1]);
    $identity = Identity::create(['application_id' => (int) $application->id, 'code' => 'recovery-user-' . $suffix, 'display_name' => 'OIDC recovery user', 'status' => 1]);
    $sessionBase = [
        'application_id' => (int) $application->id,
        'identity_id' => (int) $identity->id,
        'auth_method' => 'local_password',
        'pepper_version' => 'oidc-recovery-pg-v1',
        'access_expire_time' => date('Y-m-d H:i:s', time() + 900),
        'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400),
        'ip_hash' => hash('sha256', 'ip-' . $suffix),
        'user_agent' => 'oidc-recovery-pg',
    ];
    $revokedSession = AuthSession::create($sessionBase + [
        'access_token_hash' => hash('sha256', 'access-revoked-' . $suffix),
        'refresh_token_hash' => hash('sha256', 'refresh-revoked-' . $suffix),
        'revoked_time' => date('Y-m-d H:i:s'),
        'status' => 2,
    ]);
    $activeSession = AuthSession::create($sessionBase + [
        'access_token_hash' => hash('sha256', 'access-active-' . $suffix),
        'refresh_token_hash' => hash('sha256', 'refresh-active-' . $suffix),
        'status' => 1,
    ]);
    $client = OAuthClient::create([
        'application_id' => (int) $application->id,
        'code' => 'oidc-recovery-rp-' . $suffix,
        'name' => 'OIDC recovery RP',
        'client_type' => 'public',
        'redirect_uris' => ['https://rp.example.test/callback'],
        'post_logout_redirect_uris' => [],
        'backchannel_logout_uri' => 'https://rp.example.test/backchannel-logout',
        'backchannel_logout_session_required' => true,
        'allowed_scopes' => ['openid'],
        'allowed_audiences' => [],
        'status' => 1,
    ]);
    $otherClient = OAuthClient::create([
        'application_id' => (int) $application->id,
        'code' => 'oidc-recovery-other-' . $suffix,
        'name' => 'OIDC recovery other RP',
        'client_type' => 'public',
        'redirect_uris' => ['https://other-rp.example.test/callback'],
        'post_logout_redirect_uris' => [],
        'backchannel_logout_uri' => 'https://other-rp.example.test/backchannel-logout',
        'allowed_scopes' => ['openid'],
        'allowed_audiences' => [],
        'status' => 1,
    ]);
    $cipher = new OidcLogoutTokenCipher();
    $sourceCiphertext = $cipher->encrypt(json_encode(['target_uri' => 'https://old.example.test/logout', 'logout_token' => 'expired.fixture.token'], JSON_THROW_ON_ERROR));
    $source = OidcLogoutDelivery::create([
        'application_id' => (int) $application->id,
        'oauth_client_id' => (int) $client->id,
        'auth_session_id' => (int) $revokedSession->id,
        'event_id' => 'bcl_' . bin2hex(random_bytes(16)),
        'encrypted_logout_token' => $sourceCiphertext,
        'state' => 'dead',
        'attempt_count' => 5,
        'last_error_code' => 'OIDC_RECOVERY_PG_FORCED_DEAD',
        'status' => 2,
    ]);
    $activeSource = OidcLogoutDelivery::create([
        'application_id' => (int) $application->id,
        'oauth_client_id' => (int) $client->id,
        'auth_session_id' => (int) $activeSession->id,
        'event_id' => 'bcl_' . bin2hex(random_bytes(16)),
        'encrypted_logout_token' => $sourceCiphertext,
        'state' => 'dead',
        'attempt_count' => 5,
        'last_error_code' => 'OIDC_RECOVERY_PG_ACTIVE_SESSION',
        'status' => 2,
    ]);

    $service = new OAuthOidcService();
    $requestId = 'oidc-recovery-pg-' . $suffix;
    $created = $service->reissueBackchannelLogout((int) $client->id, (int) $application->id, (int) $source->id, 'oidc-recovery-admin', $requestId);
    $sourceAfter = OidcLogoutDelivery::find((int) $source->id);
    $successor = OidcLogoutDelivery::find((int) $created['delivery_id']);
    oidcRecoveryPgAssert($sourceAfter !== null && (string) $sourceAfter->state === 'dead' && (int) $sourceAfter->attempt_count === 5 && (string) $sourceAfter->encrypted_logout_token === $sourceCiphertext, 'source dead evidence changed');
    oidcRecoveryPgAssert($successor !== null && (string) $successor->state === 'pending' && (int) $successor->attempt_count === 0 && (int) $successor->status === 1, 'fresh successor is not pending');
    $envelope = json_decode($cipher->decrypt((string) $successor->encrypted_logout_token), true, 8, JSON_THROW_ON_ERROR);
    $jwt = oidcRecoveryPgJwt((string) ($envelope['logout_token'] ?? ''));
    oidcRecoveryPgAssert(($envelope['target_uri'] ?? '') === 'https://rp.example.test/backchannel-logout', 'successor did not use the current registered destination');
    oidcRecoveryPgAssert(($jwt['header']['typ'] ?? '') === 'logout+jwt'
        && ($jwt['claims']['aud'] ?? '') === (string) $client->code
        && ($jwt['claims']['sid'] ?? '') === (string) $revokedSession->id
        && ($jwt['claims']['jti'] ?? '') === (string) $successor->event_id
        && (int) ($jwt['claims']['exp'] ?? 0) > time() + 9000,
        'successor token is stale or incorrectly bound');

    $sameRequest = $service->reissueBackchannelLogout((int) $client->id, (int) $application->id, (int) $source->id, 'oidc-recovery-admin', $requestId);
    $otherRequest = $service->reissueBackchannelLogout((int) $client->id, (int) $application->id, (int) $source->id, 'oidc-recovery-admin', $requestId . '-other');
    oidcRecoveryPgAssert($sameRequest['delivery_id'] === (int) $successor->id && $otherRequest['delivery_id'] === (int) $successor->id && $otherRequest['already_reissued'] === true, 'recovery replay created a different successor');
    oidcRecoveryPgAssert(OidcLogoutDelivery::where('event_id', (string) $successor->event_id)->count() === 1, 'recovery successor is not unique');
    oidcRecoveryPgExpect(static fn () => $service->reissueBackchannelLogout((int) $client->id, (int) $application->id, (int) $successor->id, 'oidc-recovery-admin', $requestId . '-pending'), 'SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_RECOVERABLE');
    oidcRecoveryPgExpect(static fn () => $service->reissueBackchannelLogout((int) $otherClient->id, (int) $application->id, (int) $source->id, 'oidc-recovery-admin', $requestId . '-cross-client'), 'SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_FOUND');
    oidcRecoveryPgExpect(static fn () => $service->reissueBackchannelLogout((int) $client->id, (int) $application->id, (int) $activeSource->id, 'oidc-recovery-admin', $requestId . '-active-session'), 'SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED');
    $client->save(['status' => 2]);
    oidcRecoveryPgExpect(static fn () => $service->reissueBackchannelLogout((int) $client->id, (int) $application->id, (int) $source->id, 'oidc-recovery-admin', $requestId . '-disabled-client'), 'SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE');

    $audit = json_encode(AuditLog::where('application_id', (int) $application->id)->where('action', 'oidc.backchannel_logout_reissue')->select()->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    oidcRecoveryPgAssert(substr_count($audit, 'oidc.backchannel_logout_reissue') === 1, 'recovery audit is missing or duplicated');
    foreach ([$sourceCiphertext, (string) ($envelope['logout_token'] ?? ''), $encryptionKey, $privatePem] as $secret) {
        oidcRecoveryPgAssert(!str_contains($audit, $secret), 'recovery audit leaked token, ciphertext or key material');
    }
    echo "OIDC logout recovery PostgreSQL integration passed\n";
} finally {
    Db::rollback();
}

oidcRecoveryPgAssert(Organization::where('code', $organizationCode)->count() === 0, 'fixture transaction did not roll back completely');
