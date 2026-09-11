<?php

declare(strict_types=1);

namespace support {
    final class Log
    {
        /** @var list<array{message:string,context:array<string,mixed>}> */
        public static array $warnings = [];
        /** @param array<string,mixed> $context */
        public static function warning(string $message, array $context = []): void { self::$warnings[] = compact('message', 'context'); }
        /** @param array<string,mixed> $context */
        public static function error(string $message, array $context = []): void {}
    }
}

namespace {
    function config(string $key, mixed $default = null): mixed { return $default; }

    require_once dirname(__DIR__) . '/app/service/DirectorySyncScheduleRepository.php';
    require_once dirname(__DIR__) . '/app/service/DirectorySyncRunExecutor.php';
    require_once dirname(__DIR__) . '/app/service/EloquentDirectorySyncScheduleRepository.php';
    require_once dirname(__DIR__) . '/app/service/ServiceDirectorySyncRunExecutor.php';
    require_once dirname(__DIR__) . '/app/service/DirectorySyncScheduler.php';
    require_once dirname(__DIR__) . '/app/process/DirectorySyncWorker.php';

    use plugin\SandIam\app\process\DirectorySyncWorker;
    use plugin\SandIam\app\service\DirectorySyncScheduleRepository;
    use plugin\SandIam\app\service\DirectorySyncScheduler;
    use plugin\SandIam\app\service\ServiceDirectorySyncRunExecutor;
    use support\Log;

    final class KeysetMemoryRepository implements DirectorySyncScheduleRepository
    {
        /** @var list<array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}> */
        public array $candidates;
        /** @var list<?int> */
        public array $afterIds = [];

        /** @param list<array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}> $candidates */
        public function __construct(array $candidates) { $this->candidates = $candidates; }

        public function dueAfter(?int $afterId, int $limit): array
        {
            $this->afterIds[] = $afterId;
            $rows = array_values(array_filter($this->candidates, static fn (array $candidate): bool => $afterId === null || $candidate['id'] > $afterId));
            usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
            return array_slice($rows, 0, $limit);
        }

        public function retryableIds(array $connectorIds): array
        {
            $wanted = array_fill_keys($connectorIds, true);
            return array_values(array_map(static fn (array $candidate): int => $candidate['id'], array_filter($this->candidates, static fn (array $candidate): bool => isset($wanted[$candidate['id']]) && $candidate['status'] === 1 && $candidate['config_configured'] === true)));
        }
    }

    function directoryWorkerAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, "IAM-T10 directory sync worker behavior failed: {$message}\n"); exit(1); }
    }

    /** @return array{id:int,state:string,pulled:int,pushed:int,created:int,updated:int,missing:int,disabled:int,conflict:int} */
    function workerRunResult(int $id): array
    {
        return ['id' => $id, 'state' => 'succeeded', 'pulled' => 0, 'pushed' => 0, 'created' => 0, 'updated' => 0, 'missing' => 0, 'disabled' => 0, 'conflict' => 0];
    }

    $now = 1000;
    $calls = [];
    $repository = new KeysetMemoryRepository([
        ['id' => 1, 'organization_id' => 11, 'application_id' => 101, 'status' => 1, 'config_configured' => true],
        ['id' => 2, 'organization_id' => 11, 'application_id' => 102, 'status' => 1, 'config_configured' => true],
        ['id' => 3, 'organization_id' => 22, 'application_id' => 303, 'status' => 1, 'config_configured' => true],
        ['id' => 4, 'organization_id' => 33, 'application_id' => 404, 'status' => 2, 'config_configured' => true],
        ['id' => 5, 'organization_id' => 44, 'application_id' => 505, 'status' => 1, 'config_configured' => false],
    ]);
    // This is the actual production executor. Its injected runner is the only
    // non-PG seam and observes connector claims, not service cursor/audit logic.
    $executor = new ServiceDirectorySyncRunExecutor(static function (int $connectorId, int $applicationId, string $actor, string $requestId) use (&$calls): array {
        $calls[] = compact('connectorId', 'applicationId', 'actor', 'requestId');
        return workerRunResult($connectorId);
    });
    $scheduler = new DirectorySyncScheduler($repository, $executor, static function () use (&$now): int { return $now; }, 5, 30);
    $worker = new DirectorySyncWorker($scheduler);
    $first = $worker->tick();
    $second = $worker->tick();
    $third = $worker->tick();
    directoryWorkerAssert($first['eligible'] === 2 && $first['skipped'] === 2 && $second['eligible'] === 0 && $second['skipped'] === 2 && $third['eligible'] === 2, 'due/disabled batch behavior drifted');
    directoryWorkerAssert(array_column($calls, 'connectorId') === [1, 3, 2, 3], 'worker → scheduler → production executor wiring or same-organization rotation failed');
    directoryWorkerAssert($calls[0]['applicationId'] === 101 && $calls[1]['applicationId'] === 303 && str_starts_with($calls[0]['actor'], 'system:directory-sync-worker') && str_starts_with($calls[0]['requestId'], 'sync-worker.'), 'worker claim lost isolation, actor or request id');

    // The fixed prefix failure: id 9 is outside the first page (limit 8), but
    // a keyset cursor reaches it on the next bounded tick without a broad scan.
    $calls = [];
    $outsideWindow = [];
    for ($id = 1; $id <= 8; $id++) $outsideWindow[] = ['id' => $id, 'organization_id' => 11, 'application_id' => 100 + $id, 'status' => 1, 'config_configured' => true];
    $outsideWindow[] = ['id' => 9, 'organization_id' => 22, 'application_id' => 209, 'status' => 1, 'config_configured' => true];
    $windowRepository = new KeysetMemoryRepository($outsideWindow);
    $windowScheduler = new DirectorySyncScheduler($windowRepository, new ServiceDirectorySyncRunExecutor(static function (int $id, int $app, string $actor, string $requestId) use (&$calls): array { $calls[] = $id; return workerRunResult($id); }), static function () use (&$now): int { return $now; }, 5, 30);
    $windowScheduler->tick(1);
    $windowScheduler->tick(1);
    directoryWorkerAssert($calls === [1, 9] && $windowRepository->afterIds === [null, 1], 'keyset cursor did not reach a different organization beyond the first page');

    // Seventeen same-organization connectors exceed the bounded page. Because
    // the scan cursor follows the actual claim, every id is reached in finite ticks.
    $calls = [];
    $sameOrganization = [];
    for ($id = 1; $id <= 17; $id++) $sameOrganization[] = ['id' => $id, 'organization_id' => 11, 'application_id' => 1000 + $id, 'status' => 1, 'config_configured' => true];
    $sameRepository = new KeysetMemoryRepository($sameOrganization);
    $sameScheduler = new DirectorySyncScheduler($sameRepository, new ServiceDirectorySyncRunExecutor(static function (int $id, int $app, string $actor, string $requestId) use (&$calls): array { $calls[] = $id; return workerRunResult($id); }), static function () use (&$now): int { return $now; }, 5, 30);
    for ($tick = 0; $tick < 17; $tick++) $sameScheduler->tick(1);
    directoryWorkerAssert($calls === range(1, 17), 'same-organization connectors outside the bounded page starved');

    $attempts = 0;
    $pruneRepository = new KeysetMemoryRepository([['id' => 50, 'organization_id' => 55, 'application_id' => 550, 'status' => 1, 'config_configured' => true]]);
    $pruneScheduler = new DirectorySyncScheduler($pruneRepository, new ServiceDirectorySyncRunExecutor(static function (int $id, int $app, string $actor, string $requestId) use (&$attempts): array { $attempts++; throw new \RuntimeException('SAND_IAM_SYNC_REMOTE_RATE_LIMITED'); }), static function () use (&$now): int { return $now; }, 5, 30);
    $pruneScheduler->tick(1);
    $pruneRepository->candidates = [];
    $pruned = $pruneScheduler->tick(1);
    directoryWorkerAssert($attempts === 1 && $pruned['retry_state_pruned'] === 1 && count(Log::$warnings) === 1 && !array_key_exists('exception_message', Log::$warnings[0]['context']), 'retry cleanup or safe failure logging drifted');

    // Configuration/conflict-style service failures deliberately have no
    // exponential backoff. They become eligible on the next tick, but do not
    // receive a priority lane over another organization or same-org connector.
    $calls = [];
    $immediateRepository = new KeysetMemoryRepository([
        ['id' => 60, 'organization_id' => 66, 'application_id' => 660, 'status' => 1, 'config_configured' => true],
        ['id' => 61, 'organization_id' => 77, 'application_id' => 770, 'status' => 1, 'config_configured' => true],
    ]);
    $immediateScheduler = new DirectorySyncScheduler($immediateRepository, new ServiceDirectorySyncRunExecutor(static function (int $id, int $app, string $actor, string $requestId) use (&$calls): array { $calls[] = $id; if ($id === 60) throw new \RuntimeException('SAND_IAM_SYNC_CONFLICT_DETECTED'); return workerRunResult($id); }), static function () use (&$now): int { return $now; }, 5, 30);
    $immediateScheduler->tick(1);
    $immediateScheduler->tick(1);
    directoryWorkerAssert($calls === [60, 61], 'a permanent conflict occupied the next batch instead of fair organization rotation');

    $calls = [];
    $sameOrganizationRetryRepository = new KeysetMemoryRepository([
        ['id' => 70, 'organization_id' => 88, 'application_id' => 880, 'status' => 1, 'config_configured' => true],
        ['id' => 71, 'organization_id' => 88, 'application_id' => 881, 'status' => 1, 'config_configured' => true],
    ]);
    $sameOrganizationRetryScheduler = new DirectorySyncScheduler($sameOrganizationRetryRepository, new ServiceDirectorySyncRunExecutor(static function (int $id, int $app, string $actor, string $requestId) use (&$calls): array { $calls[] = $id; if ($id === 70) throw new \RuntimeException('SAND_IAM_SYNC_CONFLICT_DETECTED'); return workerRunResult($id); }), static function () use (&$now): int { return $now; }, 5, 30);
    $sameOrganizationRetryScheduler->tick(1);
    $sameOrganizationRetryScheduler->tick(1);
    directoryWorkerAssert($calls === [70, 71], 'a permanent conflict starved a same-organization competitor');

    $stopCalls = [];
    $stopScheduler = null;
    $stopExecutor = new ServiceDirectorySyncRunExecutor(static function (int $id, int $app, string $actor, string $requestId) use (&$stopCalls, &$stopScheduler): array { $stopCalls[] = $id; $stopScheduler->stop(); return workerRunResult($id); });
    $stopScheduler = new DirectorySyncScheduler(new KeysetMemoryRepository([
        ['id' => 1, 'organization_id' => 11, 'application_id' => 101, 'status' => 1, 'config_configured' => true],
        ['id' => 2, 'organization_id' => 22, 'application_id' => 202, 'status' => 1, 'config_configured' => true],
    ]), $stopExecutor, static function () use (&$now): int { return $now; }, 5, 30);
    $stopped = $stopScheduler->tick(2);
    directoryWorkerAssert($stopped['stopped'] === true && $stopCalls === [1], 'stop during a run claimed a later connector');

    echo "directory sync worker behavior tests passed\n";
}
