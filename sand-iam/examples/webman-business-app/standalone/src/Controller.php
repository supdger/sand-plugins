<?php

declare(strict_types=1);

namespace Example\Standalone;

final class Controller
{
    /** @param callable(string,WorkItem,string,string):string $authorize */
    public function __construct(
        private readonly Repository $repository,
        private readonly AuditWriter $auditWriter,
        private readonly mixed $authorize,
    ) {}

    /** @param array<string,string> $headers @return array{status:int,body:array<string,mixed>} */
    public function handle(string $method, string $path, array $headers, string $body): array
    {
        $action = null;
        $item = null;
        $requestId = '';
        try {
            if ($method === 'GET' && $path === '/health') {
                $this->repository->health();
                return $this->response(200, ['status' => 'ok']);
            }
            if (preg_match('#^/items/([1-9][0-9]*)$#', $path, $matches) === 1 && $method === 'GET') {
                $action = 'standalone_work_item.read';
                $requestId = $this->requestId($headers);
                $token = $this->bearerToken($headers);
                $item = $this->repository->find((int) $matches[1]);
                $actor = ($this->authorize)($token, $item, $action, $requestId);
                $this->auditWriter->writeEvent($action, 'allowed', $item, null, $actor, $requestId);
                return $this->itemResponse($item);
            }
            if (preg_match('#^/items/([1-9][0-9]*)/close$#', $path, $matches) === 1 && $method === 'POST') {
                $action = 'standalone_work_item.close';
                $requestId = $this->requestId($headers);
                $this->assertSafeBody($body);
                $token = $this->bearerToken($headers);
                $item = $this->repository->find((int) $matches[1]);
                $item = $this->repository->close(
                    (int) $matches[1],
                    fn (WorkItem $loaded): string => ($this->authorize)($token, $loaded, $action, $requestId),
                    $this->auditWriter,
                    $requestId,
                );
                return $this->itemResponse($item);
            }
            throw new HttpProblem(404, 'not_found');
        } catch (HttpProblem $problem) {
            $this->failureAudit($action, $item, $problem->problemCode, $requestId);
            return $this->response($problem->status, ['error' => $problem->problemCode]);
        } catch (\Throwable) {
            $this->failureAudit($action, $item, 'authorization_or_storage_unavailable', $requestId);
            return $this->response(503, ['error' => 'unavailable']);
        }
    }

    private function failureAudit(?string $action, ?WorkItem $item, string $reasonCode, string $requestId): void
    {
        if ($action === null || $requestId === '') return;
        try {
            $this->auditWriter->writeEvent($action, 'denied', $item, $reasonCode, 'unknown', $requestId);
        } catch (\Throwable) {
            // The operation is already denied or rolled back; never expose audit-store detail.
        }
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function itemResponse(WorkItem $item): array
    {
        return $this->response(200, ['id' => $item->id, 'state' => $item->state, 'version' => $item->version]);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    /** @param array<string,string> $headers */
    private function bearerToken(array $headers): string
    {
        $value = trim($headers['authorization'] ?? '');
        if (strncasecmp($value, 'Bearer ', 7) !== 0 || trim(substr($value, 7)) === '') {
            throw new HttpProblem(401, 'authentication_required');
        }
        return trim(substr($value, 7));
    }

    /** @param array<string,string> $headers */
    private function requestId(array $headers): string
    {
        $value = trim($headers['x-request-id'] ?? '');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) {
            throw new HttpProblem(400, 'request_id_required');
        }
        return $value;
    }

    private function assertSafeBody(string $body): void
    {
        if (trim($body) === '') {
            return;
        }
        try {
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpProblem(400, 'invalid_json');
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new HttpProblem(400, 'invalid_body');
        }
        foreach (['organization_id', 'owner_identity_id', 'scope', 'attributes'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new HttpProblem(400, 'body_scope_forbidden');
            }
        }
    }
}
