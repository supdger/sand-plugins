BEGIN;

CREATE TABLE IF NOT EXISTS sand_iam_radius_nas (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id),
    name varchar(128) NOT NULL,
    source_cidr cidr NOT NULL,
    encrypted_shared_secret text NULL,
    secret_version varchar(32) NULL,
    accounting_enabled boolean NOT NULL DEFAULT false,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_radius_nas_source UNIQUE (source_cidr)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_radius_nas_app_status
    ON sand_iam_radius_nas (application_id, status);

CREATE TABLE IF NOT EXISTS sand_iam_radius_replay (
    id bigserial PRIMARY KEY,
    radius_nas_id bigint NOT NULL REFERENCES sand_iam_radius_nas(id) ON DELETE CASCADE,
    request_fingerprint char(64) NOT NULL,
    expire_time timestamp without time zone NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_radius_replay UNIQUE (radius_nas_id, request_fingerprint)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_radius_replay_expire
    ON sand_iam_radius_replay (expire_time);

CREATE TABLE IF NOT EXISTS sand_iam_radius_accounting_session (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id),
    radius_nas_id bigint NOT NULL REFERENCES sand_iam_radius_nas(id),
    session_reference char(64) NOT NULL,
    user_reference char(64) NULL,
    state varchar(16) NOT NULL CHECK (state IN ('active', 'stopped')),
    start_time timestamp without time zone NOT NULL,
    last_event_time timestamp without time zone NOT NULL,
    stop_time timestamp without time zone NULL,
    session_seconds bigint NOT NULL DEFAULT 0 CHECK (session_seconds >= 0),
    input_octets bigint NOT NULL DEFAULT 0 CHECK (input_octets >= 0),
    output_octets bigint NOT NULL DEFAULT 0 CHECK (output_octets >= 0),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_radius_accounting_active
    ON sand_iam_radius_accounting_session (radius_nas_id, session_reference)
    WHERE state = 'active';
CREATE INDEX IF NOT EXISTS idx_sand_iam_radius_accounting_app_time
    ON sand_iam_radius_accounting_session (application_id, last_event_time DESC);

CREATE TABLE IF NOT EXISTS sand_iam_radius_accounting_event (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id),
    radius_nas_id bigint NOT NULL REFERENCES sand_iam_radius_nas(id),
    accounting_session_id bigint NULL REFERENCES sand_iam_radius_accounting_session(id),
    session_reference char(64) NOT NULL,
    user_reference char(64) NULL,
    request_fingerprint char(64) NOT NULL,
    event_type varchar(16) NOT NULL CHECK (event_type IN ('start', 'interim', 'stop')),
    outcome varchar(16) NOT NULL CHECK (outcome IN ('applied', 'orphaned', 'conflict')),
    event_time timestamp without time zone NOT NULL,
    session_seconds bigint NOT NULL DEFAULT 0 CHECK (session_seconds >= 0),
    input_octets bigint NOT NULL DEFAULT 0 CHECK (input_octets >= 0),
    output_octets bigint NOT NULL DEFAULT 0 CHECK (output_octets >= 0),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_radius_accounting_event UNIQUE (radius_nas_id, request_fingerprint)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_radius_accounting_event_time
    ON sand_iam_radius_accounting_event (application_id, event_time DESC);

COMMIT;
