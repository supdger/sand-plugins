<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthChallenge extends AbstractSandIamModel
{
    protected $table = 'sand_iam_auth_challenge';
    protected $hidden = ['token_hash', 'encrypted_challenge', 'encrypted_user_handle'];
}
