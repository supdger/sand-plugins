<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class WorkloadClientController extends AdminResourceController
{
    protected string $modelClass = WorkloadClient::class;
    protected array $writeFields = ['environment_id', 'code', 'name', 'audience', 'status'];
    protected array $requiredFields = ['environment_id', 'code', 'name', 'audience'];
    protected string $resourceType = 'workload_client';
    #[Permission('SandIAM 工作负载客户端列表', 'sand_iam:client:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 工作负载客户端读取', 'sand_iam:client:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 工作负载客户端保存', 'sand_iam:client:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 工作负载客户端更新', 'sand_iam:client:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 工作负载客户端停用', 'sand_iam:client:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['environment_id']) && !Environment::where('id', (int) $payload['environment_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: environment', 400); }
    protected function applyOrganizationScope(object $query, array $organizationIds): void { $applicationIds = Application::whereIn('organization_id', $organizationIds)->column('id'); $query->whereIn('environment_id', Environment::whereIn('application_id', $applicationIds)->column('id')); }
    protected function organizationIdForModel(object $model): ?int { $environment = Environment::find($model->environment_id); $application = $environment ? Application::find($environment->application_id) : null; return $application ? (int) $application->organization_id : null; }
    protected function assertPayloadAccess(array $payload, ?object $existing = null): void { $environmentId = (int) ($payload['environment_id'] ?? $existing?->environment_id ?? 0); $environment = Environment::find($environmentId); $application = $environment ? Application::find($environment->application_id) : null; $this->access()->assertOrganization($application ? (int) $application->organization_id : 0); }
}
