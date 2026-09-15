<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class CredentialController extends BaseController
{
    #[Permission('SandIAM 凭证列表', 'sand_iam:credential:index')]
    public function index(Request $request): Response
    {
        $query = Credential::order('id', 'desc');
        $this->scopeCredentials($query);
        $organizationId = (int) $request->input('organization_id', 0);
        $applicationId = (int) $request->input('application_id', 0);
        $environmentId = (int) $request->input('environment_id', 0);
        if ($organizationId > 0 || $applicationId > 0 || $environmentId > 0) {
            if ($organizationId > 0 || $applicationId > 0) {
                $applications = $organizationId > 0
                    ? Application::where('organization_id', $organizationId)
                    : Application::where('id', $applicationId);
                if ($applicationId > 0) { $applications->where('id', $applicationId); }
                $environments = Environment::whereIn('application_id', $applications->column('id'));
                if ($environmentId > 0) { $environments->where('id', $environmentId); }
            } else {
                $environments = Environment::where('id', $environmentId);
            }
            $clientIds = WorkloadClient::whereIn('environment_id', $environments->column('id'))->column('id');
            $query->whereIn('workload_client_id', $clientIds);
        }
        $clientId = (int) $request->input('workload_client_id', 0);
        if ($clientId > 0) { $query->where('workload_client_id', $clientId); }
        return $this->success($query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray());
    }

    #[Permission('SandIAM 凭证读取', 'sand_iam:credential:read')]
    public function read(Request $request): Response
    {
        return $this->success($this->credential((int) $request->input('id', 0))->toArray());
    }

    #[Permission('SandIAM 签发凭证', 'sand_iam:credential:issue')]
    public function issue(Request $request): Response
    {
        $data = $request->post();
        $clientId = (int) ($data['workload_client_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($clientId <= 0 || $name === '') { throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请选择服务调用身份并填写凭证名称', 400); }
        $this->enabledClient($clientId);
        $this->assertClientAccess($clientId);
        $requestId = RequestId::fromRequest($request);
        $result = (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'credential.issue',
            $requestId,
            IdempotencyService::fingerprint(['workload_client_id' => $clientId, 'name' => $name, 'expire_time' => $data['expire_time'] ?? null]),
            'credential',
            fn (): array => ['resource_id' => ($issued = $this->create($clientId, $name, $data['expire_time'] ?? null, $requestId, $this->actor($request)))['id'], 'result' => $issued],
        );
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；调用凭证明文不会再次显示' : '凭证只显示一次')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM 轮换凭证', 'sand_iam:credential:rotate')]
    public function rotate(Request $request): Response
    {
        $old = $this->credential((int) $request->post('id', 0));
        $this->enabledClient((int) $old->workload_client_id);
        $name = trim((string) $request->post('name', $old->name));
        $expireTime = $request->post('expire_time', $old->expire_time);
        $requestId = RequestId::fromRequest($request);
        $result = (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'credential.rotate',
            $requestId,
            IdempotencyService::fingerprint(['id' => (int) $old->id, 'name' => $name, 'expire_time' => $expireTime]),
            'credential',
            function () use ($old, $name, $expireTime, $requestId, $request): array {
                $old->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
                $this->audit('credential.rotate.revoke', (int) $old->id, $requestId, $this->actor($request));
                $issued = $this->create((int) $old->workload_client_id, $name, $expireTime, $requestId, $this->actor($request));
                return ['resource_id' => $issued['id'], 'result' => $issued];
            },
        );
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；新调用凭证明文不会再次显示' : '新凭证只显示一次')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM 撤销凭证', 'sand_iam:credential:revoke')]
    public function revoke(Request $request): Response
    {
        $credential = $this->credential((int) $request->post('id', 0));
        $requestId = RequestId::fromRequest($request);
        (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'credential.revoke',
            $requestId,
            IdempotencyService::fingerprint(['id' => (int) $credential->id]),
            'credential',
            function () use ($credential, $requestId, $request): array {
                $credential->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
                $this->audit('credential.revoke', (int) $credential->id, $requestId, $this->actor($request));
                return ['resource_id' => (int) $credential->id, 'result' => ['id' => (int) $credential->id]];
            },
        );
        return $this->success('已撤销');
    }

    /** @return array{id:int,key_prefix:string,credential:string,expire_time:mixed} */
    private function create(int $clientId, string $name, mixed $expireTime, string $requestId, string $actor): array
    {
        [$organizationId, $applicationId] = $this->auditScopeForClient($clientId);
        return (new \plugin\SandIam\app\service\CredentialIssuanceService())->issue($clientId, $name, $expireTime, $requestId, $actor, $organizationId, $applicationId);
    }

    private function credential(int $id): Credential
    {
        $credential = Credential::findOrEmpty($id);
        if ($id <= 0 || $credential->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 调用凭证不存在或当前账号无权访问', 400); }
        $this->assertClientAccess((int) $credential->workload_client_id);
        return $credential;
    }

    private function enabledClient(int $id): void { if (!WorkloadClient::where('id', $id)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 服务调用身份不存在或已停用', 400); }
    private function access(): AdminOrganizationAccess { $token = request()->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, null); }
    private function assertClientAccess(int $clientId): void
    {
        $client = WorkloadClient::find($clientId);
        $environment = $client ? Environment::find($client->environment_id) : null;
        $this->access()->assertApplication($environment ? (int) $environment->application_id : 0);
    }
    private function scopeCredentials(object $query): void
    {
        $access = $this->access();
        if ($access->isSuperAdmin()) return;
        $applicationIds = $access->applicationIds();
        if ($applicationIds === []) { $query->whereRaw('1 = 0'); return; }
        $environmentIds = Environment::whereIn('application_id', $applicationIds)->column('id');
        $query->whereIn('workload_client_id', WorkloadClient::whereIn('environment_id', $environmentIds)->column('id'));
    }
    private function actor(Request $request): string { $admin = $request->header('check_admin', []); return is_array($admin) ? (string) ($admin['id'] ?? 0) : '0'; }
    /** @return array{0: ?int, 1: ?int} */
    private function auditScopeForClient(int $clientId): array
    {
        $client = WorkloadClient::find($clientId);
        $environment = $client ? Environment::find($client->environment_id) : null;
        $application = $environment ? Application::find($environment->application_id) : null;
        return $application ? [(int) $application->organization_id, (int) $application->id] : [null, null];
    }
    private function audit(string $action, int $resourceId, string $requestId, string $actor): void
    {
        $credential = Credential::find($resourceId);
        [$organizationId, $applicationId] = $this->auditScopeForClient((int) ($credential?->workload_client_id ?? 0));
        (new AuditWriter())->write('admin', $actor, $organizationId, $applicationId, $action, 'credential', $resourceId, 'succeeded', $requestId);
    }
}
