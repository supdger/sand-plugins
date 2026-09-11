<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\CasProtocolService;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Public CAS endpoints; user confirmation relies only on an application-user Bearer session. */
final class CasController
{
    public function login(Request $request): Response
    {
        try {
            $result = (new CasProtocolService())->beginLogin(
                (string) $request->get('service', ''),
                $this->truthy($request->get('renew')),
                $this->truthy($request->get('gateway')),
                $this->requestId($request),
            );
            return response('', 302, ['Location' => $this->accountInteractionLocation((string) $result['interaction_uri']), 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (ApiException $exception) {
            return $this->plainError($exception);
        }
    }

    public function interaction(Request $request): Response
    {
        try {
            return json((new CasProtocolService())->interaction((string) $request->get('request', '')))
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Referrer-Policy', 'no-referrer');
        } catch (ApiException $exception) {
            return $this->jsonError($exception);
        }
    }

    public function confirm(Request $request): Response
    {
        try {
            return json((new CasProtocolService())->confirm(
                (string) $request->post('request', ''),
                $this->bearer($request),
                $this->requestId($request),
            ))->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        } catch (ApiException $exception) {
            return $this->jsonError($exception);
        }
    }

    public function reject(Request $request): Response
    {
        try {
            return json((new CasProtocolService())->reject(
                (string) $request->post('request', ''),
                $this->bearer($request),
                $this->requestId($request),
            ))->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        } catch (ApiException $exception) {
            return $this->jsonError($exception);
        }
    }

    public function validate(Request $request): Response
    {
        try {
            $principal = (new CasProtocolService())->validate((string) $request->get('service', ''), (string) $request->get('ticket', ''), $this->requestId($request));
            return response((new CasProtocolService())->cas1Response($principal), 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        } catch (ApiException) {
            return response("no\n\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
    }

    public function serviceValidate(Request $request): Response { return $this->xmlValidate($request, false); }
    public function p3ServiceValidate(Request $request): Response { return $this->xmlValidate($request, true); }

    private function xmlValidate(Request $request, bool $attributes): Response
    {
        $service = new CasProtocolService();
        try {
            $principal = $service->validate((string) $request->get('service', ''), (string) $request->get('ticket', ''), $this->requestId($request));
        } catch (ApiException) {
            $principal = null;
        }
        return response($service->xmlResponse($principal, $attributes), 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private function bearer(Request $request): string { $value = trim((string) $request->header('Authorization', '')); return strncasecmp($value, 'Bearer ', 7) === 0 ? substr($value, 7) : ''; }
    private function accountInteractionLocation(string $interactionUri): string
    {
        $query = parse_url($interactionUri, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $values);
        $request = is_string($values['request'] ?? null) ? $values['request'] : '';
        if (preg_match('/^CRT-[A-Za-z0-9_-]{48}$/', $request) !== 1) throw new ApiException('SAND_IAM_CAS_REQUEST_INVALID', 400);
        return '/app/sand-iam/account/?cas_request=' . rawurlencode($request);
    }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96); }
    private function truthy(mixed $value): bool { return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true); }
    private function plainError(ApiException $exception): Response { $status = (int) $exception->getCode(); return response('CAS 请求未被接受', $status >= 400 && $status <= 599 ? $status : 400, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']); }
    private function jsonError(ApiException $exception): Response { $status = (int) $exception->getCode(); return response(json_encode(['code' => 'SAND_IAM_CAS_REQUEST_REJECTED', 'message' => 'CAS 请求未被接受'], JSON_UNESCAPED_UNICODE), $status >= 400 && $status <= 599 ? $status : 400, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']); }
}
