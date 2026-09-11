<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\FederationService;
use plugin\SandIam\app\service\RequestId;
use support\Request;
use support\Response;

final class FederationController
{
    public function oidcStart(Request $request): Response
    {
        [$binding, $new] = $this->browserBinding($request, 'sand_iam_oidc_browser');
        $response = $this->json((new FederationService())->startOidc((string) $request->get('provider', ''), (string) $request->get('application', ''), (string) $request->get('return_uri', ''), (string) $request->get('state', ''), (string) $request->get('code_challenge', ''), $binding, $this->requestId($request), (string) $request->get('purpose', 'login'), $this->bearer($request)));
        return $new ? $this->setBrowserCookie($response, 'sand_iam_oidc_browser', $binding, 'Lax') : $response;
    }

    public function oidcCallback(Request $request): Response
    {
        $binding = (string) $request->cookie('sand_iam_oidc_browser', '');
        $response = $this->handoffRedirect((new FederationService())->completeOidc((string) $request->get('state', ''), (string) $request->get('code', ''), $binding, (string) $request->getRealIp(), (string) $request->header('User-Agent', ''), $this->requestId($request)));
        return $this->clearBrowserCookie($response, 'sand_iam_oidc_browser', 'Lax');
    }

    public function oauth2Start(Request $request): Response
    {
        [$binding, $new] = $this->browserBinding($request, 'sand_iam_oauth2_browser');
        $response = $this->json((new FederationService())->startOauth2((string) $request->get('provider', ''), (string) $request->get('application', ''), (string) $request->get('return_uri', ''), (string) $request->get('state', ''), (string) $request->get('code_challenge', ''), $binding, $this->requestId($request), (string) $request->get('purpose', 'login'), $this->bearer($request)));
        return $new ? $this->setBrowserCookie($response, 'sand_iam_oauth2_browser', $binding, 'Lax') : $response;
    }

    public function oauth2Callback(Request $request): Response
    {
        $binding = (string) $request->cookie('sand_iam_oauth2_browser', '');
        $response = $this->handoffRedirect((new FederationService())->completeOauth2((string) $request->get('state', ''), (string) $request->get('code', ''), $binding, (string) $request->getRealIp(), (string) $request->header('User-Agent', ''), $this->requestId($request)));
        return $this->clearBrowserCookie($response, 'sand_iam_oauth2_browser', 'Lax');
    }

    public function samlStart(Request $request): Response
    {
        [$binding, $new] = $this->browserBinding($request, 'sand_iam_saml_browser');
        $response = $this->json((new FederationService())->startSaml((string) $request->get('provider', ''), (string) $request->get('application', ''), (string) $request->get('return_uri', ''), (string) $request->get('state', ''), (string) $request->get('code_challenge', ''), $binding, $this->requestId($request), (string) $request->get('purpose', 'login'), $this->bearer($request)));
        return $new ? $this->setBrowserCookie($response, 'sand_iam_saml_browser', $binding, 'None') : $response;
    }

    public function samlAcs(Request $request): Response
    {
        $binding = (string) $request->cookie('sand_iam_saml_browser', '');
        $response = $this->handoffRedirect((new FederationService())->completeSaml((string) $request->post('RelayState', ''), (string) $request->post('SAMLResponse', ''), $binding, (string) $request->getRealIp(), (string) $request->header('User-Agent', ''), $this->requestId($request)));
        return $this->clearBrowserCookie($response, 'sand_iam_saml_browser', 'None');
    }
    public function exchange(Request $request): Response
    {
        return $this->json((new FederationService())->exchangeHandoff((string) $request->post('provider', ''), (string) $request->post('application', ''), (string) $request->post('code', ''), (string) $request->post('redirect_uri', ''), (string) $request->post('verifier', ''), (string) $request->getRealIp(), (string) $request->header('User-Agent', ''), $this->requestId($request)));
    }

    private function json(array $data): Response { return json($data)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer'); }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
    private function bearer(Request $request): string { $value = trim((string) $request->header('Authorization', '')); return strncasecmp($value, 'Bearer ', 7) === 0 ? trim(substr($value, 7)) : ''; }
    /** @return array{0:string,1:bool} */
    private function browserBinding(Request $request, string $cookieName): array
    {
        $value = (string) $request->cookie($cookieName, '');
        if (preg_match('/^fb_[a-f0-9]{64}$/', $value)) return [$value, false];
        return ['fb_' . bin2hex(random_bytes(32)), true];
    }
    private function setBrowserCookie(Response $response, string $name, string $value, string $sameSite): Response { return $response->withHeader('Set-Cookie', $name . '=' . rawurlencode($value) . '; Path=/api/sand-iam/v1/federation; Max-Age=600; HttpOnly; Secure; SameSite=' . $sameSite); }
    private function clearBrowserCookie(Response $response, string $name, string $sameSite): Response { return $response->withHeader('Set-Cookie', $name . '=; Path=/api/sand-iam/v1/federation; Max-Age=0; HttpOnly; Secure; SameSite=' . $sameSite); }
    /** @param array{return_uri:string,code:string,state:string} $result */
    private function handoffRedirect(array $result): Response
    {
        $location = $result['return_uri'] . (str_contains($result['return_uri'], '?') ? '&' : '?') . http_build_query(['code' => $result['code'], 'state' => $result['state']], '', '&', PHP_QUERY_RFC3986);
        return response('', 303, ['Location' => $location, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
