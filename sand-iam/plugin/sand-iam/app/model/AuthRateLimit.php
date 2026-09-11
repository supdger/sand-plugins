<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthRateLimit extends AbstractSandIamModel
{
    protected $table = 'sand_iam_auth_rate_limit';
    protected $hidden = ['subject_hash'];
}
