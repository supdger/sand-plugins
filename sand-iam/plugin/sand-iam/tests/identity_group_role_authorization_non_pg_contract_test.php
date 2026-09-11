<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    $root = dirname(__DIR__, 3);
    $package = $root . '/plugin/sand-iam';
    $files = [
        'model' => $package . '/app/model/IdentityGroupRole.php',
        'controller' => $package . '/app/admin/controller/IdentityGroupRoleController.php',
        'service' => $package . '/app/service/IdentityGroupRoleService.php',
        'authorizer' => $package . '/app/runtime/PolicyAuthorizer.php',
        'routes' => $package . '/config/route.php',
        'migration' => $root . '/migrations/033_identity_group_role.pgsql',
        'plugin migration' => $package . '/migrations/033_identity_group_role.pgsql',
        'generator' => $root . '/tools/build-lifecycle.php',
        'remove lifecycle' => $root . '/lifecycle/remove.pgsql',
        'management contract' => $root . '/docs/development/sand-iam-management-api-v0.1.md',
        'authorization contract' => $root . '/docs/development/sand-iam-authorization-contract.md',
        'transaction integration' => $package . '/tests/identity_group_role_transaction_pg_integration_test.php',
    ];
    $read = static fn (string $name): string => (string) file_get_contents($files[$name]);
    foreach ($files as $name => $path) {
        if (!is_file($path)) {
            fwrite(STDERR, "missing {$name}: {$path}\n");
            exit(1);
        }
    }

    require_once $files['authorizer'];
    $authorizer = (new \ReflectionClass(\plugin\SandIam\app\runtime\PolicyAuthorizer::class))->newInstanceWithoutConstructor();
    $addSource = new \ReflectionMethod(\plugin\SandIam\app\runtime\PolicyAuthorizer::class, 'addRoleSource');
    $subjectSource = new \ReflectionMethod(\plugin\SandIam\app\runtime\PolicyAuthorizer::class, 'subjectSource');
    $sources = [];
    $addSource->invokeArgs($authorizer, [&$sources, 8, 'direct_identity_role']);
    $addSource->invokeArgs($authorizer, [&$sources, 8, 'identity_group:17']);
    $addSource->invokeArgs($authorizer, [&$sources, 8, 'identity_group:17']);
    $rolePolicy = (object) ['identity_id' => 0, 'role_id' => 8];
    $identityPolicy = (object) ['identity_id' => 99, 'role_id' => 0];

    $checks = [
        'group-role model owns the SandIAM relation table' => str_contains($read('model'), "sand_iam_identity_group_role"),
        'management controller provides both relationship views and mutations' => str_contains($read('controller'), 'function index') && str_contains($read('controller'), 'function roleIndex') && str_contains($read('controller'), 'function grant') && str_contains($read('controller'), 'function revoke'),
        'management controller checks application access and delegates mutations to the transactional service' => str_contains($read('controller'), 'assertApplication') && str_contains($read('controller'), 'IdentityGroupRoleService') && str_contains($read('controller'), '->grant(') && str_contains($read('controller'), '->revoke('),
        'grant and revoke write audit before one shared transaction commits or rolls back' => substr_count($read('service'), 'Db::startTrans()') === 2 && substr_count($read('service'), 'Db::commit()') === 2 && substr_count($read('service'), 'Db::rollback()') === 2 && substr_count($read('service'), '$this->writeAudit(') === 2 && strpos($read('service'), '$this->writeAudit(') < strpos($read('service'), 'Db::commit()') && str_contains($read('service'), '$auditOverride') && str_contains($read('transaction integration'), 'injected audit failure') && str_contains($read('transaction integration'), 'audit failure left a persisted grant mutation') && str_contains($read('transaction integration'), 'audit failure left a persisted revoke mutation'),
        'routes expose the four group-role management operations' => str_contains($read('routes'), '/identity-group-role/index') && str_contains($read('routes'), '/identity-group-role/role-index') && str_contains($read('routes'), '/identity-group-role/grant') && str_contains($read('routes'), '/identity-group-role/revoke'),
        'developer OpenAPI catalog publishes the four management operations with the shared list permission' => str_contains((string) file_get_contents($package . '/app/developer/ManagementApiCatalog.php'), '/identity-group-role/index') && str_contains((string) file_get_contents($package . '/app/developer/ManagementApiCatalog.php'), '/identity-group-role/role-index') && str_contains((string) file_get_contents($package . '/app/developer/ManagementApiCatalog.php'), '/identity-group-role/grant') && str_contains((string) file_get_contents($package . '/app/developer/ManagementApiCatalog.php'), '/identity-group-role/revoke') && str_contains((string) file_get_contents($package . '/app/developer/ManagementApiCatalog.php'), "'GET /identity-group-role/role-index' => 'sand_iam:identity_group_role:index'"),
        'runtime merges direct and active user-group role sources' => str_contains($read('authorizer'), 'effectiveRoles') && str_contains($read('authorizer'), 'sand_iam_identity_group_role group_role') && str_contains($read('authorizer'), "'direct_identity_role'") && str_contains($read('authorizer'), "'identity_group:'"),
        'runtime fails closed on disabled membership group grant role or cross-application records' => str_contains($read('authorizer'), "->where('group_member.status', 1)") && str_contains($read('authorizer'), "->where('iam_group.status', 1)") && str_contains($read('authorizer'), "->where('group_role.status', 1)") && str_contains($read('authorizer'), "->where('iam_role.status', 1)") && str_contains($read('authorizer'), "->where('group_member.application_id', \$applicationId)") && str_contains($read('authorizer'), "->where('iam_group.application_id', \$applicationId)") && str_contains($read('authorizer'), "->where('group_role.application_id', \$applicationId)") && str_contains($read('authorizer'), "->where('iam_role.application_id', \$applicationId)"),
        'role sources are de-duplicated and preserve direct plus group provenance' => $sources === [8 => ['direct_identity_role', 'identity_group:17']] && $subjectSource->invoke($authorizer, $rolePolicy, 99, $sources) === ['direct_identity_role', 'identity_group:17'],
        'direct identity policy keeps its distinct decision source' => $subjectSource->invoke($authorizer, $identityPolicy, 99, $sources) === ['direct_identity'],
        'allow and deny audits carry decision sources' => str_contains($read('authorizer'), "'subject_source' =>") && str_contains($read('authorizer'), "'effective_role_sources' =>"),
        'migration constrains the relationship to one application and is repeatable' => str_contains($read('migration'), 'CREATE UNIQUE INDEX IF NOT EXISTS') && str_contains($read('migration'), 'CREATE TABLE IF NOT EXISTS sand_iam_identity_group_role') && str_contains($read('migration'), 'uk_sand_iam_identity_group_role') && str_contains($read('migration'), 'fk_sand_iam_group_role_group_app') && str_contains($read('migration'), 'fk_sand_iam_group_role_role_app') && str_contains($read('migration'), 'delete_time'),
        'root and packaged migration stay byte-identical' => hash_file('sha256', $files['migration']) === hash_file('sha256', $files['plugin migration']),
        'lifecycle generator and removal lifecycle include the relation' => str_contains($read('generator'), '033_identity_group_role.pgsql') && str_contains($read('remove lifecycle'), 'DROP TABLE IF EXISTS sand_iam_identity_group_role'),
        'published contracts document the API atomic audit boundary and role-only policy subject boundary' => str_contains($read('management contract'), '/identity-group-role/grant') && str_contains($read('management contract'), '审计无法写入时关系变更会完整回滚') && str_contains($read('management contract'), '不新增用户组策略') && str_contains($read('authorization contract'), 'sand_iam_identity_group_role') && str_contains($read('authorization contract'), '不使用独立授权缓存') && str_contains($read('authorization contract'), 'fail-closed'),
    ];

    $passed = 0;
    foreach ($checks as $label => $ok) {
        echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
        $passed += $ok ? 1 : 0;
    }
    $total = count($checks);
    echo "Identity group role authorization non-PG contract: passed={$passed}/{$total}; failed=" . ($total - $passed) . PHP_EOL;
    exit($passed === $total ? 0 : 1);
}
