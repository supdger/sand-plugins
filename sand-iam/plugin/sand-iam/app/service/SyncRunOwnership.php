<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use PDO;
use plugin\SandIam\app\model\SyncConnector;
use plugin\SandIam\app\model\SyncRun;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** One connector runner on one physical PostgreSQL session, across page commits. */
final class SyncRunOwnership
{
    /** @var array<int,true> Prevent session-lock reentrancy on a shared connection. */
    private static array $owners = [];
    private bool $lost = false;
    private bool $released = false;

    private function __construct(
        private readonly object $connection,
        private readonly PDO $pdo,
        private readonly int $connectorId,
        private readonly int $applicationId,
        private readonly string $key,
    ) {}

    public static function acquire(int $connectorId, int $applicationId): self
    {
        $connection = Db::connect();
        // Read/write splitting can silently select another PDO between pages.
        if ($connection->getConfig('type') !== 'pgsql' || $connection->getConfig('deploy')) {
            throw new ApiException('SAND_IAM_SYNC_SESSION_CONNECTION_REQUIRED', 503);
        }
        $connection->query('SELECT 1', [], true);
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO || $pdo->inTransaction()) {
            throw new ApiException('SAND_IAM_SYNC_SESSION_CONNECTION_REQUIRED', 503);
        }
        $objectId = spl_object_id($pdo);
        if (isset(self::$owners[$objectId])) throw new ApiException('SAND_IAM_SYNC_ALREADY_RUNNING', 409);
        $key = (string) hexdec(substr(hash('sha256', 'sand-iam:sync-run:' . $connectorId), 0, 15));
        try {
            $statement = $pdo->prepare('SELECT pg_try_advisory_lock(CAST(:key AS bigint))');
            $statement->execute(['key' => $key]);
            $locked = $statement->fetchColumn();
        } catch (\Throwable $exception) {
            // Acquisition may have succeeded even if its response was lost.
            $connection->close();
            throw $exception;
        }
        if (!in_array($locked, [true, 1, '1', 't'], true)) {
            throw new ApiException('SAND_IAM_SYNC_ALREADY_RUNNING', 409);
        }
        self::$owners[$objectId] = true;
        return new self($connection, $pdo, $connectorId, $applicationId, $key);
    }

    public function check(): void
    {
        if ($this->released || $this->lost || Db::connect() !== $this->connection
            || $this->connection->getPdo() !== $this->pdo) {
            $this->lost = true;
            throw $this->lostOwnership();
        }
        try {
            // Use the original PDO directly: this check must never reconnect.
            $this->pdo->query('SELECT 1');
        } catch (\Throwable) {
            $this->lost = true;
            throw $this->lostOwnership();
        }
    }

    /** @param list<string> $states */
    public function transaction(?int $runId, callable $operation, array $states = ['running']): mixed
    {
        $this->check();
        if ($this->pdo->inTransaction()) throw new ApiException('SAND_IAM_SYNC_SESSION_CONNECTION_REQUIRED', 503);
        try {
            $this->connection->startTrans();
            // startTrans may reconnect before beginning; fence before ORM writes.
            $this->check();
            $connector = SyncConnector::where('id', $this->connectorId)
                ->where('application_id', $this->applicationId)->lock(true)->find();
            if ($connector === null) throw $this->lostOwnership();
            $run = null;
            if ($runId !== null) {
                $run = SyncRun::where('id', $runId)->where('sync_connector_id', $this->connectorId)
                    ->where('application_id', $this->applicationId)->lock(true)->find();
                if ($run === null || !in_array((string) $run->state, $states, true)) {
                    throw $this->lostOwnership();
                }
            }
            $result = $operation($connector, $run);
            $this->check();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $exception) {
            try {
                $this->connection->rollback();
            } catch (\Throwable) {
                $this->lost = true;
                $this->connection->close();
            }
            throw $exception;
        }
    }

    public function release(): void
    {
        if ($this->released) return;
        $this->released = true;
        try {
            $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(CAST(:key AS bigint))');
            $statement->execute(['key' => $this->key]);
            if (!in_array($statement->fetchColumn(), [true, 1, '1', 't'], true)) {
                $this->connection->close();
            }
        } catch (\Throwable) {
            // Do not return a possibly locked connection to Webman's pool.
            $this->connection->close();
        } finally {
            unset(self::$owners[spl_object_id($this->pdo)]);
        }
    }

    private function lostOwnership(): ApiException
    {
        return new ApiException('SAND_IAM_SYNC_RUN_OWNERSHIP_LOST', 409);
    }
}
