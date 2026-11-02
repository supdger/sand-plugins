<?php

declare(strict_types=1);

/**
 * Build the declaration-only SandPackage v2 profile from the frozen lifecycle
 * source. The returned value is embedded into the candidate descriptor; this
 * helper is not part of the installed plugin payload.
 *
 * @return array<string, mixed>
 */
function sandIamFailedUpgradeRecoveryProfileV2(string $packageRoot): array
{
    $updateSql = file_get_contents($packageRoot . '/update.sql');
    if (!is_string($updateSql)) {
        throw new RuntimeException('cannot read update.sql for recovery profile');
    }
    if (preg_match('/base_tables\s+text\[\]\s*:=\s*ARRAY\[(.*?)\];/s', $updateSql, $match) !== 1) {
        throw new RuntimeException('cannot locate the frozen 0.6.0 relation set');
    }
    preg_match_all("/'(sand_iam_[a-z0-9_]+)'/", $match[1], $relationMatches);
    $relations = array_values(array_unique($relationMatches[1] ?? [], SORT_STRING));
    if (count($relations) !== 82) {
        throw new RuntimeException('frozen 0.6.0 relation set must contain exactly 82 relations');
    }
    $relations[] = 'sand_iam_identity_group_role';
    sort($relations, SORT_STRING);

    $assertions = [
        ['relations' => $relations, 'type' => 'relations_exact'],
    ];

    // Exact column evidence preserved by the last read-only 0.6.0/033 catalog
    // capture. The ordinal positions are derived from the frozen 001-032
    // lifecycle order, including columns appended by later migrations.
    $baselineColumns = [
        ['sand_iam_identity_provider', 'application_id', 'bigint', '', 'YES', 2],
        ['sand_iam_identity_provider', 'provider_type', 'character varying', "'local'::character varying", 'NO', 14],
        ['sand_iam_identity_binding', 'identity_provider_id', 'bigint', '', 'NO', 4],
        ['sand_iam_identity_binding', 'application_id', 'bigint', '', 'NO', 2],
        ['sand_iam_identity_binding', 'source_state', 'character varying', "'active'::character varying", 'NO', 12],
        ['sand_iam_identity_provider', 'config_version', 'bigint', '1', 'NO', 21],
        ['sand_iam_federation_transaction', 'provider_config_version', 'bigint', '1', 'NO', 18],
        ['sand_iam_directory_sync_run', 'provider_config_version', 'bigint', '1', 'NO', 12],
        ['sand_iam_auth_session', 'identity_binding_id', 'bigint', '', 'YES', 21],
        ['sand_iam_scim_group_member', 'status', 'smallint', '1', 'NO', 7],
        ['sand_iam_scim_group_member', 'update_time', 'timestamp without time zone', 'CURRENT_TIMESTAMP', 'NO', 8],
        ['sand_iam_security_operation', 'request_id', 'character varying', '', 'NO', 5],
        ['sand_iam_security_operation', 'request_fingerprint', 'character', '', 'NO', 6],
        ['sand_iam_security_operation', 'result', 'jsonb', "'{}'::jsonb", 'NO', 10],
        ['sand_iam_initialization_binding', 'object_type', 'character varying', '', 'NO', 4],
        ['sand_iam_initialization_binding', 'table_name', 'character varying', '', 'NO', 6],
        ['sand_iam_application_experience', 'delete_time', 'timestamp without time zone', '', 'YES', 16],
        ['sand_iam_application_network_policy', 'delete_time', 'timestamp without time zone', '', 'YES', 10],
        ['sand_iam_audit_archive', 'delete_time', 'timestamp without time zone', '', 'YES', 15],
        ['sand_iam_audit_retention_policy', 'delete_time', 'timestamp without time zone', '', 'YES', 13],
        ['sand_iam_cas_login_request', 'delete_time', 'timestamp without time zone', '', 'YES', 10],
        ['sand_iam_cas_service', 'delete_time', 'timestamp without time zone', '', 'YES', 9],
        ['sand_iam_cas_ticket', 'delete_time', 'timestamp without time zone', '', 'YES', 11],
        ['sand_iam_directory_sync_run', 'delete_time', 'timestamp without time zone', '', 'YES', 14],
        ['sand_iam_federation_handoff', 'delete_time', 'timestamp without time zone', '', 'YES', 15],
        ['sand_iam_federation_transaction', 'delete_time', 'timestamp without time zone', '', 'YES', 24],
        ['sand_iam_identity_group', 'delete_time', 'timestamp without time zone', '', 'YES', 11],
        ['sand_iam_identity_group_member', 'delete_time', 'timestamp without time zone', '', 'YES', 8],
        ['sand_iam_identity_import_job', 'delete_time', 'timestamp without time zone', '', 'YES', 19],
        ['sand_iam_identity_import_row', 'delete_time', 'timestamp without time zone', '', 'YES', 16],
        ['sand_iam_identity_invitation', 'delete_time', 'timestamp without time zone', '', 'YES', 21],
        ['sand_iam_identity_provider_application', 'delete_time', 'timestamp without time zone', '', 'YES', 9],
        ['sand_iam_initialization_binding', 'delete_time', 'timestamp without time zone', '', 'YES', 10],
        ['sand_iam_initialization_run', 'delete_time', 'timestamp without time zone', '', 'YES', 17],
        ['sand_iam_message_provider', 'delete_time', 'timestamp without time zone', '', 'YES', 12],
        ['sand_iam_message_provider_application', 'delete_time', 'timestamp without time zone', '', 'YES', 11],
        ['sand_iam_oauth_registration_token', 'delete_time', 'timestamp without time zone', '', 'YES', 17],
        ['sand_iam_oidc_logout_delivery', 'delete_time', 'timestamp without time zone', '', 'YES', 18],
        ['sand_iam_oidc_signing_key', 'delete_time', 'timestamp without time zone', '', 'YES', 12],
        ['sand_iam_provisioning_event', 'delete_time', 'timestamp without time zone', '', 'YES', 9],
        ['sand_iam_radius_accounting_event', 'delete_time', 'timestamp without time zone', '', 'YES', 16],
        ['sand_iam_radius_accounting_session', 'delete_time', 'timestamp without time zone', '', 'YES', 16],
        ['sand_iam_radius_nas', 'delete_time', 'timestamp without time zone', '', 'YES', 11],
        ['sand_iam_radius_replay', 'delete_time', 'timestamp without time zone', '', 'YES', 8],
        ['sand_iam_scim_group', 'delete_time', 'timestamp without time zone', '', 'YES', 13],
        ['sand_iam_scim_group_member', 'delete_time', 'timestamp without time zone', '', 'YES', 6],
        ['sand_iam_scim_resource', 'delete_time', 'timestamp without time zone', '', 'YES', 12],
        ['sand_iam_security_alert', 'delete_time', 'timestamp without time zone', '', 'YES', 15],
        ['sand_iam_sync_connector', 'delete_time', 'timestamp without time zone', '', 'YES', 19],
        ['sand_iam_sync_outbox', 'delete_time', 'timestamp without time zone', '', 'YES', 15],
        ['sand_iam_sync_resource', 'delete_time', 'timestamp without time zone', '', 'YES', 15],
        ['sand_iam_sync_run', 'delete_time', 'timestamp without time zone', '', 'YES', 21],
    ];
    foreach ($baselineColumns as [$table, $column, $dataType, $default, $nullable, $ordinal]) {
        $assertions[] = [
            'column' => $column,
            'data_type' => $dataType,
            'default_expression' => $default,
            'identity_generation' => null,
            'is_identity' => 'NO',
            'nullable' => $nullable,
            'ordinal_position' => $ordinal,
            'table' => $table,
            'type' => 'column_exact',
        ];
    }

    $columns = [
        ['id', 'bigint', '', 'BY DEFAULT', 'YES', 'NO', 1],
        ['identity_group_id', 'bigint', '', null, 'NO', 'NO', 2],
        ['role_id', 'bigint', '', null, 'NO', 'NO', 3],
        ['application_id', 'bigint', '', null, 'NO', 'NO', 4],
        ['status', 'smallint', '1', null, 'NO', 'NO', 5],
        ['created_by', 'bigint', '', null, 'NO', 'YES', 6],
        ['updated_by', 'bigint', '', null, 'NO', 'YES', 7],
        ['create_time', 'timestamp without time zone', 'CURRENT_TIMESTAMP', null, 'NO', 'NO', 8],
        ['update_time', 'timestamp without time zone', 'CURRENT_TIMESTAMP', null, 'NO', 'NO', 9],
        ['delete_time', 'timestamp without time zone', '', null, 'NO', 'YES', 10],
    ];
    foreach ($columns as [$column, $dataType, $default, $identityGeneration, $isIdentity, $nullable, $ordinal]) {
        $assertions[] = [
            'column' => $column,
            'data_type' => $dataType,
            'default_expression' => $default,
            'identity_generation' => $identityGeneration,
            'is_identity' => $isIdentity,
            'nullable' => $nullable,
            'ordinal_position' => $ordinal,
            'table' => 'sand_iam_identity_group_role',
            'type' => 'column_exact',
        ];
    }

    $constraints = [
        ['sand_iam_identity_provider', 'uk_sand_iam_identity_provider_application_code', 'u', 'UNIQUE (application_id, code)', true],
        ['sand_iam_identity_provider', 'uk_sand_iam_identity_provider_id_application', 'u', 'UNIQUE (id, application_id)', true],
        ['sand_iam_identity_binding', 'fk_sand_iam_identity_binding_identity_application', 'f', 'FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT', true],
        ['sand_iam_identity_binding', 'fk_sand_iam_identity_binding_provider_application', 'f', 'FOREIGN KEY (identity_provider_id, application_id) REFERENCES sand_iam_identity_provider_application(identity_provider_id, application_id) ON DELETE RESTRICT', true],
        ['sand_iam_identity_binding', 'uk_sand_iam_identity_binding_provider_application_subject', 'u', 'UNIQUE (identity_provider_id, application_id, subject)', true],
        ['sand_iam_identity_binding', 'ck_sand_iam_identity_binding_source_state', 'c', "CHECK (source_state::text = ANY (ARRAY['active'::character varying, 'disabled'::character varying, 'deleted'::character varying]::text[]))", true],
        ['sand_iam_identity_provider', 'ck_sand_iam_identity_provider_type', 'c', "CHECK (provider_type::text = ANY (ARRAY['local'::character varying, 'oidc'::character varying, 'oauth2'::character varying, 'saml'::character varying, 'ldap'::character varying, 'scim'::character varying, 'kerberos'::character varying]::text[]))", true],
        ['sand_iam_identity_provider', 'ck_sand_iam_identity_provider_scope', 'c', "CHECK (scope_type::text = 'application'::text AND application_id IS NOT NULL OR scope_type::text = 'organization'::text AND application_id IS NULL)", true],
        ['sand_iam_federation_transaction', 'fk_sand_iam_federation_transaction_provider_application', 'f', 'FOREIGN KEY (identity_provider_id, application_id) REFERENCES sand_iam_identity_provider_application(identity_provider_id, application_id) ON DELETE RESTRICT', true],
        ['sand_iam_federation_transaction', 'ck_sand_iam_federation_transaction_protocol', 'c', "CHECK (protocol::text = ANY (ARRAY['oidc'::character varying, 'oauth2'::character varying, 'saml'::character varying]::text[]))", true],
        ['sand_iam_auth_session', 'fk_sand_iam_auth_session_federation_binding', 'f', 'FOREIGN KEY (identity_binding_id, application_id, identity_id) REFERENCES sand_iam_identity_binding(id, application_id, identity_id)', true],
        ['sand_iam_auth_session', 'ck_sand_iam_auth_session_federation_method', 'c', "CHECK (auth_method::text = 'local_password'::text AND identity_binding_id IS NULL OR auth_method::text = 'federation'::text AND identity_binding_id IS NOT NULL)", true],
        ['sand_iam_scim_group_member', 'ck_sand_iam_scim_group_member_status', 'c', 'CHECK (status = ANY (ARRAY[1, 2]))', true],
        ['sand_iam_message_provider', 'uk_sand_iam_message_provider_id_organization', 'u', 'UNIQUE (id, organization_id)', true],
        ['sand_iam_message_provider_application', 'fk_sand_iam_message_mount_provider_organization', 'f', 'FOREIGN KEY (message_provider_id, organization_id) REFERENCES sand_iam_message_provider(id, organization_id) ON DELETE RESTRICT', true],
        ['sand_iam_message_provider_application', 'fk_sand_iam_message_mount_application_organization', 'f', 'FOREIGN KEY (application_id, organization_id) REFERENCES sand_iam_application(id, organization_id) ON DELETE RESTRICT', true],
        ['sand_iam_sync_connector', 'fk_sand_iam_sync_connector_application_organization', 'f', 'FOREIGN KEY (application_id, organization_id) REFERENCES sand_iam_application(id, organization_id) ON DELETE RESTRICT', true],
        ['sand_iam_security_operation', 'uk_sand_iam_security_operation_actor_request', 'u', 'UNIQUE (actor_type, actor_ref, operation, request_id)', true],
        ['sand_iam_security_operation', 'ck_sand_iam_security_operation_state', 'c', "CHECK (state::text = ANY (ARRAY['pending'::character varying, 'succeeded'::character varying]::text[]))", true],
        ['sand_iam_security_operation', 'ck_sand_iam_security_operation_fingerprint', 'c', "CHECK (request_fingerprint ~ '^[0-9a-f]{64}$'::text)", true],
        ['sand_iam_service_grant', 'ck_sand_iam_service_grant_data_class', 'c', "CHECK (data_class IS NULL OR data_class::text ~ '^[a-z0-9][a-z0-9._-]{1,31}$'::text) NOT VALID", false],
        ['sand_iam_service_grant', 'ck_sand_iam_service_grant_quota_object', 'c', "CHECK (quota_policy IS NULL OR jsonb_typeof(quota_policy) = 'object'::text) NOT VALID", false],
        ['sand_iam_service_invocation_operation', 'uk_sand_iam_invocation_operation_client_operation', 'u', 'UNIQUE (workload_client_id, operation_id)', true],
        ['sand_iam_service_quota_bucket', 'uk_sand_iam_service_quota_bucket', 'u', 'UNIQUE (grant_id, window_seconds, window_start)', true],
        ['sand_iam_initialization_binding', 'ck_sand_iam_initialization_binding_type', 'c', "CHECK (object_type::text = ANY (ARRAY['application'::character varying, 'role'::character varying, 'user_type'::character varying, 'resource'::character varying, 'identity_provider'::character varying, 'policy'::character varying, 'application_business_action'::character varying]::text[]))", true],
        ['sand_iam_initialization_binding', 'ck_sand_iam_initialization_binding_table', 'c', "CHECK (table_name::text = ANY (ARRAY['sand_iam_application'::character varying, 'sand_iam_role'::character varying, 'sand_iam_user_type'::character varying, 'sand_iam_resource'::character varying, 'sand_iam_identity_provider'::character varying, 'sand_iam_policy'::character varying, 'sand_iam_application_business_action'::character varying]::text[]))", true],
        ['sand_iam_audit_log', 'sand_iam_audit_log_organization_id_fkey', 'f', 'FOREIGN KEY (organization_id) REFERENCES sand_iam_organization(id) ON DELETE RESTRICT', true],
        ['sand_iam_audit_log', 'sand_iam_audit_log_application_id_fkey', 'f', 'FOREIGN KEY (application_id) REFERENCES sand_iam_application(id) ON DELETE RESTRICT', true],
        ['sand_iam_identity_group_role', 'sand_iam_identity_group_role_pkey', 'p', 'PRIMARY KEY (id)', true],
        ['sand_iam_identity_group_role', 'sand_iam_identity_group_role_application_id_fkey', 'f', 'FOREIGN KEY (application_id) REFERENCES sand_iam_application(id) ON DELETE RESTRICT', true],
        ['sand_iam_identity_group_role', 'sand_iam_identity_group_role_status_check', 'c', 'CHECK (status = ANY (ARRAY[1, 2]))', true],
        ['sand_iam_identity_group_role', 'uk_sand_iam_identity_group_role', 'u', 'UNIQUE (identity_group_id, role_id)', true],
        ['sand_iam_identity_group_role', 'fk_sand_iam_group_role_group_app', 'f', 'FOREIGN KEY (identity_group_id, application_id) REFERENCES sand_iam_identity_group(id, application_id) ON DELETE RESTRICT', true],
        ['sand_iam_identity_group_role', 'fk_sand_iam_group_role_role_app', 'f', 'FOREIGN KEY (role_id, application_id) REFERENCES sand_iam_role(id, application_id) ON DELETE RESTRICT', true],
    ];
    foreach ($constraints as [$table, $name, $constraintType, $definition, $validated]) {
        $assertions[] = [
            'constraint_type' => $constraintType,
            'definition' => $definition,
            'name' => $name,
            'table' => $table,
            'type' => 'constraint_exact',
            'validated' => $validated,
        ];
    }

    $assertions[] = [
        'definition' => 'CREATE UNIQUE INDEX ux_sand_iam_role_id_application ON public.sand_iam_role USING btree (id, application_id)',
        'name' => 'ux_sand_iam_role_id_application',
        'table' => 'sand_iam_role',
        'type' => 'index_exact',
        'unique' => true,
    ];
    $assertions[] = [
        'definition' => 'CREATE INDEX idx_sand_iam_identity_group_role_role ON public.sand_iam_identity_group_role USING btree (application_id, role_id, status)',
        'name' => 'idx_sand_iam_identity_group_role_role',
        'table' => 'sand_iam_identity_group_role',
        'type' => 'index_exact',
        'unique' => false,
    ];

    $assertions[] = [
        'rows' => [
            ['code' => 'sand_iam:identity_group_role:index', 'component' => '', 'hidden' => 1, 'icon' => '', 'name' => '查看用户组角色', 'parent_code' => 'SandIAMPeopleAccess', 'path' => '', 'slug' => 'sand_iam:identity_group_role:index', 'sort' => 1871, 'status' => 1, 'type' => 3],
            ['code' => 'sand_iam:identity_group_role:grant', 'component' => '', 'hidden' => 1, 'icon' => '', 'name' => '授予用户组角色', 'parent_code' => 'SandIAMPeopleAccess', 'path' => '', 'slug' => 'sand_iam:identity_group_role:grant', 'sort' => 1872, 'status' => 1, 'type' => 3],
            ['code' => 'sand_iam:identity_group_role:revoke', 'component' => '', 'hidden' => 1, 'icon' => '', 'name' => '撤销用户组角色', 'parent_code' => 'SandIAMPeopleAccess', 'path' => '', 'slug' => 'sand_iam:identity_group_role:revoke', 'sort' => 1873, 'status' => 1, 'type' => 3],
        ],
        'type' => 'menu_rows_exact',
    ];
    $assertions[] = ['name' => 'sand_iam_schema_migration', 'type' => 'ledger_absent'];

    return [
        'app' => 'sand-iam',
        'assertions' => $assertions,
        'from_version' => '0.6.0',
        'id' => 'sand_iam_060_to_070_prefix_033_034_v2',
        'schema' => 'sandpackage.failed-upgrade-recovery-profile/v2',
        'state' => 'prefix_033_034',
        'to_version' => '0.7.0',
    ];
}
