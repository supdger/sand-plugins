<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\security\NetworkPolicy;
use plugin\SandIam\app\security\ServiceGrantConstraintNormalizer;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Application;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use think\facade\Db;

final class ServiceGrantController extends AdminResourceController
{
    protected string $modelClass = ServiceGrant::class;
    protected array $writeFields = ['workload_client_id', 'service_action_id', 'audience', 'quota_policy', 'data_class', 'network_policy', 'expire_time', 'status'];
    protected array $requiredFields = ['workload_client_id', 'service_action_id', 'audience'];
    protected string $resourceType = 'service_grant';
    protected bool $atomicCreateAudit = true;
    protected bool $atomicMutationAudit = true;
    protected function formatIndexPage(array $page): array
    {
        $rows = $page['data'] ?? [];
        if ($rows === []) return $page;
        $actionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['service_action_id'] ?? 0), $rows,
        ))));
        $actions = $actionIds === [] ? [] : ServiceAction::whereIn('id', $actionIds)->field('id,name,service_id')->select()->toArray();
        $serviceIds = array_values(array_unique(array_column($actions, 'service_id')));
        $services = $serviceIds === [] ? [] : Service::whereIn('id', $serviceIds)->field('id,name')->select()->toArray();
        $actionsById = array_column($actions, null, 'id');
        $servicesById = array_column($services, null, 'id');
        foreach ($rows as &$row) {
            $action = $actionsById[(int) ($row['service_action_id'] ?? 0)] ?? null;
            $row['service_action_name'] = $action['name'] ?? null;
            $row['service_name'] = $servicesById[(int) ($action['service_id'] ?? 0)]['name'] ?? null;
        }
        unset($row);
        $page['data'] = $rows;
        return $page;
    }
    #[Permission('SandIAM 服务授权服务候选', 'sand_iam:grant:index')]
    public function services(Request $request): Response
    {
        $this->assertPayloadAccess(['workload_client_id' => (int) $request->input('workload_client_id', 0)]);
        return $this->candidatePage(Service::where('status', 1)->field('id,code,name'), $request);
    }

    #[Permission('SandIAM 服务授权动作候选', 'sand_iam:grant:index')]
    public function actions(Request $request): Response
    {
        $this->assertPayloadAccess(['workload_client_id' => (int) $request->input('workload_client_id', 0)]);
        $serviceId = (int) $request->input('service_id', 0);
        $id = (int) $request->input('id', 0);
        if ($serviceId <= 0 && $id <= 0) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请先选择服务', 400);
        $query = ServiceAction::where('status', 1)
            ->whereIn('service_id', Service::where('status', 1)->column('id'))
            ->field('id,code,name,service_id');
        if ($serviceId > 0) $query->where('service_id', $serviceId);
        return $this->candidatePage($query, $request);
    }

    private function candidatePage(object $query, Request $request): Response
    {
        $id = (int) $request->input('id', 0);
        if ($id > 0) $query->where('id', $id);
        $keywords = trim((string) $request->input('keywords', ''));
        if ($keywords !== '') $query->whereLike('name', '%' . $keywords . '%');
        return $this->success($query->order('id', 'asc')->paginate([
            'page' => max(1, (int) $request->input('page', 1)),
            'list_rows' => min(100, max(1, (int) $request->input('limit', 20))),
        ])->toArray());
    }
    protected function applyIndexFilters(object $query, Request $request): void
    {
        $organizationId = (int) $request->input('organization_id', 0);
        $applicationId = (int) $request->input('application_id', 0);
        $environmentId = (int) $request->input('environment_id', 0);
        if ($organizationId <= 0 && $applicationId <= 0 && $environmentId <= 0) return;
        if ($organizationId > 0 || $applicationId > 0) {
            $applications = $organizationId > 0
                ? Application::where('organization_id', $organizationId)
                : Application::where('id', $applicationId);
            if ($applicationId > 0) $applications->where('id', $applicationId);
            $environments = Environment::whereIn('application_id', $applications->column('id'));
            if ($environmentId > 0) $environments->where('id', $environmentId);
        } else {
            $environments = Environment::where('id', $environmentId);
        }
        $query->whereIn('workload_client_id', WorkloadClient::whereIn('environment_id', $environments->column('id'))->column('id'));
    }
    #[Permission('SandIAM 服务授权列表', 'sand_iam:grant:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 服务授权读取', 'sand_iam:grant:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 服务授权保存', 'sand_iam:grant:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 服务授权更新', 'sand_iam:grant:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 服务授权停用', 'sand_iam:grant:revoke')]
    public function disable(Request $request): Response { return $this->revoke($request); }
    #[Permission('SandIAM 服务授权撤销', 'sand_iam:grant:revoke')]
    public function revoke(Request $request): Response
    {
        $model = $this->find($request);
        Db::startTrans();
        try {
            $model->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
            $this->audit('revoke', (int) $model->id, $request);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $this->success('已撤销');
    }
    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        if ($existing !== null) {
            foreach (['workload_client_id', 'service_action_id', 'audience'] as $field) {
                if (array_key_exists($field, $payload) && (string) $payload[$field] !== (string) $existing->{$field}) {
                    throw new ApiException('SAND_IAM_SERVICE_GRANT_IMMUTABLE: 服务调用身份、服务动作和受众创建后不可修改；请撤销后新建授权', 409);
                }
            }
        }
        $clientId = (int) ($payload['workload_client_id'] ?? $existing?->workload_client_id ?? 0);
        $client = WorkloadClient::where('id', $clientId)->where('status', 1)->find();
        if ($client === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 服务调用身份不存在或已停用', 400);
        $audience = trim((string) ($payload['audience'] ?? $existing?->audience ?? ''));
        if (!hash_equals((string) $client->audience, $audience)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 服务授权受众必须与服务调用身份完全一致', 400);
        $actionId = (int) ($payload['service_action_id'] ?? $existing?->service_action_id ?? 0);
        $action = ServiceAction::where('id', $actionId)->where('status', 1)->find();
        $service = $action === null ? null : Service::where('id', (int) $action->service_id)->where('status', 1)->find();
        if ($action === null || $service === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 服务或服务动作不存在或已停用', 400);
    }
    protected function normalizePayload(array $payload, ?object $existing = null): array
    {
        if (array_key_exists('audience', $payload)) {
            $payload['audience'] = trim((string) $payload['audience']);
            if ($payload['audience'] === '' || strlen($payload['audience']) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 服务受众必填且不能超过 128 字符', 400);
        }
        if (array_key_exists('network_policy', $payload)) $payload['network_policy'] = NetworkPolicy::normalize($payload['network_policy']);
        if (array_key_exists('quota_policy', $payload)) {
            $payload['quota_policy'] = ServiceGrantConstraintNormalizer::quota($payload['quota_policy']);
        } elseif ($existing !== null) {
            ServiceGrantConstraintNormalizer::quota($existing->quota_policy);
        }
        if (array_key_exists('data_class', $payload)) {
            $payload['data_class'] = ServiceGrantConstraintNormalizer::dataClass($payload['data_class']);
        } elseif ($existing !== null) {
            ServiceGrantConstraintNormalizer::dataClass($existing->data_class);
        }
        return $payload;
    }
    protected function scopeIndexToOrganizations(object $query): void
    {
        $access = $this->access();
        if ($access->isSuperAdmin()) return;
        $applicationIds = $access->applicationIds();
        if ($applicationIds === []) { $query->whereRaw('1 = 0'); return; }
        $environmentIds = Environment::whereIn('application_id', $applicationIds)->column('id');
        $query->whereIn('workload_client_id', WorkloadClient::whereIn('environment_id', $environmentIds)->column('id'));
    }
    protected function assertModelAccess(object $model): void
    {
        $this->assertPayloadAccess([], $model);
    }
    protected function organizationIdForModel(object $model): ?int { $client = WorkloadClient::find($model->workload_client_id); $environment = $client ? Environment::find($client->environment_id) : null; $application = $environment ? Application::find($environment->application_id) : null; return $application ? (int) $application->organization_id : null; }
    protected function auditScopeForModel(object $model): array
    {
        $client = WorkloadClient::find((int) ($model->workload_client_id ?? 0));
        $environment = $client ? Environment::find($client->environment_id) : null;
        $application = $environment ? Application::find($environment->application_id) : null;
        return $application ? [(int) $application->organization_id, (int) $application->id] : [null, null];
    }
    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        $clientId = (int) ($payload['workload_client_id'] ?? $existing?->workload_client_id ?? 0);
        $client = WorkloadClient::find($clientId);
        $environment = $client ? Environment::find($client->environment_id) : null;
        $this->access()->assertApplication($environment ? (int) $environment->application_id : 0);
    }
}
