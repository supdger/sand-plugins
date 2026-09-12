<?php

declare(strict_types=1);

namespace plugin\SandIam\app\process;

use plugin\SandIam\app\service\SecurityOperationRetentionService;
use plugin\SandIam\app\service\AuthRateLimitRetentionService;
use support\Log;
use Workerman\Timer;

final class SecurityOperationRetentionWorker
{
    /** @var \Closure():array{security_operations:array{retention_days:int,cutoff:string,deleted:int},auth_rate_limits:array{retention_hours:int,cutoff:string,deleted:int}} */
    private \Closure $prune;

    public function __construct(?\Closure $prune = null)
    {
        $this->prune = $prune ?? static fn (): array => [
            'security_operations' => (new SecurityOperationRetentionService())->prune(
                (int) config('plugin.sand-iam.app.security_operation_retention_days', 30),
                (int) config('plugin.sand-iam.app.security_operation_retention_batch_size', 200),
            ),
            'auth_rate_limits' => (new AuthRateLimitRetentionService())->prune(
                (int) config('plugin.sand-iam.app.auth_rate_limit_retention_hours', 24),
                (int) config('plugin.sand-iam.app.security_operation_retention_batch_size', 200),
            ),
        ];
    }

    /** @return array{security_operations:array{retention_days:int,cutoff:string,deleted:int},auth_rate_limits:array{retention_hours:int,cutoff:string,deleted:int}} */
    public function tick(): array
    {
        return ($this->prune)();
    }

    public function onWorkerStart(): void
    {
        $interval = max(300, (int) config('plugin.sand-iam.app.security_operation_retention_interval_seconds', 3600));
        Timer::add($interval, function (): void {
            try {
                $result = $this->tick();
                if ($result['security_operations']['deleted'] > 0 || $result['auth_rate_limits']['deleted'] > 0) {
                    Log::info('SandIAM operational retention batch completed', [
                        'security_operation_deleted_count' => $result['security_operations']['deleted'],
                        'security_operation_retention_days' => $result['security_operations']['retention_days'],
                        'auth_rate_limit_deleted_count' => $result['auth_rate_limits']['deleted'],
                        'auth_rate_limit_retention_hours' => $result['auth_rate_limits']['retention_hours'],
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::error('SandIAM security operation retention worker tick failed', [
                    'exception_type' => $exception::class,
                    'exception_file' => basename($exception->getFile()),
                    'exception_line' => $exception->getLine(),
                ]);
            }
        });
    }
}
