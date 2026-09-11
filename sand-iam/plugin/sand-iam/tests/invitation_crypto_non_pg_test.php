<?php

declare(strict_types=1);

use plugin\SandIam\app\service\InvitationSecretCipher;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t10InvitationFail(string $message): never { fwrite(STDERR, "IAM-T10 invitation crypto test failed: {$message}\n"); exit(1); }
function t10InvitationExpect(callable $callback, string $code): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t10InvitationFail("expected {$code}"); }
$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server'; $sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t10InvitationFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); putenv('SAND_IAM_INVITATION_ENCRYPTION_KEY=' . $keyV1); putenv('SAND_IAM_INVITATION_ENCRYPTION_KEY_VERSION=v1'); putenv('SAND_IAM_INVITATION_ENCRYPTION_KEYS={}');
Config::clear(); Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$cipher = new InvitationSecretCipher(); $plain = 'siam_inv_test-secret'; $v1 = $cipher->encrypt($plain);
if (!str_starts_with($v1, 'v1.') || str_contains($v1, $plain) || $cipher->decrypt($v1) !== $plain) t10InvitationFail('v1 round trip failed');
t10InvitationExpect(static fn () => $cipher->decrypt('v1.invalid*base64'), 'SAND_IAM_INVITATION_SECRET_UNAVAILABLE');
$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); putenv('SAND_IAM_INVITATION_ENCRYPTION_KEY=' . $keyV2); putenv('SAND_IAM_INVITATION_ENCRYPTION_KEY_VERSION=v2'); putenv('SAND_IAM_INVITATION_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear(); Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); $v2Cipher = new InvitationSecretCipher();
if ($v2Cipher->decrypt($v1) !== $plain || !str_starts_with($v2Cipher->encrypt($plain), 'v2.')) t10InvitationFail('key rotation compatibility failed');
echo "invitation crypto non-PG tests passed\n";
