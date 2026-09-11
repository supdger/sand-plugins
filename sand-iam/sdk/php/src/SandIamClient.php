<?php

declare(strict_types=1);

namespace Sand\Iam\Sdk;

final class SandIamClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $organizationCode,
        private readonly string $applicationCode,
        private readonly int $timeoutSeconds = 3,
        private readonly ?\Closure $transport = null,
    ) {
        if (!str_starts_with($baseUrl, 'https://') && !str_starts_with($baseUrl, 'http://127.0.0.1') && !str_starts_with($baseUrl, 'http://localhost')) {
            throw new \InvalidArgumentException('SandIAM 地址必须使用 HTTPS；仅本机开发允许 HTTP');
        }
        if (
            !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $organizationCode)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $applicationCode)
            || $timeoutSeconds < 1
            || $timeoutSeconds > 30
        ) {
            throw new \InvalidArgumentException('SandIAM SDK 配置不完整');
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function decide(
        string $accessToken,
        string $apiCode,
        array $attributes = [],
        string $apiVersion = 'v1',
        string $requestId = '',
    ): array {
        if ($accessToken === '' || $apiCode === '') {
            throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '访问令牌和接口代码不能为空', 0);
        }
        $requestId = $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16));
        $payload = json_encode([
            'organization_code' => $this->organizationCode,
            'application_code' => $this->applicationCode,
            'api_code' => $apiCode,
            'api_version' => $apiVersion,
            'attributes' => $attributes,
        ], JSON_THROW_ON_ERROR);
        [$status, $response] = $this->send(
            rtrim($this->baseUrl, '/') . '/api/sand-iam/v1/authorization/decide',
            'POST',
            $accessToken,
            $requestId,
            $payload,
        );
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法解析的数据', $status, $exception);
        }
        if (!is_array($decoded)) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法识别的数据', $status);
        }
        if ($status < 200 || $status >= 300) {
            $message = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'SandIAM 请求失败');
            preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $matches);
            throw new SandIamException($matches[1] ?? 'SAND_IAM_REQUEST_FAILED', $message, $status);
        }
        $decision = $decoded['data'] ?? null;
        if (!$this->validDecision($decision)) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的授权结果缺少必要字段', $status);
        }
        return $decision;
    }

    /**
     * 签发短期工作负载上下文。凭证只会放进 Authorization 头，不能写入 body、URL 或日志。
     *
     * @param list<string> $actions
     * @param array<string,mixed>|null $subjectScope
     * @return array{context:string,context_id:string,expire_time:string}
     */
    public function issueContext(string $credential, string $serviceCode, string $audience, array $actions, ?array $subjectScope = null, string $requestId = ''): array
    {
        $this->assertWorkloadInput($credential, $serviceCode, $audience, $actions);
        $data = $this->workloadArrayData('POST', '/app/sand-iam/runtime/context/issue', $credential, [
            'service_code' => $serviceCode,
            'audience' => $audience,
            'actions' => array_values($actions),
            'subject_scope' => $subjectScope,
        ], $requestId);
        if (!is_string($data['context'] ?? null) || !is_string($data['context_id'] ?? null) || !is_string($data['expire_time'] ?? null)) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的工作负载上下文不完整', 200);
        }
        return ['context' => $data['context'], 'context_id' => $data['context_id'], 'expire_time' => $data['expire_time']];
    }

    /**
     * 公共运行时 verify 路由一次只接受一个 action，因此 SDK 会为每个 action 发送独立 HTTP 请求。
     * sourceIp 仅为服务端适配器保留；公共 HTTP API 从连接对端取得可信 IP，绝不把它序列化到请求中。
     *
     * @param list<string> $actions
     * @return array<string,mixed>
     */
    public function verifyContext(string $context, string $serviceCode, string $audience, array $actions, ?string $sourceIp = null, string $requestId = ''): array
    {
        $this->assertWorkloadInput($context, $serviceCode, $audience, $actions);
        if ($sourceIp !== null && filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', 'sourceIp 必须是服务器已验证的 IP 地址', 0);
        }
        $baseRequestId = $this->workloadRequestId($requestId);
        $claims = [];
        foreach (array_values($actions) as $index => $action) {
            $claims = $this->workloadArrayData('POST', '/app/sand-iam/runtime/context/verify', '', [
                'context' => $context,
                'audience' => $audience,
                'action' => $action,
            ], $this->derivedRequestId($baseRequestId, $index));
            $responseActions = $claims['actions'] ?? null;
            if (!$this->validWorkloadResponseActions($responseActions)) {
                throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的上下文动作列表不正确', 200);
            }
            if (!hash_equals($serviceCode, (string) ($claims['service_code'] ?? '')) || !in_array($action, $responseActions, true)) {
                throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的上下文与请求的服务或动作不一致', 200);
            }
        }
        return $claims;
    }

    /** @return array{0:int,1:string} */
    private function send(string $url, string $method, string $accessToken, string $requestId, ?string $payload, bool $noStore = false): array
    {
        $headers = ['X-Request-Id' => $requestId];
        if ($accessToken !== '') $headers['Authorization'] = 'Bearer ' . $accessToken;
        if ($payload !== null) $headers['Content-Type'] = 'application/json';
        if ($noStore) $headers['Cache-Control'] = 'no-store';
        if ($this->transport !== null) {
            $result = ($this->transport)([
                'url' => $url,
                'method' => $method,
                'headers' => $headers,
                'body' => $payload,
                'timeout_seconds' => $this->timeoutSeconds,
            ]);
            if (!is_array($result) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) {
                throw new SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED', '自定义传输器返回了无效结果', 0);
            }
            return [$result['status'], $result['body']];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED', '无法初始化 SandIAM 请求', 0);
        }
        $curlHeaders = array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $curlHeaders,
        ];
        if ($method !== 'GET') $options[CURLOPT_CUSTOMREQUEST] = $method;
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = $payload;
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response)) {
            throw new SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED', 'SandIAM 请求失败：' . $error, $status);
        }
        return [$status, $response];
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function authorize(
        string $accessToken,
        string $apiCode,
        array $attributes = [],
        string $apiVersion = 'v1',
        string $requestId = '',
    ): array {
        $decision = $this->decide($accessToken, $apiCode, $attributes, $apiVersion, $requestId);
        if ($decision['allowed'] !== true) {
            throw new AuthorizationDenied($decision);
        }
        return $decision;
    }

    /**
     * Two-stage helper for non-Webman consumers: obtain a coarse API decision,
     * then compare its scope with attributes extracted from a loaded entity.
     * Do not pass request-body owner or organization fields as $entity.
     *
     * @param callable(object):array<string,mixed> $attributeResolver
     * @param array<string,mixed> $routeAttributes
     * @return array<string,mixed>
     */
    public function authorizeEntity(
        string $accessToken,
        string $apiCode,
        object $entity,
        callable $attributeResolver,
        array $routeAttributes = [],
        string $apiVersion = 'v1',
        string $requestId = '',
    ): array {
        $decision = $this->authorize($accessToken, $apiCode, $routeAttributes, $apiVersion, $requestId);
        $this->assertEntityScope($decision, $entity, $attributeResolver);
        return $decision;
    }

    /**
     * @param iterable<object> $entities
     * @param callable(object):array<string,mixed> $attributeResolver
     * @param array<string,mixed> $routeAttributes
     * @return array<string,mixed>
     */
    public function authorizeCollection(
        string $accessToken,
        string $apiCode,
        iterable $entities,
        callable $attributeResolver,
        array $routeAttributes = [],
        string $apiVersion = 'v1',
        string $requestId = '',
    ): array {
        $decision = $this->authorize($accessToken, $apiCode, $routeAttributes, $apiVersion, $requestId);
        foreach ($entities as $entity) {
            if (!is_object($entity)) {
                throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '批量数据范围校验只接受已加载的实体对象', 0);
            }
            $this->assertEntityScope($decision, $entity, $attributeResolver);
        }
        return $decision;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function register(array $input, string $requestId = ''): array
    {
        return $this->arrayData('POST', '/api/sand-iam/v1/auth/register', '', $this->applicationPayload($input), $requestId);
    }

    /** @return array<string,mixed> */
    public function login(string $identifier, string $password, string $userAgent = '', string $requestId = ''): array
    {
        if (trim($identifier) === '' || $password === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '登录账号和密码不能为空', 0);
        return $this->arrayData('POST', '/api/sand-iam/v1/auth/login', '', $this->applicationPayload([
            'identifier' => $identifier,
            'password' => $password,
            'user_agent' => $userAgent,
        ]), $requestId);
    }

    /** @return array<string,mixed> */
    public function refresh(string $refreshToken, string $requestId = ''): array
    {
        if ($refreshToken === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '刷新令牌不能为空', 0);
        return $this->arrayData('POST', '/api/sand-iam/v1/auth/refresh', '', ['refresh_token' => $refreshToken], $requestId);
    }

    /** @return array<string,mixed> */
    public function profile(string $accessToken, string $requestId = ''): array
    {
        return $this->arrayData('GET', '/api/sand-iam/v1/me/profile', $accessToken, null, $requestId);
    }

    /** @return array<string,mixed> */
    public function updateProfile(string $accessToken, string $displayName, string $requestId = ''): array
    {
        return $this->arrayData('PATCH', '/api/sand-iam/v1/me/profile', $accessToken, ['display_name' => $displayName], $requestId);
    }

    /** @return array<string,mixed> */
    public function securityOverview(string $accessToken, string $requestId = ''): array
    {
        return $this->arrayData('GET', '/api/sand-iam/v1/me/security', $accessToken, null, $requestId);
    }

    /** @return list<array<string,mixed>> */
    public function connections(string $accessToken, string $requestId = ''): array
    {
        return $this->listData('GET', '/api/sand-iam/v1/me/connections', $accessToken, null, $requestId);
    }

    /** @return list<array<string,mixed>> */
    public function sessions(string $accessToken, string $requestId = ''): array
    {
        return $this->listData('GET', '/api/sand-iam/v1/auth/sessions', $accessToken, null, $requestId);
    }

    public function revokeSession(string $accessToken, int $sessionId, string $requestId = ''): void
    {
        if ($sessionId <= 0) throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '会话编号无效', 0);
        $this->requestData('POST', '/api/sand-iam/v1/auth/sessions/revoke', $accessToken, ['id' => $sessionId], $requestId);
    }

    public function changePassword(string $accessToken, string $currentPassword, string $newPassword, string $requestId = ''): void
    {
        if ($currentPassword === '' || $newPassword === '') throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '当前密码和新密码不能为空', 0);
        $this->requestData('POST', '/api/sand-iam/v1/auth/password/change', $accessToken, ['current_password' => $currentPassword, 'new_password' => $newPassword], $requestId);
    }

    public function logout(string $accessToken, string $requestId = ''): void
    {
        $this->requestData('POST', '/api/sand-iam/v1/auth/logout', $accessToken, [], $requestId);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function applicationPayload(array $payload): array
    {
        return ['organization_code' => $this->organizationCode, 'application_code' => $this->applicationCode] + $payload;
    }

    /** @param array<string,mixed>|null $payload */
    private function requestData(string $method, string $path, string $accessToken, ?array $payload, string $requestId): mixed
    {
        if ($accessToken === '' && !in_array($path, ['/api/sand-iam/v1/auth/register', '/api/sand-iam/v1/auth/login', '/api/sand-iam/v1/auth/refresh'], true)) {
            throw new SandIamException('SAND_IAM_AUTHENTICATION_FAILED', '尚未登录或登录凭证已丢失', 401);
        }
        $requestId = $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16));
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        [$status, $response] = $this->send(rtrim($this->baseUrl, '/') . $path, $method, $accessToken, $requestId, $body);
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法解析的数据', $status, $exception);
        }
        if (!is_array($decoded)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法识别的数据', $status);
        if ($status < 200 || $status >= 300) {
            $message = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'SandIAM 请求失败');
            preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $matches);
            throw new SandIamException($matches[1] ?? 'SAND_IAM_REQUEST_FAILED', $message, $status);
        }
        return $decoded['data'] ?? null;
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function arrayData(string $method, string $path, string $accessToken, ?array $payload, string $requestId): array
    {
        $data = $this->requestData($method, $path, $accessToken, $payload, $requestId);
        if (!is_array($data) || array_is_list($data)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的数据结构不正确', 200);
        return $data;
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function workloadArrayData(string $method, string $path, string $credential, ?array $payload, string $requestId): array
    {
        $requestId = $this->workloadRequestId($requestId);
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        [$status, $response] = $this->send(rtrim($this->baseUrl, '/') . $path, $method, $credential, $requestId, $body, true);
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法解析的数据', $status, $exception);
        }
        if (!is_array($decoded)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法识别的数据', $status);
        if ($status < 200 || $status >= 300) {
            $message = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'SandIAM 请求失败');
            preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $matches);
            throw new SandIamException($matches[1] ?? 'SAND_IAM_REQUEST_FAILED', $message, $status);
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的数据结构不正确', $status);
        return $data;
    }

    /** @param list<string> $actions */
    private function assertWorkloadInput(string $secretOrContext, string $serviceCode, string $audience, array $actions): void
    {
        if ($secretOrContext === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $serviceCode) || trim($audience) === '' || $actions === [] || array_filter($actions, static fn (mixed $action): bool => !is_string($action) || !preg_match('/^[a-z0-9][a-z0-9._-]{1,95}$/', $action))) {
            throw new SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '工作负载服务、受众和动作必须使用已声明的稳定代码', 0);
        }
    }

    private function validWorkloadResponseActions(mixed $actions): bool
    {
        if (!is_array($actions) || !array_is_list($actions) || $actions === []) {
            return false;
        }
        foreach ($actions as $action) {
            if (!is_string($action) || !preg_match('/^[a-z0-9][a-z0-9._-]{1,95}$/', $action)) {
                return false;
            }
        }
        return true;
    }

    private function workloadRequestId(string $requestId): string
    {
        return $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16));
    }

    private function derivedRequestId(string $requestId, int $index): string
    {
        return substr($requestId, 0, 88) . '-v' . ($index + 1);
    }

    /** @param array<string,mixed>|null $payload @return list<array<string,mixed>> */
    private function listData(string $method, string $path, string $accessToken, ?array $payload, string $requestId): array
    {
        $data = $this->requestData($method, $path, $accessToken, $payload, $requestId);
        if (!is_array($data) || !array_is_list($data)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的数据结构不正确', 200);
        foreach ($data as $item) if (!is_array($item) || array_is_list($item)) throw new SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的数据结构不正确', 200);
        return $data;
    }

    private function validDecision(mixed $decision): bool
    {
        return is_array($decision)
            && is_bool($decision['allowed'] ?? null)
            && is_string($decision['code'] ?? null)
            && is_array($decision['policy_ids'] ?? null)
            && is_array($decision['scope'] ?? null)
            && is_int($decision['application_id'] ?? null)
            && is_int($decision['identity_id'] ?? null)
            && is_string($decision['api_code'] ?? null)
            && is_string($decision['resource_code'] ?? null)
            && is_string($decision['action'] ?? null)
            && is_string($decision['operation'] ?? null);
    }

    /**
     * @param array<string,mixed> $decision
     * @param callable(object):array<string,mixed> $attributeResolver
     */
    private function assertEntityScope(array $decision, object $entity, callable $attributeResolver): void
    {
        $attributes = $attributeResolver($entity);
        if (!is_array($attributes) || array_is_list($attributes) || !$this->scopeMatches((array) $decision['scope'], $attributes)) {
            throw new AuthorizationDenied(array_replace($decision, ['code' => 'SAND_IAM_RESOURCE_SCOPE_DENIED']));
        }
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $attributes */
    private function scopeMatches(array $scope, array $attributes): bool
    {
        foreach ($scope as $operator => $constraints) {
            if (!in_array($operator, ['equals', 'in'], true) || !is_array($constraints)) {
                return false;
            }
            foreach ($constraints as $attribute => $expected) {
                if (!is_string($attribute) || $attribute === '' || str_contains($attribute, '.') || !array_key_exists($attribute, $attributes)) {
                    return false;
                }
                if ($operator === 'equals' && ($attributes[$attribute] !== $expected || (!is_scalar($expected) && $expected !== null))) {
                    return false;
                }
                if ($operator === 'in' && (!is_array($expected) || $expected === [] || !in_array($attributes[$attribute], $expected, true))) {
                    return false;
                }
            }
        }
        return true;
    }
}
