<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class OAuthToken extends AbstractSandIamModel
{
    protected $table = 'sand_iam_oauth_token';
    protected $hidden = ['token_hash'];
}
