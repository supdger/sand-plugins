# SandIAM 对 SandPackage recovery v2 的回执

状态：`HOST_COMPATIBLE / DEMO_SYNC_6_1_4_STATIC_PASS / FORMAL_GATE_A_PASS / RUNTIME_RECOVERY_NOT_AUTHORIZED`。

SandIAM 权威源码已从 v1 profile 名称和 `retry_states` 迁移到候选内联 v2 profile：

- app/from/to：`sand-iam` / `0.6.0` / `0.7.0`
- state：`prefix_033_034`
- descriptor：`sandpackage.failed-upgrade-recovery/v2`
- profile：`sandpackage.failed-upgrade-recovery-profile/v2`
- 精确断言：101 项，包括 83 表全集、62 个列断言、34 个约束、2 个索引、
  034 三条菜单和迁移账本不存在；未使用 profile 词汇表以外的动作。
- 根与插件描述器字节一致，并绑定规范化候选载荷和根 `update.sql`。

13 个 CHECK 均使用同一 demo PostgreSQL 在 `READ ONLY`、`REPEATABLE READ`
事务中返回的 `pg_get_constraintdef(..., true)` 原文；没有用源码猜测或有损规范化替代。

新候选是 v24，只用于本地审阅；未覆盖或改写历史候选，未注册、同步、安装或部署。
包完整性 23/23、描述器行为 6/6、生命周期非 PG 合同通过；同一冻结快照二次构建
ZIP 字节一致。当前 SandPackage v2 parser 对描述器的静态解析为 1/1 通过。宿主随后
使用 probe/test identity，在 demo PostgreSQL 中以 `READ ONLY`、`REPEATABLE READ` 事务完成
read-only profile/catalog compatibility probe：101/101、`retry_safe/prefix_033_034`、
failed_ids 为空；fingerprint 仅属于该探针，事务最终 ROLLBACK，没有写数据库。正式
identity-bound Gate A 在 **SandPackage 6.1.0 历史状态**为 **`BLOCKED`**：活跃失败候选缺少完整 candidate
identity，真实 `FailedUpgradeIdentityBinding` 无法构建；不得以探针结果代替真实
恢复身份验收。

全量非 PG/合同测试文件通过 93/93；package 23/23、descriptor 6/6。

早期 17-file compatibility-probe 与白名单同步记录保留作历史；当前 host binding 以
[HOST_MANIFEST.json](HOST_MANIFEST.json) 的 6.1.3 三文件增量为准。旧同步不会替代
当前版本的 source/target 哈希、非数据库合同或 formal Gate A 状态。

运行恢复阶段未获明确放行，因此不执行数据库写入、恢复写操作、registry 修改、
候选 prepare/replace/retry、服务重载或业务闭环。

## 2026-09-08：真实失败身份阻断（追加）

宿主只读回传的 registry 状态为 SandIAM `0.7.0`、`state=8`、`stage=failed`、
`failed_stage=database_update`、from `0.6.0`。真实 backup identity 为
`sand-iam-package-20260901095541-f92a9870f695`，registration manifest digest 为
`2cd54b…82d5ba`。活跃失败候选缺少
`candidate_archive_sha256`、`candidate_payload_manifest_sha256`、
`recovery_descriptor_sha256`、`update_sql_sha256`，所以真实
`FailedUpgradeIdentityBinding` 无法构建。当前 update.sql digest
`2739deb7…46cab7` 与 v24 `aff48797…f8457` 不一致。

SandPackage `verify` 会写 `FailedUpgradeRecoveryAudit`，并在身份缺失时先拒绝；
本次未调用 verify，也未执行数据库核验或任何数据库/registry/恢复/服务写操作。
formal identity-bound Gate A 因此在 **6.1.0 历史状态**为 **`BLOCKED`**，而 compatibility probe 仍为
`PASS`（仅 probe/test identity、非真实数据库 Gate A）。

该 blocker 归属 SandAdmin/SandPackage：登记完整真实 v2 candidate identity；提供
不写审计的 read-only Gate A，或单独申请审计写授权。

## 2026-09-08：SandPackage 6.1.1 同步回执（继续追加）

插件候选、descriptor、payload、`update.sql` 及其清单均**未修改**。宿主已从
`0b12481ed816a1c97d9e00fb9cb98c129fb87d3e`（SandPackage 6.1.1，parent
`16c0602e385c42af2d00a23e34c52ce008ecc681`）将严格 7-file 白名单同步到 demo；
证据为 `.artifacts/sandpackage-611-demo-sync-20260908T132546Z/`。前像为 5 个存在、
2 个缺失路径；source/target SHA 为 7/7，白名单外 SandPackage 文件前后不变。当前状态
为 `HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_PENDING_AFTER_PREPARE /
RUNTIME_RECOVERY_NOT_AUTHORIZED`。

6.1.1 中性合同已覆盖 legacy bootstrap、私有 prepare、formal read-only Gate A
（`audit_written=false`）、replace reverify 与 CLI `gate-a`/`verify` 别名。但真实 demo
尚未执行 prepare；formal identity-bound Gate A 因此为 **`PENDING_AFTER_PREPARE`**，
不是 PASS。没有数据库、registry、运行文件、服务或浏览器动作。

## 2026-09-08：B3/C1 依赖闭合（继续追加）

插件 candidate payload、descriptor、payload manifest、`update.sql` 与其摘要均**未修改**。
宿主只在既有 7-file 基础上增量同步 B3
`failed-upgrade-recovery.index-mount.ts` 与 C1
`server/plugin/sandpackage/tests/lifecycle_non_db_contract_test.php`；完整 9-file SHA 为
9/9，sorted conventional SHA-256-lines digest 为
`b24a6c67ebee7afc541be1234271b4417138ebbcdaeb7fda3a18f5fb9a3403d9`，白名单外摘要不变。

清单分类为 5 个生产运行文件、3 个前端门禁辅助和 1 个后端非 DB 测试辅助。B3 闭合
behavior harness 的 mount import，C1 提供 `think\\facade\\Db` fake 与
`PostgresLifecycleSqlExecutor` require；demo `vue-tsc --noEmit` 和 lifecycle non-DB
contract 都通过。此前 2/7 PARTIAL 已清除；9-file 静态同步结论为
**`DEMO_SYNC_STATIC_GATE_PASS`**；formal Gate A 仍为
**`PENDING_AFTER_PREPARE`**，未执行任何 prepare、数据库、registry、恢复或服务动作。

## 2026-09-08：正式 prepare 尝试阻断（继续追加）

本轮已按授权在 demo host 执行 v24 private prepare。前置 inspector 为
`runtime_restore_required=false`、`runtime_drift=false`，故未恢复运行文件。archive
`931b3c…63ded`、descriptor `1d7bca…1e90`、payload 声明 `52e7c…25f1c`、update
`aff487…f8457` 均来自冻结 v24 ZIP；prepare 仍在写 replacement record 前以
`FAILED_UPGRADE_RECOVERY_BLOCKED：替换包描述摘要不匹配` 拒绝。

不一致发生在通用 host payload manifest：SandIAM build 以 root/plugin 两个 descriptor
均排除的 606 文件算法得到 `52e7c…25f1c`，而 SandPackage 6.1.1 仅排除 root descriptor。
没有 replacement id、私有 archive、Gate A、数据库查询、registry 变更、replace、retry、
服务或浏览器动作。候选 payload、README、descriptor 和清单均未改。详情见
`.artifacts/sandiam-formal-gate-a-20260908.md`。

## 2026-09-08：SandPackage 6.1.2 algorithm-boundary sync（继续追加）

SandIAM candidate payload、descriptor、payload manifest、`update.sql` 及其摘要均**未修改**。
宿主已从 `261e64d26063f48fd97ceb3eef9b73e4a39d7bac`（parent `0b12481`）只同步
`FailedUpgradePackageIdentity.php` 与 SandPackage `config/app.php` 两项生产文件；
SHA 为 2/2，delta digest 为
`74d8658aa859f231742a78e5821142c9e0c4d4f6f94cff3c8cb4d06af0a7fbe2`，白名单外摘要不变。

6.1.2 仅把 recovery descriptor/.sha256 的 payload exclusion 限定为 root 和 exact
`plugin/<root app>`；foreign/nested/bak 同名文件仍计入。它修正 6.1.1 historical prepare
digest mismatch 的算法边界，但本轮没有重新 prepare。demo lint、non-DB contract、
`vue-tsc --noEmit` 与 source neutral contract 均通过；当前为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_PENDING_AFTER_PREPARE /
RUNTIME_RECOVERY_NOT_AUTHORIZED`，formal Gate A 未执行且不是 PASS。

## 2026-09-08：6.1.2 prepare 重试仍被 identity contract 阻断

在 `inspect` 返回 `runtime_restore_required=false`、`runtime_drift=false` 后，本轮按授权使用原
v24 ZIP 执行一次 private prepare。结果仍是
`FAILED_UPGRADE_RECOVERY_BLOCKED：替换包描述摘要不匹配`，且失败发生在 retained archive、replacement
record、transaction 前；replacement-id 为 null，故没有 Gate A、PostgreSQL 连接或事务。

6.1.2 的 root/exact-plugin descriptor exclusion 已静态通过，但不等于 manifest 语义相同：host
对含 `size`、normalized `info.ini` 的裸 map 哈希，v24 对 `schema`/`algorithm`/`files` 包装的纯
SHA map 哈希。该剩余差异必须由 SandPackage 修复；SandIAM candidate、descriptor、README 和 payload
未修改，replace/retry 也未执行。当前状态改为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_BLOCKED_PREPARE_DIGEST_MISMATCH /
RUNTIME_RECOVERY_NOT_AUTHORIZED`。详见 `.artifacts/sandiam-formal-gate-a-20260908.md`。

## 2026-09-08：SandPackage 6.1.3 canonical payload algorithm 同步（继续追加）

SandIAM candidate payload、descriptor、payload manifest、`update.sql` 及其摘要均**未修改**。宿主从
`d29d6f390d89c6cbf3d3e1cb6ee77cde0ade0bde`（parent `3ca07f2`）仅增量同步
`FailedUpgradePackageIdentity.php`、`InstallLogic.php` 与 SandPackage `config/app.php` 三项生产文件；
前像为 3 个存在、0 个缺失，source/target SHA 为 3/3，按路径 C 排序的 conventional SHA-256-lines
digest 为 `c5d549a930c07f589aea5916836b938488298e1acd3a929a31ea07e4eeea01ff`，白名单外摘要不变。最终证据在
`.artifacts/sandpackage-613-final-d29d6f3f-20260908T144505Z/`；此前 3ca07f2 三文件前像和同步记录保留为
中间步骤，不是最终 host binding。当前同步门精确为 **`DEMO_SYNC_6_1_3_STATIC_PASS`**。

6.1.3 的 descriptor payload digest 是 `sandpackage-normalized-package-manifest/v1`：canonical
`schema`/`algorithm`/`files` wrapper 包裹原始候选字节的纯 SHA map，原始 `info.ini` 也受绑定；root
与 exact `plugin/<root app>` descriptor/.sha256 排除，foreign/nested/backup/bak 仍计入。最终补丁还强制
descriptor app 必须等于 root `info.ini` 的 app，拒绝 descriptor app 选择 foreign exclusion scope。
demo PHP lint 3/3、non-DB lifecycle、`vue-tsc --noEmit`、source neutral 38/38、recovery subphases
16/16、state-machine contract 和全部 SandPackage contract tests 7/7 都通过；未连接 demo PostgreSQL。

当前总状态统一为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_PENDING_AFTER_PREPARE /
RUNTIME_RECOVERY_NOT_AUTHORIZED`。6.1.2 的 `BLOCKED_PREPARE_DIGEST_MISMATCH` 仅是历史状态；6.1.3
没有执行 prepare，formal identity-bound Gate A 为 **`PENDING_AFTER_PREPARE`**，绝非 PASS。没有执行
数据库、registry、Gate A、replace、retry、运行恢复、服务或浏览器动作。

## 2026-09-08：6.1.3 prepare 成功，Gate A lazy-PDO 阻断

无 drift inspector 后，v24 private prepare 成功并生成 replacement id
`present/redacted`。私有 record/retained archive 已绑定真实 backup lineage、archive
`931b3c…63ded`、payload `52e7c…25f1c`、descriptor `1d7bca…1e90` 与 update `aff487…f8457`。
随后 Gate A 使用该 ID fail-closed：`FAILED_UPGRADE_RECOVERY_BLOCKED：无法建立只读恢复核验连接`。

这不是数据库/权限/driver/连接名问题：`pdo_pgsql` 已启用，native socket 以及同一 Webman bootstrap 的
named/default Think ORM 连接在 `connect()` 后均完成 `BEGIN READ ONLY; SELECT current_database(),
current_user, current_schema(); ROLLBACK`（`sandadmin`/`supdger`/`public`）。宿主
`revalidatePreparedFailedUpgradeRecovery()` 直接对未初始化的 `Db::connect('pgsql')` 调用 `getPdo()`；
Think ORM 返回 `false`，错误发生在 verifier、101 断言与事务前。最小下一步是 SandPackage 将该 named
connection 显式 `connect()` 后再取得 PDO，保留现有 read-only verifier。该 host 源码修改未获本轮授权。
该连接阻断属于 6.1.3 历史；当前 6.1.4 状态见下。没有 replace/retry、active registry mutation、

## 2026-09-08：SandPackage 6.1.4 PDO 初始化同步（继续追加）

宿主 `a99af5dea0f22071939a9010a958e90829c675a3`（parent `d29d6f3`）仅同步
`InstallLogic.php`、`PostgresLifecycleSqlExecutor.php`、`config/app.php` 三项生产文件。前像 3/3，
source/target SHA 3/3，C-sorted digest
`dbdec85c3558592f2af196a689a28fdaa89f9ce56e8e38ecfcf93e6f3de5f34f`，白名单外不变；当前同步门为
**`DEMO_SYNC_6_1_4_STATIC_PASS`**（lifecycle 10/10、lint 3/3、vue-tsc PASS、关键 SHA 5/5）。

6.1.4 显式初始化 named PostgreSQL connection 的 `connect()`，避免 recovery/Gate A 与 lifecycle
executor 误用未初始化 `getPdo()`。demo lint 3/3 与 PSR-4/class load PASS；source contracts 7/7、
neutral 38/38、recovery 17/17、lifecycle 4/4 PASS。demo non-DB lifecycle 的旧 fake 没有 `connect()`，
故在 installer fixture 的 `database_install` importer-count 断言 REJECT；这是 demo test helper 未同步，
不是数据库连接，且不扩大三文件白名单修补。

prepared replacement 仍存在但仅记录为 `present/redacted`。本轮没有 Gate A rerun；当前为
`FORMAL_GATE_A_PREPARED_REPLACEMENT_PENDING_RERUN`，不是 PASS，也没有数据库、registry、replace、retry、
运行恢复、服务或浏览器动作。SandIAM candidate payload 未改。

P2 历史风险：曾出现一次不可复现 TerminalRunner 聚合失败，harness 未保留 failure frames；独立复跑
静态门已通过，故不阻断当前 PASS。

## 2026-09-08：6.1.4 formal identity-bound Gate A PASS

现有 private replacement `8ecc5cec83f03f19ea609b220e8f9e9f` 只读核验通过：真实 backup lineage、archive、
payload `52e7c…25f1c`、descriptor 与 update 摘要仍完整。`gate-a` 返回 `retry_safe`、101/101、
`failed_assertion_ids=[]`、`audit_written=false` 和 fingerprint
`714894c6e1c285717a1f6fb6a75cc1f81bf5c8ae8edd66a7b806f6784da16ce6`。6.1.4 明确初始化连接；其 verifier
使用 READ ONLY + REPEATABLE READ + ROLLBACK，成功返回也证明 connection reusable。active registry 仍是
failed state=8，未写 replace/retry、数据库、服务或浏览器。当前为 `FORMAL_GATE_A_PASS`，不是恢复完成。
数据库写入、服务或浏览器动作。
