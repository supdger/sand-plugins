<?php
declare(strict_types=1);
namespace {
    require __DIR__ . '/machine_application_access_non_pg_test.php';
}
namespace plugin\SandIam\app\model {
    class Service extends \MachineAccessTest\Model { public static array $rows = [
        ['id' => 1, 'code' => 'ai', 'name' => 'AI', 'status' => 1, 'internal' => 'hidden'],
        ['id' => 2, 'code' => 'old', 'name' => 'Old', 'status' => 2],
        ['id' => 3, 'code' => 'workflow', 'name' => 'Workflow', 'status' => 1]
    ]; }
    class ServiceAction extends \MachineAccessTest\Model { public static array $rows = [
        ['id' => 10, 'service_id' => 1, 'code' => 'parse', 'name' => 'Parse', 'status' => 1, 'internal' => 'hidden'],
        ['id' => 11, 'service_id' => 1, 'code' => 'old', 'name' => 'Old', 'status' => 2],
        ['id' => 30, 'service_id' => 3, 'code' => 'run', 'name' => 'Run', 'status' => 1]
    ]; }
}
namespace {
    \support\Request::$admin = 2;
    \plugin\SandIam\app\model\AdminOrganizationGrant::$rows = [];
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows = [['admin_user_id' => 2, 'application_id' => 11, 'status' => 1]];
    $controller = new \plugin\SandIam\app\admin\controller\ServiceGrantController();
    $services = $controller->services(new \support\Request(['workload_client_id' => 1]))->data;
    if (array_column($services['data'], 'id') !== [1, 3]) throw new \RuntimeException('Enabled service candidates missing');
    if (array_keys($services['data'][0]) !== ['id', 'code', 'name']) throw new \RuntimeException('Service projection leaked fields');
    $actions = $controller->actions(new \support\Request(['workload_client_id' => 1, 'service_id' => 1]))->data;
    if (array_column($actions['data'], 'id') !== [10]) throw new \RuntimeException('Action service/status filter missing');
    if (array_keys($actions['data'][0]) !== ['id', 'service_id', 'code', 'name']) throw new \RuntimeException('Action projection leaked fields');
    $exact = $controller->actions(new \support\Request(['workload_client_id' => 1, 'id' => 30]))->data;
    if ($exact['data'][0]['service_id'] !== 3) throw new \RuntimeException('Exact edit lookup cannot restore service');
    $conflict = $controller->actions(new \support\Request(['workload_client_id' => 1, 'service_id' => 1, 'id' => 30]))->data;
    if ($conflict['data'] !== []) throw new \RuntimeException('Conflicting service accepted');
    $page = $controller->services(new \support\Request(['workload_client_id' => 1, 'page' => 2, 'limit' => 1]))->data;
    if ($page['data'][0]['id'] !== 3 || $page['total'] !== 2) throw new \RuntimeException('Pagination failed');
    $search = $controller->services(new \support\Request(['workload_client_id' => 1, 'keywords' => 'Workflow']))->data;
    if (array_column($search['data'], 'id') !== [3]) throw new \RuntimeException('Search failed');
    foreach (['services', 'actions'] as $method) {
        foreach ([0, 2, 3, 4, 5, 999] as $clientId) {
            try { $controller->$method(new \support\Request(['workload_client_id' => $clientId, 'service_id' => 1])); }
            catch (\plugin\sandadmin\exception\ApiException $error) { continue; }
            throw new \RuntimeException('Unauthorized candidate lookup accepted');
        }
    }
    try { $controller->actions(new \support\Request(['workload_client_id' => 1])); throw new \RuntimeException('Unfiltered action list accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) {}
    \plugin\SandIam\app\model\Service::$rows[0]['status'] = 2;
    $inactive = $controller->actions(new \support\Request(['workload_client_id' => 1, 'id' => 10]))->data;
    if ($inactive['data'] !== []) throw new \RuntimeException('Inactive parent service leaked action');
    echo "service grant candidates non-PG behavior PASS\n";
}
