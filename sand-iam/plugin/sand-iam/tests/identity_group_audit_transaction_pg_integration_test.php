<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\SyncOutbox;
use plugin\SandIam\app\model\WebhookDelivery;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\IdentityGroupService;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;
use think\facade\Db;

if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') {
    fwrite(STDOUT, "SKIP: set SAND_IAM_RUN_PG_TESTS=1 to allow PostgreSQL writes\n");
    exit(0);
}

function identityGroupAuditPgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function identityGroupAuditPgExpectFault(callable $operation, string $action): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        identityGroupAuditPgAssert($exception->getMessage() === "forced audit failure for {$action}", 'unexpected audit fault');
        return;
    }
    throw new RuntimeException("{$action} did not fail at AuditLog.BeforeInsert");
}

final class IdentityGroupAuditFaultEvent
{
    public string $action = '';
    public int $endpointId = 0;
    public int $hits = 0;
    public ?int $deliveriesAtFault = null;

    public function arm(string $action, int $endpointId = 0): void
    {
        $this->action = $action;
        $this->endpointId = $endpointId;
        $this->hits = 0;
        $this->deliveriesAtFault = null;
    }

    /** @return list<mixed> */
    public function trigger(string $event, mixed $model): array
    {
        if ($event !== AuditLog::class . '.BeforeInsert'
            || !$model instanceof AuditLog
            || (string) $model->action !== $this->action) {
            return [];
        }
        ++$this->hits;
        if ($this->endpointId > 0) {
            $this->deliveriesAtFault = WebhookDelivery::where('webhook_endpoint_id', $this->endpointId)->count();
        }
        throw new RuntimeException("forced audit failure for {$this->action}");
    }
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
identityGroupAuditPgAssert(is_file($hostRoot . '/vendor/autoload.php'), 'SandAdmin dependencies are unavailable');

$previousOutbox = getenv('SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED');
putenv('SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED=1');
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);
identityGroupAuditPgAssert(
    (string) (Db::query('SELECT current_database() AS name')[0]['name'] ?? '') === 'sandadmin',
    'refusing PostgreSQL writes outside the expected sandadmin database',
);

identityGroupAuditPgAssert(
    (new ReflectionClass(IdentityGroupService::class))->getFileName() === realpath($sandIamRoot . '/plugin/sand-iam/app/service/IdentityGroupService.php'),
    'IdentityGroupService did not load from the authoritative plugin source',
);
identityGroupAuditPgAssert(
    (new ReflectionClass(AuditWriter::class))->getFileName() === realpath($sandIamRoot . '/plugin/sand-iam/app/service/AuditWriter.php'),
    'AuditWriter did not load from the authoritative plugin source',
);

$eventProperty = new ReflectionProperty(\think\Model::class, 'event');
$eventProperty->setAccessible(true);
$previousEvent = $eventProperty->getValue();
$suffix = bin2hex(random_bytes(8));
$prefix = "igpg-{$suffix}";
$createCode = "{$prefix}-created";
$ids = ['organization' => 0, 'application' => 0, 'identity' => 0, 'group' => 0, 'endpoint' => 0];
$fault = new IdentityGroupAuditFaultEvent();

try {
    $organization = Organization::create(['code' => "{$prefix}-org", 'name' => 'PG 审计回滚组织', 'status' => 1]);
    $ids['organization'] = (int) $organization->id;
    $application = Application::create(['organization_id' => $ids['organization'], 'code' => "{$prefix}-app", 'name' => 'PG 审计回滚应用', 'status' => 1]);
    $ids['application'] = (int) $application->id;
    $identity = Identity::create(['application_id' => $ids['application'], 'code' => "{$prefix}-identity", 'display_name' => 'PG 审计回滚身份', 'lifecycle_state' => 'active', 'status' => 1]);
    $ids['identity'] = (int) $identity->id;
    $group = IdentityGroup::create(['application_id' => $ids['application'], 'code' => "{$prefix}-group", 'name' => '原始用户组', 'description' => 'before', 'depth' => 1, 'status' => 1]);
    $ids['group'] = (int) $group->id;
    $endpoint = WebhookEndpoint::create([
        'application_id' => $ids['application'], 'code' => "{$prefix}-endpoint", 'name' => '仅本测试事件端点',
        'url' => 'https://example.test/identity-event', 'encrypted_secret' => 'test-only', 'secret_version' => 1,
        'event_types' => ['identity.updated'], 'timeout_seconds' => 5, 'max_attempts' => 1, 'status' => 1,
    ]);
    $ids['endpoint'] = (int) $endpoint->id;
    identityGroupAuditPgAssert(SyncOutbox::where('application_id', $ids['application'])->count() === 0, 'dedicated application unexpectedly has sync connectors');

    \think\Model::setEvent($fault);
    $service = new IdentityGroupService();

    $fault->arm('identity_group.create');
    identityGroupAuditPgExpectFault(fn () => $service->create($ids['application'], $createCode, '新增组', null, '', 'pg-test', "{$prefix}-create"), 'identity_group.create');
    identityGroupAuditPgAssert($fault->hits === 1, 'create audit hook was not hit exactly once');
    identityGroupAuditPgAssert(IdentityGroup::where('application_id', $ids['application'])->where('code', $createCode)->count() === 0, 'create business row survived audit failure');

    $fault->arm('identity_group.update');
    identityGroupAuditPgExpectFault(fn () => $service->update($ids['group'], $ids['application'], '被拒绝更新', null, 'changed', 2, 'pg-test', "{$prefix}-update"), 'identity_group.update');
    $afterUpdate = IdentityGroup::find($ids['group']);
    identityGroupAuditPgAssert($fault->hits === 1 && (string) $afterUpdate?->name === '原始用户组' && (string) $afterUpdate?->description === 'before' && (int) $afterUpdate?->status === 1, 'update business row survived audit failure');

    $fault->arm('identity_group.member_add', $ids['endpoint']);
    identityGroupAuditPgExpectFault(fn () => $service->addMember($ids['group'], $ids['identity'], $ids['application'], 'pg-test', "{$prefix}-add"), 'identity_group.member_add');
    identityGroupAuditPgAssert($fault->hits === 1 && $fault->deliveriesAtFault === 1, 'add did not create exactly one in-transaction delivery before its audit fault');
    identityGroupAuditPgAssert(IdentityGroupMember::where('identity_group_id', $ids['group'])->where('identity_id', $ids['identity'])->count() === 0, 'add membership survived audit failure');
    identityGroupAuditPgAssert(WebhookDelivery::where('webhook_endpoint_id', $ids['endpoint'])->count() === 0, 'add event delivery survived audit failure');

    IdentityGroupMember::create(['identity_group_id' => $ids['group'], 'application_id' => $ids['application'], 'identity_id' => $ids['identity'], 'status' => 1]);
    $fault->arm('identity_group.member_remove', $ids['endpoint']);
    identityGroupAuditPgExpectFault(fn () => $service->removeMember($ids['group'], $ids['identity'], $ids['application'], 'pg-test', "{$prefix}-remove"), 'identity_group.member_remove');
    $member = IdentityGroupMember::where('identity_group_id', $ids['group'])->where('identity_id', $ids['identity'])->find();
    identityGroupAuditPgAssert($fault->hits === 1 && $fault->deliveriesAtFault === 1 && (int) $member?->status === 1, 'remove membership or pre-audit event survived audit failure');
    identityGroupAuditPgAssert(WebhookDelivery::where('webhook_endpoint_id', $ids['endpoint'])->count() === 0, 'remove event delivery survived audit failure');
    identityGroupAuditPgAssert(AuditLog::where('request_id', 'like', "{$prefix}-%")->count() === 0, 'failed operations left audit rows');
    identityGroupAuditPgAssert(SyncOutbox::where('application_id', $ids['application'])->count() === 0, 'failed operations created a sync outbox row');
} finally {
    \think\Model::setEvent($previousEvent);
    Db::startTrans();
    try {
        Db::table('sand_iam_audit_log')->where('request_id', 'like', "{$prefix}-%")->delete();
        if ($ids['endpoint'] > 0) Db::table('sand_iam_webhook_delivery')->where('webhook_endpoint_id', $ids['endpoint'])->delete();
        if ($ids['application'] > 0 && $ids['group'] > 0 && $ids['identity'] > 0) Db::table('sand_iam_identity_group_member')->where('application_id', $ids['application'])->where('identity_group_id', $ids['group'])->where('identity_id', $ids['identity'])->delete();
        if ($ids['application'] > 0) Db::table('sand_iam_identity_group')->where('application_id', $ids['application'])->where('code', $createCode)->delete();
        if ($ids['group'] > 0 && $ids['application'] > 0) Db::table('sand_iam_identity_group')->where('id', $ids['group'])->where('application_id', $ids['application'])->delete();
        if ($ids['identity'] > 0 && $ids['application'] > 0) Db::table('sand_iam_identity')->where('id', $ids['identity'])->where('application_id', $ids['application'])->delete();
        if ($ids['endpoint'] > 0 && $ids['application'] > 0) Db::table('sand_iam_webhook_endpoint')->where('id', $ids['endpoint'])->where('application_id', $ids['application'])->delete();
        if ($ids['application'] > 0 && $ids['organization'] > 0) Db::table('sand_iam_application')->where('id', $ids['application'])->where('organization_id', $ids['organization'])->delete();
        if ($ids['organization'] > 0) Db::table('sand_iam_organization')->where('id', $ids['organization'])->delete();
        identityGroupAuditPgAssert(Db::table('sand_iam_audit_log')->where('request_id', 'like', "{$prefix}-%")->count() === 0, 'cleanup left owned audit rows');
        if ($ids['endpoint'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_webhook_delivery')->where('webhook_endpoint_id', $ids['endpoint'])->count() === 0, 'cleanup left owned deliveries');
        if ($ids['application'] > 0 && $ids['group'] > 0 && $ids['identity'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_identity_group_member')->where('application_id', $ids['application'])->where('identity_group_id', $ids['group'])->where('identity_id', $ids['identity'])->count() === 0, 'cleanup left owned memberships');
        if ($ids['application'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_identity_group')->where('application_id', $ids['application'])->where('code', $createCode)->count() === 0, 'cleanup left owned created group');
        if ($ids['group'] > 0 && $ids['application'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_identity_group')->where('id', $ids['group'])->where('application_id', $ids['application'])->count() === 0, 'cleanup left owned groups');
        if ($ids['identity'] > 0 && $ids['application'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_identity')->where('id', $ids['identity'])->where('application_id', $ids['application'])->count() === 0, 'cleanup left owned identities');
        if ($ids['endpoint'] > 0 && $ids['application'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_webhook_endpoint')->where('id', $ids['endpoint'])->where('application_id', $ids['application'])->count() === 0, 'cleanup left owned endpoints');
        if ($ids['application'] > 0 && $ids['organization'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_application')->where('id', $ids['application'])->where('organization_id', $ids['organization'])->count() === 0, 'cleanup left owned application');
        if ($ids['organization'] > 0) identityGroupAuditPgAssert(Db::table('sand_iam_organization')->where('id', $ids['organization'])->count() === 0, 'cleanup left owned organization');
        Db::commit();
    } catch (\Throwable $cleanupError) {
        Db::rollback();
        throw $cleanupError;
    }
    $previousOutbox === false ? putenv('SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED') : putenv("SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED={$previousOutbox}");
}

fwrite(STDOUT, "IdentityGroupService audit-failure PostgreSQL rollback test passed\n");
