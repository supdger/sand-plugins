<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class OrganizationController extends AdminResourceController
{
    protected string $modelClass = Organization::class;
    protected array $writeFields = ['code', 'name', 'status'];
    protected string $resourceType = 'organization';
    protected function applyOrganizationScope(object $query, array $organizationIds): void { $query->whereIn('id', $organizationIds); }
    #[Permission('SandIAM 客户主体列表', 'sand_iam:organization:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 客户主体读取', 'sand_iam:organization:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 客户主体保存', 'sand_iam:organization:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 客户主体更新', 'sand_iam:organization:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 客户主体停用', 'sand_iam:organization:disable')] public function disable(Request $request): Response { return parent::disable($request); }
}
