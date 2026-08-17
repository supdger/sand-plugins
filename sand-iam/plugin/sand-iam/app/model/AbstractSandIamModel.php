<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

use plugin\sandadmin\basic\think\BaseModel;

abstract class AbstractSandIamModel extends BaseModel
{
    protected $pk = 'id';
}
