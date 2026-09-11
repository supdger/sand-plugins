<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Keeps per-NAS shared secrets out of host request logs and error bodies. */
final class RadiusSensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            return $handler($request)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        } catch (ApiException $exception) {
            $status = (int) $exception->getCode();
            if ($status < 400 || $status > 599) $status = 400;
            $code = preg_match('/^SAND_IAM_[A-Z0-9_]{1,96}$/', $exception->getMessage()) ? $exception->getMessage() : 'SAND_IAM_RADIUS_CONFIGURATION_FAILED';
            return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return json(['code' => 500, 'msg' => 'SAND_IAM_RADIUS_CONFIGURATION_FAILED'])->withStatus(500)->withHeader('Cache-Control', 'no-store');
        }
    }
}
