<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\SecurityAlert;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class SecurityAlertController extends BaseController
{
    #[Permission('SandIAM 安全告警列表', 'sand_iam:security_alert:index')]
    public function index(Request $request): Response
    {
        $query = SecurityAlert::order('last_seen_time', 'desc');
        $access = $this->access($request);
        if (!$access->isSuperAdmin()) {
            $ids = $access->organizationIds();
            if ($ids === []) $query->whereRaw('1 = 0'); else $query->whereIn('organization_id', $ids);
        }
        $organizationId = (int) $request->get('organization_id', 0);
        if ($organizationId > 0) { $access->assertOrganization($organizationId); $query->where('organization_id', $organizationId); }
        $applicationId = (int) $request->get('application_id', 0);
        if ($applicationId > 0) { $access->assertApplication($applicationId); $query->where('application_id', $applicationId); }
        foreach (['status', 'severity', 'rule_code'] as $field) {
            $value = trim((string) $request->get($field, ''));
            if ($value !== '') $query->where($field, $value);
        }
        return $this->success($query->paginate(['page' => max(1, (int) $request->get('page', 1)), 'list_rows' => min(100, max(1, (int) $request->get('limit', 20)))])->toArray());
    }

    #[Permission('SandIAM 安全告警读取', 'sand_iam:security_alert:read')]
    public function read(Request $request): Response
    {
        $alert = $this->alert((int) $request->get('id', 0), $request);
        return $this->success($alert->toArray());
    }

    #[Permission('SandIAM 安全告警处理', 'sand_iam:security_alert:resolve')]
    public function resolve(Request $request): Response
    {
        $alert = $this->alert((int) $request->post('id', 0), $request);
        if ((string) $alert->status === 'resolved') throw new ApiException('SAND_IAM_SECURITY_ALERT_ALREADY_RESOLVED: 该告警已经处理', 409);
        $token = $request->header('check_admin', []);
        $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
        $alert->save(['status' => 'resolved', 'resolved_by' => $adminId, 'resolved_time' => date('Y-m-d H:i:s')]);
        (new AuditWriter())->write('admin', (string) $adminId, (int) $alert->organization_id, $alert->application_id === null ? null : (int) $alert->application_id, 'security_alert.resolve', 'security_alert', (int) $alert->id, 'succeeded', substr((string) $request->header('X-Request-Id', bin2hex(random_bytes(16))), 0, 96));
        return $this->success('告警已标记为已处理');
    }

    private function alert(int $id, Request $request): SecurityAlert
    {
        $alert = $id > 0 ? SecurityAlert::find($id) : null;
        if ($alert === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到安全告警', 404);
        $this->access($request)->assertOrganization((int) $alert->organization_id);
        if ($alert->application_id !== null) $this->access($request)->assertApplication((int) $alert->application_id);
        return $alert;
    }

    private function access(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null);
    }
}
