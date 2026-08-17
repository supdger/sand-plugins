<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Application;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ServiceGrantController extends AdminResourceController
{
    protected string $modelClass = ServiceGrant::class;
    protected array $writeFields = ['workload_client_id', 'service_action_id', 'audience', 'quota_policy', 'data_class', 'network_policy', 'expire_time', 'status'];
    protected array $requiredFields = ['workload_client_id', 'service_action_id', 'audience'];
    protected string $resourceType = 'service_grant';
    #[Permission('SandIAM 服务授权列表', 'sand_iam:grant:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 服务授权读取', 'sand_iam:grant:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 服务授权保存', 'sand_iam:grant:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 服务授权更新', 'sand_iam:grant:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 服务授权撤销', 'sand_iam:grant:revoke')]
    public function revoke(Request $request): Response { $model = $this->find($request); $model->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]); $this->audit('revoke', (int) $model->id, $request); return $this->success('已撤销'); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['workload_client_id']) && !WorkloadClient::where('id', (int) $payload['workload_client_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: workload client', 400); if (isset($payload['service_action_id']) && !ServiceAction::where('id', (int) $payload['service_action_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: service action', 400); if (isset($payload['quota_policy']) && !is_array($payload['quota_policy'])) throw new ApiException('SAND_IAM_VALIDATION_ERROR: quota_policy must be object', 400); if (isset($payload['network_policy']) && !is_array($payload['network_policy'])) throw new ApiException('SAND_IAM_VALIDATION_ERROR: network_policy must be object', 400); }
    protected function applyOrganizationScope(object $query, array $organizationIds): void { $applicationIds = Application::whereIn('organization_id', $organizationIds)->column('id'); $environmentIds = Environment::whereIn('application_id', $applicationIds)->column('id'); $query->whereIn('workload_client_id', WorkloadClient::whereIn('environment_id', $environmentIds)->column('id')); }
    protected function organizationIdForModel(object $model): ?int { $client = WorkloadClient::find($model->workload_client_id); $environment = $client ? Environment::find($client->environment_id) : null; $application = $environment ? Application::find($environment->application_id) : null; return $application ? (int) $application->organization_id : null; }
    protected function assertPayloadAccess(array $payload, ?object $existing = null): void { $clientId = (int) ($payload['workload_client_id'] ?? $existing?->workload_client_id ?? 0); $client = WorkloadClient::find($clientId); $environment = $client ? Environment::find($client->environment_id) : null; $application = $environment ? Application::find($environment->application_id) : null; $this->access()->assertOrganization($application ? (int) $application->organization_id : 0); }
}
