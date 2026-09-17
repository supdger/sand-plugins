<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use Webman\Http\Response;

/** Public federation callbacks must fail closed without leaking protocol input. */
final class FederationProtocolMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        try { return $handler($request); }
        catch (ApiException $exception) {
            $status = (int) $exception->getCode();
            if ($status < 400 || $status > 599) $status = 400;
            $code = preg_match('/^SAND_IAM_[A-Z0-9_]{1,96}$/', $exception->getMessage()) ? $exception->getMessage() : 'SAND_IAM_FEDERATION_CALLBACK_FAILED';
            return json(['error_code' => $code, 'request_id' => RequestId::fromRequestCached($request)])->withStatus($status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache')->withHeader('Referrer-Policy', 'no-referrer');
        } catch (\Throwable) {
            return json(['error_code' => 'SAND_IAM_FEDERATION_CALLBACK_FAILED', 'request_id' => RequestId::fromRequestCached($request)])->withStatus(500)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache')->withHeader('Referrer-Policy', 'no-referrer');
        }
    }
}
