<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class EnvironmentController extends AdminResourceController
{
    protected string $modelClass = Environment::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name'];
    protected string $resourceType = 'environment';
    #[Permission('SandIAM 环境列表', 'sand_iam:environment:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 环境读取', 'sand_iam:environment:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 环境保存', 'sand_iam:environment:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 环境更新', 'sand_iam:environment:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 环境停用', 'sand_iam:environment:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: application', 400); }
    protected function applyOrganizationScope(object $query, array $organizationIds): void { $query->whereIn('application_id', Application::whereIn('organization_id', $organizationIds)->column('id')); }
    protected function organizationIdForModel(object $model): ?int { $application = Application::find($model->application_id); return $application ? (int) $application->organization_id : null; }
    protected function assertPayloadAccess(array $payload, ?object $existing = null): void { $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0); $application = Application::find($applicationId); $this->access()->assertOrganization($application ? (int) $application->organization_id : 0); }
}
