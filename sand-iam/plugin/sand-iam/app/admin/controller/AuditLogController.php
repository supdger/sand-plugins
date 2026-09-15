<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuditArchive;
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
        $this->applyAccessScope($query, $request);
        $this->applyFilters($query, $request);
        return $this->success($query->paginate([
            'page' => max(1, (int) $request->input('page', 1)),
            'list_rows' => min(100, max(1, (int) $request->input('limit', 20))),
        ])->toArray());
    }

    #[Permission('SandIAM 审计读取', 'sand_iam:audit:read')]
    public function read(Request $request): Response
    {
        $audit = AuditLog::findOrEmpty((int) $request->input('id', 0));
        if ($audit->isEmpty()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到该审计记录，可能已超出当前账号可查看的管理范围', 400);
        }
        $this->assertAuditAccess($audit, $request);
        return $this->success($audit->toArray());
    }

    #[Permission('SandIAM 审计归档列表', 'sand_iam:audit:archive_index')]
    public function archiveIndex(Request $request): Response
    {
        $query = AuditArchive::order('original_audit_id', 'desc');
        $this->applyAccessScope($query, $request);
        $this->applyFilters($query, $request, false, 'original_create_time');
        return $this->success($query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray());
    }

    #[Permission('SandIAM 审计归档读取', 'sand_iam:audit:archive_read')]
    public function archiveRead(Request $request): Response
    {
        $audit = AuditArchive::findOrEmpty((int) $request->input('id', 0));
        if ($audit->isEmpty()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到该归档记录', 404);
        $this->assertAuditAccess($audit, $request);
        return $this->success($audit->toArray());
    }

    #[Permission('SandIAM 审计导出', 'sand_iam:audit:export')]
    public function export(Request $request): Response
    {
        $query = AuditLog::order('id', 'desc');
        $this->applyAccessScope($query, $request);
        $this->applyFilters($query, $request, true);
        $rows = $query->limit(10_001)->select()->all();
        if (count($rows) > 10_000) {
            throw new ApiException('SAND_IAM_AUDIT_EXPORT_TOO_LARGE: 单次最多导出 10000 条，请缩小时间或筛选范围', 400);
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new ApiException('SAND_IAM_AUDIT_EXPORT_FAILED', 500);
        fputcsv($stream, ['审计ID', '发生时间', '接入应用ID', '操作者类型', '操作者标识', '操作', '资源类型', '资源ID', '结果', '请求标识', '上下文']);
        foreach ($rows as $audit) {
            $context = $audit->context;
            if (is_string($context)) $context = json_decode($context, true);
            fputcsv($stream, [
                (string) $audit->id,
                $this->csv((string) $audit->create_time),
                $audit->application_id === null ? '' : (string) $audit->application_id,
                $this->csv((string) $audit->actor_type),
                $this->csv((string) $audit->actor_ref),
                $this->csv((string) $audit->action),
                $this->csv((string) $audit->resource_type),
                $audit->resource_id === null ? '' : (string) $audit->resource_id,
                $this->csv((string) $audit->outcome),
                $this->csv((string) $audit->request_id),
                $this->csv(json_encode(is_array($context) ? $context : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($csv)) throw new ApiException('SAND_IAM_AUDIT_EXPORT_FAILED', 500);
        return response("\xEF\xBB\xBF" . $csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="sand-iam-audit-' . date('Ymd-His') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function applyAccessScope(object $query, Request $request): void
    {
        $access = $this->access($request);
        if ($access->isSuperAdmin()) return;
        $organizationIds = $access->organizationIds();
        $applicationIds = $access->applicationIds();
        if ($organizationIds === [] && $applicationIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where(function ($scope) use ($organizationIds, $applicationIds): void {
            if ($organizationIds !== []) $scope->whereIn('organization_id', $organizationIds);
            if ($applicationIds !== []) {
                if ($organizationIds !== []) $scope->whereOr('application_id', 'in', $applicationIds);
                else $scope->whereIn('application_id', $applicationIds);
            }
        });
    }

    private function applyFilters(object $query, Request $request, bool $export = false, string $timeField = 'create_time'): void
    {
        $organizationId = (int) $request->input('organization_id', 0);
        if ($organizationId > 0) {
            $query->where('organization_id', $organizationId);
        }
        $applicationId = (int) $request->input('application_id', 0);
        if ($applicationId > 0) {
            $this->access($request)->assertApplication($applicationId);
            $query->where('application_id', $applicationId);
        }
        $resourceIdInput = trim((string) $request->input('resource_id', ''));
        if ($resourceIdInput !== '') {
            $maximumInteger = (string) PHP_INT_MAX;
            if (preg_match('/^[1-9]\d*$/', $resourceIdInput) !== 1
                || strlen($resourceIdInput) > strlen($maximumInteger)
                || (strlen($resourceIdInput) === strlen($maximumInteger) && strcmp($resourceIdInput, $maximumInteger) > 0)) {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 对象编号必须是正整数', 400);
            }
            $query->where('resource_id', (int) $resourceIdInput);
        }
        foreach (['actor_type', 'actor_ref', 'outcome', 'action', 'resource_type', 'request_id'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') $query->where($field, mb_substr($value, 0, 96));
        }
        $from = $this->dateTime((string) $request->input('from', $request->input('from_time', '')), false);
        $to = $this->dateTime((string) $request->input('to', $request->input('to_time', '')), true);
        if ($from !== null) $query->where($timeField, '>=', $from);
        if ($to !== null) $query->where($timeField, '<=', $to);
        if ($from !== null && $to !== null && strtotime($from) > strtotime($to)) {
            throw new ApiException('SAND_IAM_AUDIT_TIME_RANGE_INVALID: 起始时间不能晚于结束时间', 400);
        }
        if ($export && ($from === null || $to === null || strtotime($to) - strtotime($from) > 31 * 86400)) {
            throw new ApiException('SAND_IAM_AUDIT_EXPORT_RANGE_REQUIRED: 导出必须选择不超过 31 天的起止时间', 400);
        }
    }

    private function assertAuditAccess(object $audit, Request $request): void
    {
        $access = $this->access($request);
        if ($access->isSuperAdmin()) return;
        if ($audit->application_id !== null && in_array((int) $audit->application_id, $access->applicationIds(), true)) return;
        $access->assertOrganization((int) $audit->organization_id);
    }

    private function dateTime(string $value, bool $endOfDay): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 时间格式须为 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS', 400);
        }
        return $value;
    }

    private function csv(string $value): string
    {
        return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    private function access(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null);
    }
}
