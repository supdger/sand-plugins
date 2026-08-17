<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\controller;

use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use plugin\sandworkflow\app\logic\InstanceLogic;
use support\Response;

class TaskController extends BaseController
{
    #[Permission('待办任务列表', 'sandworkflow:task:pendingList')]
    public function pendingList(): Response
    {
        $logic = new InstanceLogic();
        return $this->success($logic->getList($logic->pendingQuery()));
    }
}
