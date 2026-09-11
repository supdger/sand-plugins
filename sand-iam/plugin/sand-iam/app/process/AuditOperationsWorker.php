<?php

declare(strict_types=1);

namespace plugin\SandIam\app\process;

use plugin\SandIam\app\service\SecurityOperationsService;
use support\Log;
use Workerman\Timer;

final class AuditOperationsWorker
{
    public function onWorkerStart(): void
    {
        Timer::add(max(300, (int) config('plugin.sand-iam.app.audit_archive_interval_seconds', 3600)), static function (): void {
            try {
                (new SecurityOperationsService())->archiveBatch((int) config('plugin.sand-iam.app.audit_archive_batch_size', 200));
            } catch (\Throwable $exception) {
                Log::error('SandIAM audit archive worker tick failed', ['exception_type' => $exception::class, 'exception_file' => basename($exception->getFile()), 'exception_line' => $exception->getLine()]);
            }
        });
    }
}
