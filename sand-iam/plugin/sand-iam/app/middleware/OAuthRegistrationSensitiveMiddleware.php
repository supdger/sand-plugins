<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Prevents one-time dynamic registration access tokens from entering host request logs. */
final class OAuthRegistrationSensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            return $handler($request);
        } catch (ApiException $exception) {
            $code = preg_match('/^(SAND_IAM_[A-Z0-9_]{1,96})/', $exception->getMessage(), $match) ? $match[1] : 'SAND_IAM_DCR_OPERATION_FAILED';
            $status = min(max((int) $exception->getCode(), 400), 599);
            return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return json(['code' => 500, 'msg' => 'SAND_IAM_DCR_OPERATION_FAILED'])->withStatus(500)->withHeader('Cache-Control', 'no-store');
        }
    }
}
