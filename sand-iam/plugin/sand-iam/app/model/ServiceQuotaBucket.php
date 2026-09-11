<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ServiceQuotaBucket extends NoSoftDeleteSandIamModel
{
    protected $table = 'sand_iam_service_quota_bucket';
}
