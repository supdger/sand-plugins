<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\IdentityInvitation;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\service\IdentityInvitationService;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityInvitationController extends BaseController
{
    #[Permission('SandIAM 用户邀请列表', 'sand_iam:identity_invitation:index')]
    public function index(Request $request): Response
    {
        $this->enabled(); $applicationId = (int) $request->input('application_id', 0); $this->access($request)->assertApplication($applicationId);
        $query = IdentityInvitation::where('application_id', $applicationId)->order('id', 'desc');
        $state = (string) $request->input('state', ''); if ($state !== '') $query->where('state', $this->state($state));
        $result = $query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray();
        $result['data'] = array_map(fn (array $item): array => $this->safe($item), $result['data'] ?? []);
        return $this->success($result);
    }
    #[Permission('SandIAM 用户邀请读取', 'sand_iam:identity_invitation:read')]
    public function read(Request $request): Response { return $this->success($this->safe($this->invitation($request)->toArray(), true)); }
    #[Permission('SandIAM 用户邀请发送', 'sand_iam:identity_invitation:send')]
    public function send(Request $request): Response
    {
        $applicationId = (int) $request->post('application_id', 0); $this->access($request)->assertApplication($applicationId);
        $groups = $request->post('initial_group_ids', []); if (!is_array($groups)) throw new ApiException('SAND_IAM_INVITATION_GROUPS_INVALID', 400);
        $guestId = (int) $request->post('guest_identity_id', 0);
        $id = (new IdentityInvitationService())->create($applicationId, (string) $request->post('target_type', ''), (string) $request->post('target', ''), $groups, (int) $request->post('ttl_hours', 72), $this->actor($request), $this->requestId($request), $guestId > 0 ? $guestId : null);
        return $this->success(['id' => $id], '邀请已发送；目标地址和邀请令牌不会在后台回显');
    }
    #[Permission('SandIAM 用户邀请重发', 'sand_iam:identity_invitation:resend')]
    public function resend(Request $request): Response { $item = $this->invitation($request); (new IdentityInvitationService())->resend((int) $item->id, (int) $item->application_id, $this->actor($request), $this->requestId($request)); return $this->success('邀请已重发，之前的邀请链接已失效'); }
    #[Permission('SandIAM 用户邀请撤销', 'sand_iam:identity_invitation:revoke')]
    public function revoke(Request $request): Response { $item = $this->invitation($request); (new IdentityInvitationService())->revoke((int) $item->id, (int) $item->application_id, $this->actor($request), $this->requestId($request)); return $this->success('邀请已撤销'); }
    private function invitation(Request $request): IdentityInvitation { $this->enabled(); $item = IdentityInvitation::find((int) $request->input('id', $request->post('id', 0))); if ($item === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户邀请不存在', 404); $this->access($request)->assertApplication((int) $item->application_id); return $item; }
    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function safe(array $item, bool $detail = false): array
    {
        $groupIds = is_array($item['initial_group_ids'] ?? null) ? array_map('intval', $item['initial_group_ids']) : [];
        $groupNames = $groupIds === [] ? [] : IdentityGroup::whereIn('id', $groupIds)->where('application_id', (int) ($item['application_id'] ?? 0))->column('name');
        $guest = empty($item['guest_identity_id']) ? null : \plugin\SandIam\app\model\Identity::find((int) $item['guest_identity_id']);
        $result = ['id' => (int) ($item['id'] ?? 0), 'application_id' => (int) ($item['application_id'] ?? 0), 'target_type' => (string) ($item['target_type'] ?? ''), 'target_masked' => (string) ($item['target_masked'] ?? ''), 'guest_identity_name' => $guest ? (string) $guest->display_name : '', 'initial_group_names' => array_values(array_map('strval', $groupNames)), 'state' => (string) ($item['state'] ?? ''), 'expire_time' => $item['expire_time'] ?? null, 'delivered_time' => $item['delivered_time'] ?? null, 'consumed_time' => $item['consumed_time'] ?? null, 'delivery_error_code' => $item['delivery_error_code'] ?? null, 'status' => (int) ($item['status'] ?? 0)];
        if ($detail) { $result['initial_group_ids'] = $groupIds; $result['guest_identity_id'] = $guest ? (int) $guest->id : null; }
        return $result;
    }
    private function state(string $value): string { if (!in_array($value, ['sending', 'pending', 'delivery_failed', 'accepted', 'revoked', 'expired'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 邀请状态无效', 400); return $value; }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503); }
    private function access(Request $request): AdminOrganizationAccess { $token = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96); }
}
