<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

final class AuthRateLimitRetentionService
{
    public const MIN_RETENTION_HOURS = 1;
    public const MAX_RETENTION_HOURS = 168;
    public const MAX_BATCH_SIZE = 1000;

    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    public function __construct(
        private readonly AuthRateLimitRetentionRepository $repository = new EloquentAuthRateLimitRetentionRepository(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /** @return array{retention_hours:int,cutoff:string,deleted:int} */
    public function prune(int $retentionHours, int $limit): array
    {
        if ($retentionHours < self::MIN_RETENTION_HOURS || $retentionHours > self::MAX_RETENTION_HOURS) {
            throw new \InvalidArgumentException('auth rate-limit retention hours are outside the safe range');
        }
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('auth rate-limit retention batch size is outside the safe range');
        }
        $cutoff = ($this->clock)()->sub(new \DateInterval('PT' . $retentionHours . 'H'))->format('Y-m-d H:i:s');
        return [
            'retention_hours' => $retentionHours,
            'cutoff' => $cutoff,
            'deleted' => $this->repository->purgeExpiredBefore($cutoff, $limit),
        ];
    }
}
