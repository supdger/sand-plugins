<?php

declare(strict_types=1);

use plugin\SandIam\app\service\MessageProviderConfigCipher;
use plugin\SandIam\app\service\MessageProviderService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t09CryptoFail(string $message): never { fwrite(STDERR, "IAM-T09 message crypto test failed: {$message}\n"); exit(1); }
function t09CryptoExpect(callable $callback, string $expected): void
{
    try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $expected)) return; t09CryptoFail("expected {$expected}, received {$exception->getMessage()}"); }
    t09CryptoFail("expected {$expected}, but no exception was thrown");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t09CryptoFail("SandAdmin host dependencies are unavailable at {$hostRoot}");
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_MESSAGE_ENCRYPTION_KEY=' . $keyV1);
putenv('SAND_IAM_MESSAGE_ENCRYPTION_KEY_VERSION=v1');
putenv('SAND_IAM_MESSAGE_ENCRYPTION_KEYS={}');
putenv('SAND_IAM_MESSAGE_PROVIDER_ENABLED=0');
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$cipher = new MessageProviderConfigCipher();
$sent = (new MessageProviderService())->sendCode(1, 'email', 'nobody@example.test', '12345678', []);
if ($sent !== false) t09CryptoFail('disabled message provider must preserve the legacy sender path without querying provider tables');
$config = ['access_key_id' => 'test-id', 'access_key_secret' => 'test-secret', 'region' => 'cn-test'];
$encryptedV1 = $cipher->encrypt($config);
if (!str_starts_with($encryptedV1, 'v1.') || str_contains($encryptedV1, 'test-secret') || $cipher->decrypt($encryptedV1) !== $config) t09CryptoFail('v1 config round trip or ciphertext format is invalid');
t09CryptoExpect(static fn () => $cipher->decrypt('v1.invalid*base64'), 'SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE');

$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_MESSAGE_ENCRYPTION_KEY=' . $keyV2);
putenv('SAND_IAM_MESSAGE_ENCRYPTION_KEY_VERSION=v2');
putenv('SAND_IAM_MESSAGE_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$rotatedCipher = new MessageProviderConfigCipher();
if ($rotatedCipher->decrypt($encryptedV1) !== $config || !str_starts_with($rotatedCipher->encrypt($config), 'v2.')) t09CryptoFail('keyring did not preserve old decrypt while moving new encryption to v2');

echo "message provider crypto non-PG tests passed\n";
