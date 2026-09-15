<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\EnvironmentResourceController;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class WorkloadClientController extends EnvironmentResourceController
{
    protected string $modelClass = WorkloadClient::class;
    protected array $writeFields = ['environment_id', 'code', 'name', 'audience', 'status'];
    protected array $requiredFields = ['environment_id', 'code', 'name', 'audience'];
    protected string $resourceType = 'workload_client';
    protected function applyIndexFilters(object $query, Request $request): void
    {
        $organizationId = (int) $request->input('organization_id', 0);
        $applicationId = (int) $request->input('application_id', 0);
        if ($organizationId <= 0 && $applicationId <= 0) return;
        $applications = $organizationId > 0
            ? Application::where('organization_id', $organizationId)
            : Application::where('id', $applicationId);
        if ($applicationId > 0) $applications->where('id', $applicationId);
        $environmentIds = Environment::whereIn('application_id', $applications->column('id'))->column('id');
        $query->whereIn('environment_id', $environmentIds);
    }
    #[Permission('SandIAM 服务调用身份列表', 'sand_iam:client:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 服务调用身份读取', 'sand_iam:client:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 服务调用身份保存', 'sand_iam:client:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 服务调用身份更新', 'sand_iam:client:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 服务调用身份停用', 'sand_iam:client:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['environment_id']) && !Environment::where('id', (int) $payload['environment_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属应用环境不存在或已停用', 400); }
}
