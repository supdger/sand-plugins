<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\oidc\NativeOidcBackchannelHttpAdapter;
use plugin\SandIam\app\service\OAuthOidcService;
use plugin\SandIam\app\service\OidcLogoutTokenCipher;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t11BackFail(string $message): never { fwrite(STDERR, "IAM-T11 back-channel logout test failed: {$message}\n"); exit(1); }
function t11BackExpect(callable $callback, string $code): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t11BackFail("expected {$code}"); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11BackFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';

$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_OIDC_ISSUER=https://iam.example.test/api/sand-iam/v1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY=' . $keyV1);
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION=v1');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS={}');
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$cipher = new OidcLogoutTokenCipher();
$plain = 'header.payload.signature';
$encrypted = $cipher->encrypt($plain);
if (!str_starts_with($encrypted, 'v1.') || str_contains($encrypted, $plain) || $cipher->decrypt($encrypted) !== $plain) t11BackFail('v1 round trip failed');
t11BackExpect(static fn () => $cipher->decrypt('v1.invalid*base64'), 'SAND_IAM_OIDC_LOGOUT_TOKEN_UNAVAILABLE');

$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY=' . $keyV2);
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION=v2');
putenv('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$rotated = new OidcLogoutTokenCipher();
if ($rotated->decrypt($encrypted) !== $plain || !str_starts_with($rotated->encrypt($plain), 'v2.')) t11BackFail('key rotation failed');

$claimsMethod = new ReflectionMethod(OAuthOidcService::class, 'backchannelLogoutClaims'); $claimsMethod->setAccessible(true);
$client = new OAuthClient(); $client->code = 'rp-client';
$oauth = new OAuthOidcService();
$claims = $claimsMethod->invoke($oauth, $client, 'session-100', 'bcl_1234567890abcdef', 1_777_777_777);
if (($claims['aud'] ?? '') !== 'rp-client' || ($claims['sid'] ?? '') !== 'session-100' || isset($claims['nonce']) || !isset($claims['events']['http://schemas.openid.net/event/backchannel-logout'])) t11BackFail('logout token claims invalid');

$recoveryIdMethod = new ReflectionMethod(OAuthOidcService::class, 'backchannelRecoveryEventId'); $recoveryIdMethod->setAccessible(true);
$recoveryId = (string) $recoveryIdMethod->invoke($oauth, 'bcl_1234567890abcdef');
$sameRecoveryId = (string) $recoveryIdMethod->invoke($oauth, 'bcl_1234567890abcdef');
$nextRecoveryId = (string) $recoveryIdMethod->invoke($oauth, $recoveryId);
if (!preg_match('/^bcl_r_[a-f0-9]{32}$/', $recoveryId) || !hash_equals($recoveryId, $sameRecoveryId) || hash_equals($recoveryId, $nextRecoveryId)) t11BackFail('recovery event ids are not deterministic, bounded and chainable');

$serviceSource = (string) file_get_contents($root . '/plugin/sand-iam/app/service/OAuthOidcService.php');
$controllerSource = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/OAuthClientController.php');
$routeSource = (string) file_get_contents($root . '/plugin/sand-iam/config/route.php');
foreach ([
    'function reissueBackchannelLogout(',
    "(string) \$source->state !== 'dead'",
    "(int) \$source->status !== 2",
    "whereNotNull('revoked_time')",
    "'source_event_digest' => hash('sha256'",
    "'successor_event_digest' => hash('sha256'",
    "'state' => 'pending'",
    "'attempt_count' => 0",
] as $fragment) if (!str_contains($serviceSource, $fragment)) t11BackFail("recovery service omits {$fragment}");
foreach (['function logoutDeliveries(', 'function reissueLogoutDelivery(', "'sand_iam:oauth_client:read'", "'sand_iam:oauth_client:update'", 'logoutDeliveryPayload'] as $fragment) if (!str_contains($controllerSource, $fragment)) t11BackFail("recovery controller omits {$fragment}");
foreach (['/oauth-client/logout-delivery/index', '/oauth-client/logout-delivery/reissue'] as $fragment) if (!str_contains($routeSource, $fragment)) t11BackFail("recovery route omits {$fragment}");
if (str_contains($controllerSource, "'encrypted_logout_token' =>")) t11BackFail('recovery DTO exposes encrypted logout token');

$destination = new ReflectionMethod(NativeOidcBackchannelHttpAdapter::class, 'destination'); $destination->setAccessible(true);
t11BackExpect(static fn () => $destination->invoke(new NativeOidcBackchannelHttpAdapter(), 'http://rp.example.test/logout'), 'SAND_IAM_OIDC_BACKCHANNEL_URI_INVALID');
t11BackExpect(static fn () => $destination->invoke(new NativeOidcBackchannelHttpAdapter(), 'https://127.0.0.1/logout'), 'SAND_IAM_OIDC_BACKCHANNEL_DESTINATION_REJECTED');

echo "OIDC back-channel logout non-PG tests passed\n";
