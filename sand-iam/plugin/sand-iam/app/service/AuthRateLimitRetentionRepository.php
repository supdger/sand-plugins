<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

interface AuthRateLimitRetentionRepository
{
    /** Delete only rate-limit rows whose window started at or before the cutoff. */
    public function purgeExpiredBefore(string $cutoff, int $limit): int;
}
