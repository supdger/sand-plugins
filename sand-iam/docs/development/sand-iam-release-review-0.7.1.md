# SandIAM 0.7.1 发布评审材料

> 评审日期：2026-09-12。本文是发布评审与交接材料，不是安装包、候选 manifest、发布指令或进度体系；计分唯一来源仍是[任务看板](sand-iam-task-board.md)和[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)。

## 结论

### 签名链初审补充（2026-09-12）

签名链初审为 **3P2**；修复后 Astra 复核 **ACCEPT（P0/P1/P2=0）**，helper SHA-256 为 `78f9…`。临时测试签名不构成正式签名；受控目录、同 UID TOCTOU、真实密钥信任和独立 Git 重建仍是必须单独证明的边界。正式 signing 未执行，因此签名/发布门槛仍未通过，发布 **0/10**、FLOW **28/48**、F/L/D 不变。

**不可发布。** 当前仅具备部分源码与包内静态证据；正式 FLOW 为 **0/7**、本地业务闭环 **0/4**、可上线部署 **0/8**，完整 FLOW 为 **28/48**，发布门槛为 **0/10**。未部署，未做线上验证。

`61a7f13821980deca8479f9c9e5e872be92cf72a` 是当前分支 `codex/plugin-repository-baseline` 的未推送候选提交；它不是最终提交。当前工作树 non-clean，有 22 项 tracked changes 与 6 个 untracked path roots，包含来源/事务修复。v12 的 manifest/validation 曾由旧 verifier 自报 `release/unsigned`、`clean committed source` 与 release hygiene PASS；独立 Astra 复核发现该 verifier 只查看 tracked dirty 状态，漏掉 62 个 ignored 的 vendor/dist 来源文件，故该自报已被推翻。v12 仅是内容自洽的历史快照，不能作为正式来源或升级包，不得上传、签名、同步、安装。最终 commit、tree、ZIP、哈希与签名：**Pending（尚未形成）**。

## 发布评审

| 项 | 当前状态 | 评审结论 |
| --- | --- | --- |
| 质量验收 | 部分静态通过；宿主/数据库/浏览器未执行 | 不可用作准出 |
| 提测准出 | 不就绪 | P1 来源可追溯性阻塞 |
| 预发验证 | 未执行 | B′/C′/D′ 未执行；G 未授权 |
| 发布物料与合规 | 公开 `LICENSE`、`NOTICE`、`SECURITY.md`、`CONTRIBUTING.md`（DCO）已在源码；最终候选仍未形成 | 不应再表述为 license/DCO 待确认 |
| 灰度方案 | 仅预案 | 不执行 |
| 压测 | Not run | 本次未触及已获授权的高 QPS 受控环境，且没有可安全运行的非生产目标或容量/SLA 基线；不得对 demo/生产做压力操作 |

## 变更范围与已知问题

- 后端：0.7.1 生命周期更新的 `001–037` 精确 preflight 与原始 `038` 组合在一个显式 PostgreSQL 事务中；正常包排除旧 failed-upgrade recovery descriptor。该事务改动仍待来源提交和真实生命周期验收。
- 前端：本轮不声明新的管理端交付或真实浏览器验收；候选提交范围复核没有 Vue/TS 文件。
- 发布输入：Composer 58 个 runtime 文件与 TypeScript SDK 4 个 `dist` 文件完成双隔离重建和锁校验；这不等于 clean provenance、签名或可安装性。
- **P1（发布阻塞）—来源可追溯性：** 旧 verifier 对 v12 的 `release/unsigned`、clean-source 与 hygiene PASS 自报已被独立推翻：它只看 tracked dirty 状态，遗漏 62 个 ignored vendor/dist 来源文件。主树 integrity 为 25/26，唯一失败项为 clean/tracked。修复需先完成最小来源提交、从 clean commit 的 Git blob 重建、重新独立验收，之后才能生成新的正式候选。
- 非阻塞但未关闭：两次测试选择器偏差已留作证据限制；只读数据库观察只证明窗口内未见可见写入，不证明此前或窗口外没有写入。

## 单一交接

| 字段 | 内容 |
| --- | --- |
| version / branch / 当前锚点 | `0.7.1` / `codex/plugin-repository-baseline` / `61a7f13821980deca8479f9e5e872be92cf72a`（未 push 的候选提交）与 `62091ae01d136016dbbe0a822a71c75ff61a98d7`（其 SandIAM tree） |
| 工作树 | non-clean：22 项 tracked changes 与 6 个 untracked path roots；不得把工作树内容称为已提交发布来源 |
| 最终 commit / tree / ZIP | **Pending**；v12 的旧 verifier 自报已被推翻，不能填作最终身份或升级包 |
| 后端清单 | 生命周期 preflight + `038` 事务组合、包来源/完整性检查、runtime/SDK 依赖重建证据；均不替代 PostgreSQL 生命周期、宿主 HTTP 或业务链 |
| 前端清单 | 无本轮 Vue/TS 变更；既有管理端证据不替代本候选三角色浏览器验收 |
| blocking | P1 来源可追溯性；B′（受控同步）、C′（既有宿主生命周期）、D′（运行配置/服务窗口）均未执行；G（隔离备份恢复）未授权；七链、外部协议、独立交付、24 小时、签名和最终包均未完成 |
| non-blocking | `HOST-202609-001` 仍是 `local draft / not sent` 的中立宿主交接，当前 demo registry 是健康 `0.7.0`，不能替代 SandIAM 0.7.1 发布验收 |

## 回滚、监控与人工窗口

- **回滚限制：** 未产生可发布 0.7.1 ZIP，也未同步/安装/部署，所以本次没有可执行发布回滚。未来正常升级涉及已存在 PostgreSQL 数据；只允许在备份、已验证候选身份和环境负责人明确授权下，按宿主生命周期回退包/配置。禁止手工改迁移账本、跳过 preflight 或用空结构重装替代恢复。
- **监控/告警预案：** 24 小时验收须持续记录候选身份、health、allow、deny、revoked、audit、metrics 七类 probe；关注 API 错误、未授权放行、数据损坏、Worker exit/restart、队列深度、不可恢复积压、p50/p95/p99、RSS/FD 与 pool/event-loop wait。错误率、Worker exit/restart、未授权放行、数据损坏和不可恢复积压为零容忍告警。
- **值班/窗口：** 尚未安排，也不执行生产值守。获得发布授权后，窗口须由环境负责人明确指定，并至少指定发布负责人、数据库负责人、宿主负责人和安全/告警接收人；无上述人员、备份或告警通道即停止。
- **灰度：** 仅作为将来预案，可建议 `1% → 5% → 20% → 100%`；每级仅在上述指标持续正常、审计完整、允许/拒绝均符合预期后推进。本评审不授权部署或任何放量。
- **stop triggers：** 候选身份/签名/来源不匹配，任一数据库或宿主步骤超出授权，任一 P1/数据完整性/权限绕过，任一零容忍指标非零，审计关联缺失或出现不可恢复积压，立即停止，不推进下一档，也不以重试掩盖证据。

## 24 小时与外部条件

Endurance v1 审计为 **4P1 + 3P2**；v2 分三批修复并经 Astra 工具最终 **ACCEPT（P0/P1/P2=0）**。该结果仅表示离线 contract/tool 结构通过：协议是协作式可信环境边界，哈希链不是签名、身份认证或防伪，Git 未独立重建，collector/probe 真实性依赖受控环境、独立保管和可信对端。所有 fixture 均非 86,400 秒，真实同一最终候选 24 小时稳定性仍未开始（**0**），external validators 的 fixture 修复即使离线结构 ACCEPT 也不代表 ready。

执行前必须把最终 clean commit、最终 ZIP、manifest 和签名绑定到同一候选，在同一受控环境连续采样至少 86,400 秒；不得拼接短跑或跨候选证据。Casdoor 三旅程双方各两轮、OIDC/SAML/LDAP/SCIM/CAS/Kerberos-SPNEGO/RADIUS 七类真实受控对端、未参与开发者按公开材料完成八步交付，以及隔离备份恢复，都需要各自的外部人员/对端、环境和一次性授权。缺少其中任一条件，相关门槛保持未通过。发布门槛 **0/10**、FLOW **28/48**，F/L/D 不变。

## 载荷排除确认

本评审文件位于 `docs/development/`，不属于 installable payload roots。权威 payload policy 仅包含公开操作文档 `docs/user-guide/` 等目录，并明确由 release hygiene 检查拒绝 `docs/development/`、`.codex/`、`.cursor/` 和 `autopilot` 内部任务引用。因此发布包不得包含本文、任务板、执行记录或其他内部评审材料。

## 2026-09-12 本地提交回写（未 push）

- 已固定的提交链为 `6e190953dc260f32e7428e751c3c6318dc9fcd8d`（HOST-202609-001）、`a7edcebb37ea06b2da3dc445d5b7307d3111a7ab`（0.7.1 生命周期）和 `1aba9b444ae8ec69972faa2c1e6fe8e6ebc32d48`（外部验收）。它们替代“生命周期改动待来源提交”的旧表述，但未生成最终 clean 候选、ZIP、哈希或签名。
- `a7edceb` 的非 vendor `git diff --cached --check` 为零；完整检查只报告 18 个原样纳入第三方 vendor 文件的 whitespace。第三方字节未改写，因此不得将完整 diff-check 写为通过。
- BLOCKED-B 的 7 个备份恢复文件未提交：`docs/user-guide/backup-and-restore.md`、`plugin/sand-iam/tests/backup_recovery_evidence_non_pg_test.php`、`plugin/sand-iam/tests/backup_restore_command_guard_non_pg_test.php`、`plugin/sand-iam/tests/external_acceptance_template_non_pg_test.php`、`tools/validate-backup-recovery.php`、`tools/prepare-external-acceptance.php`、`tools/README.md`。它们不计 G，且当前工作树仍不能作为最终 clean 来源。发布仍为 **0/10**。
