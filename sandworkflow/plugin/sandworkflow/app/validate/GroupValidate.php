<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\validate;

use plugin\sandadmin\basic\BaseValidate;

class GroupValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:100',
        'status' => 'in:1,2',
    ];

    protected $message = [
        'name.require' => '分组名称不能为空',
    ];

    protected $scene = [
        'save' => ['name', 'status'],
        'update' => ['name', 'status'],
    ];
}
