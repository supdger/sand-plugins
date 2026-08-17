<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\logic;

use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandworkflow\app\model\FlowTask;

class TaskLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new FlowTask();
        $this->orderField = 'id';
        $this->orderType = 'desc';
    }
}
