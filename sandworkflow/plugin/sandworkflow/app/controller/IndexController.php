<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\controller;

use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\app\model\system\SystemDept;
use plugin\sandadmin\app\model\system\SystemRole;
use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandadmin\service\Permission;
use support\Response;

class IndexController extends BaseController
{
    #[Permission('工作流用户列表', 'sandworkflow:index:listUsers')]
    public function listUsers(): Response
    {
        return $this->success(SystemUser::where('status', 1)->field('id,username,realname as nickname,dept_id')->select()->toArray());
    }

    #[Permission('工作流角色列表', 'sandworkflow:index:listRoles')]
    public function listRoles(): Response
    {
        return $this->success(SystemRole::where('status', 1)->field('id,name,code')->select()->toArray());
    }

    #[Permission('工作流部门列表', 'sandworkflow:index:listDepts')]
    public function listDepts(): Response
    {
        return $this->success(SystemDept::where('status', 1)->field('id,parent_id,name')->select()->toArray());
    }
}
