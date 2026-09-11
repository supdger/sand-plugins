<?php

declare(strict_types=1);

use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\IdentityGroupRole;
use plugin\SandIam\app\model\IdentityRole;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\PolicyVersion;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\runtime\PolicyAuthorizer;
use plugin\SandIam\app\service\PolicyVersionService;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

// Requires an already-installed disposable SandIAM schema. This test neither
// installs nor migrates a database; SAND_IAM_RUN_PG_TESTS=1 is explicit write
// authorization for the supplied fixture and every created row is removed.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') {
    echo "SKIP: set SAND_IAM_RUN_PG_TESTS=1 with a disposable installed SandIAM database\n";
    exit(0);
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) throw new RuntimeException('SandAdmin ThinkORM harness is unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
Config::clear(); support\App::loadAllConfig(['route']); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); ThinkOrm::start(null);

function groupRolePgAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function groupRolePgRestore(object $model, ?int $status, bool $created): void { if ($created) $model->delete(); elseif ($status !== null) $model->save(['status' => $status]); }

$applicationId = (int) getenv('SAND_IAM_GROUP_ROLE_APPLICATION_ID');
$identityId = (int) getenv('SAND_IAM_GROUP_ROLE_IDENTITY_ID');
$groupId = (int) getenv('SAND_IAM_GROUP_ROLE_GROUP_ID');
$roleId = (int) getenv('SAND_IAM_GROUP_ROLE_ROLE_ID');
$resourceId = (int) getenv('SAND_IAM_GROUP_ROLE_RESOURCE_ID');
if (min($applicationId, $identityId, $groupId, $roleId, $resourceId) <= 0) {
    echo "SKIP: set disposable SAND_IAM_GROUP_ROLE_APPLICATION_ID/IDENTITY_ID/GROUP_ID/ROLE_ID/RESOURCE_ID\n";
    exit(0);
}

$group = IdentityGroup::where('id', $groupId)->where('application_id', $applicationId)->find();
$role = Role::where('id', $roleId)->where('application_id', $applicationId)->find();
$resource = Resource::where('id', $resourceId)->where('application_id', $applicationId)->find();
groupRolePgAssert($group !== null && $role !== null && $resource !== null, 'fixture records do not share the supplied application');

$member = null; $groupRole = null; $directRole = null; $policy = null; $versionIds = [];
$memberOriginal = null; $groupRoleOriginal = null; $directRoleOriginal = null;
$memberCreated = false; $groupRoleCreated = false; $directRoleCreated = false; $roleOriginal = (int) $role->status; $groupOriginal = (int) $group->status;
try {
    $member = IdentityGroupMember::where('identity_group_id', $groupId)->where('identity_id', $identityId)->find();
    if ($member === null) { $member = IdentityGroupMember::create(['identity_group_id' => $groupId, 'identity_id' => $identityId, 'application_id' => $applicationId, 'status' => 1]); $memberCreated = true; }
    else { $memberOriginal = (int) $member->status; $member->save(['status' => 1]); }
    $groupRole = IdentityGroupRole::where('identity_group_id', $groupId)->where('role_id', $roleId)->find();
    if ($groupRole === null) { $groupRole = IdentityGroupRole::create(['identity_group_id' => $groupId, 'role_id' => $roleId, 'application_id' => $applicationId, 'status' => 1]); $groupRoleCreated = true; }
    else { $groupRoleOriginal = (int) $groupRole->status; $groupRole->save(['status' => 1]); }
    $directRole = IdentityRole::where('identity_id', $identityId)->where('role_id', $roleId)->find();
    if ($directRole === null) { $directRole = IdentityRole::create(['identity_id' => $identityId, 'role_id' => $roleId, 'status' => 2]); $directRoleCreated = true; }
    else { $directRoleOriginal = (int) $directRole->status; $directRole->save(['status' => 2]); }
    $group->save(['status' => 1]); $role->save(['status' => 1]);

    $authorizer = (new ReflectionClass(PolicyAuthorizer::class))->newInstanceWithoutConstructor();
    $effective = new ReflectionMethod(PolicyAuthorizer::class, 'effectiveRoles');
    $sources = $effective->invoke($authorizer, $applicationId, $identityId);
    groupRolePgAssert($sources['role_ids'] === [$roleId] && $sources['sources'][$roleId] === ['identity_group:' . $groupId], 'active user-group role was not the sole effective source');

    $groupRole->save(['status' => 2]);
    groupRolePgAssert($effective->invoke($authorizer, $applicationId, $identityId)['role_ids'] === [], 'revoked group role remained effective');
    $groupRole->save(['status' => 1]); $member->save(['status' => 2]);
    groupRolePgAssert($effective->invoke($authorizer, $applicationId, $identityId)['role_ids'] === [], 'removed member retained group role');
    $member->save(['status' => 1]); $group->save(['status' => 2]);
    groupRolePgAssert($effective->invoke($authorizer, $applicationId, $identityId)['role_ids'] === [], 'disabled group retained role');
    $group->save(['status' => 1]); $role->save(['status' => 2]);
    groupRolePgAssert($effective->invoke($authorizer, $applicationId, $identityId)['role_ids'] === [], 'disabled role remained effective');
    $role->save(['status' => 1]); $directRole->save(['status' => 1]);
    $sources = $effective->invoke($authorizer, $applicationId, $identityId);
    groupRolePgAssert($sources['role_ids'] === [$roleId] && $sources['sources'][$roleId] === ['direct_identity_role', 'identity_group:' . $groupId], 'direct and group sources were not merged and de-duplicated');

    $action = 'group-role-' . bin2hex(random_bytes(8));
    $policy = Policy::create(['application_id' => $applicationId, 'resource_id' => $resourceId, 'role_id' => $roleId, 'identity_id' => null, 'action' => $action, 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 10, 'state' => 'draft', 'status' => 1]);
    $published = (new PolicyVersionService())->publish((int) $policy->id, 'group-role-' . bin2hex(random_bytes(12)));
    $versionIds[] = (int) $published['version']->id;
    $decision = (new PolicyAuthorizer())->authorize($applicationId, $identityId, (string) $resource->code, $action, 'read', [], 'group-role-' . bin2hex(random_bytes(12)));
    groupRolePgAssert($decision['allowed'] === true, 'role policy did not allow the user-group member');
    $audit = AuditLog::where('application_id', $applicationId)->where('action', 'authorize.read')->order('id', 'desc')->find();
    groupRolePgAssert($audit !== null && in_array('identity_group:' . $groupId, (array) (($audit->context['subject_source'] ?? [])), true), 'authorization audit did not retain the group decision source');

    echo "identity group role PostgreSQL integration passed\n";
} finally {
    if ($policy !== null) { Policy::where('id', (int) $policy->id)->update(['published_version_id' => null]); foreach ($versionIds as $versionId) PolicyVersion::where('id', $versionId)->delete(); $policy->delete(); }
    if ($directRole !== null) groupRolePgRestore($directRole, $directRoleOriginal, $directRoleCreated);
    if ($groupRole !== null) groupRolePgRestore($groupRole, $groupRoleOriginal, $groupRoleCreated);
    if ($member !== null) groupRolePgRestore($member, $memberOriginal, $memberCreated);
    $group->save(['status' => $groupOriginal]); $role->save(['status' => $roleOriginal]);
}
