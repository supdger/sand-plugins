<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class GuestIdentityService
{
    public function __construct(private readonly AuditWriter $audit = new AuditWriter()) {}
    /** @return array{id:int,created:bool,display_name:string} */
    public function upsert(int $applicationId, string $externalGuestId, string $displayName, string $contextId, string $requestId, int $retry = 0): array
    {
        if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503);
        $application = Application::where('id', $applicationId)->where('status', 1)->find(); if ($application === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404);
        $externalGuestId = trim($externalGuestId); if ($externalGuestId === '' || strlen($externalGuestId) > 256) throw new ApiException('SAND_IAM_GUEST_REFERENCE_INVALID', 400);
        $displayName = trim($displayName); if ($displayName === '' || mb_strlen($displayName) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 访客显示名称须为 1–128 个字符', 400);
        $hash = $this->referenceHash($applicationId, $externalGuestId); $created = false; $updated = false;
        Db::startTrans();
        try {
            $identity = Identity::where('application_id', $applicationId)->where('external_guest_ref_hash', $hash)->lock(true)->find();
            if ($identity === null) { $identity = Identity::create(['application_id' => $applicationId, 'code' => 'guest_' . substr($hash, 0, 24), 'display_name' => $displayName, 'external_guest_ref_hash' => $hash, 'lifecycle_state' => 'guest', 'status' => 1]); $created = true; }
            elseif ((string) ($identity->lifecycle_state ?? '') !== 'guest' || (int) $identity->status !== 1) throw new ApiException('SAND_IAM_GUEST_REFERENCE_CONFLICT', 409);
            elseif ((string) $identity->display_name !== $displayName) { $identity->save(['display_name' => $displayName]); $updated = true; }
            if ($created || $updated) (new IdentityEventPublisher())->publish($application, $identity, $created ? 'identity.created' : 'identity.updated', $created ? ['code', 'display_name', 'lifecycle_state', 'status'] : ['display_name'], $requestId);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($retry === 0 && (str_contains(strtolower($exception->getMessage()), 'unique') || str_contains($exception->getMessage(), '23505'))) return $this->upsert($applicationId, $externalGuestId, $displayName, $contextId, $requestId, 1);
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_GUEST_REFERENCE_CONFLICT', 409);
            throw $exception;
        }
        $this->audit->write('context', $contextId, (int) $application->organization_id, $applicationId, 'identity.guest_upsert', 'identity', (int) $identity->id, 'succeeded', $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)), ['created' => $created]);
        return ['id' => (int) $identity->id, 'created' => $created, 'display_name' => (string) $identity->display_name];
    }
    private function referenceHash(int $applicationId, string $value): string { $pepper = (string) config('plugin.sand-iam.app.guest_reference_pepper', ''); if (strlen($pepper) < 32) throw new ApiException('SAND_IAM_GUEST_CONFIGURATION_UNAVAILABLE', 503); return hash_hmac('sha256', $applicationId . '|' . $value, $pepper); }
}
