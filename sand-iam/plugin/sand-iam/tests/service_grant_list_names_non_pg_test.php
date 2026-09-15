<?php
declare(strict_types=1);
require __DIR__ . '/machine_resource_filters_non_pg_test.php';

use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\admin\controller\ServiceGrantController;

ServiceAction::$records = [
    ['id' => 10, 'service_id' => 100, 'name' => 'Archived action', 'status' => 2],
    ['id' => 20, 'service_id' => 200, 'name' => 'Other app action', 'status' => 1]
];
Service::$records = [
    ['id' => 100, 'name' => 'Archived service', 'status' => 2],
    ['id' => 200, 'name' => 'Other app service', 'status' => 1]
];
ServiceGrant::$records = [
    ['id' => 1, 'workload_client_id' => 1, 'service_action_id' => 10, 'status' => 1],
    ['id' => 2, 'workload_client_id' => 1, 'service_action_id' => 999, 'status' => 1],
    ['id' => 3, 'workload_client_id' => 3, 'service_action_id' => 20, 'status' => 1]
];
AdminOrganizationAccess::$super = false;
AdminOrganizationAccess::$applications = [11];
\MachineFilterTest\Query::$selectedIds = [];
$controller = new ServiceGrantController();
$page = $controller->index(new \support\Request(['page' => 2, 'limit' => 1]))->data;
if (($page['data'][0]['service_action_name'] ?? null) !== 'Archived action') throw new RuntimeException('Current authorized page must include historical action name');
if ($page['data'][0]['service_name'] !== 'Archived service') throw new RuntimeException('Historical service name missing');
if ($page['total'] !== 2 || $page['page'] !== 2 || $page['data'][0]['service_action_id'] !== 10) throw new RuntimeException('Pagination or edit id changed');
if (\MachineFilterTest\Query::$selectedIds !== [[10], [100]]) throw new RuntimeException('Lookup was not restricted to page action and service ids');
$missing = $controller->index(new \support\Request(['page' => 1, 'limit' => 1]))->data['data'][0];
if ($missing['service_action_name'] !== null || $missing['service_name'] !== null) throw new RuntimeException('Missing references must be null');
echo "service grant list names non-PG behavior PASS\n";
