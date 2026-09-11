<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationNetworkPolicy;
use plugin\SandIam\app\security\NetworkPolicy;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApplicationNetworkPolicyController extends ApplicationResourceController
{
    protected string $modelClass = ApplicationNetworkPolicy::class;
    protected array $writeFields = ['application_id', 'allow_cidrs', 'deny_cidrs', 'status'];
    protected array $requiredFields = ['application_id'];
    protected string $resourceType = 'application_network_policy';

    #[Permission('SandIAM 应用网络规则列表', 'sand_iam:application_network_policy:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 应用网络规则读取', 'sand_iam:application_network_policy:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 应用网络规则保存', 'sand_iam:application_network_policy:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 应用网络规则更新', 'sand_iam:application_network_policy:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 应用网络规则停用', 'sand_iam:application_network_policy:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属应用不存在或已停用', 400);
        if ($existing === null && ApplicationNetworkPolicy::where('application_id', $applicationId)->find()) throw new ApiException('SAND_IAM_NETWORK_POLICY_EXISTS: 该应用已有网络规则，请直接编辑', 409);
    }

    protected function normalizePayload(array $payload, ?object $existing = null): array
    {
        $normalized = NetworkPolicy::normalize([
            'allow_cidrs' => $payload['allow_cidrs'] ?? $existing?->allow_cidrs ?? [],
            'deny_cidrs' => $payload['deny_cidrs'] ?? $existing?->deny_cidrs ?? [],
        ]);
        if (array_key_exists('allow_cidrs', $payload) || $existing === null) $payload['allow_cidrs'] = $normalized['allow_cidrs'];
        if (array_key_exists('deny_cidrs', $payload) || $existing === null) $payload['deny_cidrs'] = $normalized['deny_cidrs'];
        return $payload;
    }
}
