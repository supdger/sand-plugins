-- Align database protocol constraints with the supported federation services.
BEGIN;

ALTER TABLE sand_iam_identity_provider
    DROP CONSTRAINT IF EXISTS ck_sand_iam_identity_provider_type;
ALTER TABLE sand_iam_identity_provider
    ADD CONSTRAINT ck_sand_iam_identity_provider_type
    CHECK (provider_type IN ('local', 'oidc', 'oauth2', 'saml', 'ldap', 'scim', 'kerberos'));

ALTER TABLE sand_iam_federation_transaction
    DROP CONSTRAINT IF EXISTS sand_iam_federation_transaction_protocol_check;
ALTER TABLE sand_iam_federation_transaction
    DROP CONSTRAINT IF EXISTS ck_sand_iam_federation_transaction_protocol;
ALTER TABLE sand_iam_federation_transaction
    ADD CONSTRAINT ck_sand_iam_federation_transaction_protocol
    CHECK (protocol IN ('oidc', 'oauth2', 'saml'));

COMMIT;
