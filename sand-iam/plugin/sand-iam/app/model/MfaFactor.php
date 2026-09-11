<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class MfaFactor extends AbstractSandIamModel
{
    protected $table = 'sand_iam_mfa_factor';
    protected $hidden = ['encrypted_secret'];
}
