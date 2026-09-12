# SandIAM 终极验收原子账本（2026-09-08 历史基线）

> 冻结日期：2026-09-08。本文固定 `9 + 20 + 7 + 4 + 8 = 48` 个 FLOW 原子的名称、完成定义和当日计分基线。它是在原子名称遗失后，依据当时的产品需求、终极目标、验收矩阵、发布执行单、T09–T12 契约和现存证据完成的**基线重建**；不是对 2026-08-23 历史清单原文的伪造还原，也不把 2026-09-07/08 的候选或恢复状态冒充 P1 当前状态。后续[模块实现关口归位审计](sand-iam-module-implementation-gate-audit-2026-09-08.md)不改变 P01–P20 名称、产品范围或 20 项分母，只把误混入 P 的真实供应商、标准客户端、宿主 HTTP、浏览器、业务应用、恢复/性能和部署证据归还 F/L/D。

## 2026-09-12 完整开源成品 Goal 当前基线

### 签名链初审 checkpoint（2026-09-12）

- 签名链初审为 **3P2**；修复后 Astra 复核 **ACCEPT（P0/P1/P2=0）**，helper SHA-256 为 `78f9…`。
- 临时测试签名仅是非正式测试材料。受控目录、同 UID TOCTOU、真实密钥信任、Git 独立重建分别是未闭合的信任边界；正式 signing 尚未执行。
- 不改变计分：发布 **0/10**、FLOW **28/48**，F/L/D 不变。

本节是当前状态入口；下方 2026-09-08 表格保留为历史证据索引。0.7.0 的 `fa344cd`/tree
`6465…` 与 v70/v71 摘要均为历史基线；旧结论只有在本轮绑定到当前
SandIAM 源码树、候选包和适用运行环境并逐原子复核后才可继承，不因旧任务曾勾选而自动通过。`0.7.0-v71` 仅是历史 0.7.0 材料，不能作为 0.7.1 证据。

| 当前事实 | 只读证据 | 结论 |
| --- | --- | --- |
| SandIAM 权威源码 | A′ 已提交 `61a7f13821980deca8479f9c9e5e872be92cf72a`，独立范围复核 ACCEPT、未 push；当前工作树 non-clean，有 22 项 tracked changes 与 6 个 untracked path roots | 主树 integrity 25/26，唯一失败为 clean/tracked；最终 commit/tree/ZIP 均 pending |
| SandAdmin H1 基线 | `sandadmin-host.lock` 锁定 clean revision `558d92959947230ee562f29e015c62566be58c8e` | 仅证明宿主文件基线，不证明插件已同步、安装或运行 |
| 包内一致性 | 当前静态状态：safe **114**、PHP lint **507**、package **24/24 PASS**；Composer **58** 与 TypeScript `dist` **4** 已双隔离重建并完成锁校验 | 仅证明当前源码包内部契约，不证明生命周期 |
| 冻结 review-only artifact | v12 内容自洽；旧 verifier 对 manifest/validation 自报 `release/unsigned`、clean committed source/hygiene PASS | 独立 Astra 发现 verifier 只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件；自报已被推翻，v12 仅为历史快照，不能作为正式来源或升级包 |
| 历史 review-only 清单 | `0.7.0-v70/v71` payload 相同；v70 archive `6cae3a2f…cfd97cf82`、635 entries、descriptor-excluded payload `4bbf9289…` | 仅作历史 0.7.0 证据，不能作为 0.7.1 证据；v71 B 已因 rsync size+mtime 假阴性被独立 REJECT |
| 开源材料 | 已有 `CHANGELOG.md`、79/79 组件含 SPDX 与许可证证据引用的 `SBOM.cdx.json`、精确坐标许可证策略、`THIRD_PARTY_NOTICES.md`、`SECURITY.md`、`CONTRIBUTING.md` 和八份公开中文指南 | v12 来源完整性仍 REJECT；主树 integrity 25/26；发布材料门槛未通过 |
| 旧循环 | Codex 与 Cursor Autopilot/DETECT 均已 `enabled=false` | 旧任务不会作为当前 Goal 的自动执行入口 |

### 当前 FLOW 复核计分

| FLOW 关口 | 本轮已复核/总项 | 说明 |
| --- | ---: | --- |
| 需求/架构/票据 | **8/9** | R01–R08 已按当前文档与 R08 9/9 门禁复核；R09 仍缺双方实测 |
| 模块实现 | **20/20** | 已按[2026-09-12 模块审计](sand-iam-module-implementation-gate-audit-2026-09-12.md)逐项复核；P18 在修复 Dart SDK 后通过 |
| 正式 FLOW 验收 | **0/7** | 当前候选尚无完整七维真实证据 |
| 本地业务闭环 | **0/4** | 当前候选尚无完整业务闭环证据 |
| 可上线部署 | **0/8** | dirty workspace，未完成正式生命周期、恢复、稳定性或发布审查 |
| 当前 Goal 总计 | **28/48** | 本轮从 0/48 逐项复核恢复，非自动继承旧结论 |

### 可发布完整交付包门槛

这 10 项是发布判定，不并入 48 项 FLOW 分母；只有全部通过且无发布阻塞缺陷，才能称“可发布”。

| 发布门槛 | 当前状态 |
| --- | --- |
| 安装升级 | **0/1 未通过** |
| 七条真实业务链 | **0/1 未通过** |
| 四角色页面体验 | **0/1 未通过** |
| 权限安全 | **0/1 未通过** |
| 协议互操作 | **0/1 未通过** |
| 非 AI 业务应用 + 机器调用服务接入 | **0/1 未通过** |
| 并发与备份恢复 | **0/1 未通过** |
| 同一最终候选 24 小时稳定性 | **0/1 未通过** |
| Casdoor 三旅程各两轮对照 | **0/1 未通过** |
| 干净、可复现、许可/签名/文档一致的发布包 | **0/1 未通过** |

当前发布门槛：**0/10**。已部署：**否**。线上验证：**未执行**。

### Endurance v2 checkpoint（2026-09-12）

本次按 `write-gate begin --replace` 归档既有 endurance contract checkpoint：v1 审计为 **4P1 + 3P2**；v2 分三批修复，最终经 Astra 工具复核 **ACCEPT（P0/P1/P2=0）**。这只是离线 contract/tool 结构收口，不改变任何 FLOW 或发布门槛。

边界必须与计分同时保留：这是协作式可信环境协议；JSONL 哈希链仅提供完整性和篡改可见性，不是签名、身份认证或防伪；Git 未独立重建，collector/probe 真实性依赖受控环境、独立保管和可信对端。所有 fixture 均短于 86400 秒，真实同一最终候选 24 小时长跑尚未开始（**0**）。external validators 的 fixture 修复即使离线结构 ACCEPT，也不代表 ready。发布仍 **0/10**，FLOW **28/48**，F/L/D 不变。

## 2026-09-11 pre-P1 状态

- SandAdmin clean-host 基线 H1 已锁定为 `558d92959947230ee562f29e015c62566be58c8e`；`sandadmin-host.lock` 已记录该 revision，宿主文件同步后的 dry-run 为零文件变化。
- SandIAM P1 的源码 revision、候选 package SHA 和可信来源尚未冻结。因此，H1 的锁定与文件同步只证明宿主文件基线，不证明插件候选、数据库生命周期、真实 HTTP、浏览器业务链、可部署或线上状态。
- 下文的 `v24 review candidate`、`17-file demo`、identity-bound Gate A、升级票据 `5/7` 和恢复 `2/8` 均为 2026-09-07/08 历史快照。它们保留用于追溯，不增加 2026-09-11 的 F/L/D 分子，也不得替代新的 P1 revision、package SHA 和同一候选验收证据。

## 1. 计分规则与 2026-09-08 结论

- 分母保持不变；本次恢复稳定 ID、完成定义和证据边界；R08 的规格矩阵静态门禁 **9/9** 且独立复核 ACCEPT，计入需求冻结分子，但不使任何 P/F/L/D 原子通过。
- 同一事实可以支持多个原子的判断，但一个原子在所属关口只计一次。业务链映射、升级票据和恢复步骤只用于追踪，不增加 FLOW 分子或分母。
- `✅ 通过` 只表示满足该原子的完成定义。`△ 部分`、`◻ 未核验` 和历史证据都按未通过计分。
- 允许证据必须能定位到当前权威源码、当前候选、当前测试报告或带日期的运行记录；模糊叙述、旧候选、旧宿主状态和他方自报完成均不计分。
- 示例中的组织、应用或品牌名称只能作为可清理的 demo 值；产品功能名称、说明和验收条件保持行业中立。

| FLOW 关口 | 2026-09-08 通过/总项 | 百分比 | 本账本范围 |
| --- | ---: | ---: | --- |
| 需求/架构/票据 | **8/9** | **88.9%** | R01–R09 |
| 模块实现 | **20/20** | **100.0%** | P01–P20 |
| 正式 FLOW 验收 | **0/7** | **0%** | F01–F07 |
| 本地业务闭环 | **0/4** | **0%** | L01–L04 |
| 可上线部署 | **0/8** | **0%** | D01–D08 |
| 完整目标 | **28/48** | **58.3%** | 上述五关口之和 |

2026-09-08 的计划审阅候选是 `v24 review candidate`（`candidate/dirty-not-release`）。artifact、entries、哈希与同快照复现证据只认当时构建输出，不在本账本预写；当时升级票据是 `5/7`，实际恢复子检查是 `2/8`，两组数字都不属于上述 48 项。`17-file demo` 静态白名单同步当时已完成，但 identity-bound Gate A 在该快照中为 **`BLOCKED`**：活跃失败候选缺少 `candidate_archive_sha256`、`candidate_payload_manifest_sha256`、`recovery_descriptor_sha256`、`update_sql_sha256` 四项真实 candidate identity 摘要，当时 `update.sql` 与 v24 不同，且当时的 `verify` 会写 `FailedUpgradeRecoveryAudit`，故未执行 verify。compatibility probe 当时为 **PASS**，但仅使用 probe/test identity，不能替代正式 Gate A；该宿主问题随后交给 SandAdmin/SandPackage。该快照未执行数据库、registry、runtime recovery、浏览器与业务闭环。SandAI 的 L03 证据须从 `sand_ai` 工作区按当前候选重新核验后回填。P18 的 SDK 可消费修复在该快照中按模块实现关口计分，但不构成真实业务应用、宿主、浏览器、部署或线上验收。`v7`、`v9`、被拒绝的 `v13` 以及被后续候选替代的 `v14–v23` 只能作为历史审计材料，不能代表 P1 最终源码。

## 2. 需求、架构与票据 R01–R09

| ID | 原子项与完成定义 | 允许证据 | 不计分证据 | 2026-09-08 证据与状态 |
| --- | --- | --- | --- | --- |
| R01 | **产品定位与双平面。** 明确 SandAdmin 管理平面、SandIAM 应用用户/机器运行平面及各自会话边界。 | 经审查的产品目标、运行面/API 路由契约。 | 菜单截图、口头说明。 | [产品需求](../product/sand-iam-product-requirements.md)、[终极产品目标](../product/sand-iam-terminal-product-goal.md)、[路由权威表](sand-iam-api-route-registry.md)已冻结。**✅ 通过** |
| R02 | **领域归属与行业中立边界。** 身份、授权、审计归 SandIAM；业务实体、SandAI 执行和 SandLicense 商业许可不进入本域。 | 产品/架构文档中可执行的归属表和禁止项。 | 仅凭目录名或表名前缀推断。 | [产品需求](../product/sand-iam-product-requirements.md)、[接入边界](../architecture/sand-iam-access-boundary.md)、[终极目标第 5 节](../product/sand-iam-terminal-product-goal.md)已明确。**✅ 通过** |
| R03 | **PostgreSQL 与对象契约。** 命名、主外键、组织/应用边界、秘密治理、迁移和卸载规则可执行。 | P0/开发入口/协作契约及当前迁移规范。 | 旧 MySQL 示例、仅有 DDL 数量。 | [开发入口](sand-iam-development-entry.md)、[P0 契约](sand-iam-p0-contract.md)、[协作约定](sand-iam-pg-collaboration.md)已冻结。**✅ 通过** |
| R04 | **API 与语义动作权威来源。** 管理面、应用用户面、机器运行面路径和语义动作只有一个当前来源。 | 路由权威表与治理契约逐项对账。 | 旧 `/account`、`/authorize`、`/context`、`/scim/v2` 草案。 | [路由权威表](sand-iam-api-route-registry.md)和[接口治理契约](sand-iam-api-governance-v0.1.md)已收敛。**✅ 通过** |
| R05 | **终极能力差距与 T09–T12 票据。** 应用体验、生命周期、协议互操作、开发者/安全运营均有独立任务和契约。 | 缺口表、T09–T12 契约、任务板依赖。 | 将 T01–T06 候选泛化成终极完成。 | [Casdoor 基线缺口](sand-iam-casdoor-baseline-gap.md)及 T09–T12 四份契约已存在。**✅ 通过** |
| R06 | **产品体验与七条业务链。** 行业中立文案、首次使用、入口边界、七链完成条件和证据层级明确。 | 产品体验契约、终极管理端信息架构、验收编排契约。 | 页面存在、构建通过、内存模拟。 | [产品体验契约](sand-iam-product-experience-contract.md)、[管理端信息架构](sand-iam-terminal-admin-ia.md)、[七链编排器说明](sand-iam-seven-chain-acceptance-runner.md)已冻结。**✅ 通过** |
| R07 | **发布与授权边界。** 安装、升级、卸载、宿主、浏览器、业务联调、恢复和发布各自有条件与授权门。 | 发布执行单、任务板、证据模板。 | 把离线包 ACCEPT 当成可部署。 | [发布执行单](sand-iam-terminal-release-runbook.md)和[任务板](sand-iam-task-board.md)已区分各结论。**✅ 通过** |
| R08 | **T09–T12 末端验收规格全冻结。** 每项页面、字段、错误恢复、标准客户端/外部依赖和清理条件完成逐项复核，且无未决入口。 | 四份契约 + 管理端 IA + 路由表的独立审查记录。 | 源码候选、页面任务完成、PostgreSQL 单项通过。 | [T09–T12 末端验收规格冻结](sand-iam-t09-t12-terminal-spec-freeze.md)登记 12 个原子；静态门禁 **9/9**，矩阵、唯一 ID、四票据分布、契约字段/错误、当前与 planned API、外部门槛和旧路径均通过，并经独立复核 ACCEPT。此项只证明需求规格冻结；真实页面、HTTP、供应商/目录/Realm/NAS/业务应用仍是 P/F/L/D 门槛。**✅ 通过** |
| R09 | **开发者旅程比较基线。** 三条旅程在 SandIAM 与 Casdoor 同等环境均留下时间、操作数、失败和清理记录。 | 两边同环境实测原始记录和汇总。 | 设计自评、单边计时、功能数量比较。 | [开发者旅程验收](sand-iam-developer-journey-acceptance.md)已有 12 轮结构化证据校验器，但没有双方实测。**◻ 未通过** |

R01–R09 在 2026-09-08 的严格计数为 **8/9**。R08 已按静态门禁 **9/9** 与独立复核 ACCEPT 计入需求冻结；R09 仍未通过。本项不使任何 P/F/L/D 原子通过。

## 3. 产品模块 P01–P20

### 3.1 原子边界

**P 统一最低完成定义（覆盖本节每行旧的“允许证据”措辞）：** 当前权威源码已闭合原子，必要 schema/migration 文件在根与插件载荷存在，至少一个公开契约或可观察行为测试存在并有适用的静态/构建证据。迁移文件必须存在，但 P 不执行数据库。真实供应商/目录、标准客户端、认证浏览器、真实宿主 HTTP、真实业务应用、worker/性能、备份恢复、同步、发布和部署只属于 F/L/D，均不得阻断 P。源码文本检查只能以 `behavior-test-gate: static-rule` 身份作辅助，不能单独计分。逐项当前源码、migration、行为测试和本轮命令见[模块实现关口归位审计](sand-iam-module-implementation-gate-audit-2026-09-08.md)。

P01–P11 是旧分子中明确留下名称的 11 项，本次逐一映射，不拆分、不合并、不重复计数。P12–P20 是依据当前权威能力域重建的剩余 9 项：它们用 2026-09-08 名称向前冻结，不声称是遗失历史清单的原文。

- P03 把本地认证与会话视为一个既有原子；P04 只管 MFA/Passkey。
- P05 只管 LDAP/SCIM 协议端点、协议资源与兼容性，不包含目录驱动、调度、游标或重试；P14 只管这些目录驱动的生命周期同步运行，并引用 P05 的协议能力，不重复验收协议端点。P12 只管外部 OAuth/OIDC/SAML 联合身份；P15 只管 DCR、OIDC 登出、CAS、Kerberos、RADIUS 等互操作补齐。
- P07 是用户类型/角色/RBAC；P08 是 ABAC/条件/数据范围；P14 的用户组只作为生命周期同步目标，不重复计算角色授权引擎。
- P02 是核心组织/应用/环境对象；P16 是跨资源的委派管理范围与撤权。
- P13 只管管理员配置的应用登录体验和认证事务消息；P20 只管独立应用用户自助门户及用户可见操作，不重复管理配置。
- P17 只管通用业务/安全事件订阅 Webhook、投递、签名、重试、保留、告警和排错；不包含 P13 的验证码、邀请、恢复等认证事务消息，也不重复计算每个模块必须写审计的基础要求。

### 3.2 原子表

| ID | 产品模块与最低实现完成定义 | 允许证据 | 不计分证据 | 2026-09-08 证据与状态 |
| --- | --- | --- | --- | --- |
| P01 | **双平面边界。** 管理员、应用用户、机器身份使用正确会话与失败关闭边界。 | 当前路由/中间件/身份上下文源码及行为测试。 | 架构图本身。 | [路由权威表](sand-iam-api-route-registry.md)与当前运行面实现映射完整；沿用冻结分子。**✅ 已计分** |
| P02 | **组织/应用/环境。** 核心对象 CRUD、隔离、停用影响和审计已实现。 | 当前管理 API、服务/模型、隔离 PostgreSQL 记录。 | 旧宿主页面可见。 | [P0 契约](sand-iam-p0-contract.md)、[管理 API](sand-iam-management-api-v0.1.md)及任务板 IAM-04 记录支持；沿用冻结分子。**✅ 已计分** |
| P03 | **本地认证与会话。** 注册、验证、登录、刷新轮换、锁定、重置、改密撤销和退出已实现。 | 当前服务/API、真实 ORM 测试、隔离 PostgreSQL 生命周期记录。 | 只有登录页或接口 200。 | [人类认证契约](sand-iam-human-auth-api-v0.1.md)及 `IAM-T01` 执行记录支持；沿用冻结分子。2026-09-12 增加 refresh 响应丢失 30 秒密文恢复，非 PG 行为测试覆盖正常恢复、错 token/request id、篡改及过期；密码登录又将限流、captcha、失败计数/审计及成功会话/MFA challenge 按同一请求指纹原子化，同一成功或失败意图重试不重复产生副作用。真实 ORM 顺序断言与双进程 PostgreSQL 竞争用例已写但未获授权执行，不新增分子。**✅ 已计分** |
| P04 | **MFA/Passkey。** TOTP、恢复码、WebAuthn 注册/挑战/验证/撤销及重放拒绝已实现。 | 当前 API/服务、密码学夹具、隔离 PostgreSQL 记录。 | 仅生成二维码或静态表单。 | [MFA/Passkey 契约](sand-iam-mfa-passkey-api-v0.1.md)及 `IAM-T02` 记录支持；沿用冻结分子。2026-09-12 增加 Passkey 注册 finish 的同请求幂等事务，集成测试断言只创建一把 credential 并写一条成功审计；MFA/Passkey 登录 finish 又补齐 30 秒同请求会话令牌密文恢复，断言不会重复消费恢复码、推进 signCount、创建会话或审计，错请求/载荷及新 request id 重放拒绝；Passkey authentication options 进一步把挑战创建、限流、审计和密文恢复原子化，同请求返回同一 challenge 且不重复占用限流，已消费或过期挑战拒绝恢复。因未获数据库授权均未重跑，不新增分子。**✅ 已计分** |
| P05 | **LDAP/SCIM 协议能力。** LDAP 查询/绑定边界与 SCIM Users/Groups/token 端点、协议资源、创建/更新/停用语义及兼容性已实现；不负责目录驱动调度。 | 当前 LDAP/SCIM 协议服务、端点契约、资源正负集成测试和隔离 PostgreSQL 记录。 | Syncer 调度、游标、重试、具体目录驱动或只存在配置表。 | [SCIM 契约](sand-iam-scim-api-v0.1.md)与[终极验收矩阵](sand-iam-terminal-acceptance.md)的协议服务级证据支持；沿用冻结分子。**✅ 已计分** |
| P06 | **OAuth/OIDC Provider。** Code+PKCE、client credentials、discovery/JWKS/userinfo、refresh/revoke/logout 已实现。 | 当前协议控制器/服务、ORM/controller 测试和隔离生命周期。 | 自写 token 样例或 discovery 文本。 | [OAuth/OIDC 契约](sand-iam-oauth-oidc-api-v0.1.md)及 `IAM-T03` 记录支持；沿用冻结分子。**✅ 已计分** |
| P07 | **用户类型/角色/RBAC。** 应用边界内的用户类型、角色、资源动作和拒绝优先关系已实现。 | 当前授权源码、契约/集成测试、隔离数据库记录。 | 菜单按钮显隐。 | [授权契约](sand-iam-authorization-contract.md)及任务板既有 11 项映射支持；沿用冻结分子。**✅ 已计分** |
| P08 | **ABAC/数据范围。** 条件、scope、策略版本/优先级、解释边界及后端强制执行已实现。 | 当前授权器/ScopeMatcher、正负行为测试、数据库策略记录。 | 前端筛选、复制生产算法的测试。 | [授权契约](sand-iam-authorization-contract.md)及验收矩阵的内核证据支持；沿用冻结分子。**✅ 已计分** |
| P09 | **接口/路由授权。** 语义动作、API 目录、路由绑定、清单预检/确认、策略模拟和中间件已实现。 | 当前治理源码、路由对账、PostgreSQL 集成和停用失败关闭。 | URL 列表、OpenAPI 文档单独存在。 | [接口治理契约](sand-iam-api-governance-v0.1.md)支持；沿用冻结分子。**✅ 已计分** |
| P10 | **机器身份上下文。** workload client、凭证、service grant、audience/action 和短期 context 的签发、验证、撤销已实现。 | 当前 runtime 源码、凭证服务、行为/集成测试。 | 只创建 client/credential 记录。 | [P0 契约](sand-iam-p0-contract.md)和当前 `IdentityContextProvider`/凭证实现支持；沿用冻结分子。**✅ 已计分** |
| P11 | **机器调用约束。** quota、network、data_class 和调用事实解析实际进入授权决定。 | 当前 030 迁移、调用授权源码、正负集成测试。 | 可保存 JSON 但未执行。 | [开发者与安全运营契约](sand-iam-developer-security-operations-v0.1.md)与任务板 030 证据支持；沿用冻结分子。**✅ 已计分** |
| P12 | **外部联合身份。** 外部 OAuth/OIDC/SAML 的配置、属性映射、绑定/解绑、冲突与失败审计形成完整实现。 | 统一 P 最低定义；具体证据见审计。 | 仅 handoff DTO、假 IdP、配置表。 | `FederationService`/`FederationController`、`006/007` 与 `federation_ab_integration_test.php` 已闭合模块正负行为；真实 IdP/HTTP/标准客户端留 F/D。**✅ 通过** |
| P13 | **应用登录体验与认证事务消息。** 管理员可配置品牌、登录/注册方式和字段，以及 Email/SMS/Captcha/Notification Provider、模板、测试与认证事务投递；不包含应用用户自助门户或通用业务事件 Webhook。 | 统一 P 最低定义；具体证据见审计。 | 终端用户 profile/session/MFA 页面、通用事件订阅、静态品牌字段或 fake transport 单独通过。 | `ApplicationExperience`/`MessageProvider` 源码、`011/025` 与 `message_provider_pg_integration_test.php`；本轮 crypto 行为和 static-rule 通过。真实供应商/HTTP/页面留 F/D。**✅ 通过** |
| P14 | **目录驱动的用户生命周期同步运行。** 基于 P05 的 LDAP/SCIM 协议能力，完成目录驱动、调度、游标、增量/全量运行、冲突、重试/恢复、停用传播，以及用户/用户组生命周期同步；不重复验收 P05 的协议端点。 | 统一 P 最低定义；具体证据见审计。 | LDAP/SCIM 端点通过、单一 CSV 解析、假驱动或只建用户组表。 | `SyncConnectorService`、remote drivers、`015/026` 与既有 `sync_connector_pg_integration_test.php` 覆盖服务的游标、冲突、停用与审计；v70 保留远端拒绝、驱动异常和不可解密 outbox 的有界 failed 终态、脱敏查看、同应用幂等人工重试及 running 互斥。纯策略行为与契约已通过，新增 PostgreSQL 断言本轮未获授权执行。`DirectorySyncWorker`/`DirectorySyncScheduler` 使用进程生命周期 keyset cursor 和有界尾部 wrap；`directory_sync_worker_behavior_non_pg_test.php` 验证真实 wiring、跨组织/同组织公平、retry state 与 stop。停止只保证当前调用返回后不再领取新连接。运维前提见[worker 运维说明](directory-sync-worker-operations.md)。既有模块结论保持 **ACCEPT**；未运行真实 worker/目录/宿主/HTTP，仍留 F/L/D。**✅ 通过，计入 P**。 |
| P15 | **协议与会话互操作补齐。** DCR、OIDC 前/后通道登出、CAS、Kerberos/SPNEGO、RADIUS 达到各自声明范围。 | 统一 P 最低定义；具体证据见审计。 | 自写协议单测、迁移可安装。 | `016–018` 与 authority service/API/schema 已闭合；本轮公开输入/输出和 fail-closed 负例通过。部分局部安全测试使用私有函数反射，只作辅助，不称完整协议运行验证。标准客户端/Realm/NAS/宿主 HTTP 留 F/D。**✅ 通过** |
| P16 | **管理委派。** 平台、客户主体、应用管理员的范围读取/写入、撤权即时失效和跨范围拒绝覆盖全部适用资源。 | 统一 P 最低定义；具体证据见审计。 | 只有组织授权表或一个页面过滤器。 | `AdminOrganizationAccess` 与资源守卫、`001/009`；本轮真实 controller 行为测试和 `delegation_webhook_integration_test.php` 覆盖范围/撤权。真实页面留 F/L。**✅ 通过** |
| P17 | **通用业务/安全事件 Webhook 与审计运营。** 固定事件目录、事务 outbox、订阅、签名投递、失败重试、保留/归档、告警和排错形成完整模块；不承接 P13 的认证事务消息。 | 统一 P 最低定义；具体证据见审计。 | 验证码/邀请/恢复消息 Provider、手工 POST、只读审计列表或内存模拟。 | `EventCatalog`/`WebhookService`/worker、`010/019/027`，以及 outbox/retry/audit 行为与本轮 transport/crypto 测试。接收器、并发、告警/备份留 F/D。**✅ 通过** |
| P18 | **开发者生态。** PHP、TypeScript、Dart/Flutter SDK、Webman 中间件、CLI、管理 OpenAPI 和诊断由真实示例消费。 | 统一 P 最低定义；具体证据见审计。 | SDK 编译、生成文档或 snippet 单独通过。 | **P18-BLOCKER-TS-PACKAGE-01 独立 ACCEPT**：v17 ZIP 598 entries、checker **23/23**、ZIP 解包真实消费者 loopback allow/403/401；本轮 OpenAPI/SDK catalog 静态门禁通过。独立业务应用/宿主/浏览器/部署留 L/F/D。**✅ 通过**。 |
| P19 | **初始化包。** manifest 预检、差异、确认、草稿保存/续改/停用、幂等应用、漂移拒绝和逆序回滚可用。 | 统一 P 最低定义；具体证据见审计。 | 只有 JSON schema、SQL install 或纯函数测试。 | `InitializationService`、独立草稿/修订模型、`020` 与 `037`、显式 `draft-index|draft-read|save|update|disable` 路由和管理端页面已闭合；行为测试覆盖秘密拒绝、幂等保存、乐观版本、历史不变、并发冲突、停用、错误确认零副作用、漂移拒绝和多资源逆序回滚。后端独立复核与前端独立复核均通过，P0/P1/P2 为 0；真实 PostgreSQL、宿主和浏览器仍归 F/L/D。**✅ 通过** |
| P20 | **独立应用用户自助门户。** 应用用户在非 SandAdmin 会话中完成资料、会话列表/撤销、改密、MFA、Passkey、恢复和安全状态等用户可见操作；不重复 P13 的管理员登录体验配置。 | 统一 P 最低定义；具体证据见审计。 | 管理端品牌/Provider 配置、SandAdmin 后台页面、源码/构建/路由映射或旧截图。 | 权威 `app/service/SelfServiceService.php`、`app/api/controller/SelfServiceController.php`、`app/api/controller/AccountPortalController.php` 与 `portal/src/*`；根及插件载荷 `003_human_auth_core.pgsql`、`004_mfa_passkey.pgsql`、`006_federation_directory_scim.pgsql`、`007_federation_handoff.pgsql`。2026-09-08 运行 `php .../self_service_behavior_non_pg_test.php`：生产 `SelfServiceService` 的公开 profile/update/connections/security overview 验证资料更新、应用/身份隔离、事件/审计及撤销/过期会话、MFA/Passkey 计数边界；`self_service_non_pg_contract_test.php` 只作辅助。P03/P04 不替代本证据。demo/HTTP/多视口浏览器及实际撤销操作仍留 F/L。**✅ 通过** |

P01–P20 在 2026-09-08 的严格计数为 **20/20**。P14 与 P19 均经独立最终复核 ACCEPT 并计入 P；P19 的草稿持久化、管理端交互和回滚公开行为已补齐，前端隔离副本的 ESLint、`vue-tsc --noEmit` 与 Vite build 通过。既有真实 service PostgreSQL 行为证据当轮未重跑，`037` 也未执行。P20 已由独立生产 service 公开行为测试计分，不以 P03/P04 或 static-rule 替代。真实宿主、浏览器、业务应用、外部协议/供应商、恢复和发布仍分别留在 F/L/D，不能因 P 通过而提前通过。

## 4. 正式 FLOW F01–F07

F01–F07 是横跨适用模块和业务链的七个**证据维度**，不是升级票据七项，也不是 C01–C07 七条业务链。一个 F 原子只有在当前同一候选范围内，对所有适用链路都完成该纵向证据后才通过；局部链路不能按比例折算。

| ID | 正式验收原子与完成定义 | 允许证据 | 不计分证据 | 2026-09-08 证据与状态 |
| --- | --- | --- | --- | --- |
| F01 | **真实页面与入口。** 当前已安装候选的管理端/应用用户端所有声明入口可达，角色、状态、错误恢复和目标视口逐项通过。 | 同一宿主、同一候选、带 URL/角色/视口/时间的浏览器记录。 | 源码、Vite build、路由 54/54、旧截图。 | 快照中的 `17-file demo` 静态白名单同步已完成；当时未执行正式 Gate A、宿主安装与浏览器验收。**◻ 未通过** |
| F02 | **真实宿主 API。** 页面背后的管理、应用用户、协议和机器 API 在真实 SandAdmin/Webman 宿主按当前路由运行。 | 请求/响应、状态码、request ID、候选/宿主身份和数据库后置状态。 | controller 单测、直接调服务、历史 401/503/200。 | 截至该快照未重新完成登录后宿主 HTTP。**◻ 未通过** |
| F03 | **允许与拒绝。** 每个适用原子同时验证合法请求允许、跨组织/应用/角色/范围/状态请求稳定拒绝，前端不替代后端。 | 同一夹具的页面动作、API 正负请求和后端决定。 | 只测允许、只测按钮隐藏、内存状态机。 | 七链仅有受控模拟和局部隔离测试。**◻ 未通过** |
| F04 | **审计关联。** 允许、拒绝和管理变更均产生可查询、脱敏且 request ID 可关联的审计/事件事实。 | 页面/API/提供方或业务端与审计查询的对应记录。 | 日志字符串、手工造审计、只验证表存在。 | 快照中没有七链真实宿主的逐请求审计对账。**◻ 未通过** |
| F05 | **撤销与恢复。** 会话、因子、凭证、授权、委派、协议会话和失败升级按声明撤销/恢复，旧权限不再生效。 | 撤销/恢复前后 API、页面、审计与持久状态。 | 只改 status、只显示成功提示、旧候选恢复。 | 快照恢复为 2/8，官方核验当时仍被业务状态拒绝；其余撤销未做同候选全量复核。**◻ 未通过** |
| F06 | **夹具清理与零残留。** 每条链按正常业务接口先撤销再清理，精确对象、关系、审计保留边界和零残留查询全部通过。 | 创建清单、清理清单、数据库/接口后置查询、重复清理。 | 事务回滚、人工 SQL、内存 cleanup。 | 模拟链有 cleanup 契约，但 live driver 未执行。**◻ 未通过** |
| F07 | **同一候选可重复复核。** F01–F06 使用同一候选、同一证据清单可重复运行，报告明确失败项、残留和未验证项。 | 候选 SHA、宿主同步证明、重复运行报告和差异为零。 | 离线 ZIP 可重复构建单独通过。 | 2026-09-08 的 v24 构建证据以当时构建输出为准；宿主、数据库、浏览器与 live 报告缺失。**◻ 未通过** |

F01–F07 在 2026-09-08 的严格计数为 **0/7**。

## 5. 本地业务闭环 L01–L04

| ID | 闭环与完成定义 | 允许证据 | 不计分证据 | 2026-09-08 证据与状态 |
| --- | --- | --- | --- | --- |
| L01 | **管理员配置闭环。** 平台管理员/被委派管理员完成客户主体、应用、身份/授权/通知配置，越权拒绝、撤权、审计和清理。 | 真实宿主页面 + API + 数据/审计后置状态。 | 管理 API 单测、页面截图。 | 截至该快照未形成完整证据。**◻ 未通过** |
| L02 | **应用用户认证闭环。** 独立应用入口完成注册/邀请、登录、会话、MFA/Passkey、自助、撤销后拒绝和清理。 | 应用用户浏览器会话 + API + 审计 + 清理。 | SandAdmin 后台登录、内存模拟。 | 截至该快照未形成完整证据。**◻ 未通过** |
| L03 | **SandAI 服务接入闭环。** 已授权调用成功，无授权/错 audience/action/撤销后失败，SandIAM 与 SandAI 审计关联。 | 真实 SandAI API 副作用与双侧审计。 | Adapter 单测、catalog 登记。 | 快照中 A-01/SAND-113F 尚未完成；证据须从 `sand_ai` 工作区按新候选重新核验后回填。**◻ 未通过** |
| L04 | **非 AI 业务应用闭环。** 独立业务应用用 SDK/中间件完成登录、真实资源 allow/deny/scope、跨应用隔离、审计和清理。 | 真实业务 API、业务状态、SandIAM 决策与审计。 | 示例代码、模拟资源、前端按钮隐藏。 | 截至该快照没有真实接入记录。**◻ 未通过** |

L01–L04 在 2026-09-08 的严格计数为 **0/4**。

## 6. 可上线部署 D01–D08

| ID | 部署原子与完成定义 | 允许证据 | 不计分证据 | 2026-09-08 证据与状态 |
| --- | --- | --- | --- | --- |
| D01 | **干净可重建源码。** 从干净、可追溯 revision 构建相同候选，来源、许可和构建环境明确。 | clean checkout、revision、构建命令、bit-identical SHA。 | 脏工作树 snapshot 单独可重复。 | 2026-09-08 的 v24 是 dirty candidate，缺干净 revision/发行来源。**◻ 未通过** |
| D02 | **完整插件包。** 根文件、完整 runtime、管理端、门户、SDK、迁移、恢复描述器、元数据和许可经过独立发布审查。 | 最终 ZIP 清单、独立 checker、签名/来源记录。 | 离线 package ACCEPT。 | 已有未参与开发者公开文档八步交付模板与关闭失败验证器；尚无最终许可、签名候选或真人执行记录。**◻ 未通过** |
| D03 | **当前宿主生命周期。** 演示宿主完成受控同步、安装/连续升级/重复升级/卸载、服务重载和无残留。 | 当前宿主、当前候选、数据库和服务证据。 | 早于 033 的 82 表、恢复前 83 表、其他插件生命周期。 | 快照中的 `17-file demo` 静态白名单同步已完成；当时未执行正式 Gate A、数据库/registry/runtime recovery 与宿主生命周期。**◻ 未通过** |
| D04 | **标准客户端与外部系统。** OIDC/SAML/LDAP/SCIM/CAS/Kerberos/RADIUS、消息/目录等按声明范围完成真实互操作。 | 标准客户端、临时 Realm/NAS、受控外部服务正负报告。 | 自写单测、fake transport。 | 已有候选绑定的七类互操作模板与关闭失败验证器，但没有标准客户端、真实对端或原始运行证据。**◻ 未通过** |
| D05 | **三角色 UI。** 平台管理员、应用管理员、应用用户在目标视口完成真实任务、错误恢复、撤权和无 404。 | 当前宿主多角色多视口浏览器记录。 | 隔离 build、旧实现记录或旧宿主截图。 | 截至该快照，最终候选未在宿主执行多角色、多视口验收。**◻ 未通过** |
| D06 | **备份恢复。** PostgreSQL 备份恢复到隔离目标后，授权、撤销、审计链和验签一致，恢复过程可回滚。 | 备份/恢复命令、校验、前后摘要、清理记录。 | 运行文件备份恢复、只读 preflight。 | 已有候选绑定模板和关闭失败验证器，但未执行数据库备份恢复演练。**◻ 未通过** |
| D07 | **安全与并发。** 秘密泄露扫描、限流/重放、并发消费/撤权/刷新、密钥轮换和 fail-closed 全部通过。 | 当前候选压力/并发/故障注入及安全报告。 | lint、静态安全规则、单线程测试。 | 历史 v70 的默认关闭 retention worker 已以有限批次覆盖过期 succeeded 幂等记录和过期认证限流窗口，pending/审计保留；目录 outbox 与 OIDC back-channel dead 都已补人工恢复并发门禁，OIDC 恢复重新签发令牌而不复用过期密文。24 小时两类 retention backlog 及 queue/unrecoverable backlog 都有精确 PostgreSQL 状态公式和零容忍阈值。相关 PostgreSQL 夹具未获授权执行，且尚无压力、并发、故障注入或 24 小时资源曲线，因此仍不计分。**◻ 未通过** |
| D08 | **回滚与发布。** 失败停止、候选替换、数据库/运行文件回滚、残留清理、发布审批和线上验证计划均闭合。 | 恢复/回滚演练、最终报告、审批与可追溯发行物。 | 升级票据 5/7、恢复 UI staging。 | 快照中的实际恢复为 2/8，未进入候选替换、重试、回滚发布结论。**◻ 未通过** |

D01–D08 在 2026-09-08 的严格计数为 **0/8**。

## 7. 七条业务链 C01–C07 映射

C01–C07 是产品体验契约中的业务场景，不是新的计分关口。每条链都必须穿过 F01–F07；下表中的 R/P/L/D 只是说明它验证哪些既有原子，不能据此重复加分。P01、P19 和 D01/D02/D08 是所有链的共同基础，未在每格重复展开。

| 链 ID | 业务链 | 主要产品原子 | 正式证据维度 | 本地闭环 | 主要部署原子 |
| --- | --- | --- | --- | --- | --- |
| C01 | 客户主体 → 接入应用 → 应用环境 → 查询、修改、停用或恢复 | P02 | F01–F07 | L01 | D03、D05 |
| C02 | 身份源 → 用户/邀请/导入 → P05 协议接入 → P14 目录驱动同步 → 用户组 → 角色 → 资源 → 策略 → 允许/拒绝 | P05、P07、P08、P12、P14 | F01–F07 | L01、L02 | D03、D04、D05、D07 |
| C03 | P13 登录/认证消息配置 → 登录 → P20 自助门户 → 会话 → MFA/Passkey → 撤销后拒绝 | P03、P04、P13、P20 | F01–F07 | L02 | D03、D05、D07 |
| C04 | 调用身份 → 服务授权 → 凭证 → 成功/无权调用 → 凭证撤销 | P10、P11 | F01–F07 | L03、L04 | D03、D07 |
| C05 | OAuth/OIDC PKCE → CAS → 应用业务动作 → 接口目录 → 路由绑定 → 路由清单预检/确认 → 策略模拟 → 实际调用与审计一致 | P06、P09、P15、P18 | F01–F07 | L03、L04 | D03、D04、D05、D07 |
| C06 | 管理委派 → 范围内操作 → 越权拒绝 → 撤权与追溯 | P02、P16 | F01–F07 | L01 | D03、D05、D07 |
| C07 | 通用业务/安全事件订阅 → 变化触发 → Webhook 成功投递 → 失败重试 → 审计与排错 | P17 | F01–F07 | L01、L02 | D03、D05、D06、D07 |

## 8. 2026-09-07/08 升级恢复子检查 REC01–REC08

本节只解释 2026-09-08 任务板快照中的“实际恢复 2/8”，不进入 48 项，也不替代升级票据 5/7。

| ID | 恢复步骤 | 2026-09-07/08 状态与证据 |
| --- | --- | --- |
| REC01 | 只读预检：候选、registry、备份、数据库目录状态和服务基线可追溯。 | **✅ 通过**。见 [`00-preflight.md`](../../../.artifacts/sandiam-actual-recovery-20260907/00-preflight.md)及后续 v15 预检锚点。 |
| REC02 | 恢复升级前运行文件并保留 quarantine/backup/审计，持久摘要匹配。 | **✅ 通过**。见 [`02-runtime-restore-stop.md`](../../../.artifacts/sandiam-actual-recovery-20260907/02-runtime-restore-stop.md)；UI 当时显示矛盾，因此不把它外推为官方流程通过。 |
| REC03 | 官方恢复核验返回可重试状态和唯一允许动作。 | **◻ 未通过**。2026-09-07 留存执行返回 `FAILED_UPGRADE_RECOVERY_BLOCKED`，见 [`04-v122-verify-business-reject.md`](../../../.artifacts/sandiam-actual-recovery-20260907/04-v122-verify-business-reject.md)。 |
| REC04 | 用官方流程选择并核验 v24 候选，完成候选替换。 | **◻ 未执行**。`17-file demo` 静态白名单同步不等同于候选替换；正式 Gate A、数据库/registry/runtime recovery 与浏览器验收均未执行。 |
| REC05 | 官方 UI 重试 `0.6.0 → 0.7.0`，数据库升级成功且 registry 状态正确。 | **◻ 未执行**。 |
| REC06 | 当时候选完成安装、重复升级、卸载和失败停止/回滚核验。 | **◻ 未执行**。 |
| REC07 | 当时宿主 HTTP、三角色浏览器、外部客户端和 C01–C07 全部通过。 | **◻ 未执行**。 |
| REC08 | 清理、残留、发布/回滚结论和证据索引完成。 | **◻ 未执行**。 |

REC01–REC08 在 2026-09-08 快照中为 **2/8**。后续只有对应步骤出现新的同候选证据时才更新；历史登录页、旧浏览器截图或旧候选结果不得改变此计数。

## 9. 更新纪律

1. 任何 FLOW 数字变化，先更新本账本对应 ID 的证据和状态，再同步任务板与终极验收矩阵。
2. 一个原子从未通过改为通过时，必须列出完成定义中的全部证据；不能只写“测试通过”。
3. 候选、宿主、数据库或页面发生变化后，旧动态证据自动降为历史，除非完成内容寻址的一致性证明且该原子允许复用。
4. 升级票据、REC 子检查或 C 业务链进度可以单独汇报，但不得加到 2026-09-08 基线的 **28/48**。
5. “实现完成”“正式 FLOW 通过”“本地闭环”“可上线”“已部署”“线上验证”分别下结论。

## 10. 2026-09-12 0.7.1 提交锚点（未 push）

- 已提交：`6e190953dc260f32e7428e751c3c6318dc9fcd8d`（HOST-202609-001）、`a7edcebb37ea06b2da3dc445d5b7307d3111a7ab`（生命周期）和 `1aba9b444ae8ec69972faa2c1e6fe8e6ebc32d48`（外部验收）。它们不构成任何 F/L/D 或 G 原子通过。
- 生命周期提交的非 vendor `git diff --cached --check` 为零；完整检查只有 18 个原样第三方 vendor 文件的 whitespace 报告，未改写其字节，故不记整体 diff-check 通过。
- BLOCKED-B 的 7 个备份恢复文件未提交：`docs/user-guide/backup-and-restore.md`、`plugin/sand-iam/tests/backup_recovery_evidence_non_pg_test.php`、`plugin/sand-iam/tests/backup_restore_command_guard_non_pg_test.php`、`plugin/sand-iam/tests/external_acceptance_template_non_pg_test.php`、`tools/validate-backup-recovery.php`、`tools/prepare-external-acceptance.php`、`tools/README.md`；G 继续为未通过。
