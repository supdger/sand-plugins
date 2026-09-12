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

这个模式输出 `release/unsigned`，并在 artifact manifest v6 中同时绑定当前 Git commit 与
`HEAD:sand-iam` tree object；缺少任一摘要的清单不能签名，也不能生成外部验收模板。它仍不是签名发布物。
私钥必须保留在 SandIAM 包根和 artifact 目录之外，且权限不得向 group/other 开放。独立审核者
确认来源和验收记录后，使用包外私钥生成证明：

```bash
php sand-iam/tools/sign-release-bundle.php \
  --artifact-manifest=/controlled/candidate/manifest.json \
  --archive=/controlled/candidate/sand-iam-0.7.0-release-unsigned.zip \
  --private-key=/controlled/keys/sand-iam-ed25519.key \
  --output=/controlled/candidate/sand-iam.release-attestation.json \
  --source=git \
  --reference=<review-record> \
  --approved-by=<reviewer> \
  --approved-at=<UTC-ISO-8601>
```

签名者和使用者都应再运行 `verify-release-bundle.php`，核对签名、manifest、ZIP 整体摘要、
逐条目摘要、必需许可材料和测试载荷排除。验证器及其公共 helper 应来自可信源码 revision，
不能从尚未验证的 ZIP 中取出后反向验证同一个 ZIP。面向使用者的步骤见
`docs/user-guide/release-package-verification.md`。

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
plugin runtime includes its checked-in `vendor` tree, while node dependency
caches do not enter the package. The sole path-scoped exception is
`sdk/typescript/dist/**`: it is the locked-toolchain-generated ESM SDK runtime
and its declarations. No other `dist` directory may enter a candidate.

After every other payload file is materialized, the builder calculates the
descriptor-excluded canonical payload digest, writes byte-identical root and
plugin failed-upgrade descriptors, verifies ZIP contents and source-snapshot
parity, then rebuilds from the same snapshot. ZIP entry ordering, mtimes and
permissions are normalized; a byte-identical second ZIP is required.

Each artifact contains `manifest.json`, `source-snapshot.json`, provenance,
`SHA256SUMS`, the exact `REBUILD_COMMAND.txt`, and its reproducibility rebuild.
To independently rebuild an existing frozen snapshot without reading the
current worktree:

```bash
php sand-iam/tools/build-review-candidate.php \
  --rebuild-from=/absolute/artifact/snapshot/package \
  --output=/absolute/new-rebuild-directory
```

The two generated recovery descriptors always retain the frozen SandIAM
`0.6.0 -> 0.7.0` inline v2 profile for the exact `prefix_033_034` state and
the staged `update.sql` SHA-256. The profile is declaration-only and limited
to the host's fixed assertion vocabulary. The descriptors bind the archive
payload, never an authority-tree digest, so there is no self-hash cycle.

## External release acceptance

The final-candidate gates remain separate from package construction:

- `prepare-external-acceptance.php` creates candidate-bound endurance, Casdoor, protocol-interoperability, backup-recovery, or independent-delivery
  templates outside the package. Required operator fields are deliberately invalid
  until completed, so a freshly generated template cannot pass by accident.
- `validate-casdoor-comparison.php` validates the external 3-journey ×
  2-product × 2-round record and its per-run evidence hashes. It never operates
  either product.
- `validate-protocol-interop.php` validates all seven declared protocol families
  against versioned standard clients and real controlled counterparts, including
  protocol-specific positive, negative, replay/revocation, cleanup, independent-review,
  and evidence-hash requirements. It never connects to a protocol endpoint.
- `validate-backup-recovery.php` validates an isolated PostgreSQL custom-format
  restore record, distinct source/target fingerprints, safe restore flags, exact
  logical-state and audit-chain parity, allow/deny/revocation checks, host/plugin
  non-regression, cleanup, and hashed evidence. It never invokes PostgreSQL.
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
