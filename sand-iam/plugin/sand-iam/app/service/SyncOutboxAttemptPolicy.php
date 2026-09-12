<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

final class SyncOutboxAttemptPolicy
{
    public function __construct(private readonly ?int $configuredMaxAttempts = null) {}

    public function maxAttempts(): int
    {
        $configured = $this->configuredMaxAttempts
            ?? (int) config('plugin.sand-iam.app.sync_outbox_max_attempts', 10);
        return max(1, min(100, $configured));
    }

    /** @return array{state:string,attempt_count:int,error_code:string,delivered_time:null,status:int} */
    public function failure(int $currentAttempts, string $errorCode): array
    {
        $attempts = max(0, $currentAttempts) + 1;
        $failed = $attempts >= $this->maxAttempts();
        if (preg_match('/^SAND_IAM_[A-Z0-9_]{1,117}$/', $errorCode) !== 1) {
            $errorCode = 'SAND_IAM_SYNC_OUTBOUND_FAILED';
        }

        return [
            'state' => $failed ? 'failed' : 'pending',
            'attempt_count' => $attempts,
            'error_code' => $errorCode,
            'delivered_time' => null,
            'status' => $failed ? 2 : 1,
        ];
    }

    /** @return array{state:string,attempt_count:int,error_code:null,delivered_time:null,status:int} */
    public function retry(): array
    {
        return [
            'state' => 'pending',
            'attempt_count' => 0,
            'error_code' => null,
            'delivered_time' => null,
            'status' => 1,
        ];
    }
}
