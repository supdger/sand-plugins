<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model;

use plugin\sandadmin\basic\think\BaseModel;
use plugin\sandworkflow\app\model\concern\UsesNanoId;

class FlowTaskAssignee extends BaseModel
{
    use UsesNanoId;
    protected $pk = 'id';
    protected $table = 'sand_workflow_task_assignee';
}
