<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\SecurityOperation;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdempotencyService
{
    /** @return array{result:array<string,mixed>,replayed:true} */
    public function replay(string $actorType, string $actorRef, string $operationName, string $requestId, string $requestFingerprint): array
    {
        $replay = $this->replayIfCompleted($actorType, $actorRef, $operationName, $requestId, $requestFingerprint);
        if ($replay === null) throw new ApiException('SAND_IAM_IDEMPOTENCY_REPLAY_UNAVAILABLE: 并发请求尚未形成可重放结果，请稍后重试', 503);
        return $replay;
    }

    /**
     * Reads a completed result without ever invoking the caller callback.
     * A null result means that this operation has not been claimed yet.
     *
     * @return array{result:array<string,mixed>,replayed:true}|null
     */
    public function replayIfCompleted(string $actorType, string $actorRef, string $operationName, string $requestId, string $requestFingerprint): ?array
    {
        Db::startTrans();
        try {
            $record = SecurityOperation::where('actor_type', $actorType)->where('actor_ref', $actorRef)->where('operation', $operationName)->where('request_id', RequestId::normalize($requestId))->lock(true)->find();
            if ($record === null) { Db::commit(); return null; }
            if (!hash_equals((string) $record->request_fingerprint, $requestFingerprint)) throw new ApiException('SAND_IAM_IDEMPOTENCY_CONFLICT: request_id 已用于不同请求', 409);
            if ((string) $record->state !== 'succeeded') throw new ApiException('SAND_IAM_IDEMPOTENCY_IN_PROGRESS: 请求仍在处理中，请稍后重试', 409);
            Db::commit();
            return ['result' => array_merge($this->storedResult($record->result), ['secret_available' => false]), 'replayed' => true];
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    /**
     * Waits only for a matching in-flight operation to become replayable.
     * Missing records remain missing so a new caller can still claim work;
     * a different fingerprint always remains a conflict.
     *
     * @return array{result:array<string,mixed>,replayed:true}|null
     */
    public function waitForCompletedReplay(
        string $actorType,
        string $actorRef,
        string $operationName,
        string $requestId,
        string $requestFingerprint,
        int $attempts = 100,
        int $waitMicroseconds = 50_000,
    ): ?array {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $this->replayIfCompleted($actorType, $actorRef, $operationName, $requestId, $requestFingerprint);
            } catch (ApiException $exception) {
                if (!str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_IN_PROGRESS')) {
                    throw $exception;
                }
                if ($attempt + 1 < $attempts) {
                    usleep($waitMicroseconds);
                }
            }
        }
        return null;
    }
    /**
     * @param callable():array{resource_id?:int|null,result:array<string,mixed>} $operation
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    public function execute(
        string $actorType,
        string $actorRef,
        string $operationName,
        string $requestId,
        string $requestFingerprint,
        string $resourceType,
        callable $operation,
        int $retry = 0,
        bool $manageTransaction = true,
    ): array {
        $requestId = RequestId::normalize($requestId);
        if ($manageTransaction) Db::startTrans();
        try {
            $record = SecurityOperation::where('actor_type', $actorType)
                ->where('actor_ref', $actorRef)
                ->where('operation', $operationName)
                ->where('request_id', $requestId)
                ->lock(true)
                ->find();
            if ($record !== null) {
                if (!hash_equals((string) $record->request_fingerprint, $requestFingerprint)) {
                    throw new ApiException('SAND_IAM_IDEMPOTENCY_CONFLICT: request_id 已用于不同请求', 409);
                }
                if ((string) $record->state !== 'succeeded') {
                    throw new ApiException('SAND_IAM_IDEMPOTENCY_IN_PROGRESS: 请求仍在处理中，请稍后重试', 409);
                }
                if ($manageTransaction) Db::commit();
                $result = $this->storedResult($record->result);
                return ['result' => array_merge($result, ['secret_available' => false]), 'replayed' => true];
            }

            $record = SecurityOperation::create([
                'actor_type' => $actorType,
                'actor_ref' => $actorRef,
                'operation' => $operationName,
                'request_id' => $requestId,
                'request_fingerprint' => $requestFingerprint,
                'resource_type' => $resourceType,
                'state' => 'pending',
                'result' => [],
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            $completed = $operation();
            $result = $completed['result'];
            $record->save([
                'state' => 'succeeded',
                'resource_id' => $completed['resource_id'] ?? null,
                'result' => $this->redactSecrets($result),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            if ($manageTransaction) Db::commit();
            return ['result' => $result, 'replayed' => false];
        } catch (\Throwable $exception) {
            if ($manageTransaction) Db::rollback();
            if ($retry === 0 && $this->isUniqueViolation($exception)) {
                if (!$manageTransaction) {
                    // PostgreSQL marks the surrounding transaction aborted on
                    // 23505. The owner must roll it back before replay lookup;
                    // never recurse here or invoke the callback twice.
                    throw new ApiException('SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED: 外层事务发生唯一冲突，已回滚后才能安全读取幂等结果', 409);
                }
                return $this->execute($actorType, $actorRef, $operationName, $requestId, $requestFingerprint, $resourceType, $operation, 1, $manageTransaction);
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(self::sort($payload), JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function redactSecrets(array $result): array
    {
        foreach ($result as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['credential', 'secret', 'token', 'access_token', 'refresh_token', 'context'], true)) {
                unset($result[$key]);
            } elseif (is_array($value)) {
                $result[$key] = $this->redactSecrets($value);
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function storedResult(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function sort(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::sort($value);
            }
        }
        ksort($payload);
        return $payload;
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, '23505') || str_contains($message, 'unique constraint');
    }
}
