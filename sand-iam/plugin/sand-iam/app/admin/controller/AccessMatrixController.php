<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\service\AccessMatrixService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\app\cache\UserAuthCache;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * Home "applications × services" grid + one-click grant orchestration.
 * Grant writes reuse the existing service-grant permission; credential
 * issuance additionally requires the credential-issue permission.
 */
final class AccessMatrixController extends BaseController
{
    #[Permission('SandIAM 应用服务授权矩阵', 'sand_iam:grant:index')]
    public function index(Request $request): Response
    {
        return $this->success((new AccessMatrixService())->matrix($this->access()));
    }

    /** Idempotent entity-only provisioning: default organization + 律序 application + prod environment + sand-ai client. Grants stay an explicit decision. */
    #[Permission('SandIAM 默认实体就绪（一键接入前置）', 'sand_iam:grant:save')]
    public function ensureDefaults(Request $request): Response
    {
        if ($request->post() !== [] || $request->get() !== []) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 默认实体就绪不接受参数', 400);
        }
        $result = (new AccessMatrixService())->ensureDefaults(
            $this->adminId(),
            RequestId::fromRequest($request),
            $this->access(),
        );
        return $this->success($result['result'], '默认应用与环境已就绪');
    }

    #[Permission('SandIAM 一键授权应用使用服务', 'sand_iam:grant:save')]
    public function grant(Request $request): Response
    {
        $data = $request->post();
        $issueCredential = filter_var($data['issue_credential'] ?? false, FILTER_VALIDATE_BOOL);
        if ($issueCredential) {
            $this->assertPermission('sand_iam:credential:issue');
        }
        $result = (new AccessMatrixService())->grant(
            $data,
            $this->adminId(),
            RequestId::fromRequest($request),
            $this->access(),
        );
        $response = $this->success(
            $result['result'],
            $result['replayed'] ? '请求已处理' : '已授权',
        );
        if ($issueCredential) {
            // Plaintext credential is one-time — never cacheable.
            $response->withHeader('Cache-Control', 'no-store')
                ->withHeader('Pragma', 'no-cache');
        }
        return $response;
    }

    private function access(): AdminOrganizationAccess
    {
        $token = request()->header('check_admin', []);
        return new AdminOrganizationAccess(
            is_array($token) ? (int) ($token['id'] ?? 0) : 0,
            is_array($token) ? $token : null,
        );
    }

    private function adminId(): int
    {
        $token = request()->header('check_admin', []);
        return is_array($token) ? (int) ($token['id'] ?? 0) : 0;
    }

    /**
     * Secondary permission check mirroring host CheckAuth: super admins pass,
     * others must hold the slug in their cached auth list.
     */
    private function assertPermission(string $slug): void
    {
        $token = request()->header('check_admin', []);
        $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
        $isSuper = $adminId === 1 || (int) ($token['is_super'] ?? 0) === 1;
        if ($isSuper) return;
        if (!in_array($slug, UserAuthCache::getUserAuth($adminId), true)) {
            throw new ApiException('SAND_IAM_PERMISSION_DENIED: 当前账号没有签发调用密钥的权限', 403);
        }
    }
}
