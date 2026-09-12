<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

final class SecurityOperationRetentionService
{
    public const MIN_RETENTION_DAYS = 30;
    public const MAX_RETENTION_DAYS = 3650;
    public const MAX_BATCH_SIZE = 1000;

    /** @var \Closure():\DateTimeImmutable */
    private \Closure $clock;

    public function __construct(
        private readonly SecurityOperationRetentionRepository $repository = new EloquentSecurityOperationRetentionRepository(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /** @return array{retention_days:int,cutoff:string,deleted:int} */
    public function prune(int $retentionDays, int $limit): array
    {
        if ($retentionDays < self::MIN_RETENTION_DAYS || $retentionDays > self::MAX_RETENTION_DAYS) {
            throw new \InvalidArgumentException('security operation retention days are outside the safe range');
        }
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('security operation retention batch size is outside the safe range');
        }
        $cutoff = ($this->clock)()->sub(new \DateInterval('P' . $retentionDays . 'D'))->format('Y-m-d H:i:s');
        return [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff,
            'deleted' => $this->repository->purgeSucceededBefore($cutoff, $limit),
        ];
    }
}
