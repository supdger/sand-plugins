BEGIN;

CREATE TABLE IF NOT EXISTS sand_iam_cas_service (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id),
    name varchar(128) NOT NULL,
    service_url varchar(512) NOT NULL,
    released_attributes jsonb NOT NULL DEFAULT '[]'::jsonb,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_cas_service_url UNIQUE (service_url)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_cas_service_app_status
    ON sand_iam_cas_service (application_id, status);

CREATE TABLE IF NOT EXISTS sand_iam_cas_login_request (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id),
    cas_service_id bigint NOT NULL REFERENCES sand_iam_cas_service(id),
    request_hash char(64) NOT NULL,
    expire_time timestamp without time zone NOT NULL,
    consumed_time timestamp without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_cas_login_request_hash UNIQUE (request_hash)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_cas_login_request_expire
    ON sand_iam_cas_login_request (status, expire_time);

CREATE TABLE IF NOT EXISTS sand_iam_cas_ticket (
    id bigserial PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id),
    cas_service_id bigint NOT NULL REFERENCES sand_iam_cas_service(id),
    identity_id bigint NOT NULL REFERENCES sand_iam_identity(id),
    ticket_hash char(64) NOT NULL,
    expire_time timestamp without time zone NOT NULL,
    consumed_time timestamp without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_cas_ticket_hash UNIQUE (ticket_hash)
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_cas_ticket_expire
    ON sand_iam_cas_ticket (status, expire_time);
CREATE INDEX IF NOT EXISTS idx_sand_iam_cas_ticket_identity
    ON sand_iam_cas_ticket (application_id, identity_id, create_time DESC);

COMMIT;
