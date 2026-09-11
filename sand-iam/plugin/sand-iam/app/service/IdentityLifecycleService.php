<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\IdentityRole;
use plugin\SandIam\app\model\IdentityUserType;
use plugin\SandIam\app\model\MfaFactor;
use plugin\SandIam\app\model\WebauthnCredential;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdentityLifecycleService
{
    public function __construct(private readonly AuditWriter $audit = new AuditWriter())
    {
    }

    public function disable(int $identityId, int $applicationId, string $actor, string $requestId, bool $manageTransaction = true, bool $publishToSync = true): void
    {
        $this->change($identityId, $applicationId, 'disabled', $actor, $requestId, $manageTransaction, $publishToSync);
    }

    public function delete(int $identityId, int $applicationId, string $actor, string $requestId): void
    {
        $this->change($identityId, $applicationId, 'deleted', $actor, $requestId);
    }

    public function enable(int $identityId, int $applicationId, string $actor, string $requestId, bool $manageTransaction = true, bool $publishToSync = true): void
    {
        $application = $this->application($applicationId);
        if ($manageTransaction) Db::startTrans();
        try {
            $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->lock(true)->find();
            if ($identity === null || (string) ($identity->lifecycle_state ?? '') !== 'disabled') throw new ApiException('SAND_IAM_IDENTITY_NOT_ENABLEABLE', 409);
            $identity->save(['lifecycle_state' => 'active', 'status' => 1]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.enabled', ['lifecycle_state', 'status'], $requestId, $publishToSync);
            if ($manageTransaction) Db::commit();
        } catch (\Throwable $exception) { if ($manageTransaction) Db::rollback(); throw $exception; }
        $this->audit->write('admin', $actor, (int) $application->organization_id, $applicationId, 'identity.enable', 'identity', $identityId, 'succeeded', $this->requestId($requestId));
    }

    public function restore(int $identityId, int $applicationId, string $actor, string $requestId): void
    {
        $application = $this->application($applicationId);
        Db::startTrans();
        try {
            $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->lock(true)->find();
            if ($identity === null || (string) ($identity->lifecycle_state ?? '') !== 'deleted') throw new ApiException('SAND_IAM_IDENTITY_NOT_RESTORABLE', 409);
            if ($identity->purge_after !== null && strtotime((string) $identity->purge_after) <= time()) throw new ApiException('SAND_IAM_IDENTITY_RESTORE_WINDOW_EXPIRED', 409);
            $previous = (string) ($identity->state_before_delete ?? 'disabled');
            if (!in_array($previous, ['active', 'disabled', 'guest'], true)) $previous = 'disabled';
            $identity->save(['lifecycle_state' => $previous, 'status' => in_array($previous, ['active', 'guest'], true) ? 1 : 2, 'deleted_time' => null, 'purge_after' => null, 'state_before_delete' => null]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.restored', ['lifecycle_state', 'status'], $requestId);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        $this->audit->write('admin', $actor, (int) $application->organization_id, $applicationId, 'identity.restore', 'identity', $identityId, 'succeeded', $this->requestId($requestId), ['restored_state' => $previous]);
    }

    private function change(int $identityId, int $applicationId, string $target, string $actor, string $requestId, bool $manageTransaction = true, bool $publishToSync = true): void
    {
        $application = $this->application($applicationId);
        if ($manageTransaction) Db::startTrans();
        try {
            $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->lock(true)->find();
            if ($identity === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 应用用户不存在', 404);
            $current = (string) ($identity->lifecycle_state ?? ((int) $identity->status === 1 ? 'active' : 'disabled'));
            if ($target === 'deleted' && $current === 'deleted') throw new ApiException('SAND_IAM_IDENTITY_ALREADY_DELETED', 409);
            $changes = ['lifecycle_state' => $target, 'status' => 2];
            if ($target === 'deleted') {
                $changes += ['state_before_delete' => $current, 'deleted_time' => $this->now(), 'purge_after' => date('Y-m-d H:i:s', time() + 30 * 86400)];
            }
            $identity->save($changes);
            $this->revokeAccess($identityId, $applicationId, $target === 'deleted');
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.' . $target, ['lifecycle_state', 'status'], $requestId, $publishToSync);
            if ($manageTransaction) Db::commit();
        } catch (\Throwable $exception) {
            if ($manageTransaction) Db::rollback();
            throw $exception;
        }
        $this->audit->write('admin', $actor, (int) $application->organization_id, $applicationId, 'identity.' . $target, 'identity', $identityId, 'succeeded', $this->requestId($requestId), ['previous_state' => $current]);
    }

    private function revokeAccess(int $identityId, int $applicationId, bool $removeRelationships): void
    {
        $now = $this->now();
        $sessionIds = AuthSession::where('identity_id', $identityId)->where('application_id', $applicationId)->column('id');
        if ($sessionIds !== []) AuthRefreshToken::whereIn('session_id', $sessionIds)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
        AuthSession::where('identity_id', $identityId)->where('application_id', $applicationId)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
        if ($removeRelationships) {
            MfaFactor::where('identity_id', $identityId)->where('application_id', $applicationId)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
            WebauthnCredential::where('identity_id', $identityId)->where('application_id', $applicationId)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
            IdentityBinding::where('identity_id', $identityId)->where('application_id', $applicationId)->where('status', 1)->update(['status' => 2]);
            // identity_role and identity_user_type are scoped through their
            // globally unique identity_id; neither relation table owns an
            // application_id column.
            IdentityRole::where('identity_id', $identityId)->where('status', 1)->update(['status' => 2]);
            IdentityUserType::where('identity_id', $identityId)->where('status', 1)->update(['status' => 2]);
            IdentityGroupMember::where('identity_id', $identityId)->where('application_id', $applicationId)->where('status', 1)->update(['status' => 2]);
        }
    }

    private function application(int $id): Application
    {
        $application = Application::where('id', $id)->where('status', 1)->find();
        if ($application === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404);
        return $application;
    }
    private function requestId(string $value): string { return $value !== '' ? substr($value, 0, 96) : bin2hex(random_bytes(16)); }
    private function now(): string { return date('Y-m-d H:i:s'); }
}
