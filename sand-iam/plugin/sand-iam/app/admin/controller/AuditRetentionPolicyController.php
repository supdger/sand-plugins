<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\AuditRetentionPolicy;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AuditRetentionPolicyController extends AdminResourceController
{
    protected string $modelClass = AuditRetentionPolicy::class;
    protected array $writeFields = ['organization_id', 'archive_after_days', 'retention_days', 'purge_enabled', 'alert_window_seconds', 'alert_failure_threshold', 'status'];
    protected array $requiredFields = ['organization_id'];
    protected string $resourceType = 'audit_retention_policy';

    #[Permission('SandIAM 审计保留策略列表', 'sand_iam:audit_retention_policy:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 审计保留策略读取', 'sand_iam:audit_retention_policy:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 审计保留策略保存', 'sand_iam:audit_retention_policy:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 审计保留策略更新', 'sand_iam:audit_retention_policy:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 审计保留策略停用', 'sand_iam:audit_retention_policy:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $organizationId = (int) ($payload['organization_id'] ?? $existing?->organization_id ?? 0);
        if (!Organization::where('id', $organizationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 客户主体不存在或已停用', 400);
        if ($existing === null && AuditRetentionPolicy::where('organization_id', $organizationId)->find()) throw new ApiException('SAND_IAM_AUDIT_RETENTION_POLICY_EXISTS: 该客户主体已有审计策略，请直接编辑', 409);
        $archive = (int) ($payload['archive_after_days'] ?? $existing?->archive_after_days ?? 90);
        $retention = (int) ($payload['retention_days'] ?? $existing?->retention_days ?? 365);
        $window = (int) ($payload['alert_window_seconds'] ?? $existing?->alert_window_seconds ?? 300);
        $threshold = (int) ($payload['alert_failure_threshold'] ?? $existing?->alert_failure_threshold ?? 5);
        if ($archive < 1 || $archive > 3650 || $retention < $archive || $retention > 3650) throw new ApiException('SAND_IAM_AUDIT_RETENTION_INVALID: 归档须为 1–3650 天，保留期不得短于归档期', 400);
        if ($window < 60 || $window > 86400 || $threshold < 2 || $threshold > 10000) throw new ApiException('SAND_IAM_SECURITY_ALERT_POLICY_INVALID: 告警窗口或触发次数超出范围', 400);
    }
}
