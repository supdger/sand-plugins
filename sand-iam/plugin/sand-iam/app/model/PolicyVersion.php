<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class PolicyVersion extends NoSoftDeleteSandIamModel
{
    protected $table = 'sand_iam_policy_version';
    protected $json = ['snapshot'];
    protected $jsonAssoc = true;
}
