<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Service;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ServiceController extends AdminResourceController
{
    protected string $modelClass = Service::class;
    protected array $writeFields = ['code', 'name', 'status'];
    protected string $resourceType = 'service';
    protected bool $requiresSuperAdmin = true;
    #[Permission('SandIAM 服务列表', 'sand_iam:service:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 服务读取', 'sand_iam:service:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 服务保存', 'sand_iam:service:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 服务更新', 'sand_iam:service:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 服务停用', 'sand_iam:service:disable')] public function disable(Request $request): Response { return parent::disable($request); }
}
