# Provider B controlled workload example

This is an isolated lightweight PHP provider and caller, not a SandIAM plugin.
It exposes `GET /health` and `POST /provider/v1/documents/{id}/process`.
The caller uses the public local-path PHP SDK to issue a short-lived context and
passes it only in `X-Sand-Iam-Context`; the provider uses the same SDK's real
`verifyContext()` on every request, including an idempotency replay.

The service, audience, and action are fixed to `provider-b-document`,
`provider-b`, and `document.process`. The request cannot override them. After
verification, PostgreSQL loads the existing `provider_b_document` under lock
and requires its `organization_id` to match the verified claims before it
persists the document SHA-256 and byte count.

## Controlled setup

1. With explicit database-owner authorization, apply `schema.pgsql` in an
   isolated PostgreSQL consumer database and insert an existing document. It
   creates no database, accounts, `sand_iam_*`, or `sa_*` tables. The example
   does not include schema execution or database-connectivity acceptance;
   complete that acceptance in an isolated real environment before release.
2. Run `composer install` in this directory. `composer.json` resolves
   `sand/iam-sdk` only from `../../../sdk/php`.
3. Inject the empty keys shown in `.env.example` through deployment secret
   management. The provider refuses missing configuration or every non-`pgsql:`
   DSN. Do not commit an actual `.env`.
4. Deploy `public/index.php` with an internal PHP process manager. Complete
   service-start acceptance in an isolated real environment before release. The caller runs as
   `php caller/invoke.php <document-id>` with a stable
   `PROVIDER_B_IDEMPOTENCY_KEY`.

## Required real acceptance (not run)

- **Allow:** grant the fixed service/audience/action, process a document in the
  caller's organization, and retain a redacted request/context audit record.
- **Deny and wrong audience:** an ungranted action, wrong `provider-b`
  audience, invalid context, network/protocol error, missing document, or
  cross-organization document must fail closed and create no processing effect.
- **Revocation:** revoke the credential or grant, then retry a previously
  issued context and idempotency key. Verification happens before the replay
  lookup, so an old context cannot bypass revocation.
- **Idempotency:** `(workload_client_id,idempotency_key)` is unique. The same
  document hash and bytes return the original result; a changed document or
  document ID with that key returns 409 and creates no new process row.
- **Audit and cleanup:** `provider_b_audit_log` stores request ID, context ID,
  IDs, result, replay marker, and only a SHA-256 of the idempotency key. It
  never stores a context, credential, body, or password. Remove isolated
  fixtures and test rows after acceptance.
  Route-matched 400/401/403/409/503 failures write one independent, redacted
  provider audit (context and idempotency hashes only); its own write failure
  never permits processing or changes the original denial. Correlate this
  provider record with SandIAM's issue/verify audit by request and context
  evidence; runner logs are not audit evidence.

## Offline checks only

`composer test` is a pure offline unit test for the PostgreSQL configuration
gate, replay/conflict behavior, and denied requests producing no persistence
call. It does not contact SandIAM, HTTP, PostgreSQL, revocation, or real audit,
and therefore is not real acceptance. A real service, database, and caller
integration remains required before release.
