# SandIAM tooling

## Local review candidate

`build-review-candidate.php` is the only local packaging entrypoint for a
SandIAM review candidate. It creates a new, numbered directory under the
workspace `.artifacts/` directory; it never overwrites an existing artifact.
It is source-only: it does not access a database, service, host, browser, or
network, and it does not upload, register, synchronize, install, deploy, or
claim a release.

```bash
php sand-iam/tools/build-review-candidate.php
```

许可证确认并写入 `LICENSE`、`sand-iam/` 已提交且子树干净、SBOM、发布载荷卫生和包完整性
全部通过后，发布负责人才能生成待独立审核的无签名候选：

```bash
php sand-iam/tools/build-review-candidate.php --release-unsigned
```

这个模式输出 `release/unsigned`，并在 artifact manifest v8 中同时绑定当前 Git commit、
`HEAD:sand-iam` tree object 与[构建契约](../release-build-contract.json)。正式模式只从指定的
已提交 commit 的 Git blob materialize 两套独立 source stage；它不读取工作区的 `vendor`、
`dist` 或其他 ignored payload。两套 stage、snapshot、ZIP 条目和 ZIP 摘要都必须一致；任何
未跟踪注入、缺失、篡改或 stage/blob 差异都会拒绝。缺少任一摘要或 Git-blob 证明的清单不能签名，
也不能生成外部验收模板。它仍不是签名发布物。
私钥必须保留在 SandIAM 包根和 artifact 目录之外，且权限不得向 group/other 开放。独立审核者
确认来源和验收记录后，使用包外私钥生成证明：

```bash
php sand-iam/tools/sign-release-bundle.php \
  --artifact-manifest=/controlled/candidate/manifest.json \
  --archive=/controlled/candidate/sand-iam-0.7.2-release-unsigned.zip \
  --private-key=/controlled/keys/sand-iam-ed25519.key \
  --output=/controlled/candidate/sand-iam.release-attestation.json \
  --source=git \
  --reference=<review-record> \
  --approved-by=<reviewer> \
  --approved-at=<UTC-ISO-8601>
```

签名者和使用者都应再运行 `verify-release-bundle.php`，核对签名、manifest、ZIP 整体摘要、
逐条目摘要、必需许可材料和测试载荷排除。`verify-release-bundle.php`、
`release-bundle-attestation.php` 与 `package-payload-policy.php` 是三份独立可信输入：必须从受信
源码 revision、正式发布页或受控工具库分别取得，并核对该可信来源公布的 revision/摘要；不能从
尚未验证的 ZIP 中提取其中任一文件后反向验证同一个 ZIP。面向使用者的步骤见
`docs/user-guide/release-package-verification.md`。

签名与验证共享 ZIP 路径门禁：只接受严格的相对规范路径，拒绝反斜杠、绝对或盘符前缀、控制字符、
空/`.`/`..` 段、重复规范名以及文件/目录前缀冲突；它还实际检查 ZIP 和 manifest 均未带入四个历史
failed-upgrade descriptor/sidecar。签名输出只会在既有真实目录中以同目录、`0600` 临时文件完整写入、
flush/fsync 后以 no-replace 原子发布；已有常规文件或链接一律保持不变。

若发布在 `link`、权限或目录 fsync 后失败，工具只会按已记录的 dev/inode 删除自己的 final/temp
路径，并尽力再次 fsync 目录；它绝不删除同名的他人 inode。任何非零退出都不是可用发布物：若报
`cannot safely clean failed attestation publication`，目录持久化状态不能证明，必须隔离该目录、以可信
三文件重新验证并重新生成候选，不能继续使用该输出名。

The builder first copies the complete selected source payload into
`snapshot/package` with regular-file checks and normalized permissions and
timestamps. The archive is built only from that immutable snapshot. Its payload
contains the complete plugin backend and lifecycle mirror, root lifecycle and
metadata, SandIAM management UI source, public account-portal source, SDKs,
examples/external protocol material, user and release documentation, and the
required public files. Its single payload policy intentionally excludes
development docs/task boards, the builder/checker and other development tools.
It excludes `.git`, `.env`/secret-like files,
`node_modules`, generated `dist`/`build`/coverage/cache output, artifact trees,
test source/result output, OS junk, logs, private keys, and dumps. The full
plugin runtime includes its checked-in, lock/toolchain-verified `vendor` tree,
while node dependency caches do not enter the package. The sole path-scoped exception is
`sdk/typescript/dist/**`: it is the locked-toolchain-generated ESM SDK runtime
and its declarations. No other `dist` directory may enter a candidate. The exact
Composer, Node, pnpm, TypeScript and ZIP toolchain values, offline installation
arguments, lock digests and generated-tree digests are frozen in
`release-build-contract.json`; updating a dependency requires an independent
two-directory rebuild before changing that contract or the reviewed runtime files.

The builder excludes the historical root/plugin failed-upgrade descriptors from
the normal 0.7.2 payload, verifies ZIP contents and source-snapshot parity,
then rebuilds from the same snapshot. ZIP entry ordering, mtimes and permissions
are normalized; a byte-identical second ZIP is required.

Each artifact contains `manifest.json`, `source-snapshot.json`, provenance,
`SHA256SUMS`, the exact `REBUILD_COMMAND.txt`, and its reproducibility rebuild.
To independently rebuild an existing frozen snapshot without reading the
current worktree:

```bash
php sand-iam/tools/build-review-candidate.php \
  --rebuild-from=/absolute/artifact/snapshot/package \
  --output=/absolute/new-rebuild-directory
```

The retained recovery descriptors are historical 0.7.0 evidence for the
`0.6.0 -> 0.7.0` `ledger_absent` recovery profile. They are not generated or
included in normal 0.7.2 candidates, and do not authorize a 0.6.0 direct upgrade.

## External release acceptance

The final-candidate gates remain separate from package construction:

- `prepare-external-acceptance.php` creates candidate-bound endurance, Casdoor, protocol-interoperability, backup-recovery, or independent-delivery
  templates outside the package. Recovery generation requires separate `--output`
  (v3 report) and `--plan-output` (v2 frozen plan); the report is bound to the
  generated plan digest. Required operator fields are deliberately invalid until
  completed, so a freshly generated report/plan pair cannot pass by accident.
- `validate-casdoor-comparison.php` validates the external 3-journey ×
  2-product × 2-round record. Every v2 run has one structured document plus
  separately hashed browser, product-system and cleanup artifacts. The
  structured document binds the candidate, environment, journey, product,
  round, timestamps and all measured counters; it also requires globally unique
  request IDs, business-effect references, audit references and zero residuals.
  It never operates either product.
- `validate-protocol-interop.php` validates all seven declared protocol families
  against versioned standard clients and real controlled counterparts, including
  protocol-specific positive, negative, replay/revocation, cleanup, independent-review,
  and evidence-hash requirements. The v2 report requires one structured evidence
  document plus separately hashed client, SandIAM, counterpart and cleanup
  artifacts for every protocol. The structured document is cross-bound to the
  candidate, environment, client, counterpart and timestamps; every assertion
  has a unique request ID and must reference all three execution sides, while
  cleanup must reference a zero-residual artifact. It never connects to a
  protocol endpoint.
- `validate-backup-recovery.php` validates a v3 isolated PostgreSQL
  backup/recovery evidence record and separately supplied v2 recovery plan. It
  requires `--report`, `--plan`, `--archive` and `--artifact-manifest`,
  independently opens the candidate ZIP/file map and provenance, rejects v1/v2
  reports, recomputes eight structured evidence payload hashes, candidate/run/
  environment/collector/plan binding, typed ownership and entity parity,
  11-stage timeline/LSN/RPO/RTO, revoke/rotation reconciliation, queue
  idempotency, dual audit refs and encryption custody metadata. It never invokes
  PostgreSQL, performs a restore, or accesses a KMS. A pass always reports
  `real_g=false`: it is not release-G evidence.
- `validate-independent-delivery.php` validates that a new, non-contributing
  participant used only the packaged public documentation to verify the package,
  install and configure it, integrate one human application and one machine service,
  recover from an error, and uninstall cleanly without developer assistance.
- `run-endurance-acceptance.php` validates and, after explicit runtime
  authorization, runs candidate/health/allow/deny/revoked/audit/metrics probes
  for at least 24 hours.
- `verify-endurance-evidence.php` independently recomputes the JSONL hash chain,
  duration, continuity, latency, resource and security thresholds.

See `docs/development/sand-iam-developer-journey-acceptance.md` and
`docs/development/sand-iam-endurance-acceptance.md`. A valid plan or passing
tool test is acceptance infrastructure only; it is not a Casdoor comparison or
endurance result.

## L01 delegated notification configuration

`admin-notification-live-acceptance.php` closes the notification portion of the
L01 administrator configuration loop. It uses three distinct delegated
SandAdmin accounts plus the platform administrator to create, encrypt,
application-mount, inspect, deny out-of-scope access to, unmount and disable a
notification provider. It then revokes both grants, proves the same delegated
accounts are denied, checks exact audit attribution, disables the application
and organization, and removes only the captured child records.

The tool accepts only the existing `sandadmin` PostgreSQL database, requires a
`sand_iam_acceptance_<16 hex>_` prefix and the explicit
`I_CONFIRM_L01_EXISTING_DATABASE_FIXTURES_AND_CLEANUP` confirmation. It does not
create a database, start Webman, create SandAdmin users or roles, or disclose
the generated one-time provider token in its report. Run it only through the
authorized demo-host wrapper that supplies the four bearer headers, three
administrator IDs, existing PostgreSQL DSN and cleanup authorization.

## Consumer acceptance runner v1

`run-consumer-acceptance.php` has a strict offline `validate` mode for the
fixed `consumer_l04` plan. The plan must be an absolute, existing local regular
file with no symlink component; schemes/wrappers, directories, relative paths
and links are rejected before JSON parsing. It validates candidate, host and
consumer-tree hashes plus the exact `authorization_gate`,
`fixture_ownership_gate` and `cleanup_gate` receipts. Every receipt binds the
run ID, all three hashes, fixture-scope hash and cleanup-manifest hash.

```bash
php sand-iam/tools/run-consumer-acceptance.php \
  --mode=validate \
  --plan=/controlled/consumer-plan.json \
  --report=/controlled/consumer-report.json
```

The public shape is in `schemas/consumer-acceptance-{plan,report}.schema.json`;
the example plan is deliberately non-live. Origins are lower-case HTTPS, or
explicit `http://127.0.0.1` only; credentials cannot cross-bind origins.
`validate` never accepts a secrets file and never initializes a network or
PostgreSQL client. `live` requires an operator-owned `0600`, non-symlink secret
file outside the SandIAM source tree, but v1 has no live adapter: it exits
`unsupported`, makes no business call, does not perform cleanup, and always
reports `real_l04=false`.

## Consumer acceptance v2 offline authorization

`consumer-acceptance-v2/` is the first live-v2 contract layer only. It has no
HTTP or database adapter. It freezes the query/mutation registry and verifies
three externally supplied Ed25519 receipts using one separately supplied,
trusted public-key file; neither plans nor receipts contain a public key or a
secret value. Missing `consumer_l04_cleanup_v1` returns `preflight_blocked`
with `before_first_write=true`; every v2 report remains `real_l04=false`.
