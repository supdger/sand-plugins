<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\sandadmin\exception\ApiException;

/**
 * Pure normalization for the access-matrix grant input. Contains no model or
 * database access so it can be unit tested without PostgreSQL.
 */
final class AccessMatrixInput
{
    public const DEFAULT_AUDIENCE = 'sand-ai';
    public const DEFAULT_ORGANIZATION_CODE = 'default';
    public const DEFAULT_ORGANIZATION_NAME = '本机构';
    public const DEFAULT_ENVIRONMENT_CODE = 'prod';
    public const DEFAULT_ENVIRONMENT_NAME = '正式';
    public const DEFAULT_CLIENT_CODE = 'sand-ai';
    public const DEFAULT_CLIENT_NAME = 'AI 服务调用';

    /** @var list<string> */
    private const ALLOWED_SERVICE_CODES = ['sand_ai'];

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   organization_id:?int,
     *   application:array{id:?int,code:?string,name:?string},
     *   service_code:string,
     *   action_codes:list<string>,
     *   environment_code:string,
     *   issue_credential:bool,
     *   credential_name:string
     * }
     */
    public static function normalizeGrant(array $input): array
    {
        $organizationId = self::positiveInt($input['organization_id'] ?? null, 'organization_id');

        $application = $input['application'] ?? null;
        if (!is_array($application)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 应用数据必填，包含 id 或 code 与名称', 400);
        }
        $applicationId = self::positiveInt($application['id'] ?? null, 'application.id');
        $applicationCode = trim((string) ($application['code'] ?? ''));
        $applicationName = trim((string) ($application['name'] ?? ''));
        if ($applicationId === null) {
            if ($applicationCode === '' || $applicationName === '') {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 未提供应用 id 时，code 与名称必填', 400);
            }
            if (!self::isValidCode($applicationCode)) {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 应用 code 须为 2–64 位小写字母、数字、短横线或下划线，且首位为字母或数字', 400);
            }
            if (mb_strlen($applicationName) > 128) {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 应用名称不能超过 128 个字符', 400);
            }
        }

        $serviceCode = trim((string) ($input['service_code'] ?? 'sand_ai'));
        if (!in_array($serviceCode, self::ALLOWED_SERVICE_CODES, true)) {
            throw new ApiException('SAND_IAM_ACCESS_MATRIX_SERVICE_NOT_ALLOWED: 该服务不支持一键授权', 400);
        }

        $actionCodes = $input['action_codes'] ?? null;
        if (!is_array($actionCodes) || $actionCodes === []) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请选择至少一个能力', 400);
        }
        $normalizedActions = [];
        foreach ($actionCodes as $code) {
            $value = trim((string) $code);
            if ($value === '' || strlen($value) > 96 || !preg_match('/^[a-z0-9][a-z0-9._-]{0,95}$/', $value)) {
                throw new ApiException('SAND_IAM_ACCESS_MATRIX_ACTION_INVALID: 能力代码不合法', 400);
            }
            $normalizedActions[] = $value;
        }
        $normalizedActions = array_values(array_unique($normalizedActions));

        $environmentCode = trim((string) ($input['environment_code'] ?? self::DEFAULT_ENVIRONMENT_CODE));
        if ($environmentCode === '') $environmentCode = self::DEFAULT_ENVIRONMENT_CODE;
        if (!self::isValidCode($environmentCode)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 环境 code 不合法', 400);
        }

        $credentialName = trim((string) ($input['credential_name'] ?? '首页一键接入'));
        if ($credentialName === '') $credentialName = '首页一键接入';
        if (mb_strlen($credentialName) > 128) $credentialName = '首页一键接入';

        return [
            'organization_id' => $organizationId,
            'application' => [
                'id' => $applicationId,
                'code' => $applicationCode === '' ? null : $applicationCode,
                'name' => $applicationName === '' ? null : $applicationName,
            ],
            'service_code' => $serviceCode,
            'action_codes' => $normalizedActions,
            'environment_code' => $environmentCode,
            'issue_credential' => filter_var($input['issue_credential'] ?? false, FILTER_VALIDATE_BOOL),
            'credential_name' => $credentialName,
        ];
    }

    /**
     * Server-side whitelist: keeps only action codes that exist in the
     * provided active codes list. Returns the clipped list and skipped codes.
     *
     * @param list<string> $requested @param list<string> $activeCodes
     * @return array{granted:list<string>,skipped:list<string>}
     */
    public static function clipActionCodes(array $requested, array $activeCodes): array
    {
        $granted = array_values(array_filter($requested, static fn (string $code): bool => in_array($code, $activeCodes, true)));
        $skipped = array_values(array_diff($requested, $granted));
        return ['granted' => $granted, 'skipped' => $skipped];
    }

    private static function positiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '' || $value === 0) return null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/', trim($value)))) {
            throw new ApiException("SAND_IAM_VALIDATION_ERROR: {$field} 必须为正整数", 400);
        }
        $int = (int) $value;
        if ($int <= 0) {
            throw new ApiException("SAND_IAM_VALIDATION_ERROR: {$field} 必须为正整数", 400);
        }
        return $int;
    }

    private static function isValidCode(string $code): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) === 1;
    }
}
