# SandAdmin / SandPackage 宿主恢复交接

状态：**未解决，交给 SandAdmin / SandPackage 宿主维护者继续处置。**

本文只描述 `/Users/code/project/sand_plugins/sandadmin-demo-host` 的宿主问题和可复核证据。它不把 SandIAM 产品功能、插件源码静态检查或候选包构建结果当作宿主恢复完成。

## 先说结论

演示宿主经历了 SandIAM `0.6.0 → 0.7.0` 升级，当前记录是 `state=8`、`stage=failed`、`failed_stage=database_update`。这是 SandAdmin 的插件生命周期/失败恢复问题，不是 SandIAM 业务功能缺失。

SandIAM 已交付新的审阅候选：`.artifacts/sand-iam-0.7.0-v19-20260908T073318Z/`。ZIP SHA-256 为 `4fcba790272b902ae7e58435a78ae0c595c5cb02ffd976f43b905a8cabe7043c`，共 608 个条目，重复构建字节一致；它包含 `001–037`、86 表目标和 P19 初始化草稿前后端。该候选仍明确标记为 `candidate-dirty-not-release`，只供 SandAdmin 后续恢复验收使用，不能直接视为发布包。

当前不能称为已恢复、已部署或线上验证通过：

- 运行服务曾可恢复，端口返回 HTTP 200，但数据库、registry 和失败态生命周期没有因此闭环。
- 只读 catalog 证据证明 83 个 `sand_iam_*` 关系是合法的 `prefix_033_034` 重试前缀；修正后的 SandPackage verifier/profile 在 staging 中判定 `107/107 retry_safe`。该修正尚未构成宿主已同步或 Gate A 已通过。
- 真实备份 B 本身自洽，但宿主在备份建立后有一个管理端运行文件发生漂移，严格校验因此正确拒绝恢复。必须先作有授权的数据/文件恢复决策，不能改历史 manifest 或放宽校验。
- v17 是 SandIAM 产品主线收口前的历史候选，不包含最终 P19 初始化草稿实现。SandIAM 当前源码已扩展到 `001–037`、目标 86 张表且 package checker 23/23；SandAdmin 不得继续把 v17 当作待恢复的最终候选，应使用上面登记的 v19 审阅候选及其清单。
- Gate B 独立验收为 `28/32 (87.5%) REJECT`，仍有 retry 状态重构、归档漂移重算、审计字段和中断矩阵等 P1 缺口；不得同步到 authority 或 demo host 作正式宿主测试。

## 用户影响与边界

受影响的是演示/验收宿主上的插件生命周期操作：失败升级后的候选重试、候选替换/撤回、失败态可见操作和恢复审计。不能据此推断 SandIAM 的身份、权限、应用或审计业务功能本身失效。

健康端口、HTTP 200、能打开前端页面，均不等于：

1. registry 失败状态已安全解除；
2. PostgreSQL 已执行且验证了正确的 `035`；
3. 候选、备份、运行目录的逐文件身份一致；
4. failed-state UI、Gate A/B 和业务闭环已验收。

Chrome structured connection 曾出现 `b3 is not defined`，导致官方浏览器验证无法继续。这是验收工具/浏览器会话阻断，不是恢复的必要前置条件。SandAdmin 必须提供可审计的本地 CLI/console，使维护者可以在没有 Chrome 的情况下完成只读诊断、受控恢复准备和结果核验；浏览器只用于随后的人机界面与视觉验收。

## 当前 durable state（以证据目录为准）

已观察到的宿主状态：

- registry：SandIAM `0.7.0`，`state=8`，`stage=failed`，`failed_stage=database_update`；
- PostgreSQL：83 个 `sand_iam_*` 关系，迁移 ledger 尚未出现；没有证据表明 `035` 已执行；
- SandPackage：没有把 HTTP 健康或页面显示作为恢复结论；失败态正常安装/卸载路径应保持受阻；
- 备份：现有真实 B 的 package/deployment/stored-runtime/registration 四层清单互相闭合；当前 deployed 管理端仅一个文件与备份后 stored runtime 不同，属于备份建立后的宿主漂移；
- node_modules：现场观察到 demo host 管理端现有临时 pnpm 链接已修复（包括包入口和类型包链接）。这只说明本地前端工具链的链接问题已处理，不改变 registry、数据库或 SandPackage 恢复状态，也不构成正式前端验收。

对应证据：

- [服务恢复快照](../../.artifacts/sandpackage-services-restored-20260901T130000Z/README.md)
- [真实备份根因审计](../../.artifacts/sandiam-recovery-backup-rootcause-20260907/REPORT.md)
- [Gate A catalog 根因与 staging 修正](../../.artifacts/sandiam-recovery-dbstate-rootcause-20260907/REPORT.md)
- [SandPackage failed-upgrade contract（设计基线）](../../.artifacts/sandpackage-failed-upgrade-recovery-contract-design-20260901T110000Z/FAILED_UPGRADE_RECOVERY_CONTRACT.md)

## SandPackage 已修部分与未完成部分

### 已有可复核证据

SandPackage 的 verifier/profile/035 方向已经有以下证据：

- PostgreSQL identity、`NOT VALID`、schema-qualified index 和 PDO 参数类型差异已纳入 verifier/profile 的 staging 修正；真实 catalog 的 `prefix_033_034` 读路径达到 `107/107 retry_safe`。
- `baseline_060` 对同一 catalog 被拒绝，并出现预期的 033/034 差异；这证明判定没有把“83 表”直接猜成 0.6 baseline。
- 035 authority 的 checksum、ledger 缺失/存在条件和 033/034 前缀边界有独立报告；没有执行数据库写入。
- v17 候选构建可复现，报告内容见 [v17 validation report](../../.artifacts/sand-iam-0.7.0-v17-20260907T200515Z/validation-report.md)，但已被 P19 最终源码替代，只能用于历史审计。当前 v19 同样带 `candidate-dirty-not-release`，不得当成发布包。

### 仍不能宣称完成

- verifier/profile staging 修正尚未完成受控同步、宿主 Gate A 重跑和正式审计闭环；
- 真实宿主运行文件漂移尚未在有授权前提下隔离、留证、精确恢复并重新计算清单；
- Gate B 仍为 [28/32 REJECT](../../.artifacts/gate-b-independent-acceptance-20260907/REPORT.md)；
- failed-state UI 的静态/行为材料不等于 demo host 当前登录态浏览器验收；Chrome 阻断必须保留为阻断事实，不能用包检查替代。

## SandAdmin 宿主应提供的本地能力

维护者应在 SandAdmin/SandPackage 宿主侧提供版本化、只读优先、输出脱敏的 CLI 或 console 命令，至少覆盖：

1. **状态诊断**：输出 app、from/to version、失败阶段、registry 状态、候选/备份/隔离目录身份和事务 journal 是否存在；输出必须是固定字段白名单，不输出秘密、环境变量、完整本地路径或 SQL/catalog 原始行。
2. **Gate A 只读核验**：在同一应用锁语义下使用固定的 PostgreSQL `READ ONLY`、`REPEATABLE READ` 查询，返回 `retry_safe` / `blocked`、profile、断言计数和脱敏原因；异常必须回滚并 fail closed。只读核验不能先调用会修改或恢复 candidate transaction 的路径。
3. **备份/运行漂移诊断**：分别报告 backup 自洽性与 current runtime 漂移，至少给出计数和相对文件名摘要；不能把两个问题合并成“备份损坏”。
4. **受控恢复准备**：仅在显式管理员确认、权限检查、应用锁、原子隔离、完整清单复核、失败可回滚和审计已具备时，才允许恢复动作；恢复后必须重新走 Gate A，禁止直接改 registry 放行。
5. **候选替换/重试**：严格绑定已核验的 archive、descriptor、payload、update.sql、backup 和 runtime；重试必须验证现有失败形状，不能在内存中重构一个“看起来失败”的 registry 状态。
6. **审计与导出**：记录动作、操作者、目标版本、结果、确认来源和 server-derived outcome；响应和日志不得含 secret、SQL、catalog row、绝对路径或未脱敏文件内容。

### 安全验收标准

- 所有恢复方法默认关闭、明确 POST/console 动作、超级管理员授权并复用普通生命周期的 app lock；
- 任一 descriptor、archive、manifest、backup、runtime、journal、registry 或 catalog 漂移均拒绝，保留可诊断证据；
- 不接受 symlink、危险路径、错误权限、缺失/额外文件或未知 descriptor/profile/assertion；
- 任何 rename、journal write/fsync/rename/unlink、retry preflight 中断，都必须有可执行故障矩阵，并只收敛到完整可核验状态；
- 不以端口、HTTP、页面按钮、候选包计数或单次静态测试代替数据库和业务验收；
- 生产恢复前必须取得本次任务明确授权；本交接不授权创建数据库、执行迁移、修改 registry、启停服务、同步候选、部署或删除证据。

## 复现与后续处置顺序

复现应在隔离副本或明确授权的 demo host 上进行，并保存命令、时间、版本、清单摘要和退出码：

1. 只读记录当前 registry、candidate/backup/quarantine/journal 和 runtime manifest；
2. 只读运行 accepted/staged verifier，确认是 `prefix_033_034`、`baseline_060` 还是 `blocked`，并保留 fingerprint 是否为空；
3. 单独比较备份 stored runtime 与 current runtime，先处理漂移分类；
4. 恢复漂移前制作可回滚的完整文件快照并取得明确授权；不得用历史清单覆盖当前事实；
5. 恢复后重新执行 Gate A；只有非空 fingerprint、完整证据和审计齐全，才可进入 prepare/replace/retry；
6. 035 执行后再核验 ledger、表/约束/索引/菜单、registry、服务和用户可观察业务闭环；每层单独记录。

禁止事项：创建数据库或让工具隐式创建数据库；手工插入/改写 ledger、registry、manifest 或 failed state；把 current hash 写入历史 metadata；切换到缺少登记证据的旧备份；用浏览器截图、HTTP 200、v17 `23/23`/包检查结果伪造恢复状态；删除或覆盖原始失败证据。

## 责任边界与验收清单

| 事项 | 责任方 | 当前状态 |
| --- | --- | --- |
| SandPackage verifier/profile、固定 SQL、Gate A/B 代码与行为测试 | SandPackage 维护者 | staging 有修正；Gate B 仍 REJECT |
| demo host registry、backup/runtime 漂移、文件隔离与恢复 | SandAdmin / SandPackage 宿主维护者 | P0 阻断，尚未授权恢复 |
| SandIAM 0.7 authority 候选与 migration 035–037 内容 | SandIAM 团队 | 提供证据和候选；不负责替宿主修复 |
| 管理端 UI / Chrome 登录态、视口和业务闭环 | SandAdmin 前端/验收维护者 | 未完成；Chrome context 曾阻断 |
| 数据库写入、迁移、服务启停、候选同步/部署 | 明确授权的发布责任人 | 本交接未授权 |

宿主维护者关闭本交接前，必须逐项取得：

- [ ] current runtime 漂移已隔离、留证并按授权恢复，前后清单可复核；
- [ ] Gate A 在真实 demo host 返回非空 fingerprint 的 `retry_safe/prefix_033_034`；
- [ ] Gate B 的冻结项目全部通过，或每个未通过项有明确阻断和返工记录；
- [ ] 真实 PostgreSQL 035–037 与 registry 变更有独立审计；
- [ ] failed-state UI、candidate upload/withdrawal、replace/retry 在真实登录态通过；
- [ ] SandIAM 相关业务闭环、回滚路径和服务运行基线分别通过；
- [ ] 结果明确区分：已检查、已修改、已验证、已提交、已部署、线上验证。

本文件是交接记录，不改变 SandIAM/SandAdmin 的 FLOW 或进度分子/分母；SandIAM 团队在本任务中不再修宿主代码、demo、数据库或服务，只负责交付与最终产品源码一致的审阅候选。

## 2026-09-08：恢复契约 v2 后续交接（仅追加）

SandPackage 6.1.0 已提供候选内联 profile 的
`sandpackage.failed-upgrade-recovery/v2` 契约。SandIAM 已按该契约生成新的
v20 审阅候选；本节不覆盖上文事故证据，也不表示 demo 已恢复。

后续双方使用同一目录交换版本身份、实现回执和验收状态：
[SandPackage recovery v2 双向交接](sandpackage-recovery-v2/HOST_HANDOFF.md)。
当前停在 `HOST_REVIEW_REQUIRED`：宿主需先用其中的
`HOST_MANIFEST.json` 与 `PLUGIN_MANIFEST.json` 做兼容复核，之后才可另行取得授权，
同步 demo 并执行数据库、registry、服务和业务闭环验收。

### 同日复核回执（继续追加）

宿主已基于最新 17 文件身份和 v21 插件候选完成只读 profile/catalog compatibility probe：
使用 probe/test identity，`retry_safe/prefix_033_034`、101/101、failed_ids 为空、
非空 fingerprint（仅属于探针），事务最终 ROLLBACK。正式 identity-bound Gate A 仍为
`NOT STARTED`，不得以探针结果代替。当前状态更新为 `HOST_REVIEW_PASSED / SECOND_PHASE_NOT_AUTHORIZED`；
此前 `HOST_REVIEW_REQUIRED` 保留为过程记录，不再代表最新状态。demo 文件同步、
prepare/replace/retry、registry、服务和业务闭环仍未获第二阶段明确放行。

### 白名单同步回执（继续追加）

宿主随后明确放行 17 文件白名单同步。目标原有 10 个文件已保存前镜像、7 个缺失路径
已记录；同步后 17/17 与 HOST_MANIFEST 一致，SandPackage 相邻目录的白名单外摘要
前后不变。PHP lint、PSR-4、CLI 命令发现、目标前端 ESLint 与类型检查通过。
当前更新为 `HOST_COMPATIBLE / DEMO_SYNC_VERIFIED / RUNTIME_RECOVERY_NOT_AUTHORIZED`；没有执行数据库写入、
registry 修改、运行文件恢复、候选 prepare/replace/retry、服务启停/重载或部署。

### v24 候选与 Gate A 口径更正（继续追加）

上文关于 v21 与“真实只读 Gate A”的表述保留为历史过程记录；本追加段更正当前口径，
不改写事故证据。当前插件审阅候选为 v24：
`.artifacts/sand-iam-0.7.0-v24-20260908T110634Z`，608 entries，archive SHA-256
`931b3c13bafb61e9349f5c80be35722638a3f38cbe2f9a966f0b00f68bc63ded`，snapshot
`b733adfd1894f7e2df8c15a69759a0d9f59d30d935125145d09d34a4cde53578`，payload
`52e7c769e12693562c2cc6de2fb764ed6ddd156c9865525626e24ef102725f1c`，descriptor
`1d7bcafd01aa8e0baf5a569102c74366bfd87cdac392c62bcd7ab029c0da1e90`，update
`aff487972130f1bf0d9b2e20fc6651b9f7738bf6a30a78c8fa642bad6d3f8457`，
`bit-identical=true`。package 23/23、descriptor 6/6、non-PG suite 93/93。

101/101 只证明使用 probe/test identity 的 `read-only profile/catalog compatibility probe
PASS`；fingerprint 仅属于该探针。正式 identity-bound Gate A 仍为 `NOT STARTED`，因此
不得把探针结果写成绑定真实恢复身份或宿主真实只读 Gate A/PASS。当前总状态仍为
`HOST_COMPATIBLE / DEMO_SYNC_VERIFIED / RUNTIME_RECOVERY_NOT_AUTHORIZED`；17-file
demo 静态同步已完成，但正式 Gate A、数据库/registry/runtime recovery、浏览器及
SandIAM business loops 均未执行。

### SandPackage 6.1.0 历史：真实失败身份 Gate A 阻断（2026-09-08，过程记录）

宿主现场只读事实进一步表明，registry 中 SandIAM `0.7.0` 为 `state=8`、
`stage=failed`、`failed_stage=database_update`，来源 `0.6.0`。真实 backup identity
为 `sand-iam-package-20260901095541-f92a9870f695`，registration manifest digest
为 `2cd54b…82d5ba`。活跃失败候选缺少
`candidate_archive_sha256`、`candidate_payload_manifest_sha256`、
`recovery_descriptor_sha256`、`update_sql_sha256`；真实
`FailedUpgradeIdentityBinding` 无法构建。当前 update.sql digest
`2739deb7…46cab7` 与 v24 `aff48797…f8457` 不同。

SandPackage `verify` 会写 `FailedUpgradeRecoveryAudit`，且会先被身份缺失拒绝。
本次没有执行 verify，也没有执行数据库核验、数据库写入、registry 修改、运行恢复、
服务操作或部署。故 formal identity-bound Gate A 在 **SandPackage 6.1.0 历史状态**必须标为
**`BLOCKED`**，不能标为 `NOT_STARTED` 或 `PASS`。compatibility probe 仍为 `PASS`，但仅使用
probe/test identity，不能伪称为真实身份绑定的数据库核验。

该 blocker 归属 SandAdmin/SandPackage：需要登记完整真实 v2 candidate identity，
并提供不写审计的 read-only Gate A，或单独申请 `FailedUpgradeRecoveryAudit` 写入
授权。该历史状态不描述当前 6.1.1；本追加不改变 FLOW 计数，也不授权任何宿主、数据库
或服务操作。

### SandPackage 6.1.1 白名单同步与 Gate A 前置（2026-09-08，继续追加）

宿主 revision 已更新为 `0b12481ed816a1c97d9e00fb9cb98c129fb87d3e`（parent
`16c0602e385c42af2d00a23e34c52ce008ecc681`），SandPackage 为 `6.1.1`，contract
仍为 v2。仅同步 7 项运行白名单：Recover、FailedUpgradeRecoveryInspector、InstallLogic、
SandPackage app config 与三个 failed-upgrade recovery TS 文件。证据目录
`.artifacts/sandpackage-611-demo-sync-20260908T132546Z/` 保存 5 项前像、记录 2 项原
缺失路径；同步后 source/target SHA 为 7/7，白名单外 SandPackage 文件摘要不变。

6.1.1 代码合同已解决旧 legacy bootstrap 对全缺 v2 identity 的入口、私有 prepare
replacement、formal read-only Gate A 的 `audit_written=false`、replace 前重新核验，
以及 CLI `gate-a`/`verify` 别名。这里的“已解决”只指已同步代码和中性合同：真实 demo
还没有 prepare，所以 formal identity-bound Gate A 是 **`PENDING_AFTER_PREPARE`**，
不能提前标为 PASS；更没有执行 replace、retry、数据库/registry/运行文件恢复、服务或
浏览器验收。

本次两个 demo-local 验证门均为 **PARTIAL / REJECT**，没有以补文件绕过白名单：旧
`server/plugin/sandpackage/tests/lifecycle_non_db_contract_test.php` 缺少 6.1.1 所需
`think\\facade\\Db` fake 和 `app/service/PostgresLifecycleSqlExecutor.php` require；
`failed-upgrade-recovery.behavior.ts` 的 import
`./failed-upgrade-recovery.index-mount` 在 demo target 中缺失，令 `vue-tsc --noEmit`
报 TS2307。SandIAM candidate payload 未改。

### 9-file dependency closure（2026-09-08，继续追加）

SandAdmin 已回传并授权仅补 B3/C1：
`sandadmin-artd/src/views/plugin/sandpackage/install/failed-upgrade-recovery.index-mount.ts`
（`5a15e29b…21e4c3`）和
`server/plugin/sandpackage/tests/lifecycle_non_db_contract_test.php`
（`dbb1c4be…b8012e`）。B3 原来缺失，C1 的旧前像已保存；未同步其他文件。完整 9-file
source/target SHA 为 9/9，C-sorted conventional SHA-256-lines digest 为
`b24a6c67ebee7afc541be1234271b4417138ebbcdaeb7fda3a18f5fb9a3403d9`，白名单外
SandPackage 摘要仍不变。证据在
`.artifacts/sandpackage-611-demo-sync-20260908T132546Z/closure-9file-20260908T133548Z/`。

9 项严格分类为 5 个生产运行、3 个前端门禁辅助、1 个后端非 DB 测试辅助。新增 B3/C1
后 demo `vue-tsc --noEmit` 及 `php server/plugin/sandpackage/tests/lifecycle_non_db_contract_test.php`
均通过；此前 2/7 PARTIAL 已清除，当前 9-file 静态同步结论为
**`DEMO_SYNC_STATIC_GATE_PASS`**。当前总状态为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_PENDING_AFTER_PREPARE / RUNTIME_RECOVERY_NOT_AUTHORIZED`；formal identity-bound Gate A 仍为
**`PENDING_AFTER_PREPARE`**，没有执行 prepare、Gate A、replace、retry、数据库、
registry、运行恢复、服务或浏览器验收；SandIAM candidate payload 未改。

### SandPackage 6.1.1 v24 prepare 实际阻断（2026-09-08，继续追加）

在本次明确授权下，先执行只读 inspector：`runtime_restore_required=false`、
`runtime_drift=false`，故没有 restore。随后对精确 v24 archive 执行 private prepare；它在
写 replacement record 前 fail-closed：`FAILED_UPGRADE_RECOVERY_BLOCKED：替换包描述摘要不匹配`。
未得到 replacement id，故 formal Gate A 没有执行；没有 PostgreSQL 查询或写入、registry 修改、
replace/retry、服务或浏览器动作。

根因不是历史 active candidate：v24 builder 的 descriptor payload digest `52e7c…25f1c`
排除 root 和 plugin 两份 descriptor，SandPackage 6.1.1 的通用
`FailedUpgradePackageIdentity::PAYLOAD_EXCLUSIONS` 只排除 root descriptor。该 contract
不一致属于 SandAdmin/SandPackage 与插件 build policy 的交接阻断，不能通过手工写 record、
改 descriptor 或跳过 prepare 解决。当前状态更新为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_BLOCKED_PREPARE_DIGEST_MISMATCH / RUNTIME_RECOVERY_NOT_AUTHORIZED`；详细命令与结果见
`.artifacts/sandiam-formal-gate-a-20260908.md`。

### SandPackage 6.1.2 descriptor-algorithm 静态同步（2026-09-08，继续追加）

SandAdmin `261e64d26063f48fd97ceb3eef9b73e4a39d7bac`（parent `0b12481`）只向 demo
同步 `FailedUpgradePackageIdentity.php`（`36f10490…d01327`）和 SandPackage
`config/app.php`（`5a2dd8e6…ad5f38e`）。两个 target 前像已保存；source/target SHA 为
2/2，C-sorted conventional digest 为
`74d8658aa859f231742a78e5821142c9e0c4d4f6f94cff3c8cb4d06af0a7fbe2`，白名单外摘要不变。
证据在 `.artifacts/sandpackage-612-demo-sync-20260908T140442Z/`。

6.1.2 的 descriptor/.sha256 exclusion 仅适用于 root 与 exact
`plugin/<root app>`；foreign plugin、nested、backup/bak 内同名文件仍计入 payload。
这修正 6.1.1 historical prepare digest mismatch 的通用算法边界，但本轮没有重新 prepare。
demo PHP lint、non-DB lifecycle contract、`vue-tsc --noEmit` 以及 source neutral contract
均通过。当前状态为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_PENDING_AFTER_PREPARE / RUNTIME_RECOVERY_NOT_AUTHORIZED`；
formal Gate A 仍为 **`PENDING_AFTER_PREPARE`**，未执行数据库、registry、prepare、Gate A、
replace、retry、服务或浏览器动作；SandIAM candidate payload 未改。

### SandPackage 6.1.2 v24 prepare 重试（2026-09-08，fail-closed）

本轮授权重试前 inspector 明确 `runtime_restore_required=false`、`runtime_drift=false`，未执行
restore。对精确 v24 ZIP 的一次 private prepare 仍在任何 retained archive、replacement record 或
transaction 落盘前失败：`FAILED_UPGRADE_RECOVERY_BLOCKED：替换包描述摘要不匹配`；没有 replacement-id，
故没有 Gate A、PostgreSQL 查询/事务、registry 变更、replace、retry、服务或浏览器动作。

6.1.2 已解决 descriptor 的 root/exact-plugin 排除范围，却仍与 v24 的 manifest 表示不一致：host
哈希含 `size`、normalized `info.ini` 的裸 map，v24 绑定 `schema`/`algorithm`/`files` 包装的纯 SHA
map。该契约差异需 SandPackage 修复；不能改候选或绕过。当前为
`HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS / FORMAL_GATE_A_BLOCKED_PREPARE_DIGEST_MISMATCH /
RUNTIME_RECOVERY_NOT_AUTHORIZED`，详见 `.artifacts/sandiam-formal-gate-a-20260908.md`。

### SandPackage 6.1.3 最终 canonical payload 安全补丁与 demo 同步（2026-09-08，继续追加）

最终宿主 revision 为 `d29d6f390d89c6cbf3d3e1cb6ee77cde0ade0bde`（parent `3ca07f2`，SandPackage
`6.1.3`）。先前 3ca07f2 三文件同步的 target 保留为本最终步骤前像；随后只覆盖同一三个生产
路径：`FailedUpgradePackageIdentity.php`（`41b03179…b25410`）、`InstallLogic.php`
（`1c0bc753…64adff`）和 `config/app.php`（`be50b5cf…dd9d3`）。前像为 3 个存在、0 个缺失，
最终 source/target SHA 为 3/3，C path-sorted conventional SHA-256-lines digest 为
`c5d549a930c07f589aea5916836b938488298e1acd3a929a31ea07e4eeea01ff`，白名单外 SandPackage
摘要不变；当前同步门精确为 **`DEMO_SYNC_6_1_3_STATIC_PASS`**；证据为
`.artifacts/sandpackage-613-final-d29d6f3f-20260908T144505Z/`。

6.1.3 使用 canonical `schema`/`algorithm`/`files` pure-SHA wrapper 对原始候选文件（包括
原始 `info.ini` 字节）摘要，并保持 root/exact-plugin descriptor 排除、foreign/nested/backup/bak
计入。最终 d29d6f3f 安全补丁还要求 descriptor app 与 root `info.ini` app 一致，拒绝 foreign
app 选择排除范围；neutral payload contract 实测 38/38。demo PHP lint 3/3、demo non-DB lifecycle、
demo `vue-tsc --noEmit`、source recovery subphases 16/16、state-machine contract 和全部 SandPackage
contract tests 7/7 均通过。

6.1.2 的 `BLOCKED_PREPARE_DIGEST_MISMATCH` 只保留为历史 prepare 记录；6.1.3 未执行 prepare，
因此当前统一状态为 `HOST_COMPATIBLE / DEMO_SYNC_STATIC_GATE_PASS /
FORMAL_GATE_A_PENDING_AFTER_PREPARE / RUNTIME_RECOVERY_NOT_AUTHORIZED`。formal identity-bound Gate A
仍为 **`PENDING_AFTER_PREPARE`**，不是 PASS；未执行数据库、registry、Gate A、replace、retry、运行恢复、
服务或浏览器动作，SandIAM candidate payload 未改。

### SandPackage 6.1.3 Gate A 连接诊断（2026-09-08）

v24 private prepare 已成功，replacement id 为 `present/redacted`；Gate A 随即在
verifier 前 fail-closed：`FAILED_UPGRADE_RECOVERY_BLOCKED：无法建立只读恢复核验连接`。本地 socket 的
PostgreSQL 与 `pdo_pgsql` 可用；named/default Think ORM connection 在显式 `connect()` 后均用
`BEGIN READ ONLY; SELECT current_database(), current_user, current_schema(); ROLLBACK` 验证为
`sandadmin`/`supdger`/`public`。

根因是 SandPackage 的 lazy-PDO 调用顺序：`Db::connect('pgsql')->getPdo()` 未先初始化连接，Think ORM
因而返回 `false`。最小 host fix 是调用已有 named connection 的 `connect()` 并使用其 PDO，再进入现有
`BEGIN READ ONLY`/`REPEATABLE READ`/ROLLBACK verifier；本轮未改源码、配置或服务，也未重跑 Gate A。

### SandPackage 6.1.4 PostgreSQL PDO 初始化同步（2026-09-08，继续追加）

宿主 `a99af5dea0f22071939a9010a958e90829c675a3`（parent `d29d6f3`）只同步
`InstallLogic.php`、`PostgresLifecycleSqlExecutor.php` 和 `config/app.php`。目标前像 3 个存在、0 个
缺失，source/target SHA 3/3，C-sorted digest 为
`dbdec85c3558592f2af196a689a28fdaa89f9ce56e8e38ecfcf93e6f3de5f34f`，白名单外摘要不变；当前为
**`DEMO_SYNC_6_1_4_STATIC_PASS`**（lifecycle 10/10、lint 3/3、vue-tsc PASS、关键 SHA 5/5）。修复显式调用 named PostgreSQL connection 的 `connect()`。

demo PHP lint 3/3、PSR-4/class load PASS；source contracts 7/7、neutral 38/38、recovery 17/17、
lifecycle 4/4 PASS。demo non-DB lifecycle 因旧 test fake 没有 `connect()` 而在 `database_install` 的
importer-count 断言 REJECT；无真实数据库连接，且没有以同步 test helper 扩大白名单。

prepared replacement 已存在但标识为 `present/redacted`。本轮未执行 Gate A rerun；当前 formal Gate A 为
**`PREPARED_REPLACEMENT_PENDING_RERUN`**，不是 PASS；未执行数据库、registry、replace、retry、运行恢复、服务或浏览器动作，SandIAM candidate payload 未改。

P2 历史风险：曾一次出现不可复现 TerminalRunner 聚合失败，harness 没有保留 failure frames；独立复跑
已 ACCEPT，故不阻断当前静态门。

### SandPackage 6.1.4 formal Gate A PASS（2026-09-08）

既有 replacement `8ecc5cec83f03f19ea609b220e8f9e9f` 的 Gate A 已返回 `retry_safe`：101/101、
`failed_assertion_ids=[]`、`audit_written=false`、非空 fingerprint `714894c6…a16ce6`；host 的
READ ONLY + REPEATABLE READ + ROLLBACK verifier 且 connection reusable 已由成功路径确认。真实 backup
lineage 与 archive/payload/descriptor/update identity 均未变，active registry 仍 failed state=8。
本轮未 replace/retry、未写 DB/registry、未恢复运行文件、未启停服务或使用浏览器；这只关闭 Gate A，
不等同恢复完成。
