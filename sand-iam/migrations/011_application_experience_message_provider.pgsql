-- SandIAM T09: application login experience and organization-owned message providers.
-- PostgreSQL only. This file is not active until root lifecycle SQL is explicitly updated.

ALTER TABLE sand_iam_auth_policy
    ADD COLUMN IF NOT EXISTS require_captcha SMALLINT NOT NULL DEFAULT 2
        CHECK (require_captcha IN (1, 2));

CREATE TABLE IF NOT EXISTS sand_iam_application_experience (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    brand_name VARCHAR(128) NOT NULL,
    logo_url VARCHAR(2048),
    primary_color VARCHAR(7) NOT NULL DEFAULT '#1677ff',
    theme_mode VARCHAR(16) NOT NULL DEFAULT 'system' CHECK (theme_mode IN ('light', 'dark', 'system')),
    default_locale VARCHAR(16) NOT NULL DEFAULT 'zh-CN',
    terms_url VARCHAR(2048),
    privacy_url VARCHAR(2048),
    registration_mode VARCHAR(16) NOT NULL DEFAULT 'disabled' CHECK (registration_mode IN ('open', 'invite', 'disabled')),
    login_methods JSONB NOT NULL DEFAULT '["password"]'::jsonb CHECK (jsonb_typeof(login_methods) = 'array'),
    registration_fields JSONB NOT NULL DEFAULT '["username","display_name","email"]'::jsonb CHECK (jsonb_typeof(registration_fields) = 'array'),
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_application_experience_application UNIQUE (application_id)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_application_experience_status
    ON sand_iam_application_experience(status);

CREATE TABLE IF NOT EXISTS sand_iam_message_provider (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES sand_iam_organization(id) ON DELETE RESTRICT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    provider_type VARCHAR(24) NOT NULL CHECK (provider_type IN ('email', 'sms', 'captcha', 'notification')),
    driver_code VARCHAR(64) NOT NULL,
    encrypted_config TEXT,
    config_version INTEGER NOT NULL DEFAULT 0 CHECK (config_version >= 0),
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_message_provider_org_code UNIQUE (organization_id, code)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_message_provider_org_type_status
    ON sand_iam_message_provider(organization_id, provider_type, status);

CREATE TABLE IF NOT EXISTS sand_iam_message_provider_application (
    id BIGSERIAL PRIMARY KEY,
    message_provider_id BIGINT NOT NULL REFERENCES sand_iam_message_provider(id) ON DELETE RESTRICT,
    application_id BIGINT NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    organization_id BIGINT NOT NULL REFERENCES sand_iam_organization(id) ON DELETE RESTRICT,
    purposes JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(purposes) = 'array'),
    template_codes JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(template_codes) = 'object'),
    priority INTEGER NOT NULL DEFAULT 100 CHECK (priority BETWEEN 1 AND 1000),
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_message_provider_application UNIQUE (message_provider_id, application_id)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_message_mount_application_status_priority
    ON sand_iam_message_provider_application(application_id, status, priority, id);
