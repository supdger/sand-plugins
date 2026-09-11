-- SandIAM T10 phase 4: two-step identity CSV import and export reports.
-- PostgreSQL only; integrated into the generated root lifecycle.

CREATE TABLE IF NOT EXISTS sand_iam_identity_import_job (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    original_name VARCHAR(255) NOT NULL,
    content_digest CHAR(64) NOT NULL,
    mode VARCHAR(16) NOT NULL CHECK (mode IN ('create', 'update')),
    state VARCHAR(16) NOT NULL CHECK (state IN ('previewed', 'running', 'completed', 'partial', 'failed')),
    total_count INTEGER NOT NULL DEFAULT 0 CHECK (total_count >= 0),
    valid_count INTEGER NOT NULL DEFAULT 0 CHECK (valid_count >= 0),
    invalid_count INTEGER NOT NULL DEFAULT 0 CHECK (invalid_count >= 0),
    success_count INTEGER NOT NULL DEFAULT 0 CHECK (success_count >= 0),
    warning_count INTEGER NOT NULL DEFAULT 0 CHECK (warning_count >= 0),
    failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count >= 0),
    created_by VARCHAR(64) NOT NULL,
    confirmed_time TIMESTAMP WITHOUT TIME ZONE,
    completed_time TIMESTAMP WITHOUT TIME ZONE,
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_import_job_application_state
    ON sand_iam_identity_import_job(application_id, state, id DESC);

CREATE TABLE IF NOT EXISTS sand_iam_identity_import_row (
    id BIGSERIAL PRIMARY KEY,
    import_job_id BIGINT NOT NULL REFERENCES sand_iam_identity_import_job(id) ON DELETE RESTRICT,
    application_id BIGINT NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    row_number INTEGER NOT NULL CHECK (row_number >= 2),
    idempotency_key CHAR(64) NOT NULL,
    encrypted_payload TEXT,
    summary JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(summary) = 'object'),
    validation_errors JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(validation_errors) = 'array'),
    state VARCHAR(32) NOT NULL CHECK (state IN ('valid', 'invalid', 'processing', 'succeeded', 'succeeded_with_warning', 'failed')),
    result_identity_id BIGINT,
    result_invitation_id BIGINT,
    error_code VARCHAR(128),
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_import_row_job_number UNIQUE (import_job_id, row_number),
    CONSTRAINT uk_sand_iam_import_row_idempotency UNIQUE (idempotency_key),
    CONSTRAINT fk_sand_iam_import_row_identity_app FOREIGN KEY (result_identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_import_row_invitation FOREIGN KEY (result_invitation_id)
        REFERENCES sand_iam_identity_invitation(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_import_row_job_state
    ON sand_iam_identity_import_row(import_job_id, state, row_number);
