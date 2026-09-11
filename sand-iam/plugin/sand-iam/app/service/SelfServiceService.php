<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\MfaFactor;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\WebauthnCredential;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class SelfServiceService
{
    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter()) {}

    /** @return array<string,mixed> */
    public function profile(string $accessToken): array
    {
        [$application, $identity] = (new HumanAuthService())->authenticatedPrincipal($accessToken);
        $organization = Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($organization === null) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        return [
            'identity_id' => (int) $identity->id,
            'display_name' => (string) $identity->display_name,
            'organization' => ['code' => (string) $organization->code, 'name' => (string) $organization->name],
            'application' => ['code' => (string) $application->code, 'name' => (string) $application->name],
            'create_time' => $identity->create_time,
        ];
    }

    /** @return array<string,mixed> */
    public function updateProfile(string $accessToken, string $displayName, string $requestId): array
    {
        $displayName = $this->displayName($displayName);
        [$application, $identity] = (new HumanAuthService())->authenticatedPrincipal($accessToken);
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            $identity = $application === null ? null : Identity::where('id', (int) $identity->id)
                ->where('application_id', (int) $application->id)
                ->where('status', 1)
                ->lock(true)
                ->find();
            if ($application === null || $organization === null || $identity === null) {
                throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            }
            $identity->save(['display_name' => $displayName]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.updated', ['display_name'], $requestId);
            $this->auditWriter->write(
                'identity',
                (string) $identity->id,
                (int) $organization->id,
                (int) $application->id,
                'identity.profile_update',
                'identity',
                (int) $identity->id,
                'succeeded',
                $this->requestId($requestId),
                ['fields' => ['display_name']],
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $this->profile($accessToken);
    }

    /** @return list<array<string,mixed>> */
    public function connections(string $accessToken): array
    {
        [$application, $identity] = (new HumanAuthService())->authenticatedPrincipal($accessToken);
        $bindings = IdentityBinding::where('identity_id', (int) $identity->id)
            ->where('application_id', (int) $application->id)
            ->where('status', 1)
            ->order('id')
            ->select()
            ->all();
        $connections = [];
        foreach ($bindings as $binding) {
            $provider = IdentityProvider::where('id', (int) $binding->identity_provider_id)->where('status', 1)->find();
            $mount = $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)
                ->where('application_id', (int) $application->id)
                ->where('organization_id', (int) $application->organization_id)
                ->where('status', 1)
                ->find();
            if ($provider === null || $mount === null || (int) $provider->organization_id !== (int) $application->organization_id) continue;
            $connections[] = [
                'binding_id' => (int) $binding->id,
                'provider_name' => (string) $provider->name,
                'provider_type' => (string) $provider->provider_type,
                'account_hint' => $this->subjectHint((string) $binding->subject),
                'source_state' => (string) ($binding->source_state ?? 'active'),
                'linked_time' => $binding->create_time,
            ];
        }
        return $connections;
    }

    /** @return array<string,int|bool> */
    public function securityOverview(string $accessToken): array
    {
        [$application, $identity] = (new HumanAuthService())->authenticatedPrincipal($accessToken);
        $applicationId = (int) $application->id;
        $identityId = (int) $identity->id;
        $connectedAccounts = count($this->connections($accessToken));
        return [
            'password_enabled' => IdentityAuth::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->count() > 0,
            'active_sessions' => AuthSession::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->whereNull('revoked_time')->where('refresh_expire_time', '>', date('Y-m-d H:i:s'))->count(),
            'totp_factors' => MfaFactor::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->count(),
            'passkeys' => WebauthnCredential::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->count(),
            'connected_accounts' => $connectedAccounts,
        ];
    }

    private function displayName(string $value): string
    {
        if (!preg_match('//u', $value)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 显示名称必须是 UTF-8 文本', 400);
        }
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if ($value === '' || mb_strlen($value) > 128) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 显示名称须为 1–128 个字符', 400);
        }
        return $value;
    }

    private function subjectHint(string $subject): string
    {
        if (str_contains($subject, '@')) {
            [$local, $domain] = array_pad(explode('@', $subject, 2), 2, '');
            return mb_substr($local, 0, 2) . '***@' . $domain;
        }
        if (mb_strlen($subject) <= 8) return mb_substr($subject, 0, 2) . '***';
        return mb_substr($subject, 0, 3) . '***' . mb_substr($subject, -3);
    }

    private function requestId(string $requestId): string
    {
        return $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16));
    }
}
