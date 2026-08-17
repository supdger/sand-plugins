<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\runtime\ScopeMatcher;
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
    #[Permission('SandIAM 策略更新', 'sand_iam:policy:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 策略停用', 'sand_iam:policy:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    #[Permission('SandIAM 策略发布', 'sand_iam:policy:publish')]
    public function publish(Request $request): Response
    {
        $policy = $this->find($request);
        $this->assertPolicyShape($policy->toArray());
        $policy->save(['state' => 'published', 'status' => 1]);
        $this->audit('publish', (int) $policy->id, $request);
        return $this->success('已发布');
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

    /** @param array<string, mixed> $policy */
    private function assertPolicyShape(array $policy): void
    {
        $applicationId = (int) ($policy['application_id'] ?? 0);
        $resource = Resource::where('id', (int) ($policy['resource_id'] ?? 0))->where('application_id', $applicationId)->where('status', 1)->find();
        if (!$resource) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: resource', 400); }
        $roleId = (int) ($policy['role_id'] ?? 0);
        $identityId = (int) ($policy['identity_id'] ?? 0);
        if (($roleId > 0) === ($identityId > 0)) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: exactly one policy subject is required', 400); }
        if ($roleId > 0 && !Role::where('id', $roleId)->where('application_id', $applicationId)->where('status', 1)->find()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: role', 400); }
        if ($identityId > 0 && !Identity::where('id', $identityId)->where('application_id', $applicationId)->where('status', 1)->find()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: identity', 400); }
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', (string) ($policy['action'] ?? ''))) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid action', 400); }
        if (!in_array($policy['effect'] ?? '', ['allow', 'deny'], true) || !in_array($policy['state'] ?? 'draft', ['draft', 'published', 'revoked'], true)) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid policy state', 400); }
        try { $matcher = new ScopeMatcher(); $matcher->assertValid((array) ($policy['condition'] ?? []), 'policy condition'); $matcher->assertValid((array) ($policy['scope'] ?? []), 'policy scope'); } catch (ApiException $exception) { throw new ApiException('SAND_IAM_VALIDATION_ERROR: ' . $exception->getMessage(), 400); }
    }
}
