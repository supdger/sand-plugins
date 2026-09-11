<?php

declare(strict_types=1);

use plugin\SandIam\app\service\SyncConnectorService;
use plugin\SandIam\app\service\SyncSecretCipher;
use plugin\SandIam\app\sync\SyncDriverInterface;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

require_once dirname(__DIR__) . '/app/sync/SyncDriverInterface.php';

final class T10FakeSyncDriver implements SyncDriverInterface
{
    /** @return array{inbound:bool,outbound:bool} */
    public static function capabilities(): array
    {
        return ['inbound' => true, 'outbound' => true];
    }

    /** @param array<string,mixed> $config @return array{records:list<array<string,mixed>>,next_cursor:?string,has_more:bool,full_snapshot:bool} */
    public static function pullPage(array $config, ?string $cursor, int $limit): array
    {
        if ($limit !== 500 || ($config['endpoint'] ?? '') !== 'memory://sync') {
            throw new RuntimeException('invalid driver input');
        }
        return [
            'records' => [['source_id' => 'user-1', 'version' => '1', 'display_name' => '测试用户', 'deleted' => false]],
            'next_cursor' => null,
            'has_more' => false,
            'full_snapshot' => true,
        ];
    }

    /** @param array<string,mixed> $config @param list<array<string,mixed>> $events @return list<string> */
    public static function pushBatch(array $config, array $events): array
    {
        return array_values(array_map(static fn (array $event): string => (string) $event['event_id'], $events));
    }

    /** @param array<string,mixed> $config */
    public static function test(array $config): void
    {
        if (($config['endpoint'] ?? '') !== 'memory://sync') {
            throw new RuntimeException('connection failed');
        }
    }
}

function t10SyncFail(string $message): never
{
    fwrite(STDERR, "IAM-T10 sync crypto/driver test failed: {$message}\n");
    exit(1);
}

function t10SyncExpect(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $code)) {
            return;
        }
    }
    t10SyncFail("expected {$code}");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    t10SyncFail('SandAdmin host dependencies are unavailable');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_SYNC_ENCRYPTION_KEY=' . $keyV1);
putenv('SAND_IAM_SYNC_ENCRYPTION_KEY_VERSION=v1');
putenv('SAND_IAM_SYNC_ENCRYPTION_KEYS={}');
putenv('SAND_IAM_SYNC_DRIVERS=' . json_encode(['memory' => T10FakeSyncDriver::class], JSON_THROW_ON_ERROR));
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$cipher = new SyncSecretCipher();
$plain = ['endpoint' => 'memory://sync', 'token' => 'sync-secret'];
$encryptedV1 = $cipher->encryptArray($plain);
if (!str_starts_with($encryptedV1, 'v1.') || str_contains($encryptedV1, 'sync-secret') || $cipher->decryptArray($encryptedV1) !== $plain) {
    t10SyncFail('v1 encrypted array round trip failed');
}
t10SyncExpect(static fn () => $cipher->decrypt('v1.invalid*base64'), 'SAND_IAM_SYNC_SECRET_UNAVAILABLE');

$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('SAND_IAM_SYNC_ENCRYPTION_KEY=' . $keyV2);
putenv('SAND_IAM_SYNC_ENCRYPTION_KEY_VERSION=v2');
putenv('SAND_IAM_SYNC_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR));
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$rotatedCipher = new SyncSecretCipher();
if ($rotatedCipher->decryptArray($encryptedV1) !== $plain || !str_starts_with($rotatedCipher->encryptArray($plain), 'v2.')) {
    t10SyncFail('key rotation compatibility failed');
}

$page = T10FakeSyncDriver::pullPage($plain, null, 500);
if (($page['records'][0]['display_name'] ?? '') !== '测试用户' || T10FakeSyncDriver::pushBatch($plain, [['event_id' => 'event-0001']]) !== ['event-0001']) {
    t10SyncFail('driver contract behavior failed');
}
T10FakeSyncDriver::test($plain);

$driverMethod = new ReflectionMethod(SyncConnectorService::class, 'driver');
$driverMethod->setAccessible(true);
$resolved = $driverMethod->invoke(new SyncConnectorService(), 'memory');
if ($resolved !== T10FakeSyncDriver::class) {
    t10SyncFail('driver registry resolution failed');
}

putenv('SAND_IAM_SYNC_DRIVERS=' . json_encode(['invalid' => stdClass::class], JSON_THROW_ON_ERROR));
Config::clear();
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
t10SyncExpect(
    static fn () => $driverMethod->invoke(new SyncConnectorService(), 'invalid'),
    'SAND_IAM_SYNC_DRIVER_UNAVAILABLE',
);

echo "identity sync crypto/driver non-PG tests passed\n";
