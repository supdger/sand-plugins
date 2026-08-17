<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model;

use plugin\sandadmin\basic\think\BaseModel;
use plugin\sandworkflow\app\model\concern\UsesNanoId;

class FlowInstance extends BaseModel
{
    use UsesNanoId;
    protected $pk = 'id';
    protected $table = 'sand_workflow_instance';
    protected $json = ['definition_snapshot', 'form_value', 'participants'];
    protected $jsonAssoc = true;

    public function searchKeywordsAttr($query, $value): void
    {
        $query->where('name', 'like', '%' . $value . '%');
    }

    public function definition()
    {
        return $this->belongsTo(FlowDefinition::class, 'definition_id', 'id');
    }

    public function tasks()
    {
        return $this->hasMany(FlowTask::class, 'instance_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(FlowLog::class, 'instance_id', 'id');
    }
}
