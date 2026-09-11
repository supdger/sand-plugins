<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** RFC 7644 response boundary; never forwards bearer tokens or request body. */
final class ScimProtocolMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        try { return $this->decorate($handler($request)); }
        catch (ApiException $exception) { return $this->error($exception->getMessage(), (int) $exception->getCode()); }
        catch (\Throwable) { return $this->error('SAND_IAM_SCIM_INTERNAL_ERROR', 500); }
    }

    private function decorate(Response $response): Response
    {
        return $response
            ->withHeader('Content-Type', 'application/scim+json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function error(string $code, int $status): Response
    {
        $status = in_array($status, [400,401,404,409,412,428], true) ? $status : 500;
        $scimType = match ($status) { 409 => 'uniqueness', 412 => 'invalidVers', 400 => 'invalidSyntax', default => null };
        $payload = ['schemas'=>['urn:ietf:params:scim:api:messages:2.0:Error'],'status'=>(string)$status,'detail'=>preg_match('/^SAND_IAM_[A-Z0-9_]+$/',$code)?$code:'SAND_IAM_SCIM_REQUEST_FAILED'];
        if ($scimType !== null) $payload['scimType'] = $scimType;
        $response = $this->decorate(json($payload)->withStatus($status));
        return $status === 401 ? $response->withHeader('WWW-Authenticate','Bearer') : $response;
    }
}
