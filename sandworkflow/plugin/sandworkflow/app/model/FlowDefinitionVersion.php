<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model;

use plugin\sandadmin\basic\think\BaseModel;
use plugin\sandworkflow\app\model\concern\UsesNanoId;

class FlowDefinitionVersion extends BaseModel
{
    use UsesNanoId;
    protected $pk = 'id';
    protected $table = 'sand_workflow_definition_version';
    protected $json = ['definition_json'];
    protected $jsonAssoc = true;
}
