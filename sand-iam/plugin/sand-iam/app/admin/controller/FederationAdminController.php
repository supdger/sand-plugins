<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\service\FederationService;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\RequestId;
use plugin\SandIam\app\service\ScimService;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use think\facade\Db;

final class FederationAdminController
{
    #[Permission('SandIAM 创建联合身份源', 'sand_iam:federation:create')]
    public function createProvider(Request $request): Response
    {
        $organizationId = (int) $request->post('organization_id', 0);
        $applicationId = (int) $request->post('application_id', 0);
        $scope = (string) $request->post('scope_type', 'application');
        $code = trim((string) $request->post('code', ''));
        $name = trim((string) $request->post('name', ''));
        if ($organizationId <= 0 || !in_array($scope, ['application', 'organization'], true) || !preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $code) || $name === '') throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        $application = $applicationId > 0 ? Application::where('id', $applicationId)->where('status', 1)->find() : null;
        if (($scope === 'application' && ($application === null || (int) $application->organization_id !== $organizationId)) || ($scope === 'organization' && $applicationId !== 0)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if ($scope === 'application') $this->access($request)->assertApplication($applicationId);
        else $this->access($request)->assertOrganization($organizationId);
        try {
            Db::startTrans();
            $provider = IdentityProvider::create(['organization_id' => $organizationId, 'application_id' => $scope === 'application' ? $applicationId : null, 'scope_type' => $scope, 'code' => $code, 'name' => mb_substr($name, 0, 128), 'provider_type' => 'local', 'status' => 1]);
            $provider = IdentityProvider::where('id', (int) $provider->id)->lock(true)->find();
            if ($provider === null || !preg_match('/^[A-Za-z0-9_-]{20,128}$/', (string) $provider->public_code)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 500);
            if ($application !== null) IdentityProviderApplication::create(['identity_provider_id' => (int) $provider->id, 'application_id' => (int) $application->id, 'organization_id' => $organizationId, 'provider_scope_application_key' => $applicationId, 'status' => 1]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFLICT', 409);
            throw $exception;
        }
        return json(['id' => (int) $provider->id, 'public_code' => (string) $provider->public_code]);
    }

    #[Permission('SandIAM 联合身份配置', 'sand_iam:federation:configure')]
    public function configure(Request $request): Response
    {
        $provider = IdentityProvider::find((int) $request->post('provider_id', 0));
        if ($provider === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 404);
        $this->assertProviderManage($request, $provider);
        $config = $request->post('config', []); $mapping = $request->post('attribute_mapping', []);
        if (!is_array($config) || !is_array($mapping)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        (new FederationService())->configureProvider((int) $provider->id, (string) $request->post('provider_type', ''), $config, $mapping, (string) $request->post('conflict_policy', 'reject'), $this->requestId($request));
        return json(['code' => 200, 'msg' => '配置成功', 'data' => ['id' => (int) $provider->id]]);
    }

    #[Permission('SandIAM 身份源挂载应用', 'sand_iam:federation:mount')]
    public function mount(Request $request): Response
    {
        $provider = IdentityProvider::find((int) $request->post('provider_id', 0));
        $application = Application::find((int) $request->post('application_id', 0));
        if ($provider === null || $application === null || (int) $provider->status !== 1 || (int) $application->status !== 1 || (int) $provider->organization_id !== (int) $application->organization_id) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 404);
        if ((string) $provider->scope_type === 'organization') $this->access($request)->assertOrganization((int) $application->organization_id);
        else $this->access($request)->assertApplication((int) $application->id);
        if ((string) $provider->scope_type === 'application' && (int) $provider->application_id !== (int) $application->id) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        $key = (int) ($provider->scope_type === 'organization' ? 0 : $provider->application_id);
        try { $mount = IdentityProviderApplication::create(['identity_provider_id' => (int) $provider->id, 'application_id' => (int) $application->id, 'organization_id' => (int) $application->organization_id, 'provider_scope_application_key' => $key, 'status' => 1]); } catch (\Throwable $exception) { if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_ALREADY_MOUNTED', 409); throw $exception; }
        return json(['id' => (int) $mount->id]);
    }

    #[Permission('SandIAM 目录同步', 'sand_iam:federation:sync')]
    public function sync(Request $request): Response
    {
        [$provider, $applicationId] = $this->providerApplication($request, false);
        return json((new FederationService())->syncLdap((int) $provider->id, $applicationId, $this->requestId($request)));
    }

    #[Permission('SandIAM SCIM 令牌签发', 'sand_iam:scim:token_issue')]
    public function issueScimToken(Request $request): Response
    {
        [$provider, $applicationId] = $this->providerApplication($request, false);
        $payload = [
            'provider_id' => (int) $provider->id,
            'application_id' => $applicationId,
            'name' => (string) $request->post('name', ''),
            'expire_time' => $request->post('expire_time') === null ? null : (string) $request->post('expire_time'),
        ];
        $requestId = $this->requestId($request);
        $result = (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'scim.token_issue',
            $requestId,
            IdempotencyService::fingerprint($payload),
            'scim_token',
            function () use ($payload, $requestId): array {
                $issued = (new ScimService())->issueToken($payload['provider_id'], $payload['application_id'], $payload['name'], $requestId, $payload['expire_time'], false);
                return ['resource_id' => $issued['id'], 'result' => $issued];
            },
        );
        return json(['code' => 200, 'msg' => '签发成功', 'data' => $result['result']])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM SCIM 令牌列表', 'sand_iam:scim:token_index')]
    public function listScimTokens(Request $request): Response
    {
        [$provider, $applicationId] = $this->providerApplication($request, true);
        return json(['data' => (new ScimService())->listTokens((int) $provider->id, $applicationId)])->withHeader('Cache-Control', 'no-store');
    }

    #[Permission('SandIAM SCIM 令牌撤销', 'sand_iam:scim:token_revoke')]
    public function revokeScimToken(Request $request): Response
    {
        [$provider, $applicationId] = $this->providerApplication($request, false);
        $tokenId = (int) $request->post('token_id', 0);
        $requestId = $this->requestId($request);
        (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'scim.token_revoke',
            $requestId,
            IdempotencyService::fingerprint(['provider_id' => (int) $provider->id, 'application_id' => $applicationId, 'token_id' => $tokenId]),
            'scim_token',
            function () use ($provider, $applicationId, $tokenId, $requestId): array {
                (new ScimService())->revokeToken((int) $provider->id, $applicationId, $tokenId, $requestId, false);
                return ['resource_id' => $tokenId, 'result' => ['token_id' => $tokenId, 'revoked' => true]];
            },
        );
        return json(['code' => 200, 'msg' => '撤销成功', 'data' => ['revoked' => true]])
            ->withHeader('Cache-Control', 'no-store');
    }

    private function assertProviderManage(Request $request, IdentityProvider $provider): void
    {
        if ((string) $provider->scope_type === 'application' && (int) $provider->application_id > 0) {
            $this->access($request)->assertApplication((int) $provider->application_id);
            return;
        }
        $this->access($request)->assertOrganization((int) $provider->organization_id);
    }

    /** @return array{0:IdentityProvider,1:int} */
    private function providerApplication(Request $request, bool $input): array
    {
        $providerId = (int) ($input ? $request->input('provider_id', 0) : $request->post('provider_id', 0));
        $applicationId = (int) ($input ? $request->input('application_id', 0) : $request->post('application_id', 0));
        $provider = IdentityProvider::where('id', $providerId)->where('status', 1)->find();
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        $mount = $provider === null || $application === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)
            ->where('application_id', (int) $application->id)
            ->where('organization_id', (int) $application->organization_id)
            ->where('status', 1)
            ->find();
        if ($provider === null || $application === null || $mount === null || (int) $provider->organization_id !== (int) $application->organization_id) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 身份源未挂载到所选接入应用或已停用', 404);
        }
        $this->access($request)->assertApplication((int) $application->id);
        return [$provider, (int) $application->id];
    }

    private function access(Request $request): AdminOrganizationAccess { $token = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return is_array($token) ? (string) ($token['id'] ?? 0) : '0'; }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
}
