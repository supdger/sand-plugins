<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class MfaRecoveryCode extends AbstractSandIamModel
{
    protected $table = 'sand_iam_mfa_recovery_code';
    protected $hidden = ['code_hash'];
}
