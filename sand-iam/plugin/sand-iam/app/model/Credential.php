<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class Credential extends AbstractSandIamModel
{
    protected $table = 'sand_iam_credential';
    protected $hidden = ['secret_hash', 'delete_time'];
}
