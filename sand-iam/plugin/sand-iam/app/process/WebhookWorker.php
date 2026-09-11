<?php

declare(strict_types=1);

namespace plugin\SandIam\app\process;

use plugin\SandIam\app\service\WebhookService;
use support\Log;
use Workerman\Timer;

final class WebhookWorker
{
    public function onWorkerStart(): void
    {
        $interval = max(0.25, (int) config('plugin.sand-iam.app.webhook_poll_interval_ms', 1000) / 1000);
        Timer::add($interval, function (): void {
            try {
                (new WebhookService())->deliverBatch((int) config('plugin.sand-iam.app.webhook_batch_size', 20));
            } catch (\Throwable $exception) {
                Log::error('SandIAM webhook worker tick failed', [
                    'exception_type' => $exception::class,
                    'exception_file' => basename($exception->getFile()),
                    'exception_line' => $exception->getLine(),
                ]);
            }
        });
    }
}
