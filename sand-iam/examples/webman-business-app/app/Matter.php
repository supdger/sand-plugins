<?php

declare(strict_types=1);

namespace Example\Matter;

/** Immutable projection loaded from the business database before authorization. */
final readonly class Matter
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $ownerIdentityId,
        public string $state,
    ) {}
}
