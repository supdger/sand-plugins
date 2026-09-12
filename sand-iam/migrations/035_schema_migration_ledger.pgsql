-- SandIAM 0.7.0 migration ledger.
-- There was no database migration ledger in the published 0.6.0 package. The
-- first run therefore adopts its known baseline only after checking the
-- structural version sentinels below; it never treats an empty ledger alone as
-- evidence that a database is compatible.
BEGIN;

CREATE TABLE IF NOT EXISTS sand_iam_schema_migration (
    migration_file varchar(160) PRIMARY KEY,
    revision smallint NOT NULL,
    checksum char(64) NOT NULL,
    package_version varchar(32) NOT NULL,
    executed_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_schema_migration_revision CHECK (revision BETWEEN 1 AND 999),
    CONSTRAINT ck_sand_iam_schema_migration_checksum CHECK (checksum ~ '^[0-9a-f]{64}$')
);

DO $$
DECLARE
    required_columns integer;
    matched_columns integer;
    base_tables text[] := ARRAY['sand_iam_admin_application_grant','sand_iam_admin_organization_grant','sand_iam_api_resource','sand_iam_api_route_binding','sand_iam_application','sand_iam_application_business_action','sand_iam_application_experience','sand_iam_application_network_policy','sand_iam_audit_archive','sand_iam_audit_log','sand_iam_audit_retention_policy','sand_iam_auth_challenge','sand_iam_auth_policy','sand_iam_auth_rate_limit','sand_iam_auth_refresh_token','sand_iam_auth_session','sand_iam_auth_verification','sand_iam_authorization_code','sand_iam_cas_login_request','sand_iam_cas_service','sand_iam_cas_ticket','sand_iam_credential','sand_iam_directory_sync_run','sand_iam_environment','sand_iam_federation_handoff','sand_iam_federation_transaction','sand_iam_identity','sand_iam_identity_auth','sand_iam_identity_binding','sand_iam_identity_group','sand_iam_identity_group_member','sand_iam_identity_import_job','sand_iam_identity_import_row','sand_iam_identity_invitation','sand_iam_identity_provider','sand_iam_identity_provider_application','sand_iam_identity_role','sand_iam_identity_user_type','sand_iam_initialization_binding','sand_iam_initialization_run','sand_iam_message_provider','sand_iam_message_provider_application','sand_iam_mfa_factor','sand_iam_mfa_recovery_code','sand_iam_oauth_authorization_request','sand_iam_oauth_client','sand_iam_oauth_consent','sand_iam_oauth_grant','sand_iam_oauth_registration_token','sand_iam_oauth_token','sand_iam_oidc_logout_delivery','sand_iam_oidc_signing_key','sand_iam_organization','sand_iam_policy','sand_iam_policy_version','sand_iam_provisioning_event','sand_iam_radius_accounting_event','sand_iam_radius_accounting_session','sand_iam_radius_nas','sand_iam_radius_replay','sand_iam_resource','sand_iam_role','sand_iam_scim_group','sand_iam_scim_group_member','sand_iam_scim_resource','sand_iam_scim_token','sand_iam_security_alert','sand_iam_security_operation','sand_iam_service','sand_iam_service_action','sand_iam_service_grant','sand_iam_service_invocation_operation','sand_iam_service_quota_bucket','sand_iam_sync_connector','sand_iam_sync_outbox','sand_iam_sync_resource','sand_iam_sync_run','sand_iam_user_type','sand_iam_webauthn_credential','sand_iam_webhook_delivery','sand_iam_webhook_endpoint','sand_iam_workload_client'];
    ledger_columns integer;
    matched_ledger_columns integer;
    expected_rows integer;
    recorded_rows integer;
    total_recorded_rows integer;
BEGIN
    SELECT count(*) INTO total_recorded_rows FROM sand_iam_schema_migration;

    IF cardinality(base_tables) <> 82
       OR EXISTS (SELECT unnest(base_tables) EXCEPT SELECT relation_class.relname FROM pg_class relation_class JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation_class.relnamespace WHERE relation_namespace.nspname = current_schema() AND relation_class.relkind IN ('r', 'p') AND relation_class.relname LIKE 'sand_iam_%' AND relation_class.relname <> 'sand_iam_schema_migration')
       OR (
            total_recorded_rows = 0
            AND (
                (SELECT count(*) FROM pg_class relation_class JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation_class.relnamespace WHERE relation_namespace.nspname = current_schema() AND relation_class.relkind IN ('r', 'p') AND relation_class.relname LIKE 'sand_iam_%' AND relation_class.relname <> 'sand_iam_schema_migration') <> 83
                OR EXISTS (SELECT relation_class.relname FROM pg_class relation_class JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation_class.relnamespace WHERE relation_namespace.nspname = current_schema() AND relation_class.relkind IN ('r', 'p') AND relation_class.relname LIKE 'sand_iam_%' AND relation_class.relname <> 'sand_iam_schema_migration' EXCEPT (SELECT unnest(base_tables) UNION SELECT 'sand_iam_identity_group_role'))
            )
       )
       OR (
            total_recorded_rows > 0
            AND (
                (SELECT count(*) FROM pg_class relation_class JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation_class.relnamespace WHERE relation_namespace.nspname = current_schema() AND relation_class.relkind IN ('r', 'p') AND relation_class.relname LIKE 'sand_iam_%' AND relation_class.relname <> 'sand_iam_schema_migration') <> 85
                OR EXISTS (SELECT relation_class.relname FROM pg_class relation_class JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation_class.relnamespace WHERE relation_namespace.nspname = current_schema() AND relation_class.relkind IN ('r', 'p') AND relation_class.relname LIKE 'sand_iam_%' AND relation_class.relname <> 'sand_iam_schema_migration' EXCEPT (SELECT unnest(base_tables) UNION SELECT 'sand_iam_identity_group_role' UNION SELECT 'sand_iam_initialization_draft' UNION SELECT 'sand_iam_initialization_draft_revision'))
            )
       ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger refuses schema adoption: exact legacy or ledger-backed relation fingerprint is incompatible';
    END IF;

    WITH required_baseline_column(table_name, column_name, data_type, is_nullable, requires_default) AS (
        VALUES
            ('sand_iam_identity_provider', 'application_id', 'bigint', 'YES', false),
            ('sand_iam_identity_provider', 'provider_type', 'character varying', 'NO', false),
            ('sand_iam_identity_binding', 'identity_provider_id', 'bigint', 'NO', false),
            ('sand_iam_identity_binding', 'application_id', 'bigint', 'NO', false),
            ('sand_iam_identity_binding', 'source_state', 'character varying', 'NO', true),
            ('sand_iam_identity_provider', 'config_version', 'bigint', 'NO', true),
            ('sand_iam_federation_transaction', 'provider_config_version', 'bigint', 'NO', true),
            ('sand_iam_directory_sync_run', 'provider_config_version', 'bigint', 'NO', true),
            ('sand_iam_auth_session', 'identity_binding_id', 'bigint', 'YES', false),
            ('sand_iam_scim_group_member', 'status', 'smallint', 'NO', true),
            ('sand_iam_scim_group_member', 'update_time', 'timestamp without time zone', 'NO', true),
            ('sand_iam_security_operation', 'request_id', 'character varying', 'NO', false),
            ('sand_iam_security_operation', 'request_fingerprint', 'character', 'NO', false),
            ('sand_iam_security_operation', 'result', 'jsonb', 'NO', true),
            ('sand_iam_initialization_binding', 'object_type', 'character varying', 'NO', false),
            ('sand_iam_initialization_binding', 'table_name', 'character varying', 'NO', false),
            ('sand_iam_identity_group_role', 'status', 'smallint', 'NO', true),
            ('sand_iam_identity_group_role', 'create_time', 'timestamp without time zone', 'NO', true),
            ('sand_iam_identity_group_role', 'update_time', 'timestamp without time zone', 'NO', true),
            ('sand_iam_identity_group_role', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_application_experience', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_application_network_policy', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_audit_archive', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_audit_retention_policy', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_cas_login_request', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_cas_service', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_cas_ticket', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_directory_sync_run', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_federation_handoff', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_federation_transaction', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_identity_group', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_identity_group_member', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_identity_import_job', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_identity_import_row', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_identity_invitation', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_identity_provider_application', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_initialization_binding', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_initialization_run', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_message_provider', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_message_provider_application', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_oauth_registration_token', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_oidc_logout_delivery', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_oidc_signing_key', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_provisioning_event', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_radius_accounting_event', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_radius_accounting_session', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_radius_nas', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_radius_replay', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_scim_group', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_scim_group_member', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_scim_resource', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_security_alert', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_sync_connector', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_sync_outbox', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_sync_resource', 'delete_time', 'timestamp without time zone', 'YES', false),
            ('sand_iam_sync_run', 'delete_time', 'timestamp without time zone', 'YES', false)
    )
    SELECT count(*) INTO matched_columns
    FROM required_baseline_column expected
    JOIN information_schema.columns column_info
      ON column_info.table_schema = current_schema()
     AND column_info.table_name = expected.table_name
     AND column_info.column_name = expected.column_name
     AND column_info.data_type = expected.data_type
     AND column_info.is_nullable = expected.is_nullable
     AND (NOT expected.requires_default OR column_info.column_default IS NOT NULL);

    IF matched_columns <> 56 THEN
        RAISE EXCEPTION 'SandIAM migration ledger refuses baseline adoption: key 002/006/022/024/027/032 column fingerprint is incompatible';
    END IF;

    WITH required_constraint(table_name, constraint_name, constraint_type, definition_token, normalized_check_definition) AS (
        VALUES
            ('sand_iam_identity_provider', 'uk_sand_iam_identity_provider_application_code', 'u', 'UNIQUE (application_id, code)', NULL),
            ('sand_iam_identity_provider', 'uk_sand_iam_identity_provider_id_application', 'u', 'UNIQUE (id, application_id)', NULL),
            ('sand_iam_identity_binding', 'fk_sand_iam_identity_binding_identity_application', 'f', 'FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_identity_binding', 'fk_sand_iam_identity_binding_provider_application', 'f', 'FOREIGN KEY (identity_provider_id, application_id) REFERENCES sand_iam_identity_provider_application(identity_provider_id, application_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_identity_binding', 'uk_sand_iam_identity_binding_provider_application_subject', 'u', 'UNIQUE (identity_provider_id, application_id, subject)', NULL),
            ('sand_iam_identity_binding', 'ck_sand_iam_identity_binding_source_state', 'c', NULL, 'checksource_state=anyarray[''active'',''disabled'',''deleted'']'),
            ('sand_iam_identity_provider', 'ck_sand_iam_identity_provider_type', 'c', NULL, 'checkprovider_type=anyarray[''local'',''oidc'',''oauth2'',''saml'',''ldap'',''scim'',''kerberos'']'),
            ('sand_iam_identity_provider', 'ck_sand_iam_identity_provider_scope', 'c', NULL, 'checkscope_type=''application''andapplication_idisnotnullorscope_type=''organization''andapplication_idisnull'),
            ('sand_iam_federation_transaction', 'fk_sand_iam_federation_transaction_provider_application', 'f', 'FOREIGN KEY (identity_provider_id, application_id) REFERENCES sand_iam_identity_provider_application(identity_provider_id, application_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_federation_transaction', 'ck_sand_iam_federation_transaction_protocol', 'c', NULL, 'checkprotocol=anyarray[''oidc'',''oauth2'',''saml'']'),
            ('sand_iam_auth_session', 'fk_sand_iam_auth_session_federation_binding', 'f', 'FOREIGN KEY (identity_binding_id, application_id, identity_id) REFERENCES sand_iam_identity_binding(id, application_id, identity_id)', NULL),
            ('sand_iam_auth_session', 'ck_sand_iam_auth_session_federation_method', 'c', NULL, 'checkauth_method=''local_password''andidentity_binding_idisnullorauth_method=''federation''andidentity_binding_idisnotnull'),
            ('sand_iam_scim_group_member', 'ck_sand_iam_scim_group_member_status', 'c', NULL, 'checkstatus=anyarray[1,2]'),
            ('sand_iam_message_provider', 'uk_sand_iam_message_provider_id_organization', 'u', 'UNIQUE (id, organization_id)', NULL),
            ('sand_iam_message_provider_application', 'fk_sand_iam_message_mount_provider_organization', 'f', 'FOREIGN KEY (message_provider_id, organization_id) REFERENCES sand_iam_message_provider(id, organization_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_message_provider_application', 'fk_sand_iam_message_mount_application_organization', 'f', 'FOREIGN KEY (application_id, organization_id) REFERENCES sand_iam_application(id, organization_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_sync_connector', 'fk_sand_iam_sync_connector_application_organization', 'f', 'FOREIGN KEY (application_id, organization_id) REFERENCES sand_iam_application(id, organization_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_security_operation', 'uk_sand_iam_security_operation_actor_request', 'u', 'UNIQUE (actor_type, actor_ref, operation, request_id)', NULL),
            ('sand_iam_security_operation', 'ck_sand_iam_security_operation_state', 'c', NULL, 'checkstate=anyarray[''pending'',''succeeded'']'),
            ('sand_iam_security_operation', 'ck_sand_iam_security_operation_fingerprint', 'c', NULL, 'checkrequest_fingerprint~''^[0-9a-f]{64}$'''),
            ('sand_iam_service_grant', 'ck_sand_iam_service_grant_data_class', 'c', NULL, 'checkdata_classisnullordata_class~''^[a-z0-9][a-z0-9._-]{1,31}$'''),
            ('sand_iam_service_grant', 'ck_sand_iam_service_grant_quota_object', 'c', NULL, 'checkquota_policyisnullorjsonb_typeofquota_policy=''object'''),
            ('sand_iam_service_invocation_operation', 'uk_sand_iam_invocation_operation_client_operation', 'u', 'UNIQUE (workload_client_id, operation_id)', NULL),
            ('sand_iam_service_quota_bucket', 'uk_sand_iam_service_quota_bucket', 'u', 'UNIQUE (grant_id, window_seconds, window_start)', NULL),
            ('sand_iam_initialization_binding', 'ck_sand_iam_initialization_binding_type', 'c', NULL, 'checkobject_type=anyarray[''application'',''role'',''user_type'',''resource'',''identity_provider'',''policy'',''application_business_action'']'),
            ('sand_iam_initialization_binding', 'ck_sand_iam_initialization_binding_table', 'c', NULL, 'checktable_name=anyarray[''sand_iam_application'',''sand_iam_role'',''sand_iam_user_type'',''sand_iam_resource'',''sand_iam_identity_provider'',''sand_iam_policy'',''sand_iam_application_business_action'']'),
            ('sand_iam_identity_group_role', 'sand_iam_identity_group_role_pkey', 'p', 'PRIMARY KEY (id)', NULL),
            ('sand_iam_identity_group_role', 'uk_sand_iam_identity_group_role', 'u', 'UNIQUE (identity_group_id, role_id)', NULL),
            ('sand_iam_identity_group_role', 'fk_sand_iam_group_role_group_app', 'f', 'FOREIGN KEY (identity_group_id, application_id) REFERENCES sand_iam_identity_group(id, application_id) ON DELETE RESTRICT', NULL),
            ('sand_iam_identity_group_role', 'fk_sand_iam_group_role_role_app', 'f', 'FOREIGN KEY (role_id, application_id) REFERENCES sand_iam_role(id, application_id) ON DELETE RESTRICT', NULL)
    )
    SELECT count(*) INTO matched_columns
    FROM required_constraint expected
    JOIN pg_constraint actual_constraint
      ON actual_constraint.conname = expected.constraint_name
     AND actual_constraint.contype = expected.constraint_type
    JOIN pg_class actual_table ON actual_table.oid = actual_constraint.conrelid
    JOIN pg_namespace actual_schema ON actual_schema.oid = actual_table.relnamespace
    WHERE actual_schema.nspname = current_schema()
      AND actual_table.relname = expected.table_name
      AND actual_constraint.convalidated = CASE
          WHEN expected.constraint_name IN ('ck_sand_iam_service_grant_data_class', 'ck_sand_iam_service_grant_quota_object') THEN false
          ELSE true
      END
      AND (
          (expected.constraint_type <> 'c' AND pg_get_constraintdef(actual_constraint.oid, true) = expected.definition_token)
          OR (expected.constraint_type = 'c' AND regexp_replace(regexp_replace(regexp_replace(regexp_replace(lower(pg_get_constraintdef(actual_constraint.oid, true)), E'\\s+not\\s+valid$', '', 'g'), E'::[a-z_][a-z0-9_]*(\\s+varying)?(\\[\\])?', '', 'g'), E'\\s+', '', 'g'), '[()]', '', 'g') = expected.normalized_check_definition)
      );

    IF matched_columns <> 30 THEN
        RAISE EXCEPTION 'SandIAM migration ledger refuses baseline adoption: scoped constraint definition fingerprint is incompatible (matched %/30)', matched_columns;
    END IF;

    WITH required_ledger_column(column_name, data_type, character_maximum_length, is_nullable, requires_default) AS (
        VALUES
            ('migration_file', 'character varying', 160, 'NO', false),
            ('revision', 'smallint', NULL, 'NO', false),
            ('checksum', 'character', 64, 'NO', false),
            ('package_version', 'character varying', 32, 'NO', false),
            ('executed_time', 'timestamp without time zone', NULL, 'NO', true)
    )
    SELECT count(*), count(column_info.column_name)
    INTO ledger_columns, matched_ledger_columns
    FROM required_ledger_column expected
    LEFT JOIN information_schema.columns column_info
      ON column_info.table_schema = current_schema()
     AND column_info.table_name = 'sand_iam_schema_migration'
     AND column_info.column_name = expected.column_name
     AND column_info.data_type = expected.data_type
     AND column_info.character_maximum_length IS NOT DISTINCT FROM expected.character_maximum_length
     AND column_info.is_nullable = expected.is_nullable
     AND (NOT expected.requires_default OR column_info.column_default IS NOT NULL);

    IF matched_ledger_columns <> ledger_columns
       OR NOT EXISTS (
            SELECT 1
            FROM pg_constraint ledger_pk
            JOIN pg_class ledger_table ON ledger_table.oid = ledger_pk.conrelid
            JOIN pg_namespace ledger_schema ON ledger_schema.oid = ledger_table.relnamespace
            WHERE ledger_schema.nspname = current_schema()
              AND ledger_table.relname = 'sand_iam_schema_migration'
              AND ledger_pk.contype = 'p'
              AND pg_get_constraintdef(ledger_pk.oid, true) = 'PRIMARY KEY (migration_file)'
       ) OR NOT EXISTS (
            SELECT 1
            FROM pg_constraint ledger_revision
            JOIN pg_class ledger_table ON ledger_table.oid = ledger_revision.conrelid
            JOIN pg_namespace ledger_schema ON ledger_schema.oid = ledger_table.relnamespace
            WHERE ledger_schema.nspname = current_schema()
              AND ledger_table.relname = 'sand_iam_schema_migration'
              AND ledger_revision.conname = 'ck_sand_iam_schema_migration_revision'
              AND ledger_revision.contype = 'c'
              AND ledger_revision.convalidated
              AND regexp_replace(regexp_replace(regexp_replace(lower(pg_get_constraintdef(ledger_revision.oid, true)), E'::[a-z_][a-z0-9_]*(\\s+varying)?(\\[\\])?', '', 'g'), E'\\s+', '', 'g'), '[()]', '', 'g') = 'checkrevision>=1andrevision<=999'
       ) OR NOT EXISTS (
            SELECT 1
            FROM pg_constraint ledger_checksum
            JOIN pg_class ledger_table ON ledger_table.oid = ledger_checksum.conrelid
            JOIN pg_namespace ledger_schema ON ledger_schema.oid = ledger_table.relnamespace
            WHERE ledger_schema.nspname = current_schema()
              AND ledger_table.relname = 'sand_iam_schema_migration'
              AND ledger_checksum.conname = 'ck_sand_iam_schema_migration_checksum'
              AND ledger_checksum.contype = 'c'
              AND ledger_checksum.convalidated
              AND regexp_replace(regexp_replace(regexp_replace(lower(pg_get_constraintdef(ledger_checksum.oid, true)), E'::[a-z_][a-z0-9_]*(\\s+varying)?(\\[\\])?', '', 'g'), E'\\s+', '', 'g'), '[()]', '', 'g') = 'checkchecksum~''^[0-9a-f]{64}$'''
       ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger table definition is incompatible';
    END IF;

    IF EXISTS (
            WITH required_group_role_permission(code) AS (
                VALUES ('sand_iam:identity_group_role:index'), ('sand_iam:identity_group_role:grant'), ('sand_iam:identity_group_role:revoke')
            )
            SELECT required.code
            FROM required_group_role_permission required
            LEFT JOIN sand_system_menu permission_menu ON permission_menu.code = required.code
            LEFT JOIN sand_system_menu parent_menu ON parent_menu.id = permission_menu.parent_id
            GROUP BY required.code
            HAVING count(permission_menu.id) <> 1
                OR bool_or(parent_menu.code IS DISTINCT FROM 'SandIAMPeopleAccess' OR permission_menu.slug IS DISTINCT FROM required.code OR permission_menu.type IS DISTINCT FROM 3)
       ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger refuses baseline adoption: 034 permission catalog fingerprint is incompatible';
    END IF;

    WITH frozen_permission_group(resource_code, actions) AS (
        VALUES
            ('admin_application_grant', ARRAY['index','read','save','update','disable']::text[]),
            ('application_experience', ARRAY['index','read','save','update','disable']::text[]),
            ('message_provider', ARRAY['index','read','save','update','configure','test','disable']::text[]),
            ('message_provider_mount', ARRAY['index','save','disable']::text[]),
            ('initialization', ARRAY['index','read','preview','apply','rollback','export']::text[]),
            ('onboarding', ARRAY['preview','apply']::text[]),
            ('api_resource', ARRAY['index','read','save','update','disable']::text[]),
            ('api_route_binding', ARRAY['index','read','save','update','disable']::text[]),
            ('cas_service', ARRAY['index','read','save','update','disable']::text[]),
            ('identity', ARRAY['enable','delete','restore']::text[]),
            ('identity_export', ARRAY['masked','sensitive']::text[]),
            ('identity_group', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_group_member', ARRAY['index','add','remove']::text[]),
            ('identity_import', ARRAY['index','read','preview','confirm']::text[]),
            ('identity_invitation', ARRAY['index','read','send','resend','revoke']::text[]),
            ('oauth_registration_token', ARRAY['index','issue','revoke']::text[]),
            ('radius_nas', ARRAY['index','read','save','update','configure','disable']::text[]),
            ('sync_connector', ARRAY['index','read','save','update','configure','test','disable']::text[]),
            ('sync_run', ARRAY['index','run']::text[]),
            ('audit', ARRAY['export']::text[]),
            ('application_network_policy', ARRAY['index','read','save','update','disable']::text[]),
            ('audit_retention_policy', ARRAY['index','read','save','update','disable']::text[]),
            ('developer', ARRAY['openapi','events']::text[]),
            ('security_alert', ARRAY['index','read','resolve']::text[]),
            ('webhook', ARRAY['index','read','save','update','disable']::text[]),
            ('webhook_delivery', ARRAY['index','read','retry']::text[])
    ), frozen_permission_code(code) AS (
        SELECT 'sand_iam:' || permission_group.resource_code || ':' || action.code
        FROM frozen_permission_group permission_group
        CROSS JOIN LATERAL unnest(permission_group.actions) AS action(code)
    )
    SELECT count(*) INTO matched_columns
    FROM frozen_permission_code expected
    JOIN sand_system_menu actual ON actual.code = expected.code AND actual.type = 3;

    IF matched_columns <> 107
       OR EXISTS (
            WITH frozen_permission_group(resource_code, actions) AS (
                VALUES
                    ('admin_application_grant', ARRAY['index','read','save','update','disable']::text[]), ('application_experience', ARRAY['index','read','save','update','disable']::text[]), ('message_provider', ARRAY['index','read','save','update','configure','test','disable']::text[]), ('message_provider_mount', ARRAY['index','save','disable']::text[]), ('initialization', ARRAY['index','read','preview','apply','rollback','export']::text[]), ('onboarding', ARRAY['preview','apply']::text[]), ('api_resource', ARRAY['index','read','save','update','disable']::text[]), ('api_route_binding', ARRAY['index','read','save','update','disable']::text[]), ('cas_service', ARRAY['index','read','save','update','disable']::text[]), ('identity', ARRAY['enable','delete','restore']::text[]), ('identity_export', ARRAY['masked','sensitive']::text[]), ('identity_group', ARRAY['index','read','save','update','disable']::text[]), ('identity_group_member', ARRAY['index','add','remove']::text[]), ('identity_import', ARRAY['index','read','preview','confirm']::text[]), ('identity_invitation', ARRAY['index','read','send','resend','revoke']::text[]), ('oauth_registration_token', ARRAY['index','issue','revoke']::text[]), ('radius_nas', ARRAY['index','read','save','update','configure','disable']::text[]), ('sync_connector', ARRAY['index','read','save','update','configure','test','disable']::text[]), ('sync_run', ARRAY['index','run']::text[]), ('audit', ARRAY['export']::text[]), ('application_network_policy', ARRAY['index','read','save','update','disable']::text[]), ('audit_retention_policy', ARRAY['index','read','save','update','disable']::text[]), ('developer', ARRAY['openapi','events']::text[]), ('security_alert', ARRAY['index','read','resolve']::text[]), ('webhook', ARRAY['index','read','save','update','disable']::text[]), ('webhook_delivery', ARRAY['index','read','retry']::text[])
            ), frozen_permission_code(code) AS (
                SELECT 'sand_iam:' || permission_group.resource_code || ':' || action.code FROM frozen_permission_group permission_group CROSS JOIN LATERAL unnest(permission_group.actions) AS action(code)
            )
            SELECT expected.code FROM frozen_permission_code expected LEFT JOIN sand_system_menu actual ON actual.code = expected.code AND actual.type = 3 GROUP BY expected.code HAVING count(actual.id) <> 1
       ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger refuses baseline adoption: immutable 021 permission code set is absent or not unique';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint baseline_constraint
        JOIN pg_class baseline_table ON baseline_table.oid = baseline_constraint.conrelid
        JOIN pg_namespace baseline_schema ON baseline_schema.oid = baseline_table.relnamespace
        WHERE baseline_schema.nspname = current_schema()
          AND baseline_table.relname = 'sand_iam_identity_group_role'
          AND baseline_constraint.conname = 'uk_sand_iam_identity_group_role'
          AND baseline_constraint.contype = 'u'
          AND pg_get_constraintdef(baseline_constraint.oid, true) = 'UNIQUE (identity_group_id, role_id)'
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_index baseline_index
        JOIN pg_class index_relation ON index_relation.oid = baseline_index.indexrelid
        JOIN pg_class indexed_table ON indexed_table.oid = baseline_index.indrelid
        JOIN pg_namespace indexed_schema ON indexed_schema.oid = indexed_table.relnamespace
        WHERE indexed_schema.nspname = current_schema()
          AND indexed_table.relname = 'sand_iam_role'
          AND index_relation.relname = 'ux_sand_iam_role_id_application'
          AND baseline_index.indisunique AND baseline_index.indisvalid AND baseline_index.indisready
          AND pg_get_indexdef(baseline_index.indexrelid, 1, true) = 'id'
          AND pg_get_indexdef(baseline_index.indexrelid, 2, true) = 'application_id'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger refuses baseline adoption: constraint or index definition fingerprint is incompatible';
    END IF;

    CREATE TEMPORARY TABLE IF NOT EXISTS sand_iam_schema_migration_expected (
        migration_file varchar(160) PRIMARY KEY,
        revision smallint NOT NULL,
        checksum char(64) NOT NULL,
        package_version varchar(32) NOT NULL
    ) ON COMMIT DROP;

WITH self_checksum(checksum) AS (VALUES ('7365abe0bbaa0134c018a291a65a4a420b2b145e0c7c3d184ac0123405ae0e12')),
    expected(migration_file, revision, checksum, package_version) AS (
        VALUES
            ('001_iam04_admin_organization_grant.pgsql', 1, 'd214397c680631150f4cc1199d0af27063e968211b13b24a1e857ec9f0ceb5f6', '0.6.0'),
            ('002_identity_provider_scope.pgsql', 2, '92e2623876d251b8720303f573a88931765bc14db6c2f12a983fbb0d0084150a', '0.6.0'),
            ('003_human_auth_core.pgsql', 3, 'c75be4675a56dfe628a69ba7b2188450c1db88d0770672df0e6ddc1645e8ea50', '0.6.0'),
            ('004_mfa_passkey.pgsql', 4, 'e4c2a2b10650cc02d8b654746e4520912326609fad9675d93c6222de09accce3', '0.6.0'),
            ('005_oauth_oidc.pgsql', 5, 'de3e4f34a6e78b73179d62036788c1022c5557b08ca9671b2389a3ea1755ea62', '0.6.0'),
            ('006_federation_directory_scim.pgsql', 6, '94fea592ac863ff133d61da2cb9b015b00410a8487053858cc8481d2bec6e709', '0.6.0'),
            ('006_federation_integrity.pgsql', 6, '14095610942f64ee2aa463d1673550596d2df489ec3f0dd0d48ace6041396081', '0.6.0'),
            ('007_federation_handoff.pgsql', 7, 'f729fcae6da3effc2ebf41d8ddccc7b71251730be770d2f879d101d23f44a481', '0.6.0'),
            ('008_api_governance.pgsql', 8, 'cc16eee10ad09b1049f4d2e2e128665295fd2f64b26c8a727d4e082bb641f008', '0.6.0'),
            ('009_admin_application_grant.pgsql', 9, '6dda3f9466f51008e405877b2ad47b646891d312808dbfe9c9ab496299e8e34f', '0.6.0'),
            ('010_webhook_delivery.pgsql', 10, 'fce2c626b7ca1f69e250a0fc3c6cc8450d18a183f837f14d21adab695791f4b1', '0.6.0'),
            ('011_application_experience_message_provider.pgsql', 11, '46a087d79e14965a16421990cf888d80e097f1eecbc090f3aea658ae418a203a', '0.6.0'),
            ('012_identity_lifecycle_group.pgsql', 12, '7b44c4d2d9005c72af2922330d74ae8b50bd17a9392fc229063e427f8abf8299', '0.6.0'),
            ('013_identity_invitation.pgsql', 13, 'd9fd0740ace8a2be088f200942db8a1c49a1a114013c786415d8bdd7a40b8643', '0.6.0'),
            ('014_identity_import_export.pgsql', 14, '038b35c57d375a1d083b733915da68386b2b4f97d83224382b203b80f2ae587b', '0.6.0'),
            ('015_identity_sync_connector.pgsql', 15, '3219bd7db6b6f596d0b0c1169350f26d21f82f16f58960527e4eb382c16f6bfd', '0.6.0'),
            ('016_oauth_dynamic_registration_logout.pgsql', 16, '6d510349490eb8135c8fb02b30df8c0abcfc98b57ba4545ce115d0752c4d05bd', '0.6.0'),
            ('017_cas_protocol.pgsql', 17, '6ded442269d9d22721d78e5ca340cc9b08efddcdadfbd2efd01b76a0319df2f6', '0.6.0'),
            ('018_radius_server.pgsql', 18, '5517787fe08af96a90b125ec72a77179a7f0b6fa575b6fb59009a1ce6f22532c', '0.6.0'),
            ('019_security_operations.pgsql', 19, 'c800d667cc2efc733822970e176b3727d7be2600207137fc080112c161f035a3', '0.6.0'),
            ('020_initialization_package.pgsql', 20, '54abcaebca1ef4c7dc8747bf0f0bf84ca9bda5fc3227eb2d5415767277a0f3f2', '0.6.0'),
            ('021_admin_permission_catalog.pgsql', 21, 'f263ec1450bbd15d883c1d5b0f3a0fcfd15db602df9d120b1049a0c4cc428db9', '0.6.0'),
            ('022_model_soft_delete_contract.pgsql', 22, 'cf9e02c62b19d7115d2b1c7418f7555abfc9f5d8438be485431f6b9b1ac90d0e', '0.6.0'),
            ('023_federation_protocol_constraint_alignment.pgsql', 23, 'c88792bb4732c9c9c7aae641619416db765239122d5237ca97b991b722df78fa', '0.6.0'),
            ('024_scim_group_member_lifecycle.pgsql', 24, 'b178d81ff2b8820b3255a4e74127dbca3dbd59ae38573eeeefd8e0d688df1342', '0.6.0'),
            ('025_message_provider_mount_scope_integrity.pgsql', 25, 'dcd3c5e360566bae1cbdce7a2f5b0307ac05a36b41ae136e1fb8123bbb166f03', '0.6.0'),
            ('026_sync_connector_scope_integrity.pgsql', 26, 'b052b80e781b439781251bee7064f7dde7cada40dd67169ed19c93b42a3e70ce', '0.6.0'),
            ('027_security_operation_idempotency.pgsql', 27, 'c0c9b57cef2ed4c4d0d61be923124363f728beda27b3b2863ffae11a8f47d128', '0.6.0'),
            ('028_application_business_action.pgsql', 28, 'cb14e237f3052bb8ab200bf7e59f3d9a3b186ca2d79cdfa686af5c3b5e81329a', '0.6.0'),
            ('029_policy_versioning.pgsql', 29, '53b27e150c504600a53357c020de602819fcc8d6d9f2ff1e67820ef6b36e3c12', '0.6.0'),
            ('030_service_grant_invocation_control.pgsql', 30, '9fa587065ae878777d191db10f62d2e6e0dd33a97d90edcee883b709081602bc', '0.6.0'),
            ('031_oidc_signing_key_rotation.pgsql', 31, '7ce71dca33ee220faf2ca12812c0d132dd4190ed4c3094eb69e10bf8fdb31f14', '0.6.0'),
            ('032_initialization_binding_application_business_action.pgsql', 32, '5d3f6f7893356167f51eac62fa667990dcae2aebc4f12c11c3ac8e88f87353cb', '0.6.0'),
            ('033_identity_group_role.pgsql', 33, '161352617d1e46205a2abbf3b074ab7276e9dd691d151b61a2d8d146de26fd68', '0.7.0'),
        ('034_identity_group_role_permission_catalog.pgsql', 34, '97ab44356d905eb0e1c13ca3101a3dd0b8a4af3264cecb81310e1f522dfc2485', '0.7.0'),
        ('036_acceptance_fixture_support.pgsql', 36, '403f0fbadaf54436b81d8ff9d9eb2c14cbe83b5a26c3d13594241f2b40e17a3b', '0.7.0'),
        ('037_initialization_draft.pgsql', 37, 'cf7013ee42f9bd6319e8c23524f274e382901af7fbb32fa947732cd8beeb640c', '0.7.0'),
        ('038_auth_rate_limit_retention.pgsql', 38, '2765bed31eb3a8c5e894c0758e80120f6926f90b4f5a1a9ea39609dd608fe2c3', '0.7.0')
        UNION ALL
        SELECT '035_schema_migration_ledger.pgsql', 35, self_checksum.checksum, '0.7.0'
        FROM self_checksum
    )
    INSERT INTO pg_temp.sand_iam_schema_migration_expected (migration_file, revision, checksum, package_version)
    SELECT migration_file, revision, checksum, package_version
    FROM expected
    ON CONFLICT (migration_file) DO NOTHING;

    SELECT count(*) INTO expected_rows FROM pg_temp.sand_iam_schema_migration_expected;

    IF EXISTS (
        SELECT 1
        FROM sand_iam_schema_migration recorded
        LEFT JOIN pg_temp.sand_iam_schema_migration_expected expected
          ON expected.migration_file = recorded.migration_file
        WHERE expected.migration_file IS NULL
    ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger contains an unknown migration filename; refusing to continue';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM sand_iam_schema_migration recorded
        JOIN pg_temp.sand_iam_schema_migration_expected expected ON expected.migration_file = recorded.migration_file
        WHERE recorded.revision <> expected.revision
           OR recorded.checksum <> expected.checksum
           OR recorded.package_version <> expected.package_version
    ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger checksum or package-version conflict; refusing to continue';
    END IF;

    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration recorded
    JOIN pg_temp.sand_iam_schema_migration_expected expected ON expected.migration_file = recorded.migration_file;

    IF recorded_rows = 0 THEN
        INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
        SELECT migration_file, revision, checksum, package_version, CURRENT_TIMESTAMP
        FROM pg_temp.sand_iam_schema_migration_expected
        WHERE revision <= 35;
    ELSIF NOT (
        (recorded_rows = expected_rows AND total_recorded_rows = expected_rows)
        OR (
            recorded_rows = expected_rows - 1
            AND total_recorded_rows = expected_rows - 1
            AND NOT EXISTS (
                SELECT 1
                FROM pg_temp.sand_iam_schema_migration_expected expected
                LEFT JOIN sand_iam_schema_migration recorded ON recorded.migration_file = expected.migration_file
                WHERE expected.revision <= 35
                  AND recorded.migration_file IS NULL
            )
            AND EXISTS (SELECT 1 FROM sand_iam_schema_migration WHERE revision = 37)
            AND NOT EXISTS (SELECT 1 FROM sand_iam_schema_migration WHERE revision = 38)
        )
        OR (
            recorded_rows = expected_rows - 2
            AND total_recorded_rows = expected_rows - 2
            AND NOT EXISTS (
                SELECT 1
                FROM pg_temp.sand_iam_schema_migration_expected expected
                LEFT JOIN sand_iam_schema_migration recorded ON recorded.migration_file = expected.migration_file
                WHERE expected.revision <= 35
                  AND recorded.migration_file IS NULL
            )
            AND EXISTS (SELECT 1 FROM sand_iam_schema_migration WHERE revision = 36)
            AND NOT EXISTS (SELECT 1 FROM sand_iam_schema_migration WHERE revision IN (37, 38))
        )
        OR (
            recorded_rows = expected_rows - 3
            AND total_recorded_rows = expected_rows - 3
            AND NOT EXISTS (
                SELECT 1
                FROM pg_temp.sand_iam_schema_migration_expected expected
                LEFT JOIN sand_iam_schema_migration recorded ON recorded.migration_file = expected.migration_file
                WHERE expected.revision <= 35
                  AND recorded.migration_file IS NULL
            )
            AND NOT EXISTS (SELECT 1 FROM sand_iam_schema_migration WHERE revision IN (36, 37, 38))
        )
    ) THEN
        RAISE EXCEPTION 'SandIAM migration ledger is partial; refusing to adopt or overwrite missing baseline records';
    END IF;
END
$$;

COMMIT;
