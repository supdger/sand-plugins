<?php

declare(strict_types=1);

namespace plugin\SandIam\app\process;

use plugin\SandIam\app\service\DirectorySyncScheduler;
use support\Log;
use Workerman\Timer;

final class DirectorySyncWorker
{
    private ?int $timerId = null;

    private ?DirectorySyncScheduler $scheduler = null;

    public function __construct(?DirectorySyncScheduler $scheduler = null)
    {
        $this->scheduler = $scheduler;
    }

    public function onWorkerStart(): void
    {
        $interval = max(1, (int) config('plugin.sand-iam.app.directory_sync_worker_interval_seconds', 60));
        $this->timerId = Timer::add($interval, fn (): array => $this->tick());
    }

    public function onWorkerStop(): void
    {
        // Workerman stops dispatching timers after this callback. An active
        // synchronous driver call is intentionally not cancelled: it may be
        // midway through a remote request. The scheduler therefore finishes
        // that call and never claims a subsequent connector.
        $this->scheduler()->stop();
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
            $this->timerId = null;
        }
    }

    /** @return array{stopped:bool,eligible:int,skipped:int,deferred:int,retry_state_pruned:int,succeeded:int,failed:int} */
    public function tick(): array
    {
        try {
            return $this->scheduler()->tick((int) config('plugin.sand-iam.app.directory_sync_worker_batch_size', 20));
        } catch (\Throwable $exception) {
            Log::error('SandIAM directory sync worker tick failed', [
                'exception_type' => $exception::class,
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
            ]);
            return ['stopped' => false, 'eligible' => 0, 'skipped' => 0, 'deferred' => 0, 'retry_state_pruned' => 0, 'succeeded' => 0, 'failed' => 1];
        }
    }

    private function scheduler(): DirectorySyncScheduler
    {
        return $this->scheduler ??= new DirectorySyncScheduler();
    }
}
