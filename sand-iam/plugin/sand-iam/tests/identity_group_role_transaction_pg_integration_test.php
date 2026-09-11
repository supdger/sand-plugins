<?php

declare(strict_types=1);

use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupRole;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\service\IdentityGroupRoleService;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

// Requires an installed disposable schema. The gate is the only permission to
// write its supplied fixture; this test never installs, migrates, or clears a database.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') {
    echo "SKIP: set SAND_IAM_RUN_PG_TESTS=1 with a disposable installed SandIAM database\n";
    exit(0);
}
$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) throw new RuntimeException('SandAdmin ThinkORM harness is unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
Config::clear(); support\App::loadAllConfig(['route']); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); ThinkOrm::start(null);

function groupRoleTransactionAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$applicationId = (int) getenv('SAND_IAM_GROUP_ROLE_APPLICATION_ID');
$groupId = (int) getenv('SAND_IAM_GROUP_ROLE_GROUP_ID');
$roleId = (int) getenv('SAND_IAM_GROUP_ROLE_ROLE_ID');
if (min($applicationId, $groupId, $roleId) <= 0) {
    echo "SKIP: set disposable SAND_IAM_GROUP_ROLE_APPLICATION_ID/GROUP_ID/ROLE_ID\n";
    exit(0);
}
$group = IdentityGroup::where('id', $groupId)->where('application_id', $applicationId)->find();
$role = Role::where('id', $roleId)->where('application_id', $applicationId)->find();
groupRoleTransactionAssert($group !== null && $role !== null, 'fixture group and role must belong to the supplied application');
$groupStatus = (int) $group->status; $roleStatus = (int) $role->status;
$binding = IdentityGroupRole::where('identity_group_id', $groupId)->where('role_id', $roleId)->where('application_id', $applicationId)->find();
$bindingCreated = $binding === null; $bindingStatus = $binding === null ? null : (int) $binding->status;
try {
    $group->save(['status' => 1]); $role->save(['status' => 1]);
    $fault = new IdentityGroupRoleService(static function (): void { throw new RuntimeException('injected audit failure'); });
    try {
        $fault->grant($groupId, $roleId, $applicationId, '1', 'group-role-audit-fault-grant');
        throw new RuntimeException('audit fault unexpectedly committed a grant');
    } catch (RuntimeException $exception) {
        groupRoleTransactionAssert($exception->getMessage() === 'injected audit failure', 'grant propagated the wrong audit failure');
    }
    $afterFault = IdentityGroupRole::where('identity_group_id', $groupId)->where('role_id', $roleId)->where('application_id', $applicationId)->find();
    groupRoleTransactionAssert(($bindingCreated && $afterFault === null) || (!$bindingCreated && (int) $afterFault->status === $bindingStatus), 'audit failure left a persisted grant mutation');

    $service = new IdentityGroupRoleService();
    $grantRequestId = 'group-role-transaction-grant-' . bin2hex(random_bytes(6));
    $bindingId = $service->grant($groupId, $roleId, $applicationId, '1', $grantRequestId);
    groupRoleTransactionAssert((int) IdentityGroupRole::find($bindingId)?->status === 1, 'successful grant was not committed');
    groupRoleTransactionAssert(AuditLog::where('request_id', $grantRequestId)->where('action', 'identity_group_role.grant')->count() === 1, 'successful grant did not persist its audit in the same transaction');

    $faultRevoke = new IdentityGroupRoleService(static function (): void { throw new RuntimeException('injected audit failure'); });
    try {
        $faultRevoke->revoke($bindingId, $applicationId, '1', 'group-role-audit-fault-revoke');
        throw new RuntimeException('audit fault unexpectedly committed a revoke');
    } catch (RuntimeException $exception) {
        groupRoleTransactionAssert($exception->getMessage() === 'injected audit failure', 'revoke propagated the wrong audit failure');
    }
    groupRoleTransactionAssert((int) IdentityGroupRole::find($bindingId)?->status === 1, 'audit failure left a persisted revoke mutation');

    $revokeRequestId = 'group-role-transaction-revoke-' . bin2hex(random_bytes(6));
    $service->revoke($bindingId, $applicationId, '1', $revokeRequestId);
    groupRoleTransactionAssert((int) IdentityGroupRole::find($bindingId)?->status === 2, 'successful revoke was not committed');
    groupRoleTransactionAssert(AuditLog::where('request_id', $revokeRequestId)->where('action', 'identity_group_role.revoke')->count() === 1, 'successful revoke did not persist its audit in the same transaction');
    echo "identity group role transaction PostgreSQL integration passed\n";
} finally {
    $current = IdentityGroupRole::where('identity_group_id', $groupId)->where('role_id', $roleId)->where('application_id', $applicationId)->find();
    if ($current !== null) {
        if ($bindingCreated) $current->delete();
        else $current->save(['status' => $bindingStatus]);
    }
    $group->save(['status' => $groupStatus]); $role->save(['status' => $roleStatus]);
}
