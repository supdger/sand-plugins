<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuthPolicy extends AbstractSandIamModel
{
    protected $table = 'sand_iam_auth_policy';
    protected $json = ['webauthn_allowed_origins'];
    protected $jsonAssoc = true;
}
