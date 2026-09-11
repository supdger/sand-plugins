<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** Atomically disables an organization and invalidates every human session beneath it. */
final class OrganizationHumanSessionRevoker
{
    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter())
    {
    }

    /** @param array<string,mixed> $organizationPayload @return array{changed:bool,application_count:int,session_count:int,refresh_token_count:int} */
    public function disable(int $organizationId, array $organizationPayload, int $actorId, string $requestId): array
    {
        Db::startTrans();
        try {
            $organization = Organization::where('id', $organizationId)->lock(true)->find();
            if ($organization === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到目标记录，可能已被删除或当前账号无权访问，请刷新列表后重试', 400);
            if ((int) $organization->status !== 1) {
                Db::commit();
                return ['changed' => false, 'application_count' => 0, 'session_count' => 0, 'refresh_token_count' => 0];
            }

            $organizationPayload['status'] = 2;
            $organization->save($organizationPayload);
            $applicationIds = array_values(array_map('intval', Application::where('organization_id', $organizationId)->lock(true)->column('id')));
            $sessionIds = $applicationIds === [] ? [] : array_values(array_map('intval', AuthSession::whereIn('application_id', $applicationIds)->where('status', 1)->lock(true)->column('id')));
            $now = date('Y-m-d H:i:s');
            $refreshTokenCount = $sessionIds === [] ? 0 : AuthRefreshToken::whereIn('session_id', $sessionIds)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
            $sessionCount = $sessionIds === [] ? 0 : AuthSession::whereIn('id', $sessionIds)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
            $this->auditWriter->write(
                'admin',
                (string) $actorId,
                $organizationId,
                null,
                'organization.disable',
                'organization',
                $organizationId,
                'succeeded',
                $requestId,
                [
                    'application_count' => count($applicationIds),
                    'session_count' => $sessionCount,
                    'refresh_token_count' => $refreshTokenCount,
                ],
            );
            Db::commit();

            return ['changed' => true, 'application_count' => count($applicationIds), 'session_count' => $sessionCount, 'refresh_token_count' => $refreshTokenCount];
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }
}
