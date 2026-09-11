<?php

declare(strict_types=1);

namespace Sand\Iam\Sdk;

final class AuthorizationDenied extends SandIamException
{
    /** @param array<string,mixed> $decision */
    public function __construct(public readonly array $decision)
    {
        parent::__construct(
            (string) ($decision['code'] ?? 'SAND_IAM_POLICY_DENIED'),
            '当前账号没有执行此操作的权限',
            403,
        );
    }
}
