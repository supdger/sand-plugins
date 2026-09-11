<?php

declare(strict_types=1);

namespace Sand\Iam\Sdk;

final class SandIamWorkloadErrorCode
{
    public const NETWORK_FORBIDDEN = 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN';
    public const FACTS_UNVERIFIED = 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED';
    public const DATA_CLASS_FORBIDDEN = 'SAND_IAM_DATA_CLASS_FORBIDDEN';
    public const QUOTA_EXCEEDED = 'SAND_IAM_SERVICE_QUOTA_EXCEEDED';
    public const IDEMPOTENCY_CONFLICT = 'SAND_IAM_IDEMPOTENCY_CONFLICT';
}
