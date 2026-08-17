<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\AuditLog;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AuditLogController extends BaseController
{
    #[Permission('SandIAM 审计列表', 'sand_iam:audit:index')]
    public function index(Request $request): Response
    {
        $query = AuditLog::order('id', 'desc');
        $access = $this->access();
        if (!$access->isSuperAdmin()) { $organizationIds = $access->organizationIds(); $organizationIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('organization_id', $organizationIds); }
        foreach (['organization_id', 'application_id', 'actor_type', 'outcome'] as $field) { $value = $request->input($field, ''); if ($value !== '') { $query->where($field, $field === 'actor_type' || $field === 'outcome' ? (string) $value : (int) $value); } }
        return $this->success($query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray());
    }

    #[Permission('SandIAM 审计读取', 'sand_iam:audit:read')]
    public function read(Request $request): Response
    {
        $audit = AuditLog::findOrEmpty((int) $request->input('id', 0));
        if ($audit->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: audit', 400); }
        if (!$this->access()->isSuperAdmin()) { $this->access()->assertOrganization((int) $audit->organization_id); }
        return $this->success($audit->toArray());
    }

    private function access(): AdminOrganizationAccess { return new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null); }
}
