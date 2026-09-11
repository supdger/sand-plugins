<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\IdentityImportJob;
use plugin\SandIam\app\model\IdentityImportRow;
use plugin\SandIam\app\model\IdentityInvitation;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdentityImportService
{
    private const HEADERS = ['用户名', '显示名称', '邮箱', '手机号', '账号状态', '用户组代码'];
    public function __construct(private readonly ImportRowCipher $cipher = new ImportRowCipher(), private readonly AuditWriter $audit = new AuditWriter()) {}

    /** @return array{id:int,digest:string,total:int,valid:int,invalid:int} */
    public function preview(int $applicationId, string $filename, string $csv, string $mode, string $actor, string $requestId): array
    {
        $this->enabled(); $application = $this->application($applicationId); if (!in_array($mode, ['create', 'update'], true)) throw new ApiException('SAND_IAM_IMPORT_MODE_INVALID', 400);
        if ($csv === '' || strlen($csv) > 2_097_152 || !mb_check_encoding($csv, 'UTF-8')) throw new ApiException('SAND_IAM_IMPORT_FILE_INVALID', 400);
        $digest = hash('sha256', $csv); $rows = $this->parse($csv, $applicationId, $mode); $valid = count(array_filter($rows, static fn (array $row): bool => $row['errors'] === []));
        Db::startTrans();
        try {
            $job = IdentityImportJob::create(['application_id' => $applicationId, 'original_name' => mb_substr(basename($filename), 0, 255), 'content_digest' => $digest, 'mode' => $mode, 'state' => 'previewed', 'total_count' => count($rows), 'valid_count' => $valid, 'invalid_count' => count($rows) - $valid, 'success_count' => 0, 'warning_count' => 0, 'failure_count' => 0, 'created_by' => $actor, 'status' => 1]);
            foreach ($rows as $row) IdentityImportRow::create(['import_job_id' => (int) $job->id, 'application_id' => $applicationId, 'row_number' => $row['row_number'], 'idempotency_key' => hash('sha256', $digest . '|' . $row['row_number']), 'encrypted_payload' => $this->cipher->encrypt($row['payload']), 'summary' => $row['summary'], 'validation_errors' => $row['errors'], 'state' => $row['errors'] === [] ? 'valid' : 'invalid', 'status' => 1]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        $this->writeAudit($application, 'identity_import.preview', (int) $job->id, $actor, $requestId, 'succeeded', ['total' => count($rows), 'valid' => $valid]);
        return ['id' => (int) $job->id, 'digest' => $digest, 'total' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid];
    }

    /** @return array{state:string,success:int,warning:int,failure:int} */
    public function confirm(int $jobId, int $applicationId, string $digest, string $actor, string $requestId): array
    {
        $this->enabled(); $application = $this->application($applicationId);
        Db::startTrans();
        try {
            $job = IdentityImportJob::where('id', $jobId)->where('application_id', $applicationId)->lock(true)->find();
            if ($job === null || !hash_equals((string) $job->content_digest, $digest)) throw new ApiException('SAND_IAM_IMPORT_CONFIRMATION_MISMATCH', 409);
            if ((string) $job->state === 'completed') { Db::commit(); return ['state' => 'completed', 'success' => (int) $job->success_count, 'warning' => (int) $job->warning_count, 'failure' => (int) $job->failure_count]; }
            if (!in_array((string) $job->state, ['previewed', 'partial', 'failed'], true)) throw new ApiException('SAND_IAM_IMPORT_NOT_CONFIRMABLE', 409);
            if ((int) $job->invalid_count > 0) throw new ApiException('SAND_IAM_IMPORT_HAS_INVALID_ROWS', 409);
            $job->save(['state' => 'running', 'confirmed_time' => date('Y-m-d H:i:s')]); Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }

        foreach (IdentityImportRow::where('import_job_id', $jobId)->whereIn('state', ['valid', 'failed'])->order('row_number', 'asc')->select() as $row) $this->executeRow($job, $row, $actor, $requestId);
        $success = IdentityImportRow::where('import_job_id', $jobId)->where('state', 'succeeded')->count(); $warning = IdentityImportRow::where('import_job_id', $jobId)->where('state', 'succeeded_with_warning')->count(); $failure = IdentityImportRow::where('import_job_id', $jobId)->where('state', 'failed')->count();
        $state = $failure === 0 ? 'completed' : (($success + $warning) > 0 ? 'partial' : 'failed'); $job->save(['state' => $state, 'success_count' => $success, 'warning_count' => $warning, 'failure_count' => $failure, 'completed_time' => date('Y-m-d H:i:s')]);
        $this->writeAudit($application, 'identity_import.confirm', $jobId, $actor, $requestId, $failure === 0 ? 'succeeded' : 'failed', ['success' => $success, 'warning' => $warning, 'failure' => $failure]);
        return ['state' => $state, 'success' => $success, 'warning' => $warning, 'failure' => $failure];
    }

    public function export(int $applicationId, bool $sensitive, string $actor, string $requestId): string
    {
        $this->enabled(); $application = $this->application($applicationId); $identities = Identity::where('application_id', $applicationId)->order('id', 'asc')->limit(10_001)->select();
        if (count($identities) > 10_000) throw new ApiException('SAND_IAM_IDENTITY_EXPORT_TOO_LARGE: 单次最多导出 10000 个应用用户', 400);
        $stream = fopen('php://temp', 'w+'); if ($stream === false) throw new ApiException('SAND_IAM_IDENTITY_EXPORT_FAILED', 500);
        fputcsv($stream, ['用户名', '显示名称', '邮箱', '手机号', '账号状态', '用户组代码'], ',', '"', '');
        foreach ($identities as $identity) {
            $auth = IdentityAuth::where('application_id', $applicationId)->where('identity_id', (int) $identity->id)->find(); $email = $auth ? (string) ($auth->email ?? '') : ''; $phone = $auth ? (string) ($auth->phone ?? '') : '';
            if (!$sensitive) { if ($email !== '') $email = $this->mask('email', $email); if ($phone !== '') $phone = $this->mask('phone', $phone); }
            $groupIds = IdentityGroupMember::where('application_id', $applicationId)->where('identity_id', (int) $identity->id)->where('status', 1)->column('identity_group_id'); $groupCodes = $groupIds === [] ? [] : IdentityGroup::whereIn('id', $groupIds)->where('application_id', $applicationId)->where('status', 1)->column('code');
            $state = match ((string) ($identity->lifecycle_state ?? ((int) $identity->status === 1 ? 'active' : 'disabled'))) { 'pending' => '等待邀请', 'active' => '正常', 'disabled' => '停用', 'guest' => '访客', 'deleted' => '已删除', default => '未知' };
            fputcsv($stream, [$this->csv($auth ? (string) $auth->username : (string) $identity->code), $this->csv((string) $identity->display_name), $this->csv($email), $this->csv($phone), $state, $this->csv(implode('|', array_map('strval', $groupCodes)))], ',', '"', '');
        }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream); if (!is_string($csv)) throw new ApiException('SAND_IAM_IDENTITY_EXPORT_FAILED', 500);
        $this->writeAudit($application, $sensitive ? 'identity_export.sensitive' : 'identity_export.masked', 0, $actor, $requestId, 'succeeded', ['count' => count($identities)]);
        return "\xEF\xBB\xBF" . $csv;
    }

    private function executeRow(IdentityImportJob $job, IdentityImportRow $row, string $actor, string $requestId): void
    {
        $row->save(['state' => 'processing', 'error_code' => null]); $transactionOpen = false;
        try {
            $payload = $this->cipher->decrypt((string) $row->encrypted_payload); $applicationId = (int) $job->application_id;
            if ((string) $job->mode === 'create') {
                $targetType = (string) $payload['email'] !== '' ? 'email' : 'phone'; $target = (string) $payload[$targetType];
                $invitationId = (new IdentityInvitationService())->create($applicationId, $targetType, $target, $payload['group_ids'], 72, $actor, $requestId, null, false);
                $invitation = IdentityInvitation::find($invitationId); $warning = $invitation === null || (string) $invitation->state !== 'pending';
                $row->save(['state' => $warning ? 'succeeded_with_warning' : 'succeeded', 'result_invitation_id' => $invitationId, 'encrypted_payload' => null]);
                return;
            }
            Db::startTrans(); $transactionOpen = true;
            $auth = $this->existingAuth($applicationId, $payload); $identity = $auth ? Identity::where('id', (int) $auth->identity_id)->where('application_id', $applicationId)->lock(true)->find() : null;
            if ($identity === null) throw new ApiException('SAND_IAM_IMPORT_IDENTITY_NOT_FOUND', 404);
            if ((string) ($identity->lifecycle_state ?? '') === 'guest' || (string) ($identity->lifecycle_state ?? '') === 'deleted') throw new ApiException('SAND_IAM_IMPORT_IDENTITY_STATE_CONFLICT', 409);
            $identity->save(['display_name' => (string) $payload['display_name']]);
            $lifecycle = new IdentityLifecycleService();
            if ((string) $payload['account_state'] === 'disabled' && (int) $identity->status === 1) $lifecycle->disable((int) $identity->id, $applicationId, $actor, $requestId, false);
            if ((string) $payload['account_state'] === 'active' && (string) ($identity->lifecycle_state ?? '') === 'disabled') $lifecycle->enable((int) $identity->id, $applicationId, $actor, $requestId, false);
            $groupIds = array_values(array_unique(array_map('intval', is_array($payload['group_ids'] ?? null) ? $payload['group_ids'] : [])));
            foreach (IdentityGroupMember::where('identity_id', (int) $identity->id)->where('application_id', $applicationId)->lock(true)->select() as $member) if (!in_array((int) $member->identity_group_id, $groupIds, true) && (int) $member->status === 1) $member->save(['status' => 2]);
            foreach ($groupIds as $groupId) { $member = IdentityGroupMember::where('identity_group_id', $groupId)->where('identity_id', (int) $identity->id)->lock(true)->find(); if ($member === null) IdentityGroupMember::create(['identity_group_id' => $groupId, 'application_id' => $applicationId, 'identity_id' => (int) $identity->id, 'status' => 1]); elseif ((int) $member->status !== 1) $member->save(['status' => 1]); }
            (new IdentityEventPublisher())->publish($this->application($applicationId), $identity, 'identity.updated', ['display_name', 'groups'], $requestId);
            $row->save(['state' => 'succeeded', 'result_identity_id' => (int) $identity->id, 'encrypted_payload' => null]); Db::commit(); $transactionOpen = false;
        } catch (\Throwable $exception) { if ($transactionOpen) Db::rollback(); preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $exception->getMessage(), $matches); $row->save(['state' => 'failed', 'error_code' => $matches[1] ?? 'SAND_IAM_IMPORT_ROW_FAILED']); }
    }

    /** @return list<array{row_number:int,payload:array<string,mixed>,summary:array<string,mixed>,errors:list<string>}> */
    private function parse(string $csv, int $applicationId, string $mode): array
    {
        $stream = fopen('php://temp', 'r+'); if ($stream === false) throw new ApiException('SAND_IAM_IMPORT_FILE_INVALID', 400); fwrite($stream, $csv); rewind($stream);
        $headers = fgetcsv($stream, null, ',', '"', ''); if (!is_array($headers)) throw new ApiException('SAND_IAM_IMPORT_FILE_INVALID', 400); $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        if ($headers !== self::HEADERS) throw new ApiException('SAND_IAM_IMPORT_HEADERS_INVALID', 400);
        $rows = []; $number = 1;
        while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $number++; if ($values === [null] || $values === []) continue; if (count($values) !== count(self::HEADERS)) { $rows[] = ['row_number' => $number, 'payload' => [], 'summary' => ['row_number' => $number], 'errors' => ['列数与模板不一致']]; continue; }
            if (count($rows) >= 10_000) throw new ApiException('SAND_IAM_IMPORT_TOO_MANY_ROWS', 400);
            $data = array_combine(self::HEADERS, array_map(static fn (mixed $value): string => trim((string) $value), $values)); if (!is_array($data)) throw new ApiException('SAND_IAM_IMPORT_FILE_INVALID', 400);
            $errors = []; foreach ($data as $field => $value) if (preg_match('/^[=@]/u', $value) || ($field !== '手机号' && preg_match('/^[+\-]/u', $value))) { $errors[] = '单元格不能以公式字符开头'; break; }
            $username = strtolower($data['用户名']); if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $username)) $errors[] = '用户名格式错误';
            $display = $data['显示名称']; if ($display === '' || mb_strlen($display) > 128) $errors[] = '显示名称格式错误';
            $email = strtolower($data['邮箱']); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = '邮箱格式错误';
            $phone = $data['手机号'] === '' ? '' : '+' . preg_replace('/[^0-9]/', '', ltrim($data['手机号'], '+')); if ($phone !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) $errors[] = '手机号格式错误'; if ($email === '' && $phone === '') $errors[] = '邮箱和手机号至少填写一项';
            $state = match ($data['账号状态']) { '正常' => 'active', '停用' => 'disabled', default => '' }; if ($state === '') $errors[] = '账号状态只能填写正常或停用'; if ($mode === 'create' && $state === 'disabled') $errors[] = '新增账号必须先邀请激活，不能直接导入为停用';
            $codes = $data['用户组代码'] === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode('|', $data['用户组代码']))))); $groupIds = []; $groupNames = [];
            foreach ($codes as $code) { $group = IdentityGroup::where('application_id', $applicationId)->where('code', $code)->where('status', 1)->find(); if ($group === null) $errors[] = '用户组代码不存在：' . $code; else { $groupIds[] = (int) $group->id; $groupNames[] = (string) $group->name; } }
            $payload = ['username' => $username, 'display_name' => $display, 'email' => $email, 'phone' => $phone, 'account_state' => $state, 'group_ids' => $groupIds];
            $target = $email !== '' ? $this->mask('email', $email) : $this->mask('phone', $phone); $rows[] = ['row_number' => $number, 'payload' => $payload, 'summary' => ['row_number' => $number, 'display_name' => $display, 'target_masked' => $target, 'account_state' => $state, 'group_names' => $groupNames], 'errors' => array_values(array_unique($errors))];
        }
        fclose($stream); if ($rows === []) throw new ApiException('SAND_IAM_IMPORT_FILE_EMPTY', 400); return $rows;
    }
    /** @param array<string,mixed> $payload */ private function existingAuth(int $applicationId, array $payload): ?IdentityAuth { return IdentityAuth::where('application_id', $applicationId)->where(function ($query) use ($payload): void { $query->where('username', (string) $payload['username']); if ((string) $payload['email'] !== '') $query->whereOr('email', (string) $payload['email']); if ((string) $payload['phone'] !== '') $query->whereOr('phone', (string) $payload['phone']); })->find(); }
    private function mask(string $type, string $value): string { if ($type === 'email') { [$name, $domain] = explode('@', $value, 2); return mb_substr($name, 0, min(2, mb_strlen($name))) . '***@' . $domain; } return mb_substr($value, 0, 3) . '****' . mb_substr($value, -4); }
    private function csv(string $value): string { return preg_match('/^[=+\-@]/u', $value) ? "'" . $value : $value; }
    private function application(int $id): Application { $app = Application::where('id', $id)->where('status', 1)->find(); if ($app === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404); return $app; }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503); }
    /** @param array<string,mixed> $context */ private function writeAudit(Application $app, string $action, int $id, string $actor, string $requestId, string $outcome, array $context): void { $this->audit->write('admin', $actor, (int) $app->organization_id, (int) $app->id, $action, 'identity_import_job', $id, $outcome, $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)), $context); }
}
