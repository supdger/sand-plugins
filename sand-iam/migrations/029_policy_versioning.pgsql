-- Immutable published policy snapshots. Runtime uses published_version_id only.
CREATE TABLE IF NOT EXISTS sand_iam_policy_version (
    id bigserial PRIMARY KEY,
    policy_id bigint NOT NULL REFERENCES sand_iam_policy(id) ON DELETE CASCADE,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE CASCADE,
    version_no integer NOT NULL,
    snapshot jsonb NOT NULL,
    snapshot_hash varchar(64) NOT NULL,
    rollback_of_version_id bigint NULL REFERENCES sand_iam_policy_version(id) ON DELETE RESTRICT,
    operation varchar(16) NOT NULL DEFAULT 'publish',
    request_id varchar(96) NOT NULL,
    request_fingerprint varchar(64) NOT NULL DEFAULT '',
    create_time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_policy_version_no UNIQUE (policy_id, version_no),
    CONSTRAINT uk_sand_iam_policy_version_request UNIQUE (policy_id, request_id)
);
ALTER TABLE sand_iam_policy ADD COLUMN IF NOT EXISTS published_version_id bigint NULL;
ALTER TABLE sand_iam_policy_version ADD COLUMN IF NOT EXISTS operation varchar(16) NOT NULL DEFAULT 'publish';
ALTER TABLE sand_iam_policy_version ADD COLUMN IF NOT EXISTS request_fingerprint varchar(64) NOT NULL DEFAULT '';
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_sand_iam_policy_published_version') THEN ALTER TABLE sand_iam_policy ADD CONSTRAINT fk_sand_iam_policy_published_version FOREIGN KEY (published_version_id) REFERENCES sand_iam_policy_version(id) ON DELETE RESTRICT; END IF; END $$;
CREATE INDEX IF NOT EXISTS idx_sand_iam_policy_version_application ON sand_iam_policy_version(application_id, policy_id, version_no DESC);
CREATE INDEX IF NOT EXISTS idx_sand_iam_policy_published_version ON sand_iam_policy(published_version_id);
