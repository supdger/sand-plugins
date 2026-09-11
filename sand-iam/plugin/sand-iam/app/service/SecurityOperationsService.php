<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\AuditArchive;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuditRetentionPolicy;
use plugin\SandIam\app\model\SecurityAlert;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class SecurityOperationsService
{
    /** @return array{archived:int} */
    public function archiveBatch(int $limit = 200): array
    {
        $limit = min(max($limit, 1), 1000);
        $archived = 0;
        foreach (AuditRetentionPolicy::where('status', 1)->order('id')->select() as $policy) {
            if ($archived >= $limit) break;
            $cutoff = date('Y-m-d H:i:s', time() - (int) $policy->archive_after_days * 86400);
            Db::startTrans();
            try {
                $rows = Db::table('sand_iam_audit_log')->alias('audit')
                    ->leftJoin('sand_iam_audit_archive archive', 'archive.original_audit_id = audit.id')
                    ->where('audit.organization_id', (int) $policy->organization_id)
                    ->where('audit.create_time', '<=', $cutoff)
                    ->whereNull('archive.id')
                    ->field('audit.*')
                    ->order('audit.id')
                    ->limit($limit - $archived)
                    ->lock('FOR UPDATE OF audit SKIP LOCKED')
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    AuditArchive::create([
                        'original_audit_id' => (int) $row['id'],
                        'actor_type' => (string) $row['actor_type'],
                        'actor_ref' => (string) $row['actor_ref'],
                        'organization_id' => $row['organization_id'] === null ? null : (int) $row['organization_id'],
                        'application_id' => $row['application_id'] === null ? null : (int) $row['application_id'],
                        'action' => (string) $row['action'],
                        'resource_type' => (string) $row['resource_type'],
                        'resource_id' => $row['resource_id'] === null ? null : (int) $row['resource_id'],
                        'outcome' => (string) $row['outcome'],
                        'request_id' => (string) $row['request_id'],
                        'context' => $this->context($row['context'] ?? null),
                        'original_create_time' => (string) $row['create_time'],
                        'archive_time' => date('Y-m-d H:i:s'),
                    ]);
                    $archived++;
                }
                Db::commit();
            } catch (\Throwable $exception) {
                Db::rollback();
                throw $exception;
            }
        }
        return ['archived' => $archived];
    }

    /** @return array{purged:int} */
    public function purgeBatch(int $organizationId, string $confirmation, int $limit = 200, string $requestId = ''): array
    {
        $policy = AuditRetentionPolicy::where('organization_id', $organizationId)->where('status', 1)->find();
        if ($policy === null || !(bool) $policy->purge_enabled || (int) config('plugin.sand-iam.app.audit_purge_enabled', 0) !== 1) {
            throw new ApiException('SAND_IAM_AUDIT_PURGE_DISABLED: 审计清除未同时获得主体策略与部署开关授权', 403);
        }
        $cutoff = date('Y-m-d', time() - (int) $policy->retention_days * 86400);
        $expected = hash('sha256', "sand-iam-audit-purge\0{$organizationId}\0{$cutoff}");
        if (!hash_equals($expected, $confirmation)) throw new ApiException('SAND_IAM_AUDIT_PURGE_CONFIRMATION_INVALID', 403);
        $limit = min(max($limit, 1), 1000);
        Db::startTrans();
        try {
            $archives = AuditArchive::where('organization_id', $organizationId)
                ->where('original_create_time', '<', $cutoff . ' 00:00:00')
                ->order('id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->select();
            $ids = [];
            foreach ($archives as $archive) $ids[] = (int) $archive->original_audit_id;
            if ($ids !== []) {
                AuditLog::where('organization_id', $organizationId)->whereIn('id', $ids)->delete();
                AuditArchive::whereIn('original_audit_id', $ids)->delete();
            }
            (new AuditWriter())->write('system', 'audit_retention_worker', $organizationId, null, 'audit.retention_purge', 'audit_archive', null, 'succeeded', $requestId !== '' ? substr($requestId, 0, 96) : 'purge_' . bin2hex(random_bytes(12)), ['purged_count' => count($ids), 'retention_cutoff' => $cutoff]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return ['purged' => count($ids)];
    }

    public function observeAudit(AuditLog $audit): void
    {
        if ((int) config('plugin.sand-iam.app.security_alert_enabled', 0) !== 1 || $audit->organization_id === null || !in_array((string) $audit->outcome, ['denied', 'failed'], true)) return;
        $policy = AuditRetentionPolicy::where('organization_id', (int) $audit->organization_id)->where('status', 1)->find();
        if ($policy === null) return;
        $since = date('Y-m-d H:i:s', time() - (int) $policy->alert_window_seconds);
        $count = AuditLog::where('organization_id', (int) $audit->organization_id)
            ->where('application_id', $audit->application_id)
            ->where('action', (string) $audit->action)
            ->whereIn('outcome', ['denied', 'failed'])
            ->where('create_time', '>=', $since)
            ->count();
        if ($count < (int) $policy->alert_failure_threshold) return;
        $key = (string) config('plugin.sand-iam.app.security_alert_fingerprint_key', '');
        if (strlen($key) < 32) throw new ApiException('SAND_IAM_SECURITY_ALERT_KEY_INVALID: 告警指纹密钥至少需要 32 字节', 503);
        $fingerprint = hash_hmac('sha256', implode('|', [(int) $audit->organization_id, (int) ($audit->application_id ?? 0), (string) $audit->action]), $key);
        Db::startTrans();
        try {
            $alert = SecurityAlert::where('organization_id', (int) $audit->organization_id)->where('fingerprint', $fingerprint)->where('status', 'open')->lock(true)->find();
            if ($alert === null) {
                $alert = SecurityAlert::create([
                    'organization_id' => (int) $audit->organization_id,
                    'application_id' => $audit->application_id === null ? null : (int) $audit->application_id,
                    'rule_code' => 'repeated_security_failure',
                    'severity' => $count >= (int) $policy->alert_failure_threshold * 3 ? 'critical' : 'high',
                    'fingerprint' => $fingerprint,
                    'occurrence_count' => $count,
                    'first_seen_time' => $since,
                    'last_seen_time' => date('Y-m-d H:i:s'),
                    'status' => 'open',
                ]);
                if ($audit->application_id !== null) {
                    (new AuditEventPublisher())->publishEvent((int) $audit->application_id, 'security.alert.raised', [
                        'alert_id' => (int) $alert->id,
                        'rule_code' => 'repeated_security_failure',
                        'severity' => (string) $alert->severity,
                        'request_id' => (string) $audit->request_id,
                    ]);
                }
            } else {
                $alert->save(['occurrence_count' => (int) $alert->occurrence_count + 1, 'last_seen_time' => date('Y-m-d H:i:s')]);
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (!str_contains(strtolower($exception->getMessage()), 'unique') && !str_contains($exception->getMessage(), '23505')) throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function context(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        return is_array($value) ? $value : [];
    }
}
