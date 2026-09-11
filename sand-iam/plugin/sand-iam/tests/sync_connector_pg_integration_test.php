<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\SyncConnector;
use plugin\SandIam\app\model\SyncOutbox;
use plugin\SandIam\app\model\SyncResource;
use plugin\SandIam\app\model\SyncRun;
use plugin\SandIam\app\service\SyncConnectorService;
use plugin\SandIam\app\sync\SyncDriverInterface;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function syncPgFail(string $message): never
{
    fwrite(STDERR, "IAM-T10 Syncer PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function syncPgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        syncPgFail($message);
    }
}

function syncPgExpect(callable $callback, string $error, int $status): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getCode() === $status && str_contains($exception->getMessage(), $error)) {
            return;
        }
        syncPgFail("expected {$error}/{$status}, received {$exception->getMessage()}/{$exception->getCode()}");
    }
    syncPgFail("expected {$error}, but no exception was thrown");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    syncPgFail('SandAdmin dependencies are unavailable');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

final class T10AcceptanceSyncDriver implements SyncDriverInterface
{
    public static int $generation = 1;
    /** @var list<array<string,mixed>> */
    public static array $pushed = [];
    public static bool $tested = false;

    public static function capabilities(): array
    {
        return ['inbound' => true, 'outbound' => true];
    }

    public static function pullPage(array $config, ?string $cursor, int $limit): array
    {
        if (($config['tenant'] ?? null) !== 't10-sync-tenant' || $cursor !== null || $limit !== 500) {
            throw new ApiException('SAND_IAM_SYNC_DRIVER_REQUEST_INVALID', 400);
        }
        $records = [[
            'source_id' => 'source-user-1',
            'version' => self::$generation === 1 ? 'v1' : (self::$generation === 2 ? 'v2' : 'v3'),
            'display_name' => self::$generation === 1 ? '来源用户一' : (self::$generation === 2 ? '来源用户一（更新）' : '来源用户一（冲突）'),
            'deleted' => false,
            'attributes' => ['group_codes' => ['external-legal']],
        ]];
        if (self::$generation === 1) {
            $records[] = [
                'source_id' => 'source-user-2',
                'version' => 'v1',
                'display_name' => '来源用户二',
                'deleted' => false,
                'attributes' => ['group_codes' => []],
            ];
        }
        return ['records' => $records, 'next_cursor' => null, 'has_more' => false, 'full_snapshot' => true];
    }

    public static function pushBatch(array $config, array $events): array
    {
        self::$pushed = array_merge(self::$pushed, $events);
        return array_values(array_map(static fn (array $event): string => (string) $event['event_id'], $events));
    }

    public static function test(array $config): void
    {
        self::$tested = ($config['tenant'] ?? null) === 't10-sync-tenant';
        if (!self::$tested) {
            throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE', 503);
        }
    }
}

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organizationA = Organization::create(['code' => 't10-sync-org-a', 'name' => 'T10 同步组织 A', 'status' => 1]);
$organizationB = Organization::create(['code' => 't10-sync-org-b', 'name' => 'T10 同步组织 B', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organizationA->id, 'code' => 't10-sync-app-a', 'name' => 'T10 同步应用 A', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organizationB->id, 'code' => 't10-sync-app-b', 'name' => 'T10 同步应用 B', 'status' => 1]);
$group = IdentityGroup::create(['application_id' => (int) $applicationA->id, 'code' => 'legal-team', 'name' => '法务组', 'depth' => 1, 'status' => 1]);
$connector = SyncConnector::create([
    'organization_id' => (int) $organizationA->id,
    'application_id' => (int) $applicationA->id,
    'code' => 't10-bidirectional',
    'name' => '双向同步验收',
    'direction' => 'bidirectional',
    'driver_code' => 't10_acceptance',
    'config_version' => 0,
    'authority_map' => [],
    'conflict_policy' => 'reject',
    'missing_protection_hours' => 1,
    'disable_threshold_percent' => 60,
    'status' => 1,
]);

$sync = new SyncConnectorService();
$sync->configure($connector, ['tenant' => 't10-sync-tenant', 'group_map' => ['external-legal' => 'legal-team']], ['display_name' => 'source', 'group' => 'source'], '1', 't10-sync-configure');
$connector = SyncConnector::find((int) $connector->id);
syncPgAssert($connector !== null && (int) $connector->config_version === 1 && (string) $connector->encrypted_config !== '', 'connector configuration was not encrypted and versioned');
syncPgAssert(!str_contains((string) $connector->encrypted_config, 't10-sync-tenant'), 'connector configuration leaked plaintext');
syncPgAssert(!array_key_exists('encrypted_config', $connector->toArray()), 'connector serialization exposed encrypted config');
$sync->test($connector, '1', 't10-sync-test');
syncPgAssert(T10AcceptanceSyncDriver::$tested, 'deployment sync driver did not receive decrypted configuration');

$firstRun = $sync->run((int) $connector->id, (int) $applicationA->id, '1', 't10-sync-run-1');
syncPgAssert($firstRun['state'] === 'succeeded' && $firstRun['pulled'] === 2 && $firstRun['created'] === 2, 'initial full pull did not create two application identities');
$resources = SyncResource::where('sync_connector_id', (int) $connector->id)->order('id', 'asc')->select()->all();
syncPgAssert(count($resources) === 2, 'initial pull did not create two stable source mappings');
$identityOne = Identity::find((int) $resources[0]->identity_id);
$identityTwo = Identity::find((int) $resources[1]->identity_id);
syncPgAssert($identityOne !== null && $identityTwo !== null && (int) $identityOne->application_id === (int) $applicationA->id && (int) $identityTwo->application_id === (int) $applicationA->id, 'synchronized identities escaped the owning application');
syncPgAssert(IdentityGroupMember::where('identity_group_id', (int) $group->id)->where('identity_id', (int) $identityOne->id)->where('status', 1)->count() === 1, 'source group mapping was not applied');

$sync->enqueueOutbound((int) $connector->id, (int) $applicationA->id, (int) $identityOne->id, 'update', ['identity_id' => (int) $identityOne->id, 'display_name' => '本地更新'], 'sync-event-0001');
T10AcceptanceSyncDriver::$generation = 2;
$secondRun = $sync->run((int) $connector->id, (int) $applicationA->id, '1', 't10-sync-run-2');
syncPgAssert($secondRun['state'] === 'succeeded' && $secondRun['pulled'] === 1 && $secondRun['updated'] === 1 && $secondRun['pushed'] === 1, 'incremental pull and outbound push did not complete together');
$identityOne = Identity::find((int) $identityOne->id);
syncPgAssert($identityOne !== null && (string) $identityOne->display_name === '来源用户一（更新）', 'source-authoritative display name was not updated');
syncPgAssert((string) SyncOutbox::where('event_id', 'sync-event-0001')->value('state') === 'succeeded' && count(T10AcceptanceSyncDriver::$pushed) === 1, 'outbound event was not delivered exactly once');
$resourceTwo = SyncResource::where('identity_id', (int) $identityTwo->id)->find();
syncPgAssert($resourceTwo !== null && (string) $resourceTwo->source_state === 'missing' && $resourceTwo->missing_since !== null, 'full snapshot omission did not enter protected missing state');
$resourceTwo->save(['missing_since' => date('Y-m-d H:i:s', time() - 7200)]);

$thirdRun = $sync->run((int) $connector->id, (int) $applicationA->id, '1', 't10-sync-run-3');
$identityTwo = Identity::find((int) $identityTwo->id);
$resourceTwo = SyncResource::find((int) $resourceTwo->id);
syncPgAssert($thirdRun['disabled'] === 1 && $identityTwo !== null && (string) $identityTwo->lifecycle_state === 'disabled' && $resourceTwo !== null && (string) $resourceTwo->source_state === 'disabled', 'missing protection did not propagate a bounded disable');

$identityOne->save(['display_name' => '本地人工名称']);
T10AcceptanceSyncDriver::$generation = 3;
syncPgExpect(static fn () => $sync->run((int) $connector->id, (int) $applicationA->id, '1', 't10-sync-run-conflict'), 'SAND_IAM_SYNC_FIELD_CONFLICT', 409);
$failedRun = SyncRun::where('sync_connector_id', (int) $connector->id)->order('id', 'desc')->find();
syncPgAssert($failedRun !== null && (string) $failedRun->state === 'failed' && (string) $failedRun->error_code === 'SAND_IAM_SYNC_FIELD_CONFLICT', 'field conflict did not persist a failed sync run');
syncPgExpect(static fn () => $sync->run((int) $connector->id, (int) $applicationB->id, '1', 't10-sync-cross-app'), 'SAND_IAM_SYNC_CONNECTOR_NOT_FOUND', 404);

$crossOrganizationRejected = false;
try {
    SyncConnector::create([
        'organization_id' => (int) $organizationA->id,
        'application_id' => (int) $applicationB->id,
        'code' => 't10-cross-org',
        'name' => '跨组织错误连接',
        'direction' => 'inbound',
        'driver_code' => 't10_acceptance',
        'authority_map' => [],
        'conflict_policy' => 'manual',
        'missing_protection_hours' => 24,
        'disable_threshold_percent' => 20,
        'status' => 1,
    ]);
} catch (Throwable $exception) {
    $crossOrganizationRejected = str_contains($exception->getMessage(), 'fk_sand_iam_sync_connector_application_organization');
}
syncPgAssert($crossOrganizationRejected, 'database accepted a cross-organization sync connector');
syncPgAssert(AuditLog::where('application_id', (int) $applicationA->id)->where('action', 'sync.run')->where('outcome', 'succeeded')->count() === 3, 'successful sync runs were not audited');
syncPgAssert(AuditLog::where('application_id', (int) $applicationA->id)->where('action', 'sync.run')->where('outcome', 'failed')->count() === 1, 'failed sync run was not audited');

fwrite(STDOUT, "IAM-T10 Syncer PostgreSQL integration passed\n");
