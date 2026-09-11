<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\OidcSigningKeyService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** OIDC has one issuer-wide signer; application/environment partitions are not part of its token contract. */
final class OidcSigningKeyController extends BaseController
{
    #[Permission('SandIAM OIDC 签名密钥列表', 'sand_iam:oauth_client:index')]
    public function index(Request $request): Response
    {
        $this->access($request)->assertSuperAdmin();
        return $this->success((new OidcSigningKeyService())->list($this->actor($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM OIDC 签名密钥状态', 'sand_iam:oauth_client:read')]
    public function status(Request $request): Response
    {
        $this->access($request)->assertSuperAdmin();
        return $this->success((new OidcSigningKeyService())->status($this->actor($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM OIDC 签名密钥轮换', 'sand_iam:oauth_client:rotate')]
    public function rotate(Request $request): Response
    {
        $this->access($request)->assertSuperAdmin();
        $this->assertIssuerScope($request);
        $requestId = $this->requestId($request);
        $result = (new IdempotencyService())->execute('admin', $this->actor($request), 'oidc.signing_key.rotate', $requestId, IdempotencyService::fingerprint(['owner_scope' => 'issuer']), 'oidc_signing_key', function () use ($requestId, $request): array {
            $key = (new OidcSigningKeyService())->rotate($this->actor($request), $requestId, false);
            return ['resource_id' => (int) $key['id'], 'result' => $key];
        });
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；当前签名密钥未再次轮换' : '签名密钥已轮换；旧公钥将在验证宽限期内继续发布')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM OIDC 签名密钥退役', 'sand_iam:oauth_client:rotate')]
    public function retire(Request $request): Response
    {
        $this->access($request)->assertSuperAdmin();
        $this->assertIssuerScope($request);
        $id = (int) $request->post('id', 0);
        if ($id <= 0) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_NOT_FOUND', 404);
        $requestId = $this->requestId($request);
        $result = (new IdempotencyService())->execute('admin', $this->actor($request), 'oidc.signing_key.retire', $requestId, IdempotencyService::fingerprint(['id' => $id, 'owner_scope' => 'issuer']), 'oidc_signing_key', function () use ($id, $requestId, $request): array {
            $key = (new OidcSigningKeyService())->retire($id, $this->actor($request), $requestId, false);
            return ['resource_id' => $id, 'result' => $key];
        });
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；签名密钥状态未重复变更' : '签名密钥已退役，不再用于签发或 JWKS 发布')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    private function assertIssuerScope(Request $request): void
    {
        foreach (['application_id', 'environment_id'] as $field) {
            foreach ([$request->get($field, null), $request->post($field, null), $request->input($field, null)] as $value) {
                if (!in_array($value, [null, '', []], true)) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_SCOPE_UNSUPPORTED: 当前 OIDC issuer 不支持按应用或环境分区签名密钥', 400);
            }
        }
    }
    private function access(Request $request): AdminOrganizationAccess { $admin = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($admin) ? (int) ($admin['id'] ?? 0) : 0, is_array($admin) ? $admin : null); }
    private function actor(Request $request): string { $admin = $request->header('check_admin', []); return is_array($admin) ? (string) ($admin['id'] ?? 0) : '0'; }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
}
