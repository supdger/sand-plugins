<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\contract;

use InvalidArgumentException;

final class ActorRef
{
    public function __construct(
        public readonly string $accountSpace,
        public readonly string $accountId,
        public readonly array $snapshot = [],
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $accountSpace)) {
            throw new InvalidArgumentException('账户空间格式无效');
        }
        if ($accountId === '' || strlen($accountId) > 64 || !preg_match('/^[\x21-\x7E]+$/', $accountId)) {
            throw new InvalidArgumentException('账户标识格式无效');
        }
    }

    public function toArray(): array
    {
        return ['account_space' => $this->accountSpace, 'account_id' => $this->accountId, 'snapshot' => $this->snapshot];
    }
}
