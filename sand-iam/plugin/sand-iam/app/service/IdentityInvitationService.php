<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\IdentityInvitation;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdentityInvitationService
{
    public function __construct(private readonly InvitationSecretCipher $cipher = new InvitationSecretCipher(), private readonly AuditWriter $audit = new AuditWriter())
    {
    }

    /** @param list<int> $groupIds */
    public function create(int $applicationId, string $targetType, string $target, array $groupIds, int $ttlHours, string $actor, string $requestId, ?int $guestIdentityId = null, bool $throwOnDeliveryFailure = true): int
    {
        $this->enabled(); $application = $this->application($applicationId); $target = $this->target($targetType, $target); $groups = $this->groups($groupIds, $applicationId);
        if ($guestIdentityId !== null) { $guest = \plugin\SandIam\app\model\Identity::where('id', $guestIdentityId)->where('application_id', $applicationId)->where('lifecycle_state', 'guest')->where('status', 1)->find(); if ($guest === null) throw new ApiException('SAND_IAM_GUEST_NOT_UPGRADEABLE', 409); }
        $ttlHours = min(168, max(1, $ttlHours)); $token = $this->newToken();
        try {
            $invitation = IdentityInvitation::create(['application_id' => $applicationId, 'target_type' => $targetType, 'target_hash' => $this->hash('target:' . $target), 'target_masked' => $this->mask($targetType, $target), 'encrypted_target' => $this->cipher->encrypt($target), 'token_hash' => $this->hash('token:' . $token), 'encrypted_delivery_token' => $this->cipher->encrypt($token), 'initial_group_ids' => $groups, 'guest_identity_id' => $guestIdentityId, 'invited_by' => $actor, 'state' => 'sending', 'expire_time' => date('Y-m-d H:i:s', time() + $ttlHours * 3600), 'status' => 1]);
        } catch (\Throwable $exception) { if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_INVITATION_CONFLICT', 409); throw $exception; }
        try { $this->deliver($invitation, $application, $requestId); }
        catch (\Throwable $exception) { $this->writeAudit($application, 'identity_invitation.create', (int) $invitation->id, $actor, $requestId, 'failed'); if ($throwOnDeliveryFailure) throw $exception; return (int) $invitation->id; }
        $this->writeAudit($application, 'identity_invitation.create', (int) $invitation->id, $actor, $requestId, 'succeeded');
        return (int) $invitation->id;
    }

    public function resend(int $id, int $applicationId, string $actor, string $requestId): void
    {
        $this->enabled(); $application = $this->application($applicationId); $token = $this->newToken();
        Db::startTrans();
        try {
            $invitation = IdentityInvitation::where('id', $id)->where('application_id', $applicationId)->lock(true)->find();
            if ($invitation === null || (int) $invitation->status !== 1 || !in_array((string) $invitation->state, ['pending', 'delivery_failed'], true) || strtotime((string) $invitation->expire_time) <= time()) throw new ApiException('SAND_IAM_INVITATION_NOT_RESENDABLE', 409);
            $invitation->save(['token_hash' => $this->hash('token:' . $token), 'encrypted_delivery_token' => $this->cipher->encrypt($token), 'state' => 'sending', 'delivery_error_code' => null]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        try { $this->deliver($invitation, $application, $requestId); }
        catch (\Throwable $exception) { $this->writeAudit($application, 'identity_invitation.resend', $id, $actor, $requestId, 'failed'); throw $exception; }
        $this->writeAudit($application, 'identity_invitation.resend', $id, $actor, $requestId, 'succeeded');
    }

    public function revoke(int $id, int $applicationId, string $actor, string $requestId): void
    {
        $this->enabled(); $application = $this->application($applicationId);
        Db::startTrans();
        try {
            $invitation = IdentityInvitation::where('id', $id)->where('application_id', $applicationId)->lock(true)->find();
            if ($invitation === null || (int) $invitation->status !== 1 || !in_array((string) $invitation->state, ['pending', 'delivery_failed', 'sending'], true)) throw new ApiException('SAND_IAM_INVITATION_NOT_REVOCABLE', 409);
            $invitation->save(['state' => 'revoked', 'encrypted_delivery_token' => null, 'status' => 2]);
            $this->writeAudit($application, 'identity_invitation.revoke', $id, $actor, $requestId, 'succeeded');
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    /** @param array<string,mixed> $payload @return array{id:int,display_name:string} */
    public function accept(string $token, array $payload, string $requestId): array
    {
        $this->enabled(); if (!preg_match('/^siam_inv_[A-Za-z0-9_-]{43}$/', $token)) throw new ApiException('SAND_IAM_INVITATION_INVALID', 400);
        Db::startTrans(); $transactionOpen = true;
        try {
            $invitation = IdentityInvitation::where('token_hash', $this->hash('token:' . $token))->lock(true)->find();
            if ($invitation === null || (string) $invitation->state !== 'pending' || (int) $invitation->status !== 1) throw new ApiException('SAND_IAM_INVITATION_INVALID', 400);
            if (strtotime((string) $invitation->expire_time) <= time()) { $invitation->save(['state' => 'expired', 'status' => 2]); Db::commit(); $transactionOpen = false; throw new ApiException('SAND_IAM_INVITATION_EXPIRED', 410); }
            $application = $this->application((int) $invitation->application_id);
            $target = $this->cipher->decrypt((string) $invitation->encrypted_target); $auth = new HumanAuthService();
            if ($invitation->guest_identity_id !== null) { $guest = \plugin\SandIam\app\model\Identity::where('id', (int) $invitation->guest_identity_id)->where('application_id', (int) $application->id)->lock(true)->find(); if ($guest === null) throw new ApiException('SAND_IAM_GUEST_NOT_UPGRADEABLE', 409); $identity = $auth->upgradeGuestInvitation($application, $guest, (string) $invitation->target_type, $target, $payload, $requestId); }
            else $identity = $auth->activateInvitation($application, (string) $invitation->target_type, $target, $payload, $requestId, false);
            foreach ($this->groups(is_array($invitation->initial_group_ids ?? null) ? $invitation->initial_group_ids : [], (int) $application->id) as $groupId) { $member = IdentityGroupMember::where('identity_group_id', $groupId)->where('identity_id', (int) $identity->id)->lock(true)->find(); if ($member === null) IdentityGroupMember::create(['identity_group_id' => $groupId, 'application_id' => (int) $application->id, 'identity_id' => (int) $identity->id, 'status' => 1]); elseif ((int) $member->status !== 1) $member->save(['status' => 1]); }
            $invitation->save(['state' => 'accepted', 'identity_id' => (int) $identity->id, 'consumed_time' => date('Y-m-d H:i:s'), 'encrypted_target' => null, 'encrypted_delivery_token' => null, 'status' => 2]);
            IdentityInvitation::where('application_id', (int) $application->id)->where('target_hash', (string) $invitation->target_hash)->where('id', '<>', (int) $invitation->id)->whereIn('state', ['pending', 'delivery_failed', 'sending'])->update(['state' => 'revoked', 'encrypted_delivery_token' => null, 'status' => 2]);
            $this->writeAudit($application, 'identity_invitation.accept', (int) $invitation->id, (string) $identity->id, $requestId, 'succeeded', 'application_user');
            Db::commit(); $transactionOpen = false;
        } catch (\Throwable $exception) { if ($transactionOpen) Db::rollback(); throw $exception; }
        return ['id' => (int) $identity->id, 'display_name' => (string) $identity->display_name];
    }

    private function deliver(IdentityInvitation $invitation, Application $application, string $requestId): void
    {
        $tokenHash = (string) $invitation->token_hash;
        try {
            $target = $this->cipher->decrypt((string) $invitation->encrypted_target); $token = $this->cipher->decrypt((string) $invitation->encrypted_delivery_token);
            $base = (string) config('plugin.sand-iam.app.invitation_accept_url', '');
            if (!$this->httpsUrl($base)) throw new ApiException('SAND_IAM_INVITATION_DELIVERY_UNAVAILABLE', 503);
            $separator = str_contains($base, '?') ? '&' : '?'; $url = $base . $separator . 'token=' . rawurlencode($token);
            $sent = (new MessageProviderService())->sendMessage((int) $application->id, (string) $invitation->target_type === 'phone' ? 'sms' : 'email', 'invitation', $target, $url, ['purpose' => 'invitation', 'expire_time' => $invitation->expire_time, 'request_id' => $requestId]);
            if (!$sent) throw new ApiException('SAND_IAM_INVITATION_DELIVERY_UNAVAILABLE', 503);
            $this->recordDeliveryResult($invitation, $tokenHash, ['state' => 'pending', 'encrypted_delivery_token' => null, 'delivered_time' => date('Y-m-d H:i:s'), 'delivery_error_code' => null]);
        } catch (\Throwable $exception) {
            $this->recordDeliveryResult($invitation, $tokenHash, ['state' => 'delivery_failed', 'delivery_error_code' => $exception instanceof ApiException && preg_match('/^SAND_IAM_[A-Z0-9_]+$/', $exception->getMessage()) ? $exception->getMessage() : 'SAND_IAM_INVITATION_DELIVERY_FAILED']);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $values */
    private function recordDeliveryResult(IdentityInvitation $invitation, string $tokenHash, array $values): void
    {
        IdentityInvitation::where('id', (int) $invitation->id)
            ->where('application_id', (int) $invitation->application_id)
            ->where('state', 'sending')->where('status', 1)->where('token_hash', $tokenHash)
            ->update($values);
    }

    /** @param list<int|string> $ids @return list<int> */
    private function groups(array $ids, int $applicationId): array
    {
        foreach ($ids as &$id) {
            if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1)) throw new ApiException('SAND_IAM_INVITATION_GROUPS_INVALID', 400);
            $id = filter_var(is_string($id) ? ltrim($id, '0') : $id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new ApiException('SAND_IAM_INVITATION_GROUPS_INVALID', 400);
        }
        unset($id);
        $ids = array_values(array_unique($ids));
        if (count($ids) > 50) throw new ApiException('SAND_IAM_INVITATION_GROUPS_INVALID', 400);
        foreach ($ids as $id) if (!IdentityGroup::where('id', $id)->where('application_id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_INVITATION_GROUPS_INVALID', 400);
        return $ids;
    }
    private function application(int $id): Application { $app = Application::where('id', $id)->where('status', 1)->find(); if ($app === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404); return $app; }
    private function target(string $type, string $value): string { $value = trim($value); if ($type === 'email') { $value = strtolower($value); if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new ApiException('SAND_IAM_INVITATION_TARGET_INVALID', 400); return $value; } if ($type === 'phone') { $value = '+' . preg_replace('/[^0-9]/', '', ltrim($value, '+')); if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $value)) throw new ApiException('SAND_IAM_INVITATION_TARGET_INVALID', 400); return $value; } throw new ApiException('SAND_IAM_INVITATION_TARGET_INVALID', 400); }
    private function mask(string $type, string $value): string { if ($type === 'email') { [$name, $domain] = explode('@', $value, 2); return mb_substr($name, 0, min(2, mb_strlen($name))) . '***@' . $domain; } return mb_substr($value, 0, 3) . '****' . mb_substr($value, -4); }
    private function newToken(): string { return 'siam_inv_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function hash(string $value): string { $pepper = (string) config('plugin.sand-iam.app.invitation_token_pepper', ''); if (strlen($pepper) < 32) throw new ApiException('SAND_IAM_INVITATION_CONFIGURATION_UNAVAILABLE', 503); return hash_hmac('sha256', $value, $pepper); }
    private function httpsUrl(string $value): bool { return filter_var($value, FILTER_VALIDATE_URL) !== false && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https' && parse_url($value, PHP_URL_USER) === null && parse_url($value, PHP_URL_PASS) === null; }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503); }
    private function writeAudit(Application $app, string $action, int $id, string $actor, string $requestId, string $outcome, string $actorType = 'admin'): void { $this->audit->write($actorType, $actor, (int) $app->organization_id, (int) $app->id, $action, 'identity_invitation', $id, $outcome, $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16))); }
}
