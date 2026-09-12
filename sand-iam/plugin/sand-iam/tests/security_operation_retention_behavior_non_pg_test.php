<?php

declare(strict_types=1);

// behavior-test-gate: observable-behavior

require_once dirname(__DIR__) . '/app/service/SecurityOperationRetentionRepository.php';
require_once dirname(__DIR__) . '/app/service/EloquentSecurityOperationRetentionRepository.php';
require_once dirname(__DIR__) . '/app/service/SecurityOperationRetentionService.php';
require_once dirname(__DIR__) . '/app/service/AuthRateLimitRetentionRepository.php';
require_once dirname(__DIR__) . '/app/service/EloquentAuthRateLimitRetentionRepository.php';
require_once dirname(__DIR__) . '/app/service/AuthRateLimitRetentionService.php';
require_once dirname(__DIR__) . '/app/process/SecurityOperationRetentionWorker.php';

use plugin\SandIam\app\process\SecurityOperationRetentionWorker;
use plugin\SandIam\app\service\SecurityOperationRetentionRepository;
use plugin\SandIam\app\service\SecurityOperationRetentionService;
use plugin\SandIam\app\service\AuthRateLimitRetentionRepository;
use plugin\SandIam\app\service\AuthRateLimitRetentionService;

final class MemorySecurityOperationRetentionRepository implements SecurityOperationRetentionRepository
{
    /** @var list<array{cutoff:string,limit:int}> */
    public array $calls = [];

    public function __construct(private readonly int $deleted) {}

    public function purgeSucceededBefore(string $cutoff, int $limit): int
    {
        $this->calls[] = compact('cutoff', 'limit');
        return $this->deleted;
    }
}

final class MemoryAuthRateLimitRetentionRepository implements AuthRateLimitRetentionRepository
{
    /** @var list<array{cutoff:string,limit:int}> */
    public array $calls = [];

    public function __construct(private readonly int $deleted) {}

    public function purgeExpiredBefore(string $cutoff, int $limit): int
    {
        $this->calls[] = compact('cutoff', 'limit');
        return $this->deleted;
    }
}

function retentionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "security operation retention behavior failed: {$message}\n");
        exit(1);
    }
}

function expectRetentionArgument(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    retentionAssert(false, 'unsafe retention input was accepted');
}

$repository = new MemorySecurityOperationRetentionRepository(17);
$clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-12 08:00:00', new DateTimeZone('Asia/Shanghai'));
$service = new SecurityOperationRetentionService($repository, $clock);
$result = $service->prune(30, 200);
retentionAssert($result === ['retention_days' => 30, 'cutoff' => '2026-08-13 08:00:00', 'deleted' => 17], 'service did not expose the exact bounded result');
retentionAssert($repository->calls === [['cutoff' => '2026-08-13 08:00:00', 'limit' => 200]], 'repository did not receive the exact cutoff and batch');

foreach ([[29, 200], [3651, 200], [30, 0], [30, 1001]] as [$days, $limit]) {
    expectRetentionArgument(static fn () => $service->prune($days, $limit));
}
retentionAssert(count($repository->calls) === 1, 'invalid input reached the deletion repository');

$rateLimitRepository = new MemoryAuthRateLimitRetentionRepository(19);
$rateLimitService = new AuthRateLimitRetentionService($rateLimitRepository, $clock);
$rateLimitResult = $rateLimitService->prune(24, 200);
retentionAssert($rateLimitResult === ['retention_hours' => 24, 'cutoff' => '2026-09-11 08:00:00', 'deleted' => 19], 'rate-limit service did not expose the exact bounded result');
retentionAssert($rateLimitRepository->calls === [['cutoff' => '2026-09-11 08:00:00', 'limit' => 200]], 'rate-limit repository did not receive the exact cutoff and batch');
foreach ([[0, 200], [169, 200], [24, 0], [24, 1001]] as [$hours, $limit]) {
    expectRetentionArgument(static fn () => $rateLimitService->prune($hours, $limit));
}
retentionAssert(count($rateLimitRepository->calls) === 1, 'invalid rate-limit input reached the deletion repository');

$ticks = 0;
$worker = new SecurityOperationRetentionWorker(static function () use (&$ticks): array {
    $ticks++;
    return [
        'security_operations' => ['retention_days' => 30, 'cutoff' => '2026-08-13 08:00:00', 'deleted' => 3],
        'auth_rate_limits' => ['retention_hours' => 24, 'cutoff' => '2026-09-11 08:00:00', 'deleted' => 5],
    ];
});
$tick = $worker->tick();
retentionAssert($tick['security_operations']['deleted'] === 3 && $tick['auth_rate_limits']['deleted'] === 5 && $ticks === 1, 'worker tick did not invoke exactly one bounded maintenance pass');

echo "security operation retention behavior tests passed\n";
