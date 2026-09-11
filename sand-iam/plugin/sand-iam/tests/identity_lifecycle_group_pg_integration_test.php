<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\GuestIdentityService;
use plugin\SandIam\app\service\IdentityGroupService;
use plugin\SandIam\app\service\IdentityLifecycleService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function lifecyclePgFail(string $message): never
{
    fwrite(STDERR, "IAM-T10 identity lifecycle PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function lifecyclePgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        lifecyclePgFail($message);
    }
}

function lifecyclePgExpect(callable $callback, string $error): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $error)) {
            return;
        }
        lifecyclePgFail("expected {$error}, received {$exception->getMessage()}");
    }
    lifecyclePgFail("expected {$error}, but no exception was thrown");
}

function lifecyclePgHash(string $value): string
{
    return hash('sha256', $value);
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    lifecyclePgFail('SandAdmin dependencies are unavailable');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organization = Organization::create(['code' => 't10-pg-org', 'name' => 'T10 PostgreSQL 组织', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organization->id, 'code' => 't10-pg-app-a', 'name' => 'T10 应用 A', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organization->id, 'code' => 't10-pg-app-b', 'name' => 'T10 应用 B', 'status' => 1]);
$identity = Identity::create(['application_id' => (int) $applicationA->id, 'code' => 't10-pg-user', 'display_name' => '生命周期用户', 'lifecycle_state' => 'active', 'status' => 1]);

$groups = new IdentityGroupService();
$rootGroupId = $groups->create((int) $applicationA->id, 't10-root', '总部', null, '一级组', '1', 't10-group-root');
$childGroupId = $groups->create((int) $applicationA->id, 't10-child', '法务组', $rootGroupId, '二级组', '1', 't10-group-child');
$otherAppGroupId = $groups->create((int) $applicationB->id, 't10-other', '另一应用组', null, '', '1', 't10-group-other');
$memberId = $groups->addMember($childGroupId, (int) $identity->id, (int) $applicationA->id, '1', 't10-member-add');
lifecyclePgAssert($groups->addMember($childGroupId, (int) $identity->id, (int) $applicationA->id, '1', 't10-member-repeat') === $memberId, 'repeated group add did not remain idempotent');
lifecyclePgAssert(IdentityGroupMember::where('identity_group_id', $childGroupId)->where('identity_id', (int) $identity->id)->count() === 1, 'repeated group add created a duplicate row');
$groups->removeMember($childGroupId, (int) $identity->id, (int) $applicationA->id, '1', 't10-member-remove');
lifecyclePgAssert((int) IdentityGroupMember::find($memberId)?->status === 2, 'group removal did not disable the membership');
lifecyclePgAssert($groups->addMember($childGroupId, (int) $identity->id, (int) $applicationA->id, '1', 't10-member-restore') === $memberId && (int) IdentityGroupMember::find($memberId)?->status === 1, 'group re-add did not restore the original row');
lifecyclePgExpect(static fn () => $groups->update($rootGroupId, (int) $applicationA->id, '总部', $childGroupId, '', 1, '1', 't10-group-cycle'), 'SAND_IAM_IDENTITY_GROUP_CYCLE');
lifecyclePgExpect(static fn () => $groups->addMember($otherAppGroupId, (int) $identity->id, (int) $applicationB->id, '1', 't10-group-cross-app'), 'SAND_IAM_RESOURCE_NOT_FOUND');

$session = AuthSession::create([
    'application_id' => (int) $applicationA->id,
    'identity_id' => (int) $identity->id,
    'auth_method' => 'local_password',
    'access_token_hash' => lifecyclePgHash('t10-access'),
    'refresh_token_hash' => lifecyclePgHash('t10-refresh-family'),
    'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'),
    'access_expire_time' => date('Y-m-d H:i:s', time() + 900),
    'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400),
    'ip_hash' => lifecyclePgHash('t10-ip'),
    'status' => 1,
]);
$refresh = AuthRefreshToken::create([
    'session_id' => (int) $session->id,
    'token_hash' => lifecyclePgHash('t10-refresh-token'),
    'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'),
    'expire_time' => date('Y-m-d H:i:s', time() + 86400),
    'status' => 1,
]);

$lifecycle = new IdentityLifecycleService();
$lifecycle->disable((int) $identity->id, (int) $applicationA->id, '1', 't10-disable');
$identity = Identity::find((int) $identity->id);
lifecyclePgAssert($identity !== null && (string) $identity->lifecycle_state === 'disabled' && (int) $identity->status === 2, 'disable did not persist the identity state');
lifecyclePgAssert((int) AuthSession::find((int) $session->id)?->status === 2 && (int) AuthRefreshToken::find((int) $refresh->id)?->status === 2, 'disable did not revoke the session family');
lifecyclePgAssert((int) IdentityGroupMember::find($memberId)?->status === 1, 'disable incorrectly destroyed application relationships');

$lifecycle->enable((int) $identity->id, (int) $applicationA->id, '1', 't10-enable');
$identity = Identity::find((int) $identity->id);
lifecyclePgAssert($identity !== null && (string) $identity->lifecycle_state === 'active' && (int) $identity->status === 1, 'enable did not restore the active identity state');
$lifecycle->delete((int) $identity->id, (int) $applicationA->id, '1', 't10-delete');
$identity = Identity::find((int) $identity->id);
lifecyclePgAssert($identity !== null && (string) $identity->lifecycle_state === 'deleted' && $identity->deleted_time !== null && $identity->purge_after !== null, 'delete did not establish the recoverable deletion window');
lifecyclePgAssert((int) IdentityGroupMember::find($memberId)?->status === 2, 'delete did not revoke group membership');
lifecyclePgExpect(static fn () => $groups->addMember($childGroupId, (int) $identity->id, (int) $applicationA->id, '1', 't10-deleted-member'), 'SAND_IAM_RESOURCE_NOT_FOUND');

$lifecycle->restore((int) $identity->id, (int) $applicationA->id, '1', 't10-restore');
$identity = Identity::find((int) $identity->id);
lifecyclePgAssert($identity !== null && (string) $identity->lifecycle_state === 'active' && (int) $identity->status === 1 && $identity->deleted_time === null && $identity->purge_after === null, 'restore did not recover the previous identity state');
lifecyclePgAssert((int) IdentityGroupMember::find($memberId)?->status === 2, 'restore silently restored a revoked relationship');
lifecyclePgAssert($groups->addMember($childGroupId, (int) $identity->id, (int) $applicationA->id, '1', 't10-restored-member') === $memberId, 'restored identity did not reuse its original membership row');
lifecyclePgExpect(static fn () => $lifecycle->disable((int) $identity->id, (int) $applicationB->id, '1', 't10-cross-app-disable'), 'SAND_IAM_RESOURCE_NOT_FOUND');

$guestService = new GuestIdentityService();
$guestA = $guestService->upsert((int) $applicationA->id, 'visitor-001', '访客一号', 'ctx-t10-a', 't10-guest-a');
$guestARepeat = $guestService->upsert((int) $applicationA->id, 'visitor-001', '访客一号（更新）', 'ctx-t10-a', 't10-guest-a-repeat');
$guestB = $guestService->upsert((int) $applicationB->id, 'visitor-001', '另一应用访客', 'ctx-t10-b', 't10-guest-b');
lifecyclePgAssert($guestA['created'] === true && $guestARepeat['created'] === false && $guestA['id'] === $guestARepeat['id'], 'guest upsert was not application-idempotent');
lifecyclePgAssert($guestA['id'] !== $guestB['id'], 'the same external guest reference leaked across applications');
lifecyclePgAssert((string) Identity::find($guestA['id'])?->display_name === '访客一号（更新）', 'guest display name update was not persisted');

foreach (['identity_group.create', 'identity_group.member_add', 'identity.disabled', 'identity.enable', 'identity.deleted', 'identity.restore', 'identity.guest_upsert'] as $action) {
    lifecyclePgAssert(AuditLog::where('application_id', (int) $applicationA->id)->where('action', $action)->count() >= 1, "missing audit action {$action}");
}

fwrite(STDOUT, "IAM-T10 identity lifecycle and group PostgreSQL integration passed\n");
