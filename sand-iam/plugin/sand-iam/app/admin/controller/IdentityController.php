<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityController extends ApplicationResourceController
{
    protected string $modelClass = Identity::class;
    protected array $writeFields = ['application_id', 'code', 'display_name', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'display_name'];
    protected string $resourceType = 'identity';
    #[Permission('SandIAM 身份列表', 'sand_iam:identity:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 身份读取', 'sand_iam:identity:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 身份保存', 'sand_iam:identity:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 身份更新', 'sand_iam:identity:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 身份停用', 'sand_iam:identity:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: application', 400); }
}
