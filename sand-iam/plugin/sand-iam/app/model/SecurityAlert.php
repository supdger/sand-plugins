<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class SecurityAlert extends AbstractSandIamModel
{
    protected $table = 'sand_iam_security_alert';
    protected $hidden = ['fingerprint'];
}
