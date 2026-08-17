<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\controller;

use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use plugin\sandworkflow\app\logic\DefinitionLogic;
use plugin\sandworkflow\app\validate\DefinitionValidate;
use support\Request;
use support\Response;

class DefinitionController extends BaseController
{
    public function __construct()
    {
        $this->logic = new DefinitionLogic();
        $this->validate = new DefinitionValidate();
        parent::__construct();
    }

    #[Permission('流程定义列表', 'sandworkflow:definition:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['keywords', ''],
            ['group_id', ''],
            ['status', ''],
        ]);
        return $this->success($this->logic->getList($this->logic->search($where)));
    }

    #[Permission('可发起流程列表', 'sandworkflow:definition:available')]
    public function available(Request $request): Response
    {
        $query = $this->logic->search($request->more([
            ['keywords', ''],
            ['group_id', ''],
        ]));
        $query->where('status', 1)->whereNotNull('publish_time');
        return $this->success($this->logic->getList($query));
    }

    #[Permission('流程定义版本', 'sandworkflow:definition:versions')]
    public function versions(Request $request): Response
    {
        return $this->success($this->logic->versions((int) $request->get('id', 0)));
    }

    #[Permission('流程定义读取', 'sandworkflow:definition:read')]
    public function read(Request $request): Response
    {
        return $this->success($this->logic->read($request->input('id', 0))->toArray());
    }

    #[Permission('流程定义保存', 'sandworkflow:definition:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        return $this->success(['id' => $this->logic->add($data)], '保存成功');
    }

    #[Permission('流程定义更新', 'sandworkflow:definition:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $this->logic->edit($data['id'] ?? 0, $data);
        return $this->success('更新成功');
    }

    #[Permission('流程定义发布', 'sandworkflow:definition:publish')]
    public function publish(Request $request): Response
    {
        $data = $request->post();
        $this->validate('publish', $data);
        return $this->success(['id' => $this->logic->publish($data)], '发布成功');
    }

    #[Permission('流程定义删除', 'sandworkflow:definition:destroy')]
    public function destroy(Request $request): Response
    {
        $this->logic->destroy($request->post('ids', ''));
        return $this->success('删除成功');
    }
}
