<?php

declare(strict_types=1);

namespace Sand\Iam\Sdk;

/**
 * SandAdmin management-plane client. It is deliberately separate from the
 * application/runtime client: its Bearer token is an administrator session,
 * never a workload credential or an application-user access token.
 */
final class SandIamManagementClient
{
    private const ROOT = '/app/sand-iam/admin';

    public function __construct(
        private readonly string $baseUrl,
        private readonly \Closure $administratorToken,
        private readonly int $timeoutSeconds = 5,
        private readonly ?\Closure $transport = null,
    ) {
        $url = parse_url($baseUrl);
        if ($url === false || empty($url['host']) || preg_match('/\s/', $baseUrl) || str_contains($baseUrl, '\\')
            || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
            || !(($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http'
                && in_array(strtolower($url['host']), ['localhost', '127.0.0.1', '[::1]'], true)))
            || $timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_CONFIGURATION', '管理 API 地址必须使用 HTTPS；仅本机开发允许 HTTP', 0);
        }
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function onboardingPreview(array $manifest, string $requestId = ''): array
    {
        return $this->arrayData('POST', '/developer/onboarding/preview', ['manifest' => $manifest], $requestId, false);
    }

    /** @return array<string,mixed> */
    public function onboardingApply(SandIamOnboardingOperation $operation): array
    {
        $this->assertOperation($operation->manifest, $operation->requestId, true);
        if ($operation->previewHash === null || trim($operation->previewHash) === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '应用接入草稿必须提供预检哈希', 0);
        return $this->arrayData('POST', '/developer/onboarding/apply', ['manifest' => $operation->manifest, 'preview_hash' => $operation->previewHash, 'apply' => true], $operation->requestId, true);
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function routeSyncPreview(array $manifest, bool $disableMissing = false, string $requestId = ''): array
    {
        return $this->arrayData('POST', '/developer/route-manifest/preview', ['manifest' => $manifest, 'disable_missing' => $disableMissing], $requestId, false);
    }

    /** @return array<string,mixed> */
    public function routeSyncApply(SandIamRouteSyncOperation $operation): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $operation->previewHash) !== 1) throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '路由清单必须提供有效预检哈希', 0);
        $this->assertRequestId($operation->requestId);
        return $this->arrayData('POST', '/developer/route-manifest/apply', [
            'manifest' => $operation->manifest,
            'disable_missing' => $operation->disableMissing,
            'preview_hash' => $operation->previewHash,
            'apply' => true,
        ], $operation->requestId, true);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function policySimulate(array $input, string $requestId = ''): array
    {
        return $this->arrayData('POST', '/policy/simulate', $input, $requestId, false);
    }

    /** @return array<string,mixed> */
    public function policyRollback(int $policyId, int $versionId, string $requestId): array
    {
        if ($policyId <= 0 || $versionId <= 0) throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '策略和版本编号必须为正整数', 0);
        $this->assertRequestId($requestId);
        return $this->arrayData('POST', '/policy/rollback', ['id' => $policyId, 'version_id' => $versionId], $requestId, true);
    }

    public function credentialIssue(SandIamCredentialIssueInput $input): SandIamCredentialResult
    {
        if ($input->workloadClientId <= 0 || trim($input->name) === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '服务调用身份和凭证名称不能为空', 0);
        $this->assertRequestId($input->requestId);
        return $this->credentialResult($this->arrayData('POST', '/credential/issue', ['workload_client_id' => $input->workloadClientId, 'name' => $input->name, 'expire_time' => $input->expireTime], $input->requestId, true));
    }

    public function credentialRotate(SandIamCredentialRotateInput $input): SandIamCredentialResult
    {
        if ($input->credentialId <= 0 || trim($input->name) === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号和名称不能为空', 0);
        $this->assertRequestId($input->requestId);
        return $this->credentialResult($this->arrayData('POST', '/credential/rotate', ['id' => $input->credentialId, 'name' => $input->name, 'expire_time' => $input->expireTime], $input->requestId, true));
    }

    /** @return array<string,mixed> */
    public function credentialRevoke(SandIamCredentialRevokeInput $input): array
    {
        if ($input->credentialId <= 0) throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号必须为正整数', 0);
        $this->assertRequestId($input->requestId);
        return $this->arrayData('POST', '/credential/revoke', ['id' => $input->credentialId], $input->requestId, true);
    }

    /** @return list<array<string,mixed>> */
    public function providerPresetList(string $requestId = ''): array
    {
        return $this->listData('GET', '/identity-provider-preset/index', null, $requestId, false);
    }

    /** @return array<string,mixed> */
    public function providerPresetDraft(SandIamProviderPresetDraftInput $input): array
    {
        if (trim($input->code) === '' || trim($input->clientId) === '' || trim($input->redirectUri) === '' || $input->handoffReturnUris === []) throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '预设代码、客户端编号、回调地址和回跳白名单不能为空', 0);
        $body = ['code' => $input->code, 'client_id' => $input->clientId, 'redirect_uri' => $input->redirectUri, 'handoff_return_uris' => $input->handoffReturnUris];
        if ($input->tenantId !== null) $body['tenant_id'] = $input->tenantId;
        return $this->arrayData('POST', '/identity-provider-preset/draft', $body, $input->requestId ?? '', false);
    }

    /** @param array<string,mixed> $manifest */
    private function assertOperation(array $manifest, string $requestId, bool $requiresPreview): void
    {
        if (!is_string($manifest['operation_id'] ?? null) || trim((string) $manifest['operation_id']) === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '应用或路由同步必须在 manifest 中提供 operation_id', 0);
        if ($requiresPreview) $this->assertRequestId($requestId);
    }

    private function assertRequestId(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{8,96}$/', $requestId) !== 1) throw new SandIamException('SAND_IAM_SDK_REQUEST_ID_REQUIRED', '写操作必须显式提供 8–96 位 request_id', 0);
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function arrayData(string $method, string $path, ?array $payload, string $requestId, bool $write): array
    {
        $data = $this->request($method, $path, $payload, $requestId, $write);
        if (!is_array($data) || array_is_list($data)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的数据结构不正确', 200);
        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function listData(string $method, string $path, ?array $payload, string $requestId, bool $write): array
    {
        $data = $this->request($method, $path, $payload, $requestId, $write);
        if (!is_array($data) || !array_is_list($data) || array_filter($data, static fn (mixed $item): bool => !is_array($item) || array_is_list($item))) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的列表不正确', 200);
        return $data;
    }

    /** @param array<string,mixed>|null $payload */
    private function request(string $method, string $path, ?array $payload, string $requestId, bool $write): mixed
    {
        if ($write) $this->assertRequestId($requestId);
        $requestId = $requestId !== '' ? $requestId : bin2hex(random_bytes(16));
        $token = trim((string) ($this->administratorToken)());
        if ($token === '') throw new SandIamException('SAND_IAM_AUTHENTICATION_FAILED', '管理员登录态或 Bearer 令牌不能为空', 401);
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        [$status, $response] = $this->send(rtrim($this->baseUrl, '/') . self::ROOT . $path, $method, $token, $requestId, $body);
        try { $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException $exception) { throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回了无法解析的数据', $status, $exception); }
        if (!is_array($decoded)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回了无法识别的数据', $status);
        if ($status < 200 || $status >= 300) {
            $message = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'SandIAM 管理 API 请求失败');
            preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $matches);
            throw new SandIamException($matches[1] ?? 'SAND_IAM_REQUEST_FAILED', $message, $status);
        }
        return $decoded['data'] ?? null;
    }

    /** @return array{0:int,1:string} */
    private function send(string $url, string $method, string $token, string $requestId, ?string $body): array
    {
        $headers = ['Authorization' => 'Bearer ' . $token, 'X-Request-Id' => $requestId, 'Cache-Control' => 'no-store', 'Accept' => 'application/json'];
        if ($body !== null) $headers['Content-Type'] = 'application/json';
        if ($this->transport !== null) {
            $result = ($this->transport)(['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $body, 'timeout_seconds' => $this->timeoutSeconds]);
            if (!is_array($result) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) throw new SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED', '自定义管理 API 传输器返回了无效结果', 0);
            return [$result['status'], $result['body']];
        }
        $handle = curl_init($url);
        if ($handle === false) throw new SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED', '无法初始化 SandIAM 管理 API 请求', 0);
        $curlHeaders = array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds, CURLOPT_TIMEOUT => $this->timeoutSeconds, CURLOPT_HTTPHEADER => $curlHeaders, CURLOPT_CUSTOMREQUEST => $method] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]));
        $response = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if (!is_string($response)) throw new SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED', 'SandIAM 管理 API 请求失败：' . $error, $status);
        return [$status, $response];
    }

    /** @param array<string,mixed> $data */
    private function credentialResult(array $data): SandIamCredentialResult
    {
        $replayed = ($data['replayed'] ?? false) === true;
        $credential = $data['credential'] ?? null;
        if ($credential !== null && (!is_string($credential) || $credential === '' || $replayed)) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', '重放响应不得包含一次性调用凭证', 200);
        }
        unset($data['credential']);
        return new SandIamCredentialResult($data, $credential !== null, $credential === null ? null : new SandIamOneTimeSecret($credential), $replayed);
    }
}
