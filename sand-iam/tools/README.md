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
