<?php

declare(strict_types=1);

namespace plugin\SandIamC05Business\app;

use support\Db;

final class BusinessAuditWriter
{
    public function write(string $outcome, ?WorkItem $item, ?string $reason, string $actor, string $requestId): int
    {
        return (int) Db::table('standalone_business_audit')
            ->insertGetId([
                'action' => 'c05.route.inspect',
                'outcome' => $outcome,
                'reason_code' => $reason,
                'work_item_id' => $item?->id,
                'organization_ref' => $this->reference($item === null ? 'unknown' : (string) $item->organizationId),
                'owner_ref' => $this->reference($item === null ? 'unknown' : (string) $item->ownerIdentityId),
                'actor_ref' => $this->reference($actor),
                'request_id' => $requestId,
                'create_time' => date('Y-m-d H:i:s'),
            ], 'id');
    }

    private function reference(string $value): string
    {
        return substr(hash('sha256', $value), 0, 24);
    }
}
