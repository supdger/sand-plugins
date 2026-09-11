<?php

declare(strict_types=1);

namespace plugin\SandIam\app\process;

use plugin\SandIam\app\service\OidcBackchannelLogoutService;
use support\Log;
use Workerman\Timer;

final class OidcLogoutWorker
{
    public function onWorkerStart(): void
    {
        $interval = max(0.25, (int) config('plugin.sand-iam.app.oidc_logout_poll_interval_ms', 1000) / 1000);
        Timer::add($interval, function (): void {
            try {
                (new OidcBackchannelLogoutService())->deliverBatch((int) config('plugin.sand-iam.app.oidc_logout_batch_size', 20));
            } catch (\Throwable $exception) {
                Log::error('SandIAM OIDC logout worker tick failed', [
                    'exception_type' => $exception::class,
                    'exception_file' => basename($exception->getFile()),
                    'exception_line' => $exception->getLine(),
                ]);
            }
        });
    }
}
