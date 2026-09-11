<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\UserType;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class UserTypeController extends ApplicationResourceController
{
    protected string $modelClass = UserType::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name'];
    protected string $resourceType = 'user_type';
    #[Permission('SandIAM 用户类型列表', 'sand_iam:user_type:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 用户类型读取', 'sand_iam:user_type:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 用户类型保存', 'sand_iam:user_type:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 用户类型更新', 'sand_iam:user_type:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 用户类型停用', 'sand_iam:user_type:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属应用不存在或已停用', 400); }
}
