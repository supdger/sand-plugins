<?php
declare(strict_types=1);

namespace {
    require __DIR__ . '/machine_resource_filters_non_pg_test.php';
}

namespace plugin\SandIam\app\model {
    class Identity extends \MachineFilterTest\Model
    {
        public static array $records = [
            ['id' => 101, 'application_id' => 11, 'display_name' => 'Alice', 'status' => 1],
            ['id' => 102, 'application_id' => 11, 'display_name' => 'Bob', 'status' => 1],
            ['id' => 201, 'application_id' => 12, 'display_name' => 'Alice', 'status' => 1],
        ];
    }
}

namespace {
    require dirname(__DIR__) . '/app/admin/support/ApplicationResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/IdentityController.php';
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$super = false;
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$applications = [11];
    $controller = new \plugin\SandIam\app\admin\controller\IdentityController();
    foreach ([
        [['keywords' => 'Ali', 'application_id' => 11], [101]],
        [['keywords' => 'Ali', 'application_id' => 12], []],
        [['keywords' => 'nobody', 'application_id' => 11], []],
        [['application_id' => 11], [102, 101]],
    ] as [$params, $expected]) {
        $response = $controller->index(new \support\Request($params));
        if (array_column($response->data['data'], 'id') !== $expected) {
            throw new \RuntimeException('Identity name search or application scope mismatch');
        }
    }
    echo "identity name filter non-PG behavior PASS\n";
}
