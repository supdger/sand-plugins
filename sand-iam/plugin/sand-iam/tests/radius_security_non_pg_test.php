<?php

declare(strict_types=1);

use plugin\SandIam\app\radius\RadiusNetwork;
use plugin\SandIam\app\service\RadiusSecretCipher;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t11RadiusSecurityFail(string $message): never { fwrite(STDERR, "IAM-T11 RADIUS security test failed: {$message}\n"); exit(1); }
function t11RadiusSecurityExpect(callable $callback, string $code): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t11RadiusSecurityFail("expected {$code}"); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11RadiusSecurityFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';

foreach (['10.20.0.0/24', '10.20.30.40/32', '2001:db8::/32', '2001:db8::1/128'] as $cidr) if (!RadiusNetwork::validCidr($cidr)) t11RadiusSecurityFail("valid CIDR rejected: {$cidr}");
foreach (['10.20.0.1/24', '10.20.0.0/33', '2001:db8::1/64', 'not-an-ip/24'] as $cidr) if (RadiusNetwork::validCidr($cidr)) t11RadiusSecurityFail("non-canonical CIDR accepted: {$cidr}");
if (!RadiusNetwork::contains('10.20.0.0/24', '10.20.0.99') || RadiusNetwork::contains('10.20.0.0/24', '10.21.0.1')) t11RadiusSecurityFail('IPv4 CIDR match failed');
if (!RadiusNetwork::contains('2001:db8::/32', '2001:db8::100') || RadiusNetwork::contains('2001:db8::/32', '2001:db9::1')) t11RadiusSecurityFail('IPv6 CIDR match failed');

$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_RADIUS_ENCRYPTION_KEY=' . $keyV1);
putenv('SAND_IAM_RADIUS_ENCRYPTION_KEY_VERSION=v1');
putenv('SAND_IAM_RADIUS_ENCRYPTION_KEYS={}');
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$cipher = new RadiusSecretCipher();
$secret = 'radius-shared-secret-v1';
$encrypted = $cipher->encrypt($secret);
if (!str_starts_with($encrypted, 'v1.') || str_contains($encrypted, $secret) || $cipher->decrypt($encrypted) !== $secret) t11RadiusSecurityFail('shared-secret cipher round trip failed');
t11RadiusSecurityExpect(static fn () => $cipher->encrypt('short'), 'SAND_IAM_RADIUS_SHARED_SECRET_INVALID');

$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_RADIUS_ENCRYPTION_KEY=' . $keyV2);
putenv('SAND_IAM_RADIUS_ENCRYPTION_KEY_VERSION=v2');
putenv('SAND_IAM_RADIUS_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$rotated = new RadiusSecretCipher();
if ($rotated->decrypt($encrypted) !== $secret || !str_starts_with($rotated->encrypt($secret), 'v2.')) t11RadiusSecurityFail('shared-secret key rotation failed');

echo "RADIUS security non-PG tests passed\n";
