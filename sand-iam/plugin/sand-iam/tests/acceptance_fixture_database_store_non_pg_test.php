<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace think\facade {
    final class Db
    {
        /** @var list<array<string,mixed>> */
        public static array $rows = [];
        /** @var list<array{method:string,args:list<mixed>}> */
        public static array $calls = [];

        public static function table(string $table): object
        {
            if ($table !== 'sand_iam_service_invocation_operation') throw new \RuntimeException('unexpected table: ' . $table);
            return new class {
                /** @var array<string,mixed> */
                private array $filters = [];

                public function whereRaw(string $sql, array $bindings): self
                {
                    \think\facade\Db::$calls[] = ['method' => 'whereRaw', 'args' => [$sql, $bindings]];
                    if ($sql === 'left(request_id, ?) = ?') $this->filters['prefix'] = (string) ($bindings[1] ?? '');
                    return $this;
                }

                public function whereIn(string $field, array $values): self
                {
                    \think\facade\Db::$calls[] = ['method' => 'whereIn', 'args' => [$field, $values]];
                    $this->filters[$field] = $values;
                    return $this;
                }

                public function where(string $field, int $value): self
                {
                    \think\facade\Db::$calls[] = ['method' => 'where', 'args' => [$field, $value]];
                    $this->filters[$field] = $value;
                    return $this;
                }

                public function lock(bool $value): self { return $this; }

                /** @return list<array<string,mixed>> */
                public function select(): array
                {
                    return array_values(array_filter(\think\facade\Db::$rows, function (array $row): bool {
                        foreach ($this->filters as $field => $value) {
                            if ($field === 'prefix' && !str_starts_with((string) ($row['request_id'] ?? ''), $value)) return false;
                            if (is_array($value) && !in_array((int) ($row[$field] ?? 0), $value, true)) return false;
                            if (!is_array($value) && $field !== 'prefix' && (int) ($row[$field] ?? 0) !== $value) return false;
                        }
                        return true;
                    }));
                }
            };
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/acceptance/AcceptanceFixtureStore.php';
    require_once dirname(__DIR__) . '/app/acceptance/DatabaseAcceptanceFixtureStore.php';

    use plugin\SandIam\app\acceptance\DatabaseAcceptanceFixtureStore;
    use think\facade\Db;

    $prefix = 'sand_iam_acceptance_0123456789abcdef_';
    Db::$rows = [
        ['id' => 111, 'request_id' => $prefix . 'chain4-allow', 'credential_id' => 88, 'grant_id' => 99, 'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 55],
        ['id' => 113, 'request_id' => $prefix . 'chain4-extra', 'credential_id' => 88, 'grant_id' => 99, 'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 55],
        ['id' => 114, 'request_id' => 'historical-request', 'credential_id' => 88, 'grant_id' => 99, 'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 55],
        ['id' => 115, 'request_id' => $prefix . 'wrong-client', 'credential_id' => 88, 'grant_id' => 99, 'organization_id' => 11, 'application_id' => 22, 'environment_id' => 33, 'workload_client_id' => 56],
    ];
    $store = new DatabaseAcceptanceFixtureStore();
    $operations = $store->invocationOperations($prefix, [88], [99], ['organization' => 11, 'application' => 22, 'environment' => 33, 'workload_client' => 55], true);
    if (array_column($operations, 'id') !== [111, 113]) {
        fwrite(STDERR, "database acceptance store did not return the complete same-credential/grant prefix operation set\n");
        exit(1);
    }
    if (array_filter(Db::$calls, static fn (array $call): bool => $call['method'] === 'whereIn' && $call['args'][0] === 'request_id')) {
        fwrite(STDERR, "database acceptance store incorrectly narrowed operations to submitted request ids\n");
        exit(1);
    }
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/acceptance/DatabaseAcceptanceFixtureStore.php');
    foreach ([
        'function identityGroupMembers(string $prefix, int $applicationId, bool $lock): array',
        "Db::table('sand_iam_identity_group_member')->alias('member')",
        "->join('sand_iam_identity_group iam_group', 'iam_group.id = member.identity_group_id')",
        "->join('sand_iam_identity iam_identity', 'iam_identity.id = member.identity_id')",
        "whereRaw('(left(iam_group.code, ?) = ? OR left(iam_identity.code, ?) = ?)', [strlen(\$prefix), \$prefix, strlen(\$prefix), \$prefix])",
        'function identityGroupRoles(string $prefix, int $applicationId, bool $lock): array',
        "Db::table('sand_iam_identity_group_role')->alias('group_role')",
        "->join('sand_iam_identity_group iam_group', 'iam_group.id = group_role.identity_group_id')",
        "function webhookDeliveries(array \$endpointIds, int \$applicationId, bool \$lock): array",
        "Db::table('sand_iam_webhook_delivery')",
        "->whereIn('webhook_endpoint_id', \$endpointIds)",
        "->where('application_id', \$applicationId)",
        'function webhookDeliveryAuditIds(array $deliveryIds): array',
        "->where('action', 'webhook.delivery')",
        "->where('resource_type', 'webhook_delivery')",
        "Db::table('sand_iam_auth_challenge')",
        "->whereIn('identity_id', \$identityIds)",
        "'auth_challenge' => \$challengeRows",
        "Db::table('sand_iam_scim_group_member')",
        "->whereIn('group_id', \$groupIds)",
        "'scim_group' => \$groups",
        "'scim_group_member' => \$groupMembers",
        'function detachPolicyVersions(array $policyVersionIds, array $policyIds, int $applicationId): void',
        "Db::table('sand_iam_policy')->where('application_id', \$applicationId)->whereIn('id', \$policyIds)->lock(true)",
        "Db::table('sand_iam_policy_version')->where('id', \$publishedVersionId)->lock(true)",
        "(int) (\$version['policy_id'] ?? 0) !== (int) (\$policy['id'] ?? 0)",
        "(int) (\$version['application_id'] ?? 0) !== (int) (\$policy['application_id'] ?? 0)",
        'SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 策略 published_version',
        "Db::table('sand_iam_policy_version')->whereIn('id', \$policyVersionIds)->lock(true)",
        "->whereIn('published_version_id', \$policyVersionIds)",
        "->whereNotIn('id', \$policyIds)",
        "->field('id')",
        "->limit(1)",
        "->where('application_id', \$applicationId)",
        "->whereIn('policy_id', \$policyIds)",
        'SAND_IAM_ACCEPTANCE_FIXTURE_POLICY_VERSION_SCOPE_DENIED',
    ] as $contract) {
        if (!str_contains($source, $contract)) {
            fwrite(STDERR, "database acceptance store relationship-scope query contract is missing: {$contract}\n");
            exit(1);
        }
    }
    if (str_contains($source, "whereIn('member.id'") || str_contains($source, "whereIn('group_role.id'")) {
        fwrite(STDERR, "database acceptance store incorrectly narrows relationship scope to submitted IDs\n");
        exit(1);
    }
    if (str_contains($source, '->exists()') || substr_count($source, '->count() > 0') !== 2) {
        fwrite(STDERR, "database acceptance store must avoid unsupported locked aggregate existence checks\n");
        exit(1);
    }
    $webhookDeliveryQuery = strstr($source, 'function webhookDeliveries', true);
    $webhookDeliveryQuery = is_string($webhookDeliveryQuery) ? substr($source, strlen($webhookDeliveryQuery)) : '';
    $webhookDeliveryQuery = strstr($webhookDeliveryQuery, 'public function webhookDeliveryAuditIds', true);
    if (is_string($webhookDeliveryQuery) && str_contains($webhookDeliveryQuery, "left(event_id, ?) = ?")) {
        fwrite(STDERR, "database acceptance store incorrectly narrows webhook cleanup to same-prefix deliveries before it can reject historical or concurrent endpoint records\n");
        exit(1);
    }
    echo "acceptance fixture database store non-PG tests passed\n";
}
