<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class CasLoginRequest extends AbstractSandIamModel
{
    protected $table = 'sand_iam_cas_login_request';
    protected $hidden = ['request_hash'];
}
