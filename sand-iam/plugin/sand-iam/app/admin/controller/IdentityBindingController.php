<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use think\facade\Db;

final class IdentityBindingController extends BaseController
{
    #[Permission('SandIAM 身份绑定列表', 'sand_iam:identity_binding:index')]
    public function index(Request $request): Response
    {
        $identity = $this->identity((int) $request->input('identity_id', 0));
        $bindings = IdentityBinding::where('identity_id', $identity->id)->order('id', 'desc')->select();
        $result = [];
        foreach ($bindings as $binding) {
            $result[] = $this->bindingPayload($binding);
        }
        return $this->success($result);
    }

    #[Permission('SandIAM 身份绑定读取', 'sand_iam:identity_binding:read')]
    public function read(Request $request): Response
    {
        return $this->success($this->bindingPayload($this->binding((int) $request->input('id', 0))));
    }

    #[Permission('SandIAM 身份绑定保存', 'sand_iam:identity_binding:save')]
    public function save(Request $request): Response
    {
        $identity = $this->identity((int) $request->post('identity_id', 0));
        $subject = $request->post('subject', '');
        if (!is_string($subject) || $subject === '' || strlen($subject) > 191 || !preg_match('//u', $subject) || preg_match('/[\p{Cc}]/u', $subject) || preg_match('/^\s|\s$/u', $subject)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 身份主体必须是无首尾空白和控制字符的 UTF-8 文本，且不得超过 191 字节', 400);
        }
        $provider = $this->provider($identity, $request);
        Db::startTrans();
        try {
            $provider = $this->lockedMountedProvider((int) $provider->id, (int) $identity->application_id);
            $identity = Identity::where('id', (int) $identity->id)->where('application_id', (int) $identity->application_id)->where('status', 1)->lock(true)->find();
            if ($identity === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选应用用户身份已停用，请刷新后重试', 400);
            $binding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $identity->application_id)->where('subject', $subject)->lock(true)->find();
            if ($binding && (int) $binding->identity_id !== (int) $identity->id) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 此身份源下的主体已绑定到其他身份', 400);
            if ($binding) $binding->save(['status' => 1]);
            else $binding = IdentityBinding::create(['application_id' => $identity->application_id, 'identity_id' => $identity->id, 'identity_provider_id' => $provider->id, 'subject' => $subject, 'status' => 1]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains($exception->getMessage(), 'uk_sand_iam_identity_binding_provider_subject')) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 此身份源下的主体已绑定到其他身份', 400);
            throw $exception;
        }
        $this->audit('identity_binding.save', (int) $binding->id, $identity, $request);
        return $this->success(['id' => (int) $binding->id], '已保存');
    }

    #[Permission('SandIAM 身份绑定更新', 'sand_iam:identity_binding:update')]
    public function update(Request $request): Response
    {
        $binding = $this->binding((int) $request->post('id', 0));
        $identity = $this->identity((int) $binding->identity_id);
        $status = (int) $request->post('status', 0);
        if (!in_array($status, [1, 2], true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 状态只能选择已启用或已停用', 400);
        }
        Db::startTrans();
        try {
            $provider = $this->lockedMountedProvider((int) $binding->identity_provider_id, (int) $identity->application_id);
            $identity = Identity::where('id', (int) $identity->id)->where('application_id', (int) $identity->application_id)->where('status', 1)->lock(true)->find();
            $binding = $identity === null ? null : IdentityBinding::where('id', (int) $binding->id)->where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $identity->application_id)->lock(true)->find();
            if ($identity === null || $binding === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 身份绑定不存在或已不可用，请刷新后重试', 400);
            $binding->save(['status' => $status]);
            if ($status === 2) $this->revokeBindingSessions((int) $binding->id);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        $this->audit('identity_binding.update', (int) $binding->id, $identity, $request);
        return $this->success('已更新');
    }

    #[Permission('SandIAM 身份绑定停用', 'sand_iam:identity_binding:disable')]
    public function disable(Request $request): Response
    {
        $binding = $this->binding((int) $request->post('id', 0));
        $identity = $this->identity((int) $binding->identity_id);
        Db::startTrans();
        try {
            $provider = $this->lockedMountedProvider((int) $binding->identity_provider_id, (int) $identity->application_id);
            $identity = Identity::where('id', (int) $identity->id)->where('application_id', (int) $identity->application_id)->where('status', 1)->lock(true)->find();
            $binding = $identity === null ? null : IdentityBinding::where('id', (int) $binding->id)->where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $identity->application_id)->lock(true)->find();
            if ($identity === null || $binding === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 身份绑定不存在或已不可用，请刷新后重试', 400);
            $binding->save(['status' => 2]);
            $this->revokeBindingSessions((int) $binding->id);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        $this->audit('identity_binding.disable', (int) $binding->id, $identity, $request);
        return $this->success('已停用');
    }

    private function binding(int $bindingId): IdentityBinding
    {
        $binding = IdentityBinding::findOrEmpty($bindingId);
        if ($bindingId <= 0 || $binding->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 身份绑定不存在或当前账号无权访问，请刷新列表后重试', 400); }
        $identity = $this->identity((int) $binding->identity_id);
        $provider = $this->mountedProvider((int) $binding->identity_provider_id, $identity);
        return $binding;
    }

    /**
     * Resolves an explicit provider ID or a legacy provider_code only when the
     * new schema already has a matching mounted provider.  Legacy fallback
     * must never write old-schema-shaped rows without organization, scope,
     * public_code and mount invariants.
     */
    private function provider(Identity $identity, Request $request): IdentityProvider
    {
        $providerId = (int) $request->post('identity_provider_id', 0);
        $providerCode = trim((string) $request->post('provider_code', ''));
        if ($providerId > 0) {
            $provider = $this->mountedProvider($providerId, $identity);
            if ($providerCode !== '' && $providerCode !== (string) $provider->code) {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 所选身份源与兼容标识不一致，请重新选择身份源', 400);
            }
            return $provider;
        }

        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $providerCode)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 身份源标识须以小写字母开头，只能包含小写字母、数字、点、下划线、冒号或短横线', 400);
        }

        $provider = IdentityProvider::where('organization_id', $this->organizationId($identity))->whereIn('id', IdentityProviderApplication::where('application_id', (int) $identity->application_id)->where('organization_id', $this->organizationId($identity))->where('status', 1)->column('identity_provider_id'))->where('code', $providerCode)->where('status', 1)->find();
        if ($provider !== null) return $this->mountedProvider((int) $provider->id, $identity);
        throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 兼容身份源不存在；请先在身份源管理中创建并挂载到当前接入应用', 400);
    }

    /** @return array<string, mixed> */
    private function bindingPayload(IdentityBinding $binding): array
    {
        $identity = $this->identity((int) $binding->identity_id);
        $provider = $this->mountedProvider((int) $binding->identity_provider_id, $identity);
        $payload = $binding->toArray();
        $payload['provider_code'] = (string) $provider->code;
        return $payload;
    }

    private function identity(int $identityId): Identity
    {
        $identity = Identity::where('id', $identityId)->where('status', 1)->find();
        $application = $identity ? Application::where('id', (int) $identity->application_id)->where('status', 1)->find() : null;
        $organization = $application ? Organization::where('id', (int) $application->organization_id)->where('status', 1)->find() : null;
        if (!$identity || !$application || !$organization) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选应用用户身份不存在、已停用，或所属接入应用不可用', 400); }
        $this->access()->assertApplication((int) $application->id);
        return $identity;
    }

    private function mountedProvider(int $providerId, Identity $identity): IdentityProvider
    {
        $provider = IdentityProvider::where('id', $providerId)->where('organization_id', $this->organizationId($identity))->where('status', 1)->find();
        $mount = $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $identity->application_id)->where('organization_id', $this->organizationId($identity))->where('status', 1)->find();
        if ($provider === null || $mount === null || ((string) $provider->scope_type === 'application' && (int) $provider->application_id !== (int) $identity->application_id)) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选身份源不存在、已停用或未挂载到当前接入应用，请刷新后重新选择', 400);
        }
        return $provider;
    }

    /** Locks provider → application → organization → mount before binding/session rows. */
    private function lockedMountedProvider(int $providerId, int $applicationId): IdentityProvider
    {
        $provider = IdentityProvider::where('id', $providerId)->where('status', 1)->lock(true)->find();
        $application = $provider === null ? null : Application::where('id', $applicationId)->where('organization_id', (int) $provider->organization_id)->where('status', 1)->lock(true)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
        $mount = $organization === null || $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('organization_id', (int) $organization->id)->where('status', 1)->lock(true)->find();
        if ($provider === null || $application === null || $organization === null || $mount === null || ((string) $provider->scope_type === 'application' && (int) $provider->application_id !== $applicationId)) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选身份源、接入应用、组织或挂载关系已停用，请刷新后重试', 400);
        }
        $this->access()->assertApplication((int) $application->id);
        return $provider;
    }

    private function organizationId(Identity $identity): int
    {
        $application = Application::find((int) $identity->application_id);
        return $application === null ? 0 : (int) $application->organization_id;
    }

    private function revokeBindingSessions(int $bindingId): void
    {
        $sessionIds = AuthSession::where('identity_binding_id', $bindingId)->where('status', 1)->lock(true)->column('id');
        if ($sessionIds === []) return;
        $now = date('Y-m-d H:i:s');
        AuthSession::whereIn('id', $sessionIds)->update(['status' => 2, 'revoked_time' => $now]);
        AuthRefreshToken::whereIn('session_id', $sessionIds)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
    }

    private function access(): AdminOrganizationAccess { return new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null); }
    private function audit(string $action, int $resourceId, Identity $identity, Request $request): void { $application = Application::find($identity->application_id); (new AuditWriter())->write('admin', (string) ($this->adminId ?? 0), $application ? (int) $application->organization_id : null, (int) $identity->application_id, $action, 'identity_binding', $resourceId, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12)))); }
}
