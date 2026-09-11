<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\OAuthClient;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class OAuthClientController extends ApplicationResourceController
{
    protected string $modelClass = OAuthClient::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'client_type', 'redirect_uris', 'post_logout_redirect_uris', 'frontchannel_logout_uri', 'frontchannel_logout_session_required', 'backchannel_logout_uri', 'backchannel_logout_session_required', 'allowed_scopes', 'allowed_audiences', 'default_audience', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name', 'client_type'];
    protected string $resourceType = 'oauth_client';

    #[Permission('SandIAM OAuth 客户端列表', 'sand_iam:oauth_client:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM OAuth 客户端读取', 'sand_iam:oauth_client:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM OAuth 客户端保存', 'sand_iam:oauth_client:save')]
    public function save(Request $request): Response
    {
        $payload = $this->payload($request, false);
        $this->normalize($payload);
        $this->assertReferences($payload);
        $this->assertPayloadAccess($payload);
        if ((string) $payload['client_type'] === 'confidential') {
            $secret = 'siam_cs_' . bin2hex(random_bytes(32));
            $payload['secret_hash'] = $this->secretHash($secret);
            $payload['secret_version'] = 'v1';
        } else {
            $secret = null;
            $payload['secret_hash'] = null;
            $payload['secret_version'] = null;
        }
        try {
            $client = OAuthClient::create($payload);
        } catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'unique')) throw new ApiException('SAND_IAM_OAUTH_CLIENT_CODE_EXISTS', 409);
            throw $exception;
        }
        $this->audit('create', (int) $client->id, $request);
        $data = ['id' => (int) $client->id, 'client_id' => (string) $client->code];
        if ($secret !== null) $data['client_secret'] = $secret;
        return $this->success($data, '客户端已创建。客户端密钥仅此一次展示，请立即安全保存。');
    }

    #[Permission('SandIAM OAuth 客户端更新', 'sand_iam:oauth_client:update')]
    public function update(Request $request): Response
    {
        $client = $this->find($request);
        $payload = $this->payload($request, true);
        unset($payload['application_id'], $payload['code'], $payload['client_type']);
        $this->normalize($payload, $client);
        $this->assertReferences($payload, $client);
        $this->assertPayloadAccess($payload, $client);
        $client->save($payload);
        $this->audit('update', (int) $client->id, $request);
        return $this->success('更新成功');
    }

    #[Permission('SandIAM OAuth 客户端停用', 'sand_iam:oauth_client:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    #[Permission('SandIAM OAuth 客户端轮换密钥', 'sand_iam:oauth_client:rotate')]
    public function rotateSecret(Request $request): Response
    {
        $client = $this->find($request);
        if ((string) $client->client_type !== 'confidential' || (int) $client->status !== 1) throw new ApiException('SAND_IAM_OAUTH_CLIENT_SECRET_UNAVAILABLE', 400);
        $secret = 'siam_cs_' . bin2hex(random_bytes(32));
        $client->save(['secret_hash' => $this->secretHash($secret), 'secret_version' => 'v1', 'update_time' => date('Y-m-d H:i:s')]);
        $this->audit('secret_rotate', (int) $client->id, $request);
        return $this->success(['client_id' => (string) $client->code, 'client_secret' => $secret], '客户端密钥已轮换。旧密钥立即失效，新密钥仅此一次展示。');
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 400);
    }

    /** @param array<string,mixed> $payload */
    private function normalize(array &$payload, ?OAuthClient $existing = null): void
    {
        $type = (string) ($payload['client_type'] ?? $existing?->client_type ?? '');
        if (!in_array($type, ['public', 'confidential'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 客户端类型只能选择公开客户端或机密客户端', 400);
        foreach (['redirect_uris', 'post_logout_redirect_uris'] as $field) {
            if (!array_key_exists($field, $payload)) continue;
            $uris = $payload[$field];
            if (is_string($uris)) $uris = json_decode($uris, true);
            if (!is_array($uris) || count($uris) > 50) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 回调地址必须是最多 50 项的 HTTPS 地址列表', 400);
            $out = [];
            foreach ($uris as $uri) {
                if (!is_string($uri) || !$this->validUri($uri, $type === 'public')) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 回调地址必须是精确 HTTPS 地址；公开客户端可使用本机回环 HTTP 地址', 400);
                $out[] = $uri;
            }
            $payload[$field] = array_values(array_unique($out));
        }
        $redirects = $payload['redirect_uris'] ?? $existing?->redirect_uris ?? [];
        if (is_string($redirects)) $redirects = json_decode($redirects, true);
        if (!is_array($redirects) || $redirects === []) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 至少需要一个登录回调地址', 400);
        foreach (['frontchannel_logout_uri', 'backchannel_logout_uri'] as $field) {
            if (!array_key_exists($field, $payload)) continue;
            $uri = trim((string) $payload[$field]);
            if ($uri !== '' && !$this->validLogoutUri($uri, $redirects)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: OIDC 登出地址必须是与登录回调同源的精确 HTTPS 地址', 400);
            $payload[$field] = $uri === '' ? null : $uri;
        }
        foreach (['frontchannel_logout_session_required', 'backchannel_logout_session_required'] as $field) if (array_key_exists($field, $payload)) $payload[$field] = filter_var($payload[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        if (array_key_exists('allowed_scopes', $payload)) {
            $scopes = $payload['allowed_scopes'];
            if (is_string($scopes)) $scopes = json_decode($scopes, true);
            if (!is_array($scopes) || $scopes === [] || array_filter($scopes, static fn (mixed $scope): bool => !is_string($scope) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $scope))) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 允许范围必须是 1–128 位的协议范围代码', 400);
            $payload['allowed_scopes'] = array_values(array_unique($scopes));
        }
        if (array_key_exists('allowed_audiences', $payload)) {
            $audiences = $payload['allowed_audiences'];
            if (is_string($audiences)) $audiences = json_decode($audiences, true);
            if (!is_array($audiences) || count($audiences) > 30 || array_filter($audiences, static fn (mixed $audience): bool => !is_string($audience) || $audience === '' || strlen($audience) > 255)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 机器访问受众必须是最多 30 项的有效地址或资源标识', 400);
            $payload['allowed_audiences'] = array_values(array_unique($audiences));
        }
        $audiences = $payload['allowed_audiences'] ?? $existing?->allowed_audiences ?? [];
        if (is_string($audiences)) $audiences = json_decode($audiences, true);
        $defaultAudience = (string) ($payload['default_audience'] ?? $existing?->default_audience ?? '');
        if ($defaultAudience !== '' && (!is_array($audiences) || !in_array($defaultAudience, $audiences, true))) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 默认机器访问受众必须已列入允许受众', 400);
    }

    private function validUri(string $uri, bool $allowLoopback): bool
    {
        if (strlen($uri) > 2048 || !filter_var($uri, FILTER_VALIDATE_URL) || str_contains($uri, '*')) return false;
        $parts = parse_url($uri);
        if (!isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
        if (($parts['scheme'] ?? '') === 'https') return true;
        return $allowLoopback && ($parts['scheme'] ?? '') === 'http' && in_array(strtolower((string) $parts['host']), ['127.0.0.1', '::1'], true);
    }

    /** @param list<string> $redirects */
    private function validLogoutUri(string $uri, array $redirects): bool
    {
        if (!$this->validUri($uri, false)) return false;
        $origin = $this->origin($uri);
        foreach ($redirects as $redirect) if (is_string($redirect) && hash_equals($origin, $this->origin($redirect))) return true;
        return false;
    }

    private function origin(string $uri): string
    {
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . $host . ':' . $port;
    }

    private function secretHash(string $secret): string
    {
        $pepper = (string) config('plugin.sand-iam.app.auth_pepper', '');
        if ($pepper === '') throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash(hash_hmac('sha256', 'client-secret:' . $secret, $pepper), $algorithm);
    }
}
