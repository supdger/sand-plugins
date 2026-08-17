<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model;

use plugin\sandadmin\basic\think\BaseModel;

class FlowDefinition extends BaseModel
{
    protected $pk = 'id';
    protected $table = 'sand_workflow_definition';
    protected $json = ['definition_json'];
    protected $jsonAssoc = true;
    protected $append = ['group_name'];

    public function searchKeywordsAttr($query, $value): void
    {
        $query->where('name', 'like', '%' . $value . '%');
    }

    public function group()
    {
        return $this->belongsTo(FlowGroup::class, 'group_id', 'id');
    }

    public function getGroupNameAttr(): string
    {
        $group = FlowGroup::findOrEmpty($this->group_id);
        return $group->isEmpty() ? '' : (string) $group->getAttr('name');
    }
}
