<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model;

use plugin\sandadmin\basic\think\BaseModel;

class FlowGroup extends BaseModel
{
    protected $pk = 'id';
    protected $table = 'sand_workflow_group';

    public function searchKeywordsAttr($query, $value): void
    {
        $query->where('name', 'like', '%' . $value . '%');
    }

    public function definitions()
    {
        return $this->hasMany(FlowDefinition::class, 'group_id', 'id');
    }
}
