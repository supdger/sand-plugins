<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\ScimService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class ScimController
{
    public function schemas(Request $request, string $provider): Response
    {
        return $this->guard(function () use ($request, $provider): Response {
            [, , $service] = $this->principal($request, $provider);
            return json($service->schemas())->withHeader('Content-Type', 'application/scim+json');
        });
    }

    public function resourceTypes(Request $request, string $provider): Response
    {
        return $this->guard(function () use ($request, $provider): Response {
            [, , $service] = $this->principal($request, $provider);
            return json($service->resourceTypes())->withHeader('Content-Type', 'application/scim+json');
        });
    }

    public function serviceProviderConfig(Request $request, string $provider): Response
    {
        return $this->guard(function () use ($request, $provider): Response {
            [, , $service] = $this->principal($request, $provider);
            return json($service->serviceProviderConfig())->withHeader('Content-Type', 'application/scim+json');
        });
    }

    public function users(Request $request, string $provider): Response
    {
        return $this->guard(function () use ($request, $provider): Response {
            [$identityProvider, $applicationId, $service] = $this->principal($request, $provider);
            if ($request->method() === 'POST') return $this->resource($provider, $service->createUser($identityProvider, $applicationId, $request->post(), $this->requestId($request)), 201, 'Users');
            return json($this->listResponse($provider, $service->listUsers($identityProvider, $applicationId, $request->get('filter'), (int) $request->get('startIndex', 1), (int) $request->get('count', 100)), 'Users'))->withHeader('Content-Type', 'application/scim+json');
        });
    }

    public function user(Request $request, string $provider, string $id): Response
    {
        return $this->guard(function () use ($request, $provider, $id): Response {
            [$identityProvider, $applicationId, $service] = $this->principal($request, $provider);
            if ($request->method() === 'PATCH') return $this->resource($provider, $service->patchUser($identityProvider, $applicationId, $id, $request->post(), $request->header('If-Match'), $this->requestId($request)), 200, 'Users');
            if ($request->method() === 'PUT') return $this->resource($provider, $service->replaceUser($identityProvider, $applicationId, $id, $request->post(), $request->header('If-Match'), $this->requestId($request)), 200, 'Users');
            if ($request->method() === 'DELETE') { $service->deleteUser($identityProvider, $applicationId, $id, $request->header('If-Match'), $this->requestId($request)); return new Response(204, ['Content-Type' => 'application/scim+json']); }
            return $this->resource($provider, $service->getUser($identityProvider, $applicationId, $id), 200, 'Users');
        });
    }

    public function groups(Request $request, string $provider): Response
    {
        return $this->guard(function () use ($request, $provider): Response {
            [$identityProvider,$applicationId,$service]=$this->principal($request,$provider);
            if($request->method()==='POST') return $this->resource($provider,$service->createGroup($identityProvider,$applicationId,$request->post(),$this->requestId($request)),201,'Groups');
            return json($this->listResponse($provider,$service->listGroups($identityProvider,$applicationId,$request->get('filter'),(int)$request->get('startIndex',1),(int)$request->get('count',100)),'Groups'))->withHeader('Content-Type','application/scim+json');
        });
    }

    public function group(Request $request,string $provider,string $id): Response
    {
        return $this->guard(function () use ($request, $provider, $id): Response {
            [$identityProvider,$applicationId,$service]=$this->principal($request,$provider);
            if($request->method()==='PATCH') return $this->resource($provider,$service->patchGroup($identityProvider,$applicationId,$id,$request->post(),$request->header('If-Match'),$this->requestId($request)),200,'Groups');
            if($request->method()==='PUT') return $this->resource($provider,$service->replaceGroup($identityProvider,$applicationId,$id,$request->post(),$request->header('If-Match'),$this->requestId($request)),200,'Groups');
            if($request->method()==='DELETE'){ $service->deleteGroup($identityProvider,$applicationId,$id,$request->header('If-Match'),$this->requestId($request));return new Response(204,['Content-Type'=>'application/scim+json']);}
            return $this->resource($provider,$service->getGroup($identityProvider,$applicationId,$id),200,'Groups');
        });
    }

    /** @return array{0:object,1:int,2:ScimService} */
    private function principal(Request $request, string $provider): array { $value = trim((string) $request->header('Authorization', '')); if (strncasecmp($value, 'Bearer ', 7) !== 0) throw new ApiException('SAND_IAM_SCIM_UNAUTHORIZED', 401); $service = new ScimService(); [$identityProvider, $applicationId] = $service->authenticate($provider, substr($value, 7)); return [$identityProvider, $applicationId, $service]; }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }

    private function guard(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ApiException $exception) {
            return $this->error($exception->getMessage(), (int) $exception->getCode());
        } catch (\Throwable) {
            return $this->error('SAND_IAM_SCIM_INTERNAL_ERROR', 500);
        }
    }

    private function error(string $code, int $status): Response
    {
        $status = in_array($status, [400, 401, 404, 409, 412, 428], true) ? $status : 500;
        $scimType = match ($status) { 409 => 'uniqueness', 412 => 'invalidVers', 400 => 'invalidSyntax', default => null };
        $payload = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status, 'detail' => preg_match('/^SAND_IAM_[A-Z0-9_]+$/', $code) ? $code : 'SAND_IAM_SCIM_REQUEST_FAILED'];
        if ($scimType !== null) $payload['scimType'] = $scimType;
        $response = json($payload)->withStatus($status)->withHeader('Content-Type', 'application/scim+json')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        return $status === 401 ? $response->withHeader('WWW-Authenticate', 'Bearer') : $response;
    }

    /** @param array<string,mixed> $resource */
    private function resource(string $provider, array $resource, int $status, string $kind): Response { $id=(string)($resource['id']??''); $location='/api/sand-iam/v1/scim/'.rawurlencode($provider).'/'.$kind.'/'.rawurlencode($id); $resource['meta']['location']=$location; $response=json($resource)->withStatus($status)->withHeader('Content-Type','application/scim+json')->withHeader('ETag',(string)($resource['meta']['version']??'')); return $status===201?$response->withHeader('Location',$location):$response; }
    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function listResponse(string $provider, array $response, string $kind): array
    {
        foreach ($response['Resources'] ?? [] as $index => $resource) {
            if (!is_array($resource)) continue;
            $response['Resources'][$index]['meta']['location'] = '/api/sand-iam/v1/scim/' . rawurlencode($provider) . '/' . $kind . '/' . rawurlencode((string) ($resource['id'] ?? ''));
        }
        return $response;
    }
}
