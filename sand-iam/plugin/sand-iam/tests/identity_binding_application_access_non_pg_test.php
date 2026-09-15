<?php
declare(strict_types=1);

namespace {
    require __DIR__ . '/machine_application_access_non_pg_test.php';
}
namespace IdentityBindingAccessTest {
    class Query extends \MachineAccessTest\Query {
        public function find(): ?object {
            $row = parent::find();
            return $row === null ? null : new \plugin\SandIam\app\model\Identity($row);
        }
    }
}
namespace plugin\SandIam\app\model {
    class Identity {
        public int $id;
        public int $application_id;
        public int $status;
        public static array $rows = [
            ['id' => 1, 'application_id' => 11, 'status' => 1],
            ['id' => 2, 'application_id' => 12, 'status' => 1],
            ['id' => 3, 'application_id' => 21, 'status' => 1],
            ['id' => 4, 'application_id' => 11, 'status' => 2],
            ['id' => 5, 'application_id' => 999, 'status' => 1],
        ];
        public function __construct(object $row) {
            $this->id = $row->id;
            $this->application_id = $row->application_id;
            $this->status = $row->status;
        }
        public static function where(string $key, mixed $value): \IdentityBindingAccessTest\Query {
            return (new \IdentityBindingAccessTest\Query(self::$rows))->where($key, $value);
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/admin/controller/IdentityRoleController.php';
    require dirname(__DIR__) . '/app/admin/controller/IdentityUserTypeController.php';
    use plugin\SandIam\app\model\AdminApplicationGrant;
    use plugin\SandIam\app\model\AdminOrganizationGrant;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Organization;

    // Exercise the shared identity gate called by index, grant, and revoke.
    // Public mutation persistence and Permission middleware are outside this fixture.
    function bindingIdentityAccess(int $identityId, bool $allowed, int $admin = 2): void {
        foreach ([
            \plugin\SandIam\app\admin\controller\IdentityRoleController::class,
            \plugin\SandIam\app\admin\controller\IdentityUserTypeController::class,
        ] as $class) {
            $controller = new $class();
            // Host BaseController normally supplies these fields.
            @$controller->adminId = $admin;
            @$controller->adminInfo = null;
            try {
                $identity = invoke($controller, 'identity', $identityId);
                $actual = $identity->id === $identityId;
            } catch (\plugin\sandadmin\exception\ApiException $error) {
                $actual = false;
            }
            if ($actual !== $allowed) throw new \RuntimeException("{$class}: identity {$identityId} scope mismatch");
        }
    }
    AdminOrganizationGrant::$rows = [];
    AdminApplicationGrant::$rows = [['admin_user_id' => 2, 'application_id' => 11, 'status' => 1]];
    bindingIdentityAccess(1, true);
    foreach ([2, 3, 4, 5, 999] as $id) bindingIdentityAccess($id, false);
    AdminApplicationGrant::$rows[0]['status'] = 2;
    bindingIdentityAccess(1, false);
    AdminApplicationGrant::$rows[0]['status'] = 1;
    Application::$rows[0]['status'] = 2;
    bindingIdentityAccess(1, false);
    Application::$rows[0]['status'] = 1;
    Organization::$rows[0]['status'] = 2;
    bindingIdentityAccess(1, false);
    Organization::$rows[0]['status'] = 1;
    AdminApplicationGrant::$rows = [];
    bindingIdentityAccess(1, false);
    AdminOrganizationGrant::$rows = [['admin_user_id' => 2, 'organization_id' => 1, 'status' => 1]];
    bindingIdentityAccess(1, true);
    bindingIdentityAccess(2, true);
    bindingIdentityAccess(3, false);
    foreach ([1, 2, 3] as $id) bindingIdentityAccess($id, true, 1);
    foreach ([4, 5, 999] as $id) bindingIdentityAccess($id, false, 1);
    echo "identity binding application access non-PG behavior PASS\n";
}
