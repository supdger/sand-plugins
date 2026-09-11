<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthSession extends AbstractSandIamModel
{
    protected $table = 'sand_iam_auth_session';
    protected $hidden = ['access_token_hash', 'refresh_token_hash', 'previous_refresh_token_hash', 'ip_hash'];
}
