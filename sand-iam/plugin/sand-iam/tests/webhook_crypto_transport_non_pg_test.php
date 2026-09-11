<?php

declare(strict_types=1);

use plugin\SandIam\app\service\WebhookSecretCipher;
use plugin\SandIam\app\webhook\NativeWebhookHttpAdapter;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t06CryptoFail(string $message): never
{
    fwrite(STDERR, "IAM-T06 webhook crypto/transport test failed: {$message}\n");
    exit(1);
}

function t06CryptoExpect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $expected)) return;
        t06CryptoFail("expected {$expected}, received {$exception->getMessage()}");
    }
    t06CryptoFail("expected {$expected}, but no exception was thrown");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t06CryptoFail("SandAdmin host dependencies are unavailable at {$hostRoot}");
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY=' . $keyV1);
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION=v1');
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEYS={}');
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$cipher = new WebhookSecretCipher();
$secret = 'siwh_t06_runtime_secret';
$encryptedV1 = $cipher->encrypt($secret);
if (!str_starts_with($encryptedV1, 'v1.') || str_contains($encryptedV1, $secret) || $cipher->decrypt($encryptedV1) !== $secret) {
    t06CryptoFail('v1 secretbox round trip or ciphertext format is invalid');
}
t06CryptoExpect(static fn () => $cipher->decrypt('v1.invalid*base64'), 'SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE');

$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY=' . $keyV2);
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION=v2');
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$rotatedCipher = new WebhookSecretCipher();
if ($rotatedCipher->decrypt($encryptedV1) !== $secret || !str_starts_with($rotatedCipher->encrypt($secret), 'v2.')) {
    t06CryptoFail('keyring did not preserve old decrypt while moving new encryption to v2');
}

$destination = (new ReflectionClass(NativeWebhookHttpAdapter::class))->getMethod('destination');
$adapter = new NativeWebhookHttpAdapter();
foreach (['http://example.test/hook', 'https://user@example.test/hook', 'https://example.test/hook#fragment'] as $invalidUrl) {
    t06CryptoExpect(static fn () => $destination->invoke($adapter, $invalidUrl), 'SAND_IAM_WEBHOOK_URL_INVALID');
}
foreach (['https://127.0.0.1/hook', 'https://localhost/hook'] as $privateUrl) {
    t06CryptoExpect(static fn () => $destination->invoke($adapter, $privateUrl), 'SAND_IAM_WEBHOOK_DESTINATION_REJECTED');
}

echo "webhook crypto and transport non-PG tests passed\n";
