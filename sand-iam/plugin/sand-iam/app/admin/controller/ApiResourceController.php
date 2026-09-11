<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Resource;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApiResourceController extends ApplicationResourceController
{
    private const OPERATIONS = ['list', 'read', 'create', 'update', 'delete', 'export', 'batch'];
    private const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    protected string $modelClass = ApiResource::class;
    protected array $writeFields = [
        'application_id',
        'resource_id',
        'code',
        'name',
        'action',
        'operation',
        'api_version',
        'audience',
        'required_scope',
        'risk_level',
        'description',
        'status',
    ];
    protected array $requiredFields = [
        'application_id',
        'resource_id',
        'code',
        'name',
        'action',
        'operation',
        'api_version',
        'audience',
        'risk_level',
    ];
    protected string $resourceType = 'api_resource';

    #[Permission('SandIAM 接口目录列表', 'sand_iam:api_resource:index')]
    public function index(Request $request): Response { return parent::index($request); }

    #[Permission('SandIAM 接口目录读取', 'sand_iam:api_resource:read')]
    public function read(Request $request): Response { return parent::read($request); }

    #[Permission('SandIAM 接口目录保存', 'sand_iam:api_resource:save')]
    public function save(Request $request): Response
    {
        try {
            return parent::save($request);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ApiException('SAND_IAM_API_CONFLICT: 当前应用中已存在相同接口代码和版本', 409);
            }
            throw $exception;
        }
    }

    #[Permission('SandIAM 接口目录更新', 'sand_iam:api_resource:update')]
    public function update(Request $request): Response { return parent::update($request); }

    #[Permission('SandIAM 接口目录停用', 'sand_iam:api_resource:disable')]
    public function disable(Request $request): Response { return parent::disable($request); }

    protected function payload(Request $request, bool $updating): array
    {
        $payload = parent::payload($request, $updating);
        if ($updating) {
            foreach (['application_id', 'resource_id', 'code', 'action', 'operation', 'api_version'] as $immutable) {
                unset($payload[$immutable]);
            }
        }
        return $payload;
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        $resourceId = (int) ($payload['resource_id'] ?? $existing?->resource_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 400);
        }
        if (!Resource::where('id', $resourceId)->where('application_id', $applicationId)->where('status', 1)->find()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 业务资源不存在、已停用或不属于所选应用', 400);
        }

        $action = (string) ($payload['action'] ?? $existing?->action ?? '');
        // New and changed API records must bind to the application's declared
        // business vocabulary. Legacy rows are intentionally handled by the
        // pending-claim compatibility report rather than guessed as valid.
        (new ApplicationBusinessActionCatalog())->assertEnabled($applicationId, $action, true);
        $operation = (string) ($payload['operation'] ?? $existing?->operation ?? '');
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 数据操作类型不在允许范围内', 400);
        }
        $apiVersion = (string) ($payload['api_version'] ?? $existing?->api_version ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $apiVersion)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 接口版本须为 1–32 位字母、数字、点、下划线或短横线', 400);
        }
        $audience = (string) ($payload['audience'] ?? $existing?->audience ?? '');
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$#', $audience)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 接口受众格式不正确', 400);
        }
        $requiredScope = (string) ($payload['required_scope'] ?? $existing?->required_scope ?? '');
        if ($requiredScope !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/', $requiredScope)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: OAuth Scope 格式不正确', 400);
        }
        $riskLevel = (string) ($payload['risk_level'] ?? $existing?->risk_level ?? '');
        if (!in_array($riskLevel, self::RISK_LEVELS, true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 风险等级只能选择低、中、高或关键', 400);
        }
        $description = (string) ($payload['description'] ?? $existing?->description ?? '');
        if (mb_strlen($description) > 500) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 接口说明不能超过 500 个字符', 400);
        }
    }

    protected function isValidCode(string $code): bool
    {
        return preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/', $code) === 1;
    }

    protected function codeValidationMessage(): string
    {
        return '接口代码须为 2–96 位，以小写字母开头，只能包含小写字母、数字、点、下划线、冒号或短横线';
    }
}
