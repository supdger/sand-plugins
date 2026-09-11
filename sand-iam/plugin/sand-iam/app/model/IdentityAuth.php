<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class IdentityAuth extends AbstractSandIamModel
{
    protected $table = 'sand_iam_identity_auth';
    protected $hidden = ['password_hash'];
}
