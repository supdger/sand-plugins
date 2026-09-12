# HOST-202609-001: SandPackage failed-upgrade recovery needs a DB-committed continuation

- Type: `HOST`
- Status: `local draft / not sent`
- Scope: neutral SandPackage lifecycle contract. SandIAM is only the frozen demo consumer; this request does not assert a second plugin reproduction.
- Frozen consumer host: `sandadmin-demo-host`, lock revision `558d92959947230ee562f29e015c62566be58c8e`.
- Authorization: this evidence did not write a host, database, registry, service, lock file, or deployment. It does not authorize any of those actions, sync, commit, push, or external issue submission.

## Current fact versus historical artifacts

The active demo registry is currently healthy: `server/runtime/sandpackage/sand-iam/info.ini` reports `state=1`, `stage=completed`, version `0.7.0`. Its `last_stable_state=1` agrees. Historical `state=8`, `stage=failed`, `failed_stage=database_update` records remain only under `server/runtime/sandpackage/quarantine/sand-iam/`; they are not the current runtime state and must not be presented as one.

## Minimal neutral, non-DB evidence

Run from this repository:

```sh
php docs/host-requests/fixtures/HOST-202609-001/reproduce.php
```

The fixture loads the frozen demo's real `InstallLogic`, `FailedUpgradeRecoveryInspector`, `FailedUpgradePackageIdentity`, and `FailedUpgradeRecoveryFileTransaction`. It supplies only a local `Server::getIni()` substitute and a unique `sys_get_temp_dir()` tree. It never calls an SQL executor, `Db::connect`, controller, upload extraction, deployment, service registration, or restart. The fixture removes its temporary tree in `finally`.

Its executable evidence is deliberately split:

| Case | What the fixture proves | Limit |
| --- | --- | --- |
| A | The real `InstallLogic::assertNotFailedUpgradeRecovery()` gate exists, can be called, and does not reject a temporary `state=1, stage=completed` INI shape. | This is not an install/registry-runtime result. |
| B | The same real gate rejects a simulated `state=8/failed/database_update` shape three times. Static-rule assertions establish only that normal `upload`, `uploadFromPath`, `install`, `uninstall`, `registerExisting`, and `discardCandidate` contain a call to that gate. Real v2 inspector/identity classes reject a partial identity and parse a canonical descriptor. | The harness does not establish public-entry call order or execute upload/install/uninstall. |
| C | Structure-only: the frozen `retryFailedUpgrade()` source contains SQL, file deploy and service-registration calls; its compensation method contains no SQL executor call; no public `resumeAfterDb` method is present. | No PostgreSQL commit/deploy fault is executed. This is not proof that SQL may be skipped or that any recovery is safe. |
| D | The real file-transaction class is fault-injected, then resumed and called once more in a temporary directory. A temporary unrelated-plugin registry/file/service canary has the same snapshot after each of those three operations. Static-rule assertions establish only that listed public recovery methods contain an operation-lock call. | The canary is a local file substitute, not a real plugin/registry/service. Multi-process locking is not dynamically exercised and no concurrency-safety conclusion is claimed. |

## Reproduction result

The evidence command records only its own PASS/FAIL result. It must be rerun after a SandPackage change; it is not a substitute for PostgreSQL isolation acceptance or demo recovery.

## Requested host contract

Keep failed `database_update` fail-closed for ordinary install/upload/withdraw/uninstall. Add a durable, app-locked continuation state that distinguishes `db_retry_pending` from `db_committed_deploy_failed` and binds candidate archive, payload, descriptor, update SQL, backup, runtime manifest, registry and DB fingerprint.

The lifecycle must create a trustworthy commit receipt inside the PostgreSQL transaction or at an explicitly bounded, immediately adjacent durable commit boundary. It must bind request ID, app/version lineage, candidate/update digest, transaction/commit fingerprint and next deploy state. A successful `COMMIT` whose receipt was not durably persisted, or whose confirmation was lost, is `unknown`: it must neither rerun SQL nor jump directly to deploy. The only permitted resolution is a read-only DB fingerprint check, an explicitly authorized deterministic reconcile, or manual review. `resume-after-db` may skip SQL only for a receipt-backed `db_committed_deploy_failed` instance with every identity still matching; it then continues deployment/registration/finalization.

Unknown journals, identity drift, failed audit, or any state outside this exact shape must block for manual review rather than repeat SQL or infer rollback.

## SandAdmin-side acceptance still required

The neutral fixture is not this acceptance. SandAdmin must run isolated real PostgreSQL and multi-process tests, with a second already-installed plugin used as a real DB sentinel. Before and after every row, compare the target plugin and the sentinel plugin's DB rows/ledger, registry, deployed files, service directory and business smoke result.

| Scenario | Required observation |
| --- | --- |
| Success | One SQL execution, durable commit receipt, deploy/register/finalize converge; sentinel remains unchanged. |
| SQL failure before commit | No commit receipt or deploy; retry eligibility is explicit and no sentinel drift. |
| Commit then file/service failure | Committed ledger remains; receipt-backed `resume-after-db` does not run `update.sql` again; convergence and sentinel comparison pass. |
| COMMIT succeeds but receipt persistence/confirmation is lost | State is `unknown`; no automatic SQL retry or deploy skip; only read-only fingerprint, authorized deterministic reconcile, or manual review is available. |
| Duplicate request ID | Returns the same durable outcome and does not duplicate SQL, deployment, service registration or audit. |
| Concurrent UI/CLI workers | Actual operation lock preserves one durable outcome; losing worker does not mutate target or sentinel. |
