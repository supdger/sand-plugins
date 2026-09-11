-- SandIAM T11: RFC 7591 initial access tokens and OIDC logout metadata.
-- Candidate migration only; root lifecycle integration is verified separately.

CREATE TABLE IF NOT EXISTS sand_iam_oauth_registration_token (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL,
    name VARCHAR(128) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    allowed_redirect_hosts JSONB NOT NULL DEFAULT '[]'::JSONB,
    allowed_scopes JSONB NOT NULL DEFAULT '[]'::JSONB,
    max_uses INTEGER NOT NULL DEFAULT 1,
    used_count INTEGER NOT NULL DEFAULT 0,
    expire_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    issued_by VARCHAR(128) NOT NULL,
    last_used_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    last_used_ip_hash CHAR(64) NULL,
    revoked_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    status SMALLINT NOT NULL DEFAULT 1,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_oauth_registration_token_hash UNIQUE (token_hash),
    CONSTRAINT ck_sand_iam_oauth_registration_token_hosts CHECK (jsonb_typeof(allowed_redirect_hosts) = 'array'),
    CONSTRAINT ck_sand_iam_oauth_registration_token_scopes CHECK (jsonb_typeof(allowed_scopes) = 'array'),
    CONSTRAINT ck_sand_iam_oauth_registration_token_uses CHECK (max_uses BETWEEN 1 AND 1000 AND used_count BETWEEN 0 AND max_uses),
    CONSTRAINT ck_sand_iam_oauth_registration_token_status CHECK (status IN (1, 2)),
    CONSTRAINT fk_sand_iam_oauth_registration_token_app FOREIGN KEY (application_id) REFERENCES sand_iam_application (id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_oauth_registration_token_app_status
    ON sand_iam_oauth_registration_token (application_id, status, expire_time, id DESC);

ALTER TABLE sand_iam_oauth_client
    ADD COLUMN IF NOT EXISTS registration_source VARCHAR(16) NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS dynamic_registration_token_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS frontchannel_logout_uri VARCHAR(2048) NULL,
    ADD COLUMN IF NOT EXISTS frontchannel_logout_session_required BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS backchannel_logout_uri VARCHAR(2048) NULL,
    ADD COLUMN IF NOT EXISTS backchannel_logout_session_required BOOLEAN NOT NULL DEFAULT TRUE;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_iam_oauth_client_registration_source') THEN
        ALTER TABLE sand_iam_oauth_client
            ADD CONSTRAINT ck_sand_iam_oauth_client_registration_source
            CHECK (registration_source IN ('manual', 'dynamic'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_sand_iam_oauth_client_dcr_token') THEN
        ALTER TABLE sand_iam_oauth_client
            ADD CONSTRAINT fk_sand_iam_oauth_client_dcr_token
            FOREIGN KEY (dynamic_registration_token_id)
            REFERENCES sand_iam_oauth_registration_token (id) ON DELETE RESTRICT;
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS idx_sand_iam_oauth_client_dcr_token
    ON sand_iam_oauth_client (dynamic_registration_token_id)
    WHERE dynamic_registration_token_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS sand_iam_oidc_logout_delivery (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL,
    oauth_client_id BIGINT NOT NULL,
    auth_session_id BIGINT NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    encrypted_logout_token TEXT NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    next_attempt_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    locked_until TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    delivered_time TIMESTAMP(0) WITHOUT TIME ZONE NULL,
    response_status INTEGER NULL,
    response_digest CHAR(64) NULL,
    last_error_code VARCHAR(128) NULL,
    status SMALLINT NOT NULL DEFAULT 1,
    create_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_oidc_logout_delivery_event UNIQUE (event_id),
    CONSTRAINT ck_sand_iam_oidc_logout_delivery_state CHECK (state IN ('pending', 'sending', 'delivered', 'dead')),
    CONSTRAINT ck_sand_iam_oidc_logout_delivery_attempt CHECK (attempt_count BETWEEN 0 AND 5),
    CONSTRAINT ck_sand_iam_oidc_logout_delivery_status CHECK (status IN (1, 2)),
    CONSTRAINT fk_sand_iam_oidc_logout_delivery_app FOREIGN KEY (application_id) REFERENCES sand_iam_application (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_oidc_logout_delivery_client FOREIGN KEY (oauth_client_id) REFERENCES sand_iam_oauth_client (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_oidc_logout_delivery_session FOREIGN KEY (auth_session_id) REFERENCES sand_iam_auth_session (id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_oidc_logout_delivery_pending
    ON sand_iam_oidc_logout_delivery (next_attempt_time, id)
    WHERE state = 'pending' AND status = 1;
