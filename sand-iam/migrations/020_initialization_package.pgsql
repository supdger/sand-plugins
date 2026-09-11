-- SandIAM 0.12: reviewable initialization packages and rollback records.
-- No secrets, identities or business data may be stored in these tables.

CREATE TABLE IF NOT EXISTS sand_iam_initialization_run (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL REFERENCES sand_iam_organization(id) ON DELETE RESTRICT,
    application_id bigint NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    package_code varchar(64) NOT NULL,
    package_hash char(64) NOT NULL,
    preview_hash char(64) NOT NULL,
    manifest jsonb NOT NULL,
    changes jsonb NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'applied',
    applied_by bigint NOT NULL,
    applied_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rollback_by bigint NULL,
    rollback_time timestamp(0) without time zone NULL,
    request_id varchar(96) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_initialization_run_state CHECK (state IN ('applied', 'rolled_back'))
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_initialization_run_scope ON sand_iam_initialization_run(organization_id, application_id, applied_time DESC);
CREATE INDEX IF NOT EXISTS idx_sand_iam_initialization_run_package ON sand_iam_initialization_run(package_code, package_hash);

CREATE TABLE IF NOT EXISTS sand_iam_initialization_binding (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE CASCADE,
    package_code varchar(64) NOT NULL,
    object_type varchar(32) NOT NULL,
    object_key varchar(128) NOT NULL,
    table_name varchar(64) NOT NULL,
    resource_id bigint NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_initialization_binding UNIQUE (application_id, package_code, object_type, object_key),
    CONSTRAINT ck_sand_iam_initialization_binding_type CHECK (object_type IN ('application', 'role', 'user_type', 'resource', 'identity_provider', 'policy', 'application_business_action')),
    CONSTRAINT ck_sand_iam_initialization_binding_table CHECK (table_name IN ('sand_iam_application', 'sand_iam_role', 'sand_iam_user_type', 'sand_iam_resource', 'sand_iam_identity_provider', 'sand_iam_policy', 'sand_iam_application_business_action'))
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_initialization_binding_resource ON sand_iam_initialization_binding(table_name, resource_id);
