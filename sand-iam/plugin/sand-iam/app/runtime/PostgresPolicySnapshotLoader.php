<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use PDO;
use think\facade\Db;

/** Reads facts only. No request matching, policy writes or authorization effects. */
final class PostgresPolicySnapshotLoader implements PolicySnapshotLoader
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function load(int $applicationId, int $identityId, string $resourceCode, string $action, ?array $apiContext = null): array
    {
        $connection = $this->connection;
        if ($connection === null) {
            $orm = Db::connect();
            $current = $orm->getPdo();
            if ($current instanceof PDO && $current->inTransaction()) {
                throw new \RuntimeException('SAND_IAM_POLICY_SNAPSHOT_UNAVAILABLE: caller transaction is still active');
            }
            // Select the writer explicitly: a prior ORM SELECT may have left
            // linkID on a replica. This probe reads no authorization facts.
            $orm->query('SELECT 1', [], true);
            $connection = $orm->getPdo();
        }
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $connection->inTransaction()) {
            throw new \RuntimeException('SAND_IAM_POLICY_SNAPSHOT_UNAVAILABLE: requires a fresh PostgreSQL transaction');
        }
        // Own one PDO for the entire short snapshot. Never use ORM queries here:
        // a reconnect/read replica or READ COMMITTED statement would mix facts.
        $connection->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        try {
            $query = static function (string $sql, array $parameters = []) use ($connection): array {
                $statement = $connection->prepare($sql);
                $statement->execute($parameters);
                return $statement->fetchAll(PDO::FETCH_ASSOC);
            };
            $application = $query('SELECT id, organization_id FROM sand_iam_application WHERE id=? AND status=1 AND delete_time IS NULL', [$applicationId])[0] ?? null;
            $identity = $query('SELECT id FROM sand_iam_identity WHERE id=? AND application_id=? AND status=1 AND delete_time IS NULL', [$identityId, $applicationId])[0] ?? null;
            $resource = $query('SELECT id, code FROM sand_iam_resource WHERE application_id=? AND code=? AND status=1 AND delete_time IS NULL', [$applicationId, $resourceCode])[0] ?? null;
            $declaration = $query('SELECT status FROM sand_iam_application_business_action WHERE application_id=? AND code=? AND delete_time IS NULL', [$applicationId, $action])[0] ?? null;
            $apiValid = true;
            if ($apiContext !== null) {
                $api = $query('SELECT id, resource_id, action, operation, code, api_version FROM sand_iam_api_resource WHERE id=? AND application_id=? AND status=1 AND delete_time IS NULL', [$apiContext['id'] ?? 0, $applicationId])[0] ?? null;
                $apiValid = $api !== null && $resource !== null && (int) $api['resource_id'] === (int) $resource['id'];
                foreach (['action', 'operation', 'code', 'api_version'] as $key) {
                    $apiValid = $apiValid && ($api[$key] ?? null) === ($apiContext[$key] ?? null);
                }
            }
            $policies = $query('SELECT p.id, p.published_version_id, v.id AS version_id, v.policy_id AS version_policy_id, v.application_id AS version_application_id, v.snapshot FROM sand_iam_policy p LEFT JOIN sand_iam_policy_version v ON v.id=p.published_version_id WHERE p.application_id=? AND p.status=1 AND p.published_version_id>0 AND p.delete_time IS NULL ORDER BY p.id', [$applicationId]);
            $references = [];
            foreach (['identity', 'role', 'resource'] as $type) {
                // Includes disabled references for ownership verification. Disabled
                // role grants are normal state: only live edges below are loaded.
                $references[$type] = array_map('intval', array_column($query('SELECT id FROM sand_iam_' . $type . ' WHERE application_id=?', [$applicationId]), 'id'));
            }
            $direct = $query('SELECT r.id AS role_id FROM sand_iam_identity_role ir JOIN sand_iam_role r ON r.id=ir.role_id WHERE ir.identity_id=? AND ir.status=1 AND ir.delete_time IS NULL AND r.application_id=? AND r.status=1', [$identityId, $applicationId]);
            $groups = $query('SELECT r.id AS role_id, g.id AS group_id FROM sand_iam_identity_group_member gm JOIN sand_iam_identity_group g ON g.id=gm.identity_group_id JOIN sand_iam_identity_group_role gr ON gr.identity_group_id=g.id JOIN sand_iam_role r ON r.id=gr.role_id WHERE gm.identity_id=? AND gm.application_id=? AND gm.status=1 AND gm.delete_time IS NULL AND g.application_id=? AND g.status=1 AND gr.application_id=? AND gr.status=1 AND r.application_id=? AND r.status=1', [$identityId, $applicationId, $applicationId, $applicationId, $applicationId]);
            foreach ($policies as &$policy) {
                $policy['snapshot'] = json_decode($policy['snapshot'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            }
            unset($policy);
            $facts = ['application_id' => $applicationId, 'identity_id' => $identityId, 'application' => $application, 'identity' => $identity, 'resource' => $resource, 'available' => $application !== null && $identity !== null && $resource !== null && $action !== '' && ($declaration === null || (int) $declaration['status'] === 1) && $apiValid, 'policies' => $policies, 'references' => $references, 'direct_roles' => $direct, 'group_roles' => $groups];
            $connection->commit();
            return $facts;
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $exception;
        }
    }
}
