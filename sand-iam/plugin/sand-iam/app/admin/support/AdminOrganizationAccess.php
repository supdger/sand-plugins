<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\SandIam\app\model\AdminOrganizationGrant;
use plugin\SandIam\app\model\AdminApplicationGrant;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;

/**
 * Resolves the SandIAM control-plane boundary for the logged-in SandAdmin user.
 * It deliberately has no foreign key to sa_system_user: that table remains
 * owned by the host application.
 */
final class AdminOrganizationAccess
{
    /** @param array<string, mixed>|null $adminInfo */
    public function __construct(private readonly int $adminId, private readonly ?array $adminInfo)
    {
    }

    public function isSuperAdmin(): bool
    {
        return $this->adminId === 1 || (int) ($this->adminInfo['is_super'] ?? 0) === 1;
    }

    /** @return list<int> */
    public function organizationIds(): array
    {
        if ($this->isSuperAdmin()) {
            return [];
        }
        $grantedIds = AdminOrganizationGrant::where('admin_user_id', $this->adminId)
            ->where('status', 1)
            ->column('organization_id');
        if ($grantedIds === []) return [];
        $ids = Organization::whereIn('id', $grantedIds)->where('status', 1)->column('id');
        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
    }

    public function assertOrganization(int $organizationId): void
    {
        if ($organizationId > 0 && ($this->isSuperAdmin() || in_array($organizationId, $this->organizationIds(), true))) {
            return;
        }
        $this->deny('organization.access', $organizationId > 0 ? $organizationId : null);
    }

    /** @return list<int> */
    public function applicationIds(): array
    {
        if ($this->isSuperAdmin()) return [];
        $grantedApplicationIds = AdminApplicationGrant::where('admin_user_id', $this->adminId)
            ->where('status', 1)
            ->column('application_id');
        $activeOrganizationIds = Organization::where('status', 1)->column('id');
        $applicationIds = $grantedApplicationIds === [] ? [] : Application::whereIn('id', $grantedApplicationIds)
            ->where('status', 1)
            ->whereIn('organization_id', $activeOrganizationIds)
            ->column('id');
        $organizationIds = $this->organizationIds();
        if ($organizationIds !== []) {
            $applicationIds = array_merge(
                $applicationIds,
                Application::whereIn('organization_id', $organizationIds)->where('status', 1)->column('id'),
            );
        }
        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $applicationIds)));
    }

    public function assertApplication(int $applicationId): void
    {
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($application !== null && $organization !== null && ($this->isSuperAdmin() || in_array($applicationId, $this->applicationIds(), true))) {
            return;
        }
        $organizationId = $application === null ? null : (int) $application->organization_id;
        (new AuditWriter())->write('admin', (string) $this->adminId, $organizationId, $applicationId > 0 ? $applicationId : null, 'application.access', 'application', $applicationId > 0 ? $applicationId : null, 'denied', $this->requestId());
        throw new ApiException('SAND_IAM_APPLICATION_ACCESS_DENIED: 当前账号未获授该接入应用的管理范围，请联系客户主体管理员授权', 403);
    }

    /**
     * Recovering a disabled application is intentionally narrower than normal
     * application access. In particular, an application delegate cannot use
     * its former application grant to reactivate that application.
     */
    public function assertApplicationRecovery(int $applicationId): void
    {
        $application = Application::where('id', $applicationId)->find();
        $organizationId = $application === null ? null : (int) $application->organization_id;
        $organization = $organizationId === null
            ? null
            : Organization::where('id', $organizationId)->where('status', 1)->find();
        if ($application !== null
            && (int) $application->status === 2
            && $organization !== null
            && ($this->isSuperAdmin() || in_array($organizationId, $this->organizationIds(), true))) {
            return;
        }
        (new AuditWriter())->write('admin', (string) $this->adminId, $organizationId, $applicationId > 0 ? $applicationId : null, 'application.recovery_access', 'application', $applicationId > 0 ? $applicationId : null, 'denied', $this->requestId());
        throw new ApiException('SAND_IAM_APPLICATION_RECOVERY_ACCESS_DENIED: 当前账号无权恢复该停用接入应用，或其所属客户主体未启用', 403);
    }

    public function assertSuperAdmin(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->deny('organization.global_access', null);
        }
    }

    private function deny(string $action, ?int $organizationId): never
    {
        (new AuditWriter())->write('admin', (string) $this->adminId, $organizationId, null, $action, 'organization', $organizationId, 'denied', $this->requestId());
        throw new ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED: 当前账号未获授该客户主体的管理范围，请联系平台管理员授权', 403);
    }

    private function requestId(): string
    {
        if (!function_exists('request')) return RequestId::normalize('');
        try {
            $request = request();
        } catch (\Throwable) {
            return RequestId::normalize('');
        }
        return $request instanceof Request ? RequestId::fromRequestCached($request) : RequestId::normalize('');
    }
}
