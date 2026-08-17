<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\controller;

use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use plugin\sandworkflow\app\logic\InstanceLogic;
use plugin\sandworkflow\app\service\SandAdminWorkflowContextFactory;
use plugin\sandworkflow\app\service\WorkflowRuntimeService;
use plugin\sandworkflow\common\FlowConstant;
use support\Request;
use support\Response;

class InstanceController extends BaseController
{
    public function __construct()
    {
        $this->logic = new InstanceLogic();
        parent::__construct();
    }

    #[Permission('流程实例列表', 'sandworkflow:instance:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['keywords', ''],
            ['definition_id', ''],
            ['flow_status', ''],
        ]);
        $query = $this->logic->search($where)
            ->with([
                'definition' => static function ($relation): void {
                    $relation->field(['id', 'name']);
                },
                'tasks' => static function ($relation): void {
                    $relation->where('task_status', 0)
                        ->field(['id', 'instance_id', 'node_id', 'node_name', 'task_status'])
                        ->order('create_time', 'asc');
                },
            ])
            ->withoutField(['definition_snapshot', 'form_value', 'participants']);
        return $this->success($this->logic->getList($query));
    }

    #[Permission('发起流程', 'sandworkflow:instance:start')]
    public function start(Request $request): Response
    {
        $input = $request->post();
        return $this->success((new WorkflowRuntimeService())->start(
            $input,
            SandAdminWorkflowContextFactory::start($input),
            SandAdminWorkflowContextFactory::organizationMaterializer()
        ), '发起成功');
    }

    #[Permission('待办流程', 'sandworkflow:instance:pendingList')]
    public function pendingList(Request $request): Response
    {
        $query = $this->logic->pendingQuery();
        if ($request->get('keywords')) {
            $query->hasWhere('instance', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->get('keywords') . '%');
            });
        }
        return $this->success($this->logic->getList($query));
    }

    #[Permission('我发起的流程', 'sandworkflow:instance:myList')]
    public function myList(): Response
    {
        return $this->success($this->logic->getList($this->logic->myQuery()));
    }

    #[Permission('我已处理的任务', 'sandworkflow:instance:processedList')]
    public function processedList(): Response
    {
        return $this->success($this->logic->getList($this->logic->processedQuery()));
    }

    #[Permission('流程实例详情', 'sandworkflow:instance:getDetail')]
    public function getDetail(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->detail(
                (string) $request->get('id', ''),
                SandAdminWorkflowContextFactory::actor(),
                [
                    'tenant_id' => 'sandadmin',
                    'owner_scope' => 'sandadmin',
                ],
                true
            )
        );
    }

    #[Permission('审批通过', 'sandworkflow:instance:approve')]
    public function approve(Request $request): Response
    {
        return $this->success($this->logic->handleAction($request->post(), FlowConstant::CMD['APPROVED']), '处理成功');
    }

    #[Permission('审批拒绝', 'sandworkflow:instance:reject')]
    public function reject(Request $request): Response
    {
        return $this->success($this->logic->handleAction($request->post(), FlowConstant::CMD['REJECTED']), '处理成功');
    }

    #[Permission('撤销流程', 'sandworkflow:instance:cancel')]
    public function cancel(Request $request): Response
    {
        return $this->success($this->logic->cancel((string) $request->post('id', '')), '撤销成功');
    }

    #[Permission('评论流程', 'sandworkflow:instance:comment')]
    public function comment(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->comment($request->post(), SandAdminWorkflowContextFactory::actor(), [
                'tenant_id' => 'sandadmin',
                'owner_scope' => 'sandadmin',
            ]),
            '评论成功'
        );
    }

    #[Permission('转办流程', 'sandworkflow:instance:transfer')]
    public function transfer(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->transfer($request->post(), SandAdminWorkflowContextFactory::actor(), [
                'tenant_id' => 'sandadmin',
                'owner_scope' => 'sandadmin',
            ]),
            '转办成功'
        );
    }

    #[Permission('流程加签', 'sandworkflow:instance:addSign')]
    public function addSign(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->addSign($request->post(), SandAdminWorkflowContextFactory::actor(), [
                'tenant_id' => 'sandadmin',
                'owner_scope' => 'sandadmin',
            ]),
            '加签成功'
        );
    }

    #[Permission('流程减签', 'sandworkflow:instance:delSign')]
    public function delSign(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->delSign($request->post(), SandAdminWorkflowContextFactory::actor(), [
                'tenant_id' => 'sandadmin',
                'owner_scope' => 'sandadmin',
            ]),
            '减签成功'
        );
    }

    #[Permission('可回退节点', 'sandworkflow:instance:backNodeList')]
    public function backNodeList(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->backNodeList(
                (string) $request->post('task_id', ''),
                SandAdminWorkflowContextFactory::actor(),
                [
                    'tenant_id' => 'sandadmin',
                    'owner_scope' => 'sandadmin',
                ]
            )
        );
    }

    #[Permission('回退流程', 'sandworkflow:instance:back')]
    public function back(Request $request): Response
    {
        return $this->success(
            (new WorkflowRuntimeService())->back($request->post(), SandAdminWorkflowContextFactory::actor(), [
                'tenant_id' => 'sandadmin',
                'owner_scope' => 'sandadmin',
            ]),
            '回退成功'
        );
    }

    #[Permission('抄送给我的流程', 'sandworkflow:instance:copyList')]
    public function copyList(Request $request): Response
    {
        $query = (new WorkflowRuntimeService())->copyQuery(
            SandAdminWorkflowContextFactory::actor(),
            [
                'tenant_id' => 'sandadmin',
                'owner_scope' => 'sandadmin',
            ]
        );
        $keywords = trim((string) $request->get('keywords', ''));
        if ($keywords !== '') {
            $query->where('instance.name', 'like', '%' . $keywords . '%');
        }
        return $this->success($this->logic->getList($query));
    }
}
