-- SandIAM T10: application-scoped identity synchronization connector.
-- Candidate migration only. The root install/update/uninstall lifecycle scripts
-- remain the release source of truth and must be integrated and verified separately.

CREATE TABLE IF NOT EXISTS sand_iam_sync_connector (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL,
    application_id BIGINT NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    direction VARCHAR(16) NOT NULL DEFAULT 'inbound',
    driver_code VARCHAR(64) NOT NULL,
    encrypted_config TEXT NULL,
    config_version INTEGER NOT NULL DEFAULT 0,
    authority_map JSONB NOT NULL DEFAULT '{}'::JSONB,
    conflict_policy VARCHAR(16) NOT NULL DEFAULT 'manual',
    encrypted_cursor TEXT NULL,
    missing_protection_hours INTEGER NOT NULL DEFAULT 24,
    disable_threshold_percent INTEGER NOT NULL DEFAULT 20,
    last_sync_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    status SMALLINT NOT NULL DEFAULT 1,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_sync_connector_direction
        CHECK (direction IN ('inbound', 'outbound', 'bidirectional')),
    CONSTRAINT ck_sand_iam_sync_connector_conflict_policy
        CHECK (conflict_policy IN ('manual', 'reject', 'source_wins', 'local_wins')),
    CONSTRAINT ck_sand_iam_sync_connector_config_version
        CHECK (config_version >= 0),
    CONSTRAINT ck_sand_iam_sync_connector_missing_protection
        CHECK (missing_protection_hours BETWEEN 1 AND 720),
    CONSTRAINT ck_sand_iam_sync_connector_disable_threshold
        CHECK (disable_threshold_percent BETWEEN 1 AND 100),
    CONSTRAINT ck_sand_iam_sync_connector_status
        CHECK (status IN (1, 2)),
    CONSTRAINT uk_sand_iam_sync_connector_app_code
        UNIQUE (application_id, code),
    CONSTRAINT uk_sand_iam_sync_connector_id_app
        UNIQUE (id, application_id),
    CONSTRAINT fk_sand_iam_sync_connector_org
        FOREIGN KEY (organization_id) REFERENCES sand_iam_organization (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_sync_connector_app
        FOREIGN KEY (application_id) REFERENCES sand_iam_application (id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_sync_connector_app_status
    ON sand_iam_sync_connector (application_id, status, id DESC);

CREATE TABLE IF NOT EXISTS sand_iam_sync_run (
    id BIGSERIAL PRIMARY KEY,
    sync_connector_id BIGINT NOT NULL,
    application_id BIGINT NOT NULL,
    connector_config_version INTEGER NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'running',
    cursor_before_hash CHAR(64) NULL,
    cursor_after_hash CHAR(64) NULL,
    pulled INTEGER NOT NULL DEFAULT 0,
    pushed INTEGER NOT NULL DEFAULT 0,
    created INTEGER NOT NULL DEFAULT 0,
    updated INTEGER NOT NULL DEFAULT 0,
    missing INTEGER NOT NULL DEFAULT 0,
    disabled INTEGER NOT NULL DEFAULT 0,
    conflict INTEGER NOT NULL DEFAULT 0,
    error_code VARCHAR(128) NULL,
    start_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finish_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    status SMALLINT NOT NULL DEFAULT 1,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_sync_run_state
        CHECK (state IN ('running', 'succeeded', 'failed')),
    CONSTRAINT ck_sand_iam_sync_run_counts
        CHECK (pulled >= 0 AND pushed >= 0 AND created >= 0 AND updated >= 0
            AND missing >= 0 AND disabled >= 0 AND conflict >= 0),
    CONSTRAINT ck_sand_iam_sync_run_status
        CHECK (status IN (1, 2)),
    CONSTRAINT uk_sand_iam_sync_run_id_app
        UNIQUE (id, application_id),
    CONSTRAINT fk_sand_iam_sync_run_connector_app
        FOREIGN KEY (sync_connector_id, application_id)
        REFERENCES sand_iam_sync_connector (id, application_id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_sync_run_running
    ON sand_iam_sync_run (sync_connector_id)
    WHERE state = 'running';
CREATE INDEX IF NOT EXISTS idx_sand_iam_sync_run_connector_time
    ON sand_iam_sync_run (sync_connector_id, id DESC);

CREATE TABLE IF NOT EXISTS sand_iam_sync_resource (
    id BIGSERIAL PRIMARY KEY,
    sync_connector_id BIGINT NOT NULL,
    application_id BIGINT NOT NULL,
    source_key_hash CHAR(64) NOT NULL,
    source_version VARCHAR(128) NOT NULL,
    identity_id BIGINT NOT NULL,
    encrypted_snapshot TEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    source_state VARCHAR(16) NOT NULL DEFAULT 'active',
    missing_since TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    last_seen_run_id BIGINT NULL,
    status SMALLINT NOT NULL DEFAULT 1,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_sync_resource_state
        CHECK (source_state IN ('active', 'missing', 'disabled', 'conflict')),
    CONSTRAINT ck_sand_iam_sync_resource_status
        CHECK (status IN (1, 2)),
    CONSTRAINT uk_sand_iam_sync_resource_source
        UNIQUE (sync_connector_id, source_key_hash),
    CONSTRAINT fk_sand_iam_sync_resource_connector_app
        FOREIGN KEY (sync_connector_id, application_id)
        REFERENCES sand_iam_sync_connector (id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_sync_resource_identity_app
        FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity (id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_sync_resource_run_app
        FOREIGN KEY (last_seen_run_id, application_id)
        REFERENCES sand_iam_sync_run (id, application_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_sync_resource_missing
    ON sand_iam_sync_resource (sync_connector_id, missing_since)
    WHERE source_state = 'missing' AND status = 1;
CREATE INDEX IF NOT EXISTS idx_sand_iam_sync_resource_identity
    ON sand_iam_sync_resource (application_id, identity_id);

CREATE TABLE IF NOT EXISTS sand_iam_sync_outbox (
    id BIGSERIAL PRIMARY KEY,
    sync_connector_id BIGINT NOT NULL,
    application_id BIGINT NOT NULL,
    identity_id BIGINT NOT NULL,
    event_id VARCHAR(128) NOT NULL,
    operation VARCHAR(16) NOT NULL,
    encrypted_payload TEXT NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    error_code VARCHAR(128) NULL,
    delivered_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    status SMALLINT NOT NULL DEFAULT 1,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_sync_outbox_operation
        CHECK (operation IN ('create', 'update', 'disable', 'delete')),
    CONSTRAINT ck_sand_iam_sync_outbox_state
        CHECK (state IN ('pending', 'succeeded', 'failed')),
    CONSTRAINT ck_sand_iam_sync_outbox_attempt_count
        CHECK (attempt_count >= 0),
    CONSTRAINT ck_sand_iam_sync_outbox_status
        CHECK (status IN (1, 2)),
    CONSTRAINT uk_sand_iam_sync_outbox_event
        UNIQUE (event_id),
    CONSTRAINT fk_sand_iam_sync_outbox_connector_app
        FOREIGN KEY (sync_connector_id, application_id)
        REFERENCES sand_iam_sync_connector (id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_sync_outbox_identity_app
        FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity (id, application_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_sync_outbox_pending
    ON sand_iam_sync_outbox (sync_connector_id, id)
    WHERE state = 'pending' AND status = 1;
