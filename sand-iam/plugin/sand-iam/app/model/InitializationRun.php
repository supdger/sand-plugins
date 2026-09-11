<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class InitializationRun extends AbstractSandIamModel
{
    protected $table = 'sand_iam_initialization_run';
    protected $json = ['manifest', 'changes'];
    protected $jsonAssoc = true;
}
