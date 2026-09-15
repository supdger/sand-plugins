<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Credential;
use plugin\sandadmin\exception\ApiException;

/** One-time workload credential issuer shared by admin and onboarding flows. */
final class CredentialIssuanceService
{
    /** @return array{id:int,key_prefix:string,credential:string,expire_time:mixed} */
    public function issue(int $workloadClientId, string $name, mixed $expireTime, string $requestId, string $actor, ?int $organizationId = null, ?int $applicationId = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 凭证名称不能为空且不能超过 128 个字符', 400);
        if ($expireTime !== null && !is_string($expireTime)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 凭证到期时间须为时间字符串或空值', 400);
        if ($expireTime !== null && $expireTime !== '') {
            $format = 'Y-m-d H:i:s';
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $expireTime) || substr($expireTime, 0, 4) === '0000') {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 凭证到期时间须为有效的 YYYY-MM-DD HH:MM:SS', 400);
            }
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $expireTime);
            if ($date === false || $date->format($format) !== $expireTime) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 凭证到期时间须为有效的 YYYY-MM-DD HH:MM:SS', 400);
        }
        $plain = 'siam_' . bin2hex(random_bytes(24));
        $credential = Credential::create(['workload_client_id' => $workloadClientId, 'name' => $name, 'key_prefix' => substr($plain, 0, 16), 'secret_hash' => password_hash($plain, PASSWORD_DEFAULT), 'expire_time' => $expireTime === '' ? null : $expireTime, 'status' => 1]);
        (new AuditWriter())->write('admin', $actor, $organizationId, $applicationId, 'credential.issue', 'credential', (int) $credential->id, 'succeeded', $requestId, ['workload_client_id' => $workloadClientId, 'key_prefix' => (string) $credential->key_prefix]);
        return ['id' => (int) $credential->id, 'key_prefix' => (string) $credential->key_prefix, 'credential' => $plain, 'expire_time' => $credential->expire_time];
    }
}
