<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\logic;

use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandworkflow\app\model\FlowGroup;

class GroupLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new FlowGroup();
        $this->orderField = 'sort';
    }
}
