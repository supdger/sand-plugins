<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationBusinessAction;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApplicationBusinessActionController extends ApplicationResourceController
{
    protected string $modelClass = ApplicationBusinessAction::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'description', 'state', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name'];
    protected string $resourceType = 'application_business_action';

    // Reuses the existing API-governance administration capability until a
    // separately versioned permission catalog exposes a dedicated UI entry.
    #[Permission('SandIAM 应用业务动作列表', 'sand_iam:api_resource:index')]
    public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 应用业务动作读取', 'sand_iam:api_resource:read')]
    public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 应用业务动作保存', 'sand_iam:api_resource:save')]
    public function save(Request $request): Response
    {
        try {
            return parent::save($request);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->throwWriteFailure($exception);
        }
    }
    #[Permission('SandIAM 应用业务动作更新', 'sand_iam:api_resource:update')]
    public function update(Request $request): Response
    {
        // The common resource controller intentionally drops code on update.
        // Reject instead of silently accepting a rename so SDK/policy/route
        // references can never drift, including after publication.
        $posted = $request->post();
        if (array_key_exists('code', $posted)) {
            $model = $this->find($request);
            if ((string) $posted['code'] !== (string) $model->code) {
                throw new ApiException('SAND_IAM_APPLICATION_ACTION_CODE_IMMUTABLE: 业务动作代码创建后不可修改；请新建声明并迁移接口和策略引用', 409);
            }
        }
        try {
            return parent::update($request);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->throwWriteFailure($exception);
        }
    }
    #[Permission('SandIAM 应用业务动作停用', 'sand_iam:api_resource:disable')]
    public function disable(Request $request): Response { return parent::disable($request); }

    #[Permission('SandIAM 应用业务动作发布', 'sand_iam:api_resource:update')]
    public function publish(Request $request): Response
    {
        $model = $this->find($request);
        if ((int) $model->status !== ApplicationBusinessActionCatalog::STATUS_ENABLED) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_INVALID: 停用的业务动作不能发布', 400);
        }
        $model->save(['state' => ApplicationBusinessActionCatalog::STATE_PUBLISHED]);
        $this->audit('publish', (int) $model->id, $request);
        return $this->success('已发布；业务动作代码从创建起即不可修改');
    }

    #[Permission('SandIAM 应用业务动作待认领报告', 'sand_iam:api_resource:read')]
    public function pendingClaims(Request $request): Response
    {
        $applicationId = (int) $request->input('application_id', 0);
        $this->access()->assertApplication($applicationId);
        return $this->success([
            'application_id' => $applicationId,
            'state' => 'pending_claim',
            'items' => (new ApplicationBusinessActionCatalog())->pendingClaimsForApplication($applicationId),
        ]);
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $merged = array_merge($existing?->toArray() ?? [], $payload);
        $applicationId = (int) ($merged['application_id'] ?? 0);
        if (Application::where('id', $applicationId)->where('status', 1)->find() === null) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 400);
        }
        ApplicationBusinessActionCatalog::declaration($merged);
    }

    protected function isValidCode(string $code): bool
    {
        try { ApplicationBusinessActionCatalog::code($code); return true; } catch (ApiException) { return false; }
    }

    protected function codeValidationMessage(): string
    {
        return '业务动作代码须为 2–96 位，以小写字母开头，只能包含小写字母、数字、点、下划线、冒号或短横线';
    }

    private function throwWriteFailure(\Throwable $exception): never
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'unique') || str_contains($message, 'duplicate key') || str_contains($message, '23505')) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_CONFLICT: 当前应用已存在相同业务动作代码，请使用已有声明或改用新代码', 409);
        }
        throw $exception;
    }
}
