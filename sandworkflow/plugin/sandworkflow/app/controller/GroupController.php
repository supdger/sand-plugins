<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\controller;

use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use plugin\sandworkflow\app\logic\GroupLogic;
use plugin\sandworkflow\app\validate\GroupValidate;
use support\Request;
use support\Response;

class GroupController extends BaseController
{
    public function __construct()
    {
        $this->logic = new GroupLogic();
        $this->validate = new GroupValidate();
        parent::__construct();
    }

    #[Permission('流程分组列表', 'sandworkflow:group:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['keywords', ''],
            ['status', ''],
        ]);
        return $this->success($this->logic->getList($this->logic->search($where)));
    }

    #[Permission('流程分组全部', 'sandworkflow:group:all')]
    public function all(): Response
    {
        return $this->success($this->logic->getAll($this->logic->search(['status' => 1])));
    }

    #[Permission('流程分组读取', 'sandworkflow:group:read')]
    public function read(Request $request): Response
    {
        return $this->success($this->logic->read($request->input('id', 0))->toArray());
    }

    #[Permission('流程分组保存', 'sandworkflow:group:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->success(['id' => $this->logic->add($data)], '保存成功');
    }

    #[Permission('流程分组更新', 'sandworkflow:group:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $this->logic->edit($data['id'] ?? 0, $data);
        return $this->success('更新成功');
    }

    #[Permission('流程分组删除', 'sandworkflow:group:destroy')]
    public function destroy(Request $request): Response
    {
        $this->logic->destroy($request->post('ids', ''));
        return $this->success('删除成功');
    }
}
