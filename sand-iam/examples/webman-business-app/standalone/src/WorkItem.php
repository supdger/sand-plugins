<?php

declare(strict_types=1);

namespace Example\Standalone;

final readonly class WorkItem
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $ownerIdentityId,
        public string $state,
        public int $version,
    ) {}
}
