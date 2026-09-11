<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\runtime\ScopeMatcher;
use plugin\SandIam\app\runtime\PolicyAuthorizer;
use plugin\SandIam\app\service\PolicyVersionService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class PolicyController extends ApplicationResourceController
{
    protected string $modelClass = Policy::class;
    protected array $writeFields = ['application_id', 'resource_id', 'role_id', 'identity_id', 'action', 'effect', 'condition', 'scope', 'priority', 'state', 'status'];
    protected array $requiredFields = ['application_id', 'resource_id', 'action', 'effect'];
    protected string $resourceType = 'policy';
    #[Permission('SandIAM 策略列表', 'sand_iam:policy:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 策略读取', 'sand_iam:policy:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 策略保存', 'sand_iam:policy:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 策略停用', 'sand_iam:policy:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    #[Permission('SandIAM 策略发布', 'sand_iam:policy:publish')]
    public function publish(Request $request): Response
    {
        $policy = $this->find($request);
        $this->assertPolicyShape($policy->toArray());
        $published = (new PolicyVersionService())->publish((int) $policy->id, RequestId::fromRequest($request));
        if (!$published['replayed']) $this->audit('publish', (int) $policy->id, $request);
        return $this->success(['published_version_id' => (int) $published['version']->id, 'version_no' => (int) $published['version']->version_no, 'replayed' => $published['replayed']], '已发布不可变策略版本；草稿后续编辑不会改变当前运行版本');
    }

    #[Permission('SandIAM 策略回滚发布', 'sand_iam:policy:publish')]
    public function rollback(Request $request): Response
    {
        $policy = $this->find($request); $versionId = (int) $request->post('version_id', 0);
        if ($versionId <= 0) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请提供要回滚到的策略版本', 400);
        $published = (new PolicyVersionService())->publish((int) $policy->id, RequestId::fromRequest($request), $versionId);
        if (!$published['replayed']) $this->audit('rollback', (int) $policy->id, $request);
        return $this->success(['published_version_id' => (int) $published['version']->id, 'version_no' => (int) $published['version']->version_no], '已将历史快照作为新的策略版本发布；历史版本未被修改');
    }

    #[Permission('SandIAM 策略模拟与解释', 'sand_iam:policy:read')]
    public function simulate(Request $request): Response
    {
        $applicationId = (int) $request->post('application_id', 0); $identityId = (int) $request->post('identity_id', 0); $attributes = $request->post('attributes', []);
        if ($applicationId <= 0 || $identityId <= 0 || !is_array($attributes)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请提供应用、应用身份和 JSON 授权上下文', 400);
        $this->access()->assertApplication($applicationId);
        $result = (new PolicyAuthorizer())->simulate($applicationId, $identityId, trim((string) $request->post('resource_code', '')), trim((string) $request->post('action', '')), trim((string) $request->post('operation', 'read')), $attributes, RequestId::fromRequest($request));
        return $this->success($result)->withHeader('Cache-Control', 'no-store');
    }

    #[Permission('SandIAM 策略撤销', 'sand_iam:policy:revoke')]
    public function revoke(Request $request): Response
    {
        $policy = $this->find($request);
        $policy->save(['state' => 'revoked', 'status' => 2]);
        $this->audit('revoke', (int) $policy->id, $request);
        return $this->success('已撤销');
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $merged = array_merge($existing?->toArray() ?? [], $payload);
        $this->assertPolicyShape($merged);
    }

    #[Permission('SandIAM 策略更新', 'sand_iam:policy:update')]
    public function update(Request $request): Response
    {
        $policy = $this->find($request);
        if ($request->post('application_id', null) !== null && (int) $request->post('application_id') !== (int) $policy->application_id) throw new ApiException('SAND_IAM_POLICY_APPLICATION_IMMUTABLE: 已创建策略不可变更所属接入应用；请新建草稿策略', 409);
        return parent::update($request);
    }

    /** @param array<string, mixed> $policy */
    private function assertPolicyShape(array $policy): void
    {
        $applicationId = (int) ($policy['application_id'] ?? 0);
        $resource = Resource::where('id', (int) ($policy['resource_id'] ?? 0))->where('application_id', $applicationId)->where('status', 1)->find();
        if (!$resource) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 业务资源不存在、已停用或不属于所选应用', 400); }
        $roleId = (int) ($policy['role_id'] ?? 0);
        $identityId = (int) ($policy['identity_id'] ?? 0);
        if (($roleId > 0) === ($identityId > 0)) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: 策略主体必须在角色和应用身份中选择一项且只能选择一项', 400); }
        if ($roleId > 0 && !Role::where('id', $roleId)->where('application_id', $applicationId)->where('status', 1)->find()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 角色不存在、已停用或不属于所选应用', 400); }
        if ($identityId > 0 && !Identity::where('id', $identityId)->where('application_id', $applicationId)->where('status', 1)->find()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 应用身份不存在、已停用或不属于所选应用', 400); }
        (new ApplicationBusinessActionCatalog())->assertEnabled($applicationId, (string) ($policy['action'] ?? ''), true);
        if (!in_array($policy['effect'] ?? '', ['allow', 'deny'], true) || !in_array($policy['state'] ?? 'draft', ['draft', 'published', 'revoked'], true)) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: 授权效果或发布状态不正确', 400); }
        try { $matcher = new ScopeMatcher(); $matcher->assertValid((array) ($policy['condition'] ?? []), '策略生效条件'); $matcher->assertValid((array) ($policy['scope'] ?? []), '策略数据范围'); } catch (ApiException $exception) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: ' . $exception->getMessage(), 400); }
    }
}
