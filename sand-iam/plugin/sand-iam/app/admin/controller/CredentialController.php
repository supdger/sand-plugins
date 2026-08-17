<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\service\AuditWriter;
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
        if ($clientId <= 0 || $name === '') { throw new ApiException('SAND_IAM_VALIDATION_ERROR: workload_client_id and name are required', 400); }
        $this->enabledClient($clientId);
        $this->assertClientAccess($clientId);
        return $this->success($this->create($clientId, $name, $data['expire_time'] ?? null, $request), '凭证只显示一次');
    }

    #[Permission('SandIAM 轮换凭证', 'sand_iam:credential:rotate')]
    public function rotate(Request $request): Response
    {
        $old = $this->credential((int) $request->post('id', 0));
        $this->enabledClient((int) $old->workload_client_id);
        $old->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
        $this->audit('credential.rotate.revoke', (int) $old->id, $request);
        return $this->success($this->create((int) $old->workload_client_id, trim((string) $request->post('name', $old->name)), $request->post('expire_time', $old->expire_time), $request), '新凭证只显示一次');
    }

    #[Permission('SandIAM 撤销凭证', 'sand_iam:credential:revoke')]
    public function revoke(Request $request): Response
    {
        $credential = $this->credential((int) $request->post('id', 0));
        $credential->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
        $this->audit('credential.revoke', (int) $credential->id, $request);
        return $this->success('已撤销');
    }

    /** @return array{id:int,key_prefix:string,credential:string,expire_time:mixed} */
    private function create(int $clientId, string $name, mixed $expireTime, Request $request): array
    {
        $plain = 'siam_' . bin2hex(random_bytes(24));
        $credential = Credential::create(['workload_client_id' => $clientId, 'name' => $name, 'key_prefix' => substr($plain, 0, 16), 'secret_hash' => password_hash($plain, PASSWORD_DEFAULT), 'expire_time' => $expireTime ?: null, 'status' => 1]);
        $this->audit('credential.issue', (int) $credential->id, $request);
        return ['id' => (int) $credential->id, 'key_prefix' => (string) $credential->key_prefix, 'credential' => $plain, 'expire_time' => $credential->expire_time];
    }

    private function credential(int $id): Credential
    {
        $credential = Credential::findOrEmpty($id);
        if ($id <= 0 || $credential->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: credential', 400); }
        $this->assertClientAccess((int) $credential->workload_client_id);
        return $credential;
    }

    private function enabledClient(int $id): void { if (!WorkloadClient::where('id', $id)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: workload client', 400); }
    private function access(): AdminOrganizationAccess { return new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null); }
    private function assertClientAccess(int $clientId): void { $client = WorkloadClient::find($clientId); $environment = $client ? Environment::find($client->environment_id) : null; $application = $environment ? Application::find($environment->application_id) : null; $this->access()->assertOrganization($application ? (int) $application->organization_id : 0); }
    private function scopeCredentials(object $query): void { $access = $this->access(); if ($access->isSuperAdmin()) { return; } $organizationIds = $access->organizationIds(); if ($organizationIds === []) { $query->whereRaw('1 = 0'); return; } $applicationIds = Application::whereIn('organization_id', $organizationIds)->column('id'); $environmentIds = Environment::whereIn('application_id', $applicationIds)->column('id'); $query->whereIn('workload_client_id', WorkloadClient::whereIn('environment_id', $environmentIds)->column('id')); }
    private function audit(string $action, int $resourceId, Request $request): void { (new AuditWriter())->write('admin', (string) ($this->adminId ?? 0), null, null, $action, 'credential', $resourceId, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12)))); }
}
