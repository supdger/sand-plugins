# SandIAM initialization draft API v0.1

`initialization/index` and `initialization/read` remain immutable applied-run
responses. Drafts use the explicit `draft-index` and `draft-read` routes; this
prevents an existing run-history client from receiving a different shape.

All routes require the matching `sand_iam:initialization:*` permission and the
same organization/application delegation as the package. Requests are sent to
`/app/sand-iam/admin/initialization/*` and use `X-Request-Id`.
The three new permissions are cataloged only: administrators must grant them
explicitly; the migration never inherits them to existing roles.

| Route | Permission | Input | Success response |
| --- | --- | --- | --- |
| `GET draft-index` | `index` | current run-list filters | list rows without `manifest` |
| `GET draft-read?id=` | `read` | draft id | same-scope secret-free draft, including `manifest` |
| `POST save` | `save` | `{manifest}` | `{draft_id, revision: 1, status: 1, manifest_hash}` |
| `POST update` | `update` | `{id, revision, manifest}` | `{draft_id, revision, status: 1, manifest_hash}` |
| `POST disable` | `disable` | `{id, revision}` | `{draft_id, revision, status: 2, manifest_hash}` |

`manifest` is normalized through `InitializationPackage::normalize`; passwords,
credentials, tokens, private keys, and external identity-provider configuration
are rejected before persistence. `package_code` and organization are immutable
after save. A first update may bind a previously unbound draft to the application
created by its package, but may not move a bound draft to another application.

Each mutation is idempotent by administrator, operation, `X-Request-Id`, and a
fingerprint containing the target/revision/hash. `update` and `disable` require
the current positive revision. A mismatch returns
`SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE` with HTTP 409 and makes no draft,
revision, or audit mutation. Disabled drafts cannot be updated.

Stable errors include `SAND_IAM_INITIALIZATION_DRAFT_NOT_FOUND` (404),
`SAND_IAM_INITIALIZATION_DRAFT_CONFLICT` (409),
`SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID` (400),
`SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE` (409),
`SAND_IAM_INITIALIZATION_DRAFT_DISABLED` (409), and the existing
`SAND_IAM_INITIALIZATION_SENSITIVE_FIELD` / provider validation errors. Each
successful action writes `initialization_draft.create`, `.update`, or `.disable`;
an applied configuration still uses only `initialization.apply` and
`initialization.rollback`.
