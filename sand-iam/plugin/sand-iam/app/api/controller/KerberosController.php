<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\kerberos\SpnegoContextResolver;
use plugin\SandIam\app\kerberos\UnavailableSpnegoContextResolver;
use plugin\SandIam\app\service\KerberosProtocolService;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class KerberosController
{
    public function negotiate(Request $request): Response
    {
        try {
            $context = $this->contextResolver()->resolve($request);
            $result = (new KerberosProtocolService())->negotiate(
                (string) $request->post('provider', ''),
                (string) $request->post('application', ''),
                trim((string) $request->header('Authorization', '')),
                (string) $context['channel_binding'],
                (string) $context['remote_ip'],
                (string) $request->header('User-Agent', ''),
                substr((string) $request->header('X-Request-Id', ''), 0, 96),
            );
            $response = json($result['tokens'])->withHeader('Cache-Control', 'no-store');
            if ($result['response_token'] !== null) $response = $response->withHeader('WWW-Authenticate', 'Negotiate ' . $result['response_token']);
            return $response;
        } catch (ApiException $exception) {
            $status = (int) $exception->getCode();
            if ($status < 400 || $status > 599) $status = 401;
            return response(json_encode(['code' => 'SAND_IAM_KERBEROS_AUTHENTICATION_FAILED', 'message' => '企业网络认证未通过'], JSON_UNESCAPED_UNICODE), $status, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => 'Negotiate',
            ]);
        }
    }

    private function contextResolver(): SpnegoContextResolver
    {
        $class = trim((string) config('plugin.sand-iam.app.kerberos_context_resolver', ''));
        if ($class === '' || !class_exists($class)) return new UnavailableSpnegoContextResolver();
        $resolver = new $class();
        return $resolver instanceof SpnegoContextResolver ? $resolver : new UnavailableSpnegoContextResolver();
    }
}
