<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class EnvironmentController extends ApplicationResourceController
{
    protected string $modelClass = Environment::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name'];
    protected string $resourceType = 'environment';
    #[Permission('SandIAM 应用环境列表', 'sand_iam:environment:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 应用环境读取', 'sand_iam:environment:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 应用环境保存', 'sand_iam:environment:save')]
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

    #[Permission('SandIAM 应用环境更新', 'sand_iam:environment:update')]
    public function update(Request $request): Response
    {
        try {
            return parent::update($request);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->throwWriteFailure($exception);
        }
    }
    #[Permission('SandIAM 应用环境停用', 'sand_iam:environment:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属应用不存在或已停用', 400); }

    private function throwWriteFailure(\Throwable $exception): never
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'uk_sand_iam_environment_application_code')) {
            throw new ApiException('SAND_IAM_ENVIRONMENT_CONFLICT: 所选接入应用中已存在相同的环境代码，请使用已有环境或换一个系统代码', 409);
        }
        throw $exception;
    }
}
