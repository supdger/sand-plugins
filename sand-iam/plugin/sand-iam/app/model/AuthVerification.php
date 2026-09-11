<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthVerification extends AbstractSandIamModel
{
    protected $table = 'sand_iam_auth_verification';
    protected $hidden = ['destination_hash', 'code_hash'];
}
