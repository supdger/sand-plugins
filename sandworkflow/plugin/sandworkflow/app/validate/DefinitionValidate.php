<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\validate;

use plugin\sandadmin\basic\BaseValidate;

class DefinitionValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:120',
        'status' => 'in:1,2',
        'definition_json' => 'require',
    ];

    protected $message = [
        'name.require' => '流程名称不能为空',
        'definition_json.require' => '流程定义不能为空',
    ];

    protected $scene = [
        'save' => ['name', 'status'],
        'update' => ['name', 'status'],
        'publish' => ['name', 'status', 'definition_json'],
    ];
}
