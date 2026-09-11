<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\OAuthOidcService;
use plugin\SandIam\app\service\OAuthDynamicRegistrationService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Public OAuth 2.0 / OpenID Connect endpoints; never use SandAdmin admin state. */
final class OAuthOidcController
{
    public function authorize(Request $request): Response
    {
        try {
            $result = (new OAuthOidcService())->beginAuthorization($this->authorizePayload($request), $this->requestId($request));
            if (isset($result['redirect_uri'])) return response('', 302, ['Location' => $result['redirect_uri'], 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']);
            return response('', 302, ['Location' => $this->accountInteractionLocation((string) $result['interaction_uri'], 'oauth_request', '/^siam_oar_[a-f0-9]{64}$/'), 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']);
        } catch (ApiException $exception) {
            return $this->authorizationEndpointError($exception);
        }
    }

    public function interaction(Request $request): Response { return json((new OAuthOidcService())->interaction((string) $request->get('request', '')))->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer'); }
    public function interactionSession(Request $request): Response { return json((new OAuthOidcService())->bindInteraction((string) $request->post('authorization_request', ''), $this->bearer($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer'); }
    public function interactionConfirm(Request $request): Response
    {
        $result = (new OAuthOidcService())->approveAuthorization($request->post(), $this->bearer($request), $this->requestId($request));
        return json($result)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function token(Request $request): Response
    {
        try {
            return json((new OAuthOidcService())->token($this->clientPayload($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            return $this->oauthError($exception, 'token');
        }
    }

    public function userinfo(Request $request): Response
    {
        try {
            return json((new OAuthOidcService())->userinfo($this->bearer($request), $this->requestId($request)))->withHeader('Cache-Control', 'no-store');
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            return $this->oauthError($exception, 'userinfo');
        }
    }

    public function revoke(Request $request): Response
    {
        try {
            (new OAuthOidcService())->revoke($this->clientPayload($request), $this->requestId($request));
            return response('', 200, ['Cache-Control' => 'no-store']);
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            return $this->oauthError($exception, 'revoke');
        }
    }

    public function logout(Request $request): Response
    {
        try {
            $result = (new OAuthOidcService())->logout(array_merge($request->get(), $request->post()), $this->requestId($request));
            if ($result === null) return response('', 200, ['Cache-Control' => 'no-store']);
            if (($result['frontchannel_uris'] ?? []) !== []) return $this->frontchannelLogoutPage($result['frontchannel_uris'], $result['redirect_uri'] ?? null);
            if (($result['redirect_uri'] ?? null) === null) return response('', 200, ['Cache-Control' => 'no-store']);
            return response('', 302, ['Location' => $result['redirect_uri'], 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            return $this->oauthError($exception, 'logout');
        }
    }

    public function register(Request $request): Response
    {
        try {
            $result = (new OAuthDynamicRegistrationService())->register($this->registrationBearer($request), $request->post(), (string) $request->getRealIp(), $this->requestId($request));
            return json($result)->withStatus(201)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        } catch (ApiException $exception) {
            return $this->dynamicRegistrationError($exception);
        }
    }

    public function discovery(): Response { return json((new OAuthOidcService())->discovery())->withHeader('Cache-Control', 'public, max-age=300'); }
    public function jwks(): Response { return json((new OAuthOidcService())->jwks())->withHeader('Cache-Control', 'public, max-age=300'); }

    /** @return array<string,mixed> */
    private function clientPayload(Request $request): array
    {
        $payload = $request->post();
        $authorization = trim((string) $request->header('Authorization', ''));
        if (strncasecmp($authorization, 'Basic ', 6) === 0) {
            if (array_key_exists('client_id', $payload) || array_key_exists('client_secret', $payload)) {
                throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_OAUTH_MULTIPLE_CLIENT_AUTH_METHODS', 400);
            }
            $decoded = base64_decode(substr($authorization, 6), true);
            if (!is_string($decoded) || !str_contains($decoded, ':')) {
                $payload['client_id'] = '';
                $payload['client_secret'] = '';
            } else {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);
                $payload['client_id'] = urldecode($clientId);
                $payload['client_secret'] = urldecode($clientSecret);
            }
        }
        return $payload;
    }

    private function bearer(Request $request): string { $value = trim((string) $request->header('Authorization', '')); $token = strncasecmp($value, 'Bearer ', 7) === 0 ? trim(substr($value, 7)) : ''; if ($token === '') throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401); return $token; }
    private function registrationBearer(Request $request): string { $value = trim((string) $request->header('Authorization', '')); $token = strncasecmp($value, 'Bearer ', 7) === 0 ? trim(substr($value, 7)) : ''; if (preg_match('/^siam_dcr_[A-Za-z0-9_-]{48}$/', $token) !== 1) throw new ApiException('SAND_IAM_DCR_ACCESS_DENIED', 401); return $token; }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
    private function accountInteractionLocation(string $interactionUri, string $parameter, string $pattern): string
    {
        $query = parse_url($interactionUri, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $values);
        $request = is_string($values['request'] ?? null) ? $values['request'] : '';
        if (preg_match($pattern, $request) !== 1) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
        return '/app/sand-iam/account/?' . rawurlencode($parameter) . '=' . rawurlencode($request);
    }
    /** @return array<string,mixed> */
    private function authorizePayload(Request $request): array
    {
        $query = $request->get();
        if (strtoupper($request->method()) !== 'POST') return $query;
        $form = $request->post();
        foreach (array_intersect(array_keys($query), array_keys($form)) as $key) {
            throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_OAUTH_DUPLICATE_AUTHORIZE_PARAMETER', 400);
        }
        return array_merge($query, $form);
    }
    private function oauthError(\plugin\sandadmin\exception\ApiException $exception, string $endpoint): Response
    {
        $message = $exception->getMessage();
        $insufficientScope = str_contains($message, 'INSUFFICIENT_SCOPE');
        $error = str_contains($message, 'INVALID_CLIENT') || str_contains($message, 'MULTIPLE_CLIENT_AUTH') ? 'invalid_client' : (str_contains($message, 'UNAUTHORIZED_CLIENT') ? 'unauthorized_client' : (str_contains($message, 'UNSUPPORTED_GRANT') ? 'unsupported_grant_type' : (str_contains($message, 'INVALID_SCOPE') ? 'invalid_scope' : (str_contains($message, 'INVALID_GRANT') || str_contains($message, 'REPLAY') ? 'invalid_grant' : ($insufficientScope ? 'insufficient_scope' : 'invalid_token')))));
        $status = $error === 'invalid_client' ? 401 : (int) $exception->getCode();
        if ($status < 400 || $status > 599) $status = 400;
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($endpoint === 'userinfo') $headers['WWW-Authenticate'] = $insufficientScope ? 'Bearer error="insufficient_scope", scope="openid"' : 'Bearer error="invalid_token"';
        if ($error === 'invalid_client') $headers['WWW-Authenticate'] = 'Basic realm="sand-iam"';
        return response(json_encode(['error' => $error, 'error_description' => 'OAuth 请求未被接受'], JSON_UNESCAPED_UNICODE), $status, $headers);
    }

    private function authorizationEndpointError(ApiException $exception): Response
    {
        $message = $exception->getMessage();
        $configurationFailure = str_contains($message, 'CONFIGURATION_UNAVAILABLE');
        $invalidRequest = str_contains($message, 'INVALID_CLIENT') || str_contains($message, 'REDIRECT_URI_INVALID') || str_contains($message, 'DUPLICATE_AUTHORIZE_PARAMETER');
        $error = $configurationFailure ? 'temporarily_unavailable' : ($invalidRequest ? 'invalid_request' : 'server_error');
        $status = $configurationFailure ? 503 : ($invalidRequest ? 400 : 500);
        return response(
            json_encode(['error' => $error, 'error_description' => '授权请求未被接受'], JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer'],
        );
    }

    private function dynamicRegistrationError(ApiException $exception): Response
    {
        $message = $exception->getMessage();
        $authenticationFailure = str_contains($message, 'DCR_ACCESS_DENIED') || str_contains($message, 'AUTHENTICATION_FAILED');
        if ($authenticationFailure) {
            return response(
                json_encode(['error' => 'invalid_token', 'error_description' => '动态注册访问令牌无效'], JSON_UNESCAPED_UNICODE),
                401,
                ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'WWW-Authenticate' => 'Bearer realm="sand-iam", error="invalid_token"'],
            );
        }
        if (str_contains($message, 'CONFIGURATION_UNAVAILABLE')) {
            return response(
                json_encode(['error' => 'temporarily_unavailable', 'error_description' => '动态注册服务暂不可用'], JSON_UNESCAPED_UNICODE),
                503,
                ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
            );
        }
        $error = str_contains($message, 'DISABLED') ? 'access_denied' : (str_contains($message, 'REDIRECT_URI') ? 'invalid_redirect_uri' : 'invalid_client_metadata');
        $status = $error === 'access_denied' ? 403 : 400;
        return response(
            json_encode(['error' => $error, 'error_description' => '客户端注册请求未被接受'], JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }

    /** @param list<string> $uris */
    private function frontchannelLogoutPage(array $uris, ?string $redirectUri): Response
    {
        $frames = '';
        foreach ($uris as $uri) {
            if (!is_string($uri)) continue;
            $frames .= '<iframe hidden sandbox="allow-scripts allow-same-origin" src="' . htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></iframe>';
        }
        $redirect = $redirectUri === null ? '' : '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redirectUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $body = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">' . $redirect . '<title>正在退出</title></head><body><p>正在通知已登录的应用退出，请稍候…</p>' . $frames . '</body></html>';
        return response($body, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; frame-src https:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
        ]);
    }
}
