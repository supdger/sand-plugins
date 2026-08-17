<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model;

use plugin\sandadmin\basic\think\BaseModel;
use plugin\sandworkflow\app\model\concern\UsesNanoId;

class FlowTask extends BaseModel
{
    use UsesNanoId;
    protected $pk = 'id';
    protected $table = 'sand_workflow_task';
    protected $json = ['assignee_refs'];
    protected $jsonAssoc = true;

    public function instance()
    {
        return $this->belongsTo(FlowInstance::class, 'instance_id', 'id');
    }

    public function assignees()
    {
        return $this->hasMany(FlowTaskAssignee::class, 'task_id', 'id');
    }
}
