-- SandIAM 0.12: application IP allowlist, audit retention/archive and alerts.
-- This immutable migration is not wired into root lifecycle SQL until the
-- separately authorized PostgreSQL lifecycle acceptance.

CREATE TABLE IF NOT EXISTS sand_iam_application_network_policy (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE CASCADE,
    allow_cidrs jsonb NOT NULL DEFAULT '[]'::jsonb,
    deny_cidrs jsonb NOT NULL DEFAULT '[]'::jsonb,
    status smallint NOT NULL DEFAULT 1,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_application_network_policy UNIQUE (application_id),
    CONSTRAINT ck_sand_iam_application_network_policy_status CHECK (status IN (1, 2)),
    CONSTRAINT ck_sand_iam_application_network_policy_allow CHECK (jsonb_typeof(allow_cidrs) = 'array'),
    CONSTRAINT ck_sand_iam_application_network_policy_deny CHECK (jsonb_typeof(deny_cidrs) = 'array')
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_application_network_policy_status ON sand_iam_application_network_policy(status);

CREATE TABLE IF NOT EXISTS sand_iam_audit_retention_policy (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL REFERENCES sand_iam_organization(id) ON DELETE CASCADE,
    archive_after_days integer NOT NULL DEFAULT 90,
    retention_days integer NOT NULL DEFAULT 365,
    purge_enabled boolean NOT NULL DEFAULT false,
    alert_window_seconds integer NOT NULL DEFAULT 300,
    alert_failure_threshold integer NOT NULL DEFAULT 5,
    status smallint NOT NULL DEFAULT 1,
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_audit_retention_policy UNIQUE (organization_id),
    CONSTRAINT ck_sand_iam_audit_retention_range CHECK (archive_after_days BETWEEN 1 AND 3650 AND retention_days BETWEEN archive_after_days AND 3650),
    CONSTRAINT ck_sand_iam_audit_alert_window CHECK (alert_window_seconds BETWEEN 60 AND 86400),
    CONSTRAINT ck_sand_iam_audit_alert_threshold CHECK (alert_failure_threshold BETWEEN 2 AND 10000),
    CONSTRAINT ck_sand_iam_audit_retention_status CHECK (status IN (1, 2))
);

CREATE TABLE IF NOT EXISTS sand_iam_audit_archive (
    id bigserial PRIMARY KEY,
    original_audit_id bigint NOT NULL,
    actor_type varchar(32) NOT NULL,
    actor_ref varchar(128) NOT NULL,
    organization_id bigint NULL,
    application_id bigint NULL,
    action varchar(96) NOT NULL,
    resource_type varchar(64) NOT NULL,
    resource_id bigint NULL,
    outcome varchar(32) NOT NULL,
    request_id varchar(96) NOT NULL,
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    original_create_time timestamp(0) without time zone NOT NULL,
    archive_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_audit_archive_original UNIQUE (original_audit_id)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_audit_archive_org_time ON sand_iam_audit_archive(organization_id, original_create_time DESC);
CREATE INDEX IF NOT EXISTS idx_sand_iam_audit_archive_request ON sand_iam_audit_archive(request_id);

CREATE TABLE IF NOT EXISTS sand_iam_security_alert (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL REFERENCES sand_iam_organization(id) ON DELETE CASCADE,
    application_id bigint NULL REFERENCES sand_iam_application(id) ON DELETE CASCADE,
    rule_code varchar(64) NOT NULL,
    severity varchar(16) NOT NULL,
    fingerprint char(64) NOT NULL,
    occurrence_count integer NOT NULL DEFAULT 1,
    first_seen_time timestamp(0) without time zone NOT NULL,
    last_seen_time timestamp(0) without time zone NOT NULL,
    status varchar(16) NOT NULL DEFAULT 'open',
    resolved_by bigint NULL,
    resolved_time timestamp(0) without time zone NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_sand_iam_security_alert_severity CHECK (severity IN ('low', 'medium', 'high', 'critical')),
    CONSTRAINT ck_sand_iam_security_alert_status CHECK (status IN ('open', 'acknowledged', 'resolved'))
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_security_alert_scope ON sand_iam_security_alert(organization_id, application_id, status, last_seen_time DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_security_alert_open ON sand_iam_security_alert(organization_id, fingerprint) WHERE status = 'open';
