<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ServiceActionController extends AdminResourceController
{
    protected string $modelClass = ServiceAction::class;
    protected array $writeFields = ['service_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['service_id', 'code', 'name'];
    protected string $resourceType = 'service_action';
    protected bool $requiresSuperAdmin = true;
    #[Permission('SandIAM 服务动作列表', 'sand_iam:service_action:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 服务动作读取', 'sand_iam:service_action:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 服务动作保存', 'sand_iam:service_action:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 服务动作更新', 'sand_iam:service_action:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 服务动作停用', 'sand_iam:service_action:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['service_id']) && !Service::where('id', (int) $payload['service_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: service', 400); }
}
