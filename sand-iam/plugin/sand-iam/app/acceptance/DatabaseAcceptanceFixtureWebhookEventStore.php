<?php

declare(strict_types=1);

namespace plugin\SandIam\app\acceptance;

use think\facade\Db;

final class DatabaseAcceptanceFixtureWebhookEventStore implements AcceptanceFixtureWebhookEventStore
{
    public function transaction(callable $operation): mixed
    {
        Db::startTrans();
        try {
            $result = $operation();
            Db::commit();
            return $result;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    public function application(int $applicationId, bool $lock): ?array
    {
        $query = Db::table('sand_iam_application')->where('id', $applicationId);
        if ($lock) $query->lock(true);
        return $this->one($query->find());
    }

    public function endpoint(int $endpointId, int $applicationId, bool $lock): ?array
    {
        $query = Db::table('sand_iam_webhook_endpoint')
            ->where('id', $endpointId)
            ->where('application_id', $applicationId);
        if ($lock) $query->lock(true);
        return $this->one($query->find());
    }

    public function creationAuditIds(string $requestId, int $endpointId, string $prefix): array
    {
        $ids = Db::table('sand_iam_audit_log')
            ->where('action', 'webhook.create')
            ->where('resource_type', 'webhook_endpoint')
            ->where('resource_id', $endpointId)
            ->where('request_id', $requestId)
            ->whereRaw('left(request_id, ?) = ?', [strlen($prefix), $prefix])
            ->where('outcome', 'succeeded')
            ->column('resource_id');
        $result = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, is_array($ids) ? $ids : [])));
        sort($result);
        return $result;
    }

    public function deliveries(int $endpointId, int $applicationId, string $eventId, bool $lock): array
    {
        $query = Db::table('sand_iam_webhook_delivery')
            ->where('webhook_endpoint_id', $endpointId)
            ->where('application_id', $applicationId)
            ->where('event_id', $eventId);
        if ($lock) $query->lock(true);
        $rows = $query->select();
        $values = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : $rows;
        return is_array($values) ? array_values(array_filter($values, 'is_array')) : [];
    }

    /** @return array<string,mixed>|null */
    private function one(mixed $row): ?array
    {
        if (is_object($row) && method_exists($row, 'toArray')) $row = $row->toArray();
        return is_array($row) ? $row : null;
    }
}
