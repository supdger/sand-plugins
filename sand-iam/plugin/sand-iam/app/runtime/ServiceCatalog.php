<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Registers service-provider capabilities declared by an installed plugin.
 *
 * The catalog owns only the shared service/action directory. It never creates
 * an organization, application, workload client, credential, or grant.
 */
final class ServiceCatalog
{
    private const MAX_ACTIONS = 256;

    /**
     * @param array{code:string,name:string} $service
     * @param array<string,string> $actions
     */
    public function registerServiceActions(array $service, array $actions): void
    {
        [$serviceCode, $serviceName, $normalizedActions] = $this->declaration($service, $actions);
        $this->persist($serviceCode, $serviceName, $normalizedActions, 0);
    }

    /** @param array<string,string> $actions */
    private function persist(string $serviceCode, string $serviceName, array $actions, int $retry): void
    {
        Db::startTrans();
        try {
            $serviceRecord = Service::where('code', $serviceCode)->lock(true)->find();
            if ($serviceRecord === null) {
                $serviceRecord = new Service();
                $serviceRecord->save(['code' => $serviceCode, 'name' => $serviceName, 'status' => 1]);
            } elseif ((int) $serviceRecord->status !== 1) {
                throw new ApiException("SAND_IAM_SERVICE_CATALOG_DISABLED: 平台服务已停用，不能由插件安装自动恢复：{$serviceCode}", 409);
            }

            foreach ($actions as $actionCode => $actionName) {
                $action = ServiceAction::where('service_id', (int) $serviceRecord->id)
                    ->where('code', $actionCode)
                    ->lock(true)
                    ->find();
                if ($action === null) {
                    $action = new ServiceAction();
                    $action->save([
                        'service_id' => (int) $serviceRecord->id,
                        'code' => $actionCode,
                        'name' => $actionName,
                        'status' => 1,
                    ]);
                } elseif ((int) $action->status !== 1) {
                    throw new ApiException("SAND_IAM_SERVICE_CATALOG_DISABLED: 服务动作已停用，不能由插件安装自动恢复：{$actionCode}", 409);
                }
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($retry === 0 && $this->isUniqueViolation($exception)) {
                $this->persist($serviceCode, $serviceName, $actions, 1);
                return;
            }
            throw $exception;
        }
    }

    /**
     * @param array{code:string,name:string} $service
     * @param array<string,string> $actions
     * @return array{0:string,1:string,2:array<string,string>}
     */
    private function declaration(array $service, array $actions): array
    {
        $serviceCode = trim((string) ($service['code'] ?? ''));
        $serviceName = trim((string) ($service['name'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $serviceCode) !== 1
            || $serviceName === '' || mb_strlen($serviceName) > 128
            || $actions === [] || count($actions) > self::MAX_ACTIONS) {
            throw new ApiException('SAND_IAM_SERVICE_CATALOG_INVALID: 服务代码、名称或动作数量不符合插件目录契约', 400);
        }

        $normalized = [];
        foreach ($actions as $actionCode => $actionName) {
            $actionCode = trim((string) $actionCode);
            $actionName = trim((string) $actionName);
            if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}$/', $actionCode) !== 1
                || $actionName === '' || mb_strlen($actionName) > 128
                || isset($normalized[$actionCode])) {
                throw new ApiException('SAND_IAM_SERVICE_CATALOG_INVALID: 服务动作代码、名称或唯一性不符合插件目录契约', 400);
            }
            $normalized[$actionCode] = $actionName;
        }

        return [$serviceCode, $serviceName, $normalized];
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, '23505') || str_contains($message, 'unique constraint');
    }
}
