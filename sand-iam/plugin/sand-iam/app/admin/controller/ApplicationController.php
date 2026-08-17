<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApplicationController extends AdminResourceController
{
    protected string $modelClass = Application::class;
    protected array $writeFields = ['organization_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['organization_id', 'code', 'name'];
    protected string $resourceType = 'application';
    #[Permission('SandIAM 应用列表', 'sand_iam:application:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 应用读取', 'sand_iam:application:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 应用保存', 'sand_iam:application:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 应用更新', 'sand_iam:application:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 应用停用', 'sand_iam:application:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['organization_id']) && !Organization::where('id', (int) $payload['organization_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: organization', 400); }
}
