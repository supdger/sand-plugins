<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthorizationCode extends AbstractSandIamModel
{
    protected $table = 'sand_iam_authorization_code';
    protected $hidden = ['code_hash', 'encrypted_nonce', 'nonce_hash'];
}
