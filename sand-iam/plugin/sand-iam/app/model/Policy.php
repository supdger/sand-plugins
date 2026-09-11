<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class Policy extends AbstractSandIamModel
{
    protected $table = 'sand_iam_policy';
    protected $json = ['condition', 'scope'];
    protected $jsonAssoc = true;
}
