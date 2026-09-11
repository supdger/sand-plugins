<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\WebhookDelivery;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\RequestId;
use plugin\SandIam\app\service\WebhookService;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class WebhookController extends BaseController
{
    #[Permission('SandIAM Webhook 列表', 'sand_iam:webhook:index')]
    public function index(Request $request): Response
    {
        $query = WebhookEndpoint::order('id', 'desc');
        $this->scope($query, $request);
        $applicationId = (int) $request->input('application_id', 0);
        if ($applicationId > 0) {
            $this->access($request)->assertApplication($applicationId);
            $query->where('application_id', $applicationId);
        }
        $status = (int) $request->input('status', 0);
        if (in_array($status, [1, 2], true)) $query->where('status', $status);
        $keyword = trim((string) $request->input('keywords', ''));
        if ($keyword !== '') $query->whereLike('name', '%' . $keyword . '%');
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $result['data'] = array_map(fn (array $endpoint): array => $this->endpointPayload($endpoint), $result['data'] ?? []);
        return $this->success($result);
    }

    #[Permission('SandIAM Webhook 读取', 'sand_iam:webhook:read')]
    public function read(Request $request): Response
    {
        return $this->success($this->endpointPayload($this->endpoint($request)->toArray()));
    }

    #[Permission('SandIAM Webhook 保存', 'sand_iam:webhook:save')]
    public function save(Request $request): Response
    {
        $applicationId = (int) $request->post('application_id', 0);
        $this->access($request)->assertApplication($applicationId);
        $eventTypes = $request->post('event_types', []);
        if (!is_array($eventTypes)) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID', 400);
        $requestId = $this->requestId($request);
        $payload = [
            'application_id' => $applicationId,
            'code' => trim((string) $request->post('code', '')),
            'name' => trim((string) $request->post('name', '')),
            'url' => trim((string) $request->post('url', '')),
            'event_types' => $eventTypes,
            'timeout_seconds' => (int) $request->post('timeout_seconds', 10),
            'max_attempts' => (int) $request->post('max_attempts', 5),
        ];
        $result = (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'webhook.create',
            $requestId,
            IdempotencyService::fingerprint($payload),
            'webhook_endpoint',
            function () use ($payload, $requestId): array {
                $created = (new WebhookService())->createEndpoint(
                    $payload['application_id'], $payload['code'], $payload['name'], $payload['url'], $payload['event_types'],
                    $payload['timeout_seconds'], $payload['max_attempts'], $requestId,
                );
                return ['resource_id' => $created['id'], 'result' => $created];
            },
        );
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；签名密钥不会再次显示' : 'Webhook 已创建；签名密钥只显示本次，请立即保存')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM Webhook 更新', 'sand_iam:webhook:update')]
    public function update(Request $request): Response
    {
        $endpoint = $this->endpoint($request);
        $eventTypes = $request->post('event_types', []);
        if (!is_array($eventTypes)) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID', 400);
        (new WebhookService())->updateEndpoint(
            (int) $endpoint->id,
            (int) $endpoint->application_id,
            (string) $request->post('name', ''),
            (string) $request->post('url', ''),
            $eventTypes,
            (int) $request->post('timeout_seconds', 10),
            (int) $request->post('max_attempts', 5),
            $this->requestId($request),
        );
        return $this->success('Webhook 已更新');
    }

    #[Permission('SandIAM Webhook 停用', 'sand_iam:webhook:disable')]
    public function disable(Request $request): Response
    {
        $endpoint = $this->endpoint($request);
        (new WebhookService())->disableEndpoint((int) $endpoint->id, (int) $endpoint->application_id, $this->requestId($request));
        return $this->success('Webhook 已停用');
    }

    #[Permission('SandIAM Webhook 密钥轮换', 'sand_iam:webhook:secret_rotate')]
    public function rotateSecret(Request $request): Response
    {
        $endpoint = $this->endpoint($request);
        $requestId = $this->requestId($request);
        $payload = ['id' => (int) $endpoint->id, 'application_id' => (int) $endpoint->application_id];
        $result = (new IdempotencyService())->execute(
            'admin',
            $this->actor($request),
            'webhook.secret_rotate',
            $requestId,
            IdempotencyService::fingerprint($payload),
            'webhook_endpoint',
            function () use ($payload, $requestId): array {
                $rotated = (new WebhookService())->rotateSecret($payload['id'], $payload['application_id'], $requestId);
                return ['resource_id' => $payload['id'], 'result' => $rotated];
            },
        );
        return $this->success($result['result'], $result['replayed'] ? '请求已处理；新签名密钥不会再次显示' : '签名密钥已轮换；新密钥只显示本次')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    #[Permission('SandIAM Webhook 投递列表', 'sand_iam:webhook_delivery:index')]
    public function deliveries(Request $request): Response
    {
        $applicationId = (int) $request->input('application_id', 0);
        $this->access($request)->assertApplication($applicationId);
        $query = WebhookDelivery::where('application_id', $applicationId)->order('id', 'desc');
        $endpointId = (int) $request->input('webhook_endpoint_id', 0);
        if ($endpointId > 0) $query->where('webhook_endpoint_id', $endpointId);
        $status = (int) $request->input('status', 0);
        if (in_array($status, [1, 2, 3, 4], true)) $query->where('status', $status);
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $result['data'] = array_map(fn (array $delivery): array => $this->deliveryPayload($delivery, false), $result['data'] ?? []);
        return $this->success($result);
    }

    #[Permission('SandIAM Webhook 投递读取', 'sand_iam:webhook_delivery:read')]
    public function readDelivery(Request $request): Response
    {
        $delivery = WebhookDelivery::find((int) $request->input('id', 0));
        if ($delivery === null) throw new ApiException('SAND_IAM_WEBHOOK_DELIVERY_NOT_FOUND', 404);
        $this->access($request)->assertApplication((int) $delivery->application_id);
        return $this->success($this->deliveryPayload($delivery->toArray(), true));
    }

    #[Permission('SandIAM Webhook 投递重试', 'sand_iam:webhook_delivery:retry')]
    public function retryDelivery(Request $request): Response
    {
        $delivery = WebhookDelivery::find((int) $request->post('id', 0));
        if ($delivery === null) throw new ApiException('SAND_IAM_WEBHOOK_DELIVERY_NOT_FOUND', 404);
        $this->access($request)->assertApplication((int) $delivery->application_id);
        (new WebhookService())->retry((int) $delivery->id, (int) $delivery->application_id, $this->requestId($request));
        return $this->success('投递任务已重新排队');
    }

    private function endpoint(Request $request): WebhookEndpoint
    {
        $id = (int) $request->input('id', $request->post('id', 0));
        $endpoint = WebhookEndpoint::find($id);
        if ($endpoint === null) throw new ApiException('SAND_IAM_WEBHOOK_NOT_FOUND', 404);
        $this->access($request)->assertApplication((int) $endpoint->application_id);
        return $endpoint;
    }

    private function scope(object $query, Request $request): void
    {
        $access = $this->access($request);
        if ($access->isSuperAdmin()) return;
        $applicationIds = $access->applicationIds();
        if ($applicationIds === []) $query->whereRaw('1 = 0');
        else $query->whereIn('application_id', $applicationIds);
    }

    /** @param array<string,mixed> $endpoint @return array<string,mixed> */
    private function endpointPayload(array $endpoint): array
    {
        return [
            'id' => (int) ($endpoint['id'] ?? 0),
            'application_id' => (int) ($endpoint['application_id'] ?? 0),
            'code' => (string) ($endpoint['code'] ?? ''),
            'name' => (string) ($endpoint['name'] ?? ''),
            'url' => (string) ($endpoint['url'] ?? ''),
            'event_types' => is_array($endpoint['event_types'] ?? null) ? $endpoint['event_types'] : [],
            'secret_version' => (int) ($endpoint['secret_version'] ?? 0),
            'timeout_seconds' => (int) ($endpoint['timeout_seconds'] ?? 0),
            'max_attempts' => (int) ($endpoint['max_attempts'] ?? 0),
            'status' => (int) ($endpoint['status'] ?? 0),
            'disabled_time' => $endpoint['disabled_time'] ?? null,
            'create_time' => $endpoint['create_time'] ?? null,
            'update_time' => $endpoint['update_time'] ?? null,
        ];
    }

    /** @param array<string,mixed> $delivery @return array<string,mixed> */
    private function deliveryPayload(array $delivery, bool $withPayload): array
    {
        $result = [
            'id' => (int) ($delivery['id'] ?? 0),
            'application_id' => (int) ($delivery['application_id'] ?? 0),
            'webhook_endpoint_id' => (int) ($delivery['webhook_endpoint_id'] ?? 0),
            'event_id' => (string) ($delivery['event_id'] ?? ''),
            'event_type' => (string) ($delivery['event_type'] ?? ''),
            'status' => (int) ($delivery['status'] ?? 0),
            'attempt_count' => (int) ($delivery['attempt_count'] ?? 0),
            'next_attempt_time' => $delivery['next_attempt_time'] ?? null,
            'delivered_time' => $delivery['delivered_time'] ?? null,
            'response_status' => ($delivery['response_status'] ?? null) === null ? null : (int) $delivery['response_status'],
            'last_error_code' => $delivery['last_error_code'] ?? null,
            'create_time' => $delivery['create_time'] ?? null,
        ];
        if ($withPayload) $result['payload'] = is_array($delivery['payload'] ?? null) ? $delivery['payload'] : [];
        return $result;
    }

    private function access(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null);
    }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return is_array($token) ? (string) ($token['id'] ?? 0) : '0'; }
}
