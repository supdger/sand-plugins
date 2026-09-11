<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthRefreshToken extends AbstractSandIamModel
{
    protected $table = 'sand_iam_auth_refresh_token';
    protected $hidden = ['token_hash'];
}
