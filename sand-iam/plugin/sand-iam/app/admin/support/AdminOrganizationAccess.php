<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\SandIam\app\model\AdminOrganizationGrant;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\exception\ApiException;

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
        $ids = AdminOrganizationGrant::where('admin_user_id', $this->adminId)
            ->where('status', 1)
            ->column('organization_id');
        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
    }

    public function assertOrganization(int $organizationId): void
    {
        if ($organizationId > 0 && ($this->isSuperAdmin() || in_array($organizationId, $this->organizationIds(), true))) {
            return;
        }
        $this->deny('organization.access', $organizationId > 0 ? $organizationId : null);
    }

    public function assertSuperAdmin(): void
    {
        if (!$this->isSuperAdmin()) {
            $this->deny('organization.global_access', null);
        }
    }

    private function deny(string $action, ?int $organizationId): never
    {
        (new AuditWriter())->write('admin', (string) $this->adminId, $organizationId, null, $action, 'organization', $organizationId, 'denied', bin2hex(random_bytes(16)));
        throw new ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403);
    }
}
