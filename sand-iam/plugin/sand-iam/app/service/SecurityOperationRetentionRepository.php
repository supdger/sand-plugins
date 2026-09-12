<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

interface SecurityOperationRetentionRepository
{
    /** Delete only succeeded operations whose update time is at or before the cutoff. */
    public function purgeSucceededBefore(string $cutoff, int $limit): int;
}
