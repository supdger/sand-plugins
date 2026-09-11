<?php

declare(strict_types=1);

namespace plugin\SandIam\app\developer;

use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\ApplicationBusinessAction;
use plugin\SandIam\app\model\Policy;
use plugin\sandadmin\exception\ApiException;

/**
 * Authoritative application business-action catalog.
 *
 * A missing declaration is a compatibility "pending claim" only for already
 * stored records. New configuration and route synchronization use strict mode.
 */
final class ApplicationBusinessActionCatalog
{
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 2;
    public const STATE_DRAFT = 'draft';
    public const STATE_PUBLISHED = 'published';

    public static function code(string $value): string
    {
        $code = trim($value);
        if (!preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/', $code)) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_CODE_INVALID: 业务动作代码须为 2–96 位，以小写字母开头，只能包含小写字母、数字、点、下划线、冒号或短横线', 400);
        }
        return $code;
    }

    /** @param array<string,mixed> $input @return array{code:string,name:string,description:string,state:string,status:int} */
    public static function declaration(array $input): array
    {
        $code = self::code((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $state = (string) ($input['state'] ?? self::STATE_DRAFT);
        $status = (int) ($input['status'] ?? self::STATUS_ENABLED);
        if ($name === '' || mb_strlen($name) > 128 || preg_match('/[\p{Cc}]/u', $name)) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_INVALID: 业务动作中文名称无效', 400);
        }
        if (mb_strlen($description) > 500 || preg_match('/[\p{Cc}]/u', $description)) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_INVALID: 业务动作说明无效', 400);
        }
        if (!in_array($state, [self::STATE_DRAFT, self::STATE_PUBLISHED], true) || !in_array($status, [self::STATUS_ENABLED, self::STATUS_DISABLED], true)) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_INVALID: 业务动作发布状态或启用状态无效', 400);
        }
        return compact('code', 'name', 'description', 'state', 'status');
    }

    /**
     * @return 'declared'|'pending_claim'
     */
    public function assertEnabled(int $applicationId, string $action, bool $strict = true): string
    {
        $action = self::code($action);
        $declaration = ApplicationBusinessAction::where('application_id', $applicationId)->where('code', $action)->find();
        if ($declaration === null) {
            if ($strict) {
                throw new ApiException('SAND_IAM_APPLICATION_ACTION_UNDECLARED: 业务动作未在所属接入应用声明，不能用于新建、变更或同步', 409);
            }
            return 'pending_claim';
        }
        if ((int) $declaration->status !== self::STATUS_ENABLED) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_DISABLED: 业务动作已停用，接口授权已按拒绝处理', 403);
        }
        return 'declared';
    }

    /** @return list<array{code:string,sources:list<string>,state:string}> */
    public function pendingClaimsForApplication(int $applicationId): array
    {
        $declared = array_flip(ApplicationBusinessAction::where('application_id', $applicationId)->column('code'));
        $claims = [];
        foreach (ApiResource::where('application_id', $applicationId)->where('status', self::STATUS_ENABLED)->select() as $api) {
            $this->collectClaim($claims, (string) $api->action, 'api_resource', $declared);
        }
        foreach (Policy::where('application_id', $applicationId)->where('status', self::STATUS_ENABLED)->select() as $policy) {
            $this->collectClaim($claims, (string) $policy->action, 'policy', $declared);
        }
        ksort($claims);
        return array_values($claims);
    }

    /** @param list<array<string,mixed>> $declarations @return array<string,string> */
    public static function sdkConstants(array $declarations): array
    {
        $constants = [];
        foreach ($declarations as $declaration) {
            $code = self::code((string) ($declaration['code'] ?? ''));
            $constant = strtoupper((string) preg_replace('/[^a-z0-9]+/', '_', $code));
            $constant = trim($constant, '_');
            if ($constant === '' || isset($constants[$constant])) {
                throw new ApiException('SAND_IAM_APPLICATION_ACTION_SDK_CONSTANT_CONFLICT: 业务动作无法生成唯一 SDK 常量', 409);
            }
            $constants[$constant] = $code;
        }
        ksort($constants);
        return $constants;
    }

    /** @param array<string,array{code:string,sources:list<string>,state:string}> $claims @param array<string,mixed> $declared */
    private function collectClaim(array &$claims, string $action, string $source, array $declared): void
    {
        if (isset($declared[$action])) return;
        try {
            $action = self::code($action);
        } catch (ApiException) {
            return;
        }
        $claims[$action] ??= ['code' => $action, 'sources' => [], 'state' => 'pending_claim'];
        if (!in_array($source, $claims[$action]['sources'], true)) $claims[$action]['sources'][] = $source;
    }
}
