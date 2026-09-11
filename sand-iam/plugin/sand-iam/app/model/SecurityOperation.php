<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class SecurityOperation extends NoSoftDeleteSandIamModel
{
    protected $table = 'sand_iam_security_operation';
    protected $json = ['result'];
    protected $jsonAssoc = true;
}
