<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ScimToken extends AbstractSandIamModel
{
    protected $table = 'sand_iam_scim_token';
    protected $hidden = ['token_hash'];
}
