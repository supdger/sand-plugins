<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\OAuthRegistrationToken;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\OAuthDynamicRegistrationService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class OAuthRegistrationTokenController extends BaseController
{
    #[Permission('SandIAM 动态注册令牌列表', 'sand_iam:oauth_registration_token:index')]
    public function index(Request $request): Response
    {
        $applicationId = (int) $request->get('application_id', 0);
        $this->access($request)->assertApplication($applicationId);
        $page = OAuthRegistrationToken::where('application_id', $applicationId)
            ->order('id', 'desc')
            ->paginate([
                'page' => max(1, (int) $request->get('page', 1)),
                'list_rows' => min(100, max(1, (int) $request->get('limit', 20))),
            ])
            ->toArray();
        $page['data'] = array_map(fn (array $item): array => $this->safe($item), $page['data'] ?? []);
        return $this->success($page);
    }

    #[Permission('SandIAM 签发动态注册令牌', 'sand_iam:oauth_registration_token:issue')]
    public function issue(Request $request): Response
    {
        $applicationId = (int) $request->post('application_id', 0);
        $this->access($request)->assertApplication($applicationId);
        $hosts = $request->post('allowed_redirect_hosts', []);
        $scopes = $request->post('allowed_scopes', []);
        if (!is_array($hosts) || !is_array($scopes)) throw new ApiException('SAND_IAM_DCR_TOKEN_POLICY_INVALID', 400);
        $payload = [
            'application_id' => $applicationId,
            'name' => (string) $request->post('name', ''),
            'allowed_redirect_hosts' => $hosts,
            'allowed_scopes' => $scopes,
            'ttl_hours' => (int) $request->post('ttl_hours', 24),
            'max_uses' => (int) $request->post('max_uses', 1),
        ];
        $requestId = $this->requestId($request);
        $result = (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'oauth.dynamic_registration_token.issue',
            $requestId,
            IdempotencyService::fingerprint($payload),
            'oauth_registration_token',
            function () use ($payload, $requestId, $request): array {
                $issued = (new OAuthDynamicRegistrationService())->issueToken(
                    $payload['application_id'],
                    $payload['name'],
                    $payload['allowed_redirect_hosts'],
                    $payload['allowed_scopes'],
                    $payload['ttl_hours'],
                    $payload['max_uses'],
                    $this->actor($request),
                    $requestId,
                );
                return ['resource_id' => $issued['id'], 'result' => $issued];
            },
        );
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；动态注册令牌不会再次显示。' : '动态注册令牌仅此一次展示，请交给指定客户端并安全保存。')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM 撤销动态注册令牌', 'sand_iam:oauth_registration_token:revoke')]
    public function revoke(Request $request): Response
    {
        $id = (int) $request->post('id', 0);
        $token = OAuthRegistrationToken::find($id);
        if ($token === null) throw new ApiException('SAND_IAM_DCR_TOKEN_NOT_FOUND', 404);
        $this->access($request)->assertApplication((int) $token->application_id);
        $requestId = $this->requestId($request);
        (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'oauth.dynamic_registration_token.revoke',
            $requestId,
            IdempotencyService::fingerprint(['id' => $id, 'application_id' => (int) $token->application_id]),
            'oauth_registration_token',
            function () use ($id, $token, $requestId, $request): array {
                (new OAuthDynamicRegistrationService())->revokeToken($id, (int) $token->application_id, $this->actor($request), $requestId);
                return ['resource_id' => $id, 'result' => ['id' => $id, 'revoked' => true]];
            },
        );
        return $this->success('动态注册令牌已撤销');
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function safe(array $item): array
    {
        $maxUses = (int) ($item['max_uses'] ?? 0);
        $usedCount = (int) ($item['used_count'] ?? 0);
        return [
            'id' => (int) ($item['id'] ?? 0),
            'application_id' => (int) ($item['application_id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'allowed_redirect_hosts' => array_values(is_array($item['allowed_redirect_hosts'] ?? null) ? $item['allowed_redirect_hosts'] : []),
            'allowed_scopes' => array_values(is_array($item['allowed_scopes'] ?? null) ? $item['allowed_scopes'] : []),
            'max_uses' => $maxUses,
            'used_count' => $usedCount,
            'remaining_uses' => max(0, $maxUses - $usedCount),
            'expire_time' => $item['expire_time'] ?? null,
            'last_used_time' => $item['last_used_time'] ?? null,
            'status' => (int) ($item['status'] ?? 0),
        ];
    }

    private function access(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null);
    }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
}
