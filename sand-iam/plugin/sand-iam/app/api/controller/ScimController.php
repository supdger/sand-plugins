<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\ScimService;
use plugin\SandIam\app\service\RequestId;
use support\Request;
use support\Response;

final class ScimController
{
    public function schemas(Request $request): Response { [, , $service] = $this->principal($request); return json($service->schemas())->withHeader('Content-Type', 'application/scim+json'); }
    public function resourceTypes(Request $request): Response { [, , $service] = $this->principal($request); return json($service->resourceTypes())->withHeader('Content-Type', 'application/scim+json'); }
    public function serviceProviderConfig(Request $request): Response { [, , $service] = $this->principal($request); return json($service->serviceProviderConfig())->withHeader('Content-Type', 'application/scim+json'); }
    public function users(Request $request): Response
    {
        [$provider, $applicationId, $service] = $this->principal($request);
        if ($request->method() === 'POST') return $this->resource($request, $service->createUser($provider, $applicationId, $request->post(), $this->requestId($request)), 201, 'Users');
        return json($this->listResponse($request, $service->listUsers($provider, $applicationId, $request->get('filter'), (int) $request->get('startIndex', 1), (int) $request->get('count', 100)), 'Users'))->withHeader('Content-Type', 'application/scim+json');
    }
    public function user(Request $request, string $id): Response
    {
        [$provider, $applicationId, $service] = $this->principal($request);
        if ($request->method() === 'PATCH') return $this->resource($request, $service->patchUser($provider, $applicationId, $id, $request->post(), $request->header('If-Match'), $this->requestId($request)), 200, 'Users');
        if ($request->method() === 'PUT') return $this->resource($request, $service->replaceUser($provider, $applicationId, $id, $request->post(), $request->header('If-Match'), $this->requestId($request)), 200, 'Users');
        if ($request->method() === 'DELETE') { $service->deleteUser($provider, $applicationId, $id, $request->header('If-Match'), $this->requestId($request)); return new Response(204, ['Content-Type' => 'application/scim+json']); }
        return $this->resource($request, $service->getUser($provider, $applicationId, $id), 200, 'Users');
    }
    public function groups(Request $request): Response { [$provider,$applicationId,$service]=$this->principal($request); if($request->method()==='POST') return $this->resource($request,$service->createGroup($provider,$applicationId,$request->post(),$this->requestId($request)),201,'Groups'); return json($this->listResponse($request,$service->listGroups($provider,$applicationId,$request->get('filter'),(int)$request->get('startIndex',1),(int)$request->get('count',100)),'Groups'))->withHeader('Content-Type','application/scim+json'); }
    public function group(Request $request,string $id): Response { [$provider,$applicationId,$service]=$this->principal($request); if($request->method()==='PATCH') return $this->resource($request,$service->patchGroup($provider,$applicationId,$id,$request->post(),$request->header('If-Match'),$this->requestId($request)),200,'Groups'); if($request->method()==='PUT') return $this->resource($request,$service->replaceGroup($provider,$applicationId,$id,$request->post(),$request->header('If-Match'),$this->requestId($request)),200,'Groups'); if($request->method()==='DELETE'){ $service->deleteGroup($provider,$applicationId,$id,$request->header('If-Match'),$this->requestId($request));return new Response(204,['Content-Type'=>'application/scim+json']);} return $this->resource($request,$service->getGroup($provider,$applicationId,$id),200,'Groups'); }
    /** @return array{0:object,1:int,2:ScimService} */
    private function principal(Request $request): array { $value = trim((string) $request->header('Authorization', '')); if (strncasecmp($value, 'Bearer ', 7) !== 0) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_SCIM_UNAUTHORIZED', 401); $service = new ScimService(); [$provider, $applicationId] = $service->authenticate((string) $request->param('provider', ''), substr($value, 7)); return [$provider, $applicationId, $service]; }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
    /** @param array<string,mixed> $resource */
    private function resource(Request $request, array $resource, int $status, string $kind): Response { $id=(string)($resource['id']??''); $location='/api/sand-iam/v1/scim/'.rawurlencode((string)$request->param('provider','')).'/'.$kind.'/'.rawurlencode($id); $resource['meta']['location']=$location; $response=json($resource)->withStatus($status)->withHeader('Content-Type','application/scim+json')->withHeader('ETag',(string)($resource['meta']['version']??'')); return $status===201?$response->withHeader('Location',$location):$response; }
    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function listResponse(Request $request, array $response, string $kind): array
    {
        foreach ($response['Resources'] ?? [] as $index => $resource) {
            if (!is_array($resource)) continue;
            $response['Resources'][$index]['meta']['location'] = '/api/sand-iam/v1/scim/' . rawurlencode((string) $request->param('provider', '')) . '/' . $kind . '/' . rawurlencode((string) ($resource['id'] ?? ''));
        }
        return $response;
    }
}
