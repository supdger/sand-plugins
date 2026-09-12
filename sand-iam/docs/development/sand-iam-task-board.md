# SandIAM 任务看板

## 2026-09-12 完整开源成品 Goal

### 签名链初审 checkpoint（2026-09-12）

- 签名链初审记录为 **3P2**；修复后由 Astra 复核 **ACCEPT（P0/P1/P2=0）**，helper SHA-256 记录为 `78f9…`（仅作该次 helper 身份锚点）。
- 初审结论仍受边界约束：临时测试签名仅为非正式测试材料；受控目录、同 UID 的 TOCTOU 防护、真实密钥信任与从独立 Git 来源重建必须分别具备证据。当前没有正式 signing、真实密钥信任或 Git 独立重建证据。
- 该 checkpoint 不改变任何 FLOW 或发布计分：正式 signing **未执行**，发布 **0/10**，FLOW **28/48**，F/L/D 计数不变。

- 当前 Goal 是唯一活跃执行目标；2026-09-08 及更早的 SandIAM/Cursor/Codex Autopilot 任务均为历史输入，不恢复其循环。
- 2026-09-12 当前态复核：Codex 任务列表中本工作区仅本 Goal 为 `active`，旧 SandIAM 任务均为 `notLoaded`；本机 automation 配置中没有 SandIAM/sand_plugins 定时项。该结论来自本轮只读任务列表与配置扫描，没有修改外部状态。
- 旧任务状态不得自动计入当前通过。可复用证据先绑定当前源码树、候选摘要和适用环境，再回写同一[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)。
- 当前复核计分：需求 **8/9**、模块 **20/20**、正式 FLOW **0/7**、本地业务闭环 **0/4**、可上线部署 **0/8**，合计 **28/48**；发布门槛 **0/10**。这是本轮逐原子复核后的新结论，不是旧任务自动继承。
- A′ 已按授权提交为 `61a7f13821980deca8479f9c9e5e872be92cf72a`，独立范围复核 **ACCEPT**，未 push；仅含白名单 43 files（A=4、M=39、D=0），无 migration/Vue/TS/越界路径。当前工作树 non-clean，有 22 项 tracked changes 与 6 个 untracked path roots；来源/事务修复仍未提交。主树 integrity **25/26**，唯一失败为 clean/tracked。
- v12 archive 内容自洽，但其旧 verifier 对 manifest/validation 自报 `release/unsigned`、clean committed source 与 hygiene PASS 已被独立 Astra 推翻：它只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件。v12 仅是历史快照，正式来源验收 **REJECT**，不得作为正式来源或升级包。Composer **58** 与 TypeScript `dist` **4** 已完成双隔离重建和锁校验。
- 两次测试选择器偏差已记录；只读 DB 复核只在时间窗口内未见可见写入，不能证明此前或窗口外无写入：86 tables、ledger 38 rows、max revision 37、revision 038 rows 0、runtime 仍 0.7.0。B′/C′/D′ 尚未执行，G 未授权；发布 **0/10**、FLOW **28/48**、业务链计数均不变，当前仍未部署、未线上验证。历史 `0.7.0-v70/v71` 摘要不能作为 0.7.1 证据。
- **Endurance contract checkpoint（write-gate begin --replace 归档）**：v1 审计记录为 **4P1 + 3P2**；v2 分三批修复，最终由 Astra 工具复核 **ACCEPT（P0/P1/P2=0）**。该 ACCEPT 只证明离线 contract/tool 修复，不是实际长跑通过。协议明确为协作式可信环境边界：JSONL 哈希链只提供完整性/篡改可见性，不是签名、身份认证或防伪；Git 来源未独立重建，collector/probe 的真实性仍依赖受控环境、独立保管和可信对端。所有 fixture 均不是 86400 秒；真实同一最终候选 24 小时运行尚未开始（**0**）。external validators 的 fixture 修复即使离线结构 ACCEPT，也不表示 ready。发布门槛仍 **0/10**，FLOW **28/48**，F/L/D 计数不变。

### 当前执行顺序

1. **OSS-00 基线与开源材料审计**：复核 48 个 FLOW 原子的可继承证据；完成依赖来源/许可证/再分发清单，提交宽松许可证方案供用户确认；修正文档中的过时状态。
2. **OSS-01 候选冻结与静态回归**：在不触碰数据库/宿主的前提下完成源码、依赖、包内容、默认关闭验收接口、秘密/本机路径/内部材料扫描和可复现构建预验收。
3. **OSS-02 起逐链执行**：每批只取一条完整业务链，先提交所需数据库写入、迁移、服务启停和宿主同步的精确一次性授权清单；完成后才进入下一链。
4. **OSS-03 外部与人工门槛**：标准客户端/真实受控对端、Casdoor 三旅程各两轮、未参与开发者公开文档安装接入、最终候选 24 小时稳定性。
5. **OSS-04 发布包终验**：10/10 发布门槛、独立终验、无阻塞缺陷；终点不含 push、正式 Release、部署或线上验证。

当前进展：OSS-00 已完成；OSS-01 的依赖/许可证审计已完成，源码已具备 Apache-2.0
`LICENSE`、`NOTICE`、私密漏洞报告入口和唯一 DCO 贡献机制，不再表述为 license/DCO 待确认。它们不消除来源、签名、生命周期或正式发布门槛。

OSS-02/OSS-03 的静态基线已完成；OSS-05 的外部门槛只完成 runner 预备：PHP lint **507/507**、
non-PG/contract **114/114**、测试质量扫描 `tests=139, SOURCE_MATCH=0, heuristic_leads=1`
（唯一 heuristic 已人工确认是执行生产
`RouteBindingSynchronizer` 的内存仓储行为夹具，不复制生产算法）、R08 **9/9**、PHP SDK、
portal 契约与 TypeScript SDK 均通过。Dart SDK 先复现 3 个 analyze error，修复
`onboardingPreview` 的 async 返回与私有命名参数后，`dart analyze` 0 问题、测试 **11/11**。
管理端源码与 demo dry-run 无差异；宿主全量 `vue-tsc` 仍被 SandPackage 自身的
`failed-upgrade-recovery.vite.config.mts` 模块解析错误阻断，不计 SandIAM 失败，也不计发布构建通过。
历史 `0.7.0-v70/v71` 仅作追溯，不能作为 0.7.1 候选或证据。v12 的内容一致性虽通过，但其旧 verifier 对 `release/unsigned`、clean committed source/hygiene PASS 的自报已被独立 Astra 推翻：该 verifier 只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件；v12 仅为历史快照，正式来源 **REJECT**。最终 commit/tree/ZIP/签名均未形成。`LICENSE`、私密漏洞报告入口与 DCO 已在源码中，不得再列作未确认项。该状态不计 F/L/D 或发布门槛。
机器服务示例已增加 HTTPS、HTTP 状态、JSON/data 结构及 service/audience/action 的关闭失败校验，成功结果不再输出短期 context；尚未进行真实 HTTP 调用，不计业务接入通过。
公开备份恢复指南已补齐 PostgreSQL 归档列表/摘要、预建隔离库、源/恢复 DSN 防同库和单事务失败即停命令；未实际执行备份或恢复，不计恢复门槛通过。
公开配置参考和随包离线预检已覆盖 `app.php`/`process.php` 的 85/85 个 `SAND_IAM_*` 环境键，明确密钥格式、keyring、默认关闭和 worker 双开关；插件 debug 已从硬编码开启改为部署开关且发布默认 `0`。默认关闭的运维 retention worker 现以有限批次处理过期 succeeded 幂等记录与过期认证限流窗口，迁移 038 提供时间索引，两类 backlog 均纳入 24 小时零容忍指标。目录 Sync outbox 已补有界失败终态、脱敏查看、同应用幂等重试和 running 并发互斥；24 小时队列/不可恢复积压也已绑定具体状态公式。预检不输出秘密、不连接数据库，行为测试 8/8；迁移、worker 与 PostgreSQL 夹具均未执行，不计运行配置通过。
24 小时 runner、Casdoor 同环境 12 轮证据校验器，以及 OIDC/SAML/LDAP/SCIM/CAS/
Kerberos-SPNEGO/RADIUS 七类标准客户端与真实对端互操作验证器已经关闭失败回归，但尚无任何
真实长跑、双方旅程或协议运行原始记录，因此 R09、D04、24 小时和 Casdoor 发布门槛仍为未通过。
隔离备份恢复也已有候选绑定模板和关闭失败验证器，覆盖同库误操作、危险 restore flags、撤销状态
复活、审计/逻辑状态不一致及证据缺失；没有实际 `pg_dump/pg_restore`，D06 仍未通过。
未参与开发者仅依靠十二份公开材料的八步交付也已有候选绑定模板和关闭失败验证器，覆盖包验签、预检、
安装、配置、人类/机器双接入、失败恢复和卸载清理；没有真实独立参与者执行，不计发布门槛通过。
运行代码缺口扫描未发现 TODO、占位实现、调试输出或明显默认放行；敏感序列化门禁已由手工子集补全为
自动发现全部 35 个 `$hidden` 模型、61 个字段，并逐模型执行 `toArray()`/JSON 泄漏验证。
一次性秘密响应审计发现并修复 OAuth 机密客户端创建/轮换缺少禁止缓存头；机器凭证、动态注册令牌、
开发者接入以及登录/MFA 令牌响应也统一为 `Cache-Control: no-store` 与 `Pragma: no-cache`，七控制器契约通过。
继续审计发现 OAuth 客户端创建/轮换在响应丢失后会重复执行，且通用幂等结果脱敏漏掉 `client_secret`；
现已按同一 `X-Request-Id` 事务化、轮换时锁定客户端，重放不再次执行且只返回 `secret_available=false`，公开文档同步说明重试规则。
RFC 7591 动态注册、DCR 初始令牌和 SCIM 令牌的签发/撤销也已纳入同一幂等事务；初始访问令牌额度、
客户端创建、令牌状态、审计与脱敏结果共同提交。递归脱敏覆盖当前已知协议秘密并保留安全元数据。
TOTP 建立、确认和恢复码再生成也已按应用用户与同一 `X-Request-Id` 在同一事务提交状态、审计和脱敏结果；重放不创建第二因子、不重复启用或覆盖恢复码，也不再次返回 seed、URI 或恢复码。当前密码和 TOTP code 仅以部署 pepper 的 HMAC proof 参与指纹，不持久化明文或裸摘要。PostgreSQL 集成用例已补响应丢失重试断言，但本轮没有数据库授权，未执行，故不冒充 MFA 运行验收通过。
当前依赖漏洞公告查询也尚未完成：官方 Composer audit 因会发送锁定包名/版本而被安全审批拒绝，
没有改用第三方服务绕过；只读外部元数据范围已加入 OSS-04 F 授权项。

> 下文保留旧看板作为历史证据索引，其中“进行中”“已完成”均不代表当前 Goal 已复核通过。

## 当前唯一产品主线／目标纠偏（2026-09-08）

- SandIAM 权威源码的完成标准是七条真实业务闭环 **F01–F07 全部 7/7**，并继续完成适用的 **L/D** 关口；当前模块 **20/20** 只代表实现，不代表插件完成。
- SandPackage 6.1.4 下“既有 replacement `8ecc5cec…f9e9f` 的正式只读 Gate A”已由实施者执行并经独立复核 **ACCEPT**；它不等于当前候选替换、registry/数据库恢复、retry/runtime 或宿主完成。至此冻结恢复支线，不再占用 SandIAM 产品主线。新的宿主/SandPackage 问题仅一次性交接至 `/Users/code/project/sandadmin`，SandIAM 当前任务不得继续修改宿主。
- SandAI 明确排除，由 `/Users/code/project/sand_ai` 自行核验；SandIAM 可用行业中立或非 SandAI 的受控调用端完成本插件相应闭环。
- 以一条真实链端到端为批次：页面进入 → 保存 → 刷新 → 实际生效 → 允许/拒绝 → 撤销/恢复 → 审计 → 清理。每条只在完整闭环后做一次独立验收；禁止以静态、模拟、候选或文档计数作为业务完成。CLI 优先，浏览器仅用于最终真实页面验收。

> **任务状态唯一来源。** 每次状态变化都更新本文件：负责人、状态、完成证据、解锁项与最小阻塞条件。它不是排期表，不按时间推进。
>
> Codex 原子队列：`/Users/code/project/sand_plugins/.codex/autopilot/tasks.md`
> Cursor 原子队列：`/Users/code/project/sand_plugins/.cursor/autopilot/tasks.md`
> 协作边界：[sand-iam-pg-collaboration.md](sand-iam-pg-collaboration.md)

> IAM-T03 已通过主代理复核、真实 ORM 协议语义测试和隔离 PostgreSQL 生命周期验收；真实 SandAdmin HTTP、标准 OIDC 客户端互操作、浏览器会话和同意页面仍归 IAM-T07/T08。

> FLOW 严格口径：需求/架构/票据 **8/9**、模块实现 **20/20**、正式 FLOW 验收 **0/7**、本地业务闭环 **0/4**、可上线部署 **0/8**，合计 **28/48（58.3%）**。2026-09-08 已在[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)完成基线重建，冻结 R01–R09、P01–P20、F01–F07、L01–L04、D01–D08 的稳定名称、完成定义和证据边界；R08 的[T09–T12 末端验收规格冻结](sand-iam-t09-t12-terminal-spec-freeze.md)已登记 12 个原子，静态门禁 **9/9** 且独立复核 ACCEPT，现计入需求冻结。这不是伪造 2026-08-23 遗失清单原文。随后[模块实现关口归位审计](sand-iam-module-implementation-gate-audit-2026-09-08.md)只把 P 中误混入的真实供应商/标准客户端/宿主 HTTP/浏览器/业务应用/恢复/部署移回 F/L/D；P14、P19 已独立最终复核 ACCEPT，P20 以独立生产 service 的公开行为测试计分。七条业务链、升级票据 `5/7` 和实际恢复 `2/8` 均不变且不重复计入 48 项。

> **历史候选摘要（P19 收口之前，仅供追溯）**：下述 `001–036`、84 表目标、包完整性、早期恢复描述器和 U-14～U-16 源码记录均早于当前 `001–038`/86 表权威源码；其中“未实现宿主 verifier/profile”等判断已被后续 SandPackage 交付取代，不得再当作当前状态。当前产品结论只认本页 2026-09-08 的 20/20 模块记录；宿主和恢复结论只认升级票据、REC01–REC08 及仓库级交接文档。

> **七链历史模拟证据（不计正式 FLOW）**：[验收编排器](sand-iam-seven-chain-acceptance-runner.md)曾完成源码预检、内存式模拟和非 PostgreSQL 专测；不同历史报告的检查总数随契约演进，不得脱离具体候选复用。七链当前映射和计分边界以[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md) C01–C07 为准；真实宿主、数据库、worker、外部提供方和浏览器仍未核验。

> 链 4 清理已补源码与非 PostgreSQL 回归：默认关闭的同一受控接口现在以固定 16 位前缀、精确创建审计、完整层级和同 credential/grant 下的前缀 operation 全集核验；提交调用请求号与实际 operation 必须无序精确相等，少一条、多一条或同请求双记录都会拒绝。清理先撤销 credential→grant，再按 `service_quota_bucket → service_invocation_operation → credential → service_grant` 物理删除；预置范围对象、历史调用和审计均保留。credential 签发失败但 grant 已创建时，driver 走独立 grant 回收/状态分支；失败审计使用独立动作，不阻塞同请求键故障消除后的成功重试。provider 三个 POST 统一使用 `X-Sand-IAM-Credential`；本地 simulator 仅验证 allow 200、ungranted 403、revoked 401 的协议状态机，不伪装 SandIAM operation。live 计划已经改为 `api_available:true` 并绑定创建/调用请求号、条件分支清理契约和匹配的零残留状态查询。此为静态/受控模拟准备，不上调正式 FLOW、本地业务闭环或上线计数；真实宿主链 4 仍待独立写入授权和受控提供方。

> 链 2 清理已补源码、计划和非 PostgreSQL 回归：只接受同一固定 16 位前缀、精确创建请求号及组织/应用边界下本轮捕获的 `identity`、`identity_group`、`identity_group_member`、`identity_group_role`。成员审计同时精确绑定 group、identity 与 request_id；错应用、错用户组/身份/角色、历史关系、重复关系、漏对象或多对象都会拒绝，审计只保留脱敏记录。清理先撤销组角色、移除成员，再按正常生命周期停用/删除身份与用户组，并按 `identity_group_role → identity_group_member → identity_group → identity` 子先父物理清理；完整、成员、用户组和仅身份的部分失败都可由已捕获对象重试回收。角色、资源、策略、应用、环境和组织均是只读前置条件，绝不会清理。本次为静态/受控模拟准备，不上调正式 FLOW、本地业务闭环或上线计数；真实宿主的 FK、生命周期接口和审计字段仍待独立写入授权验证。

> **2026-09-07 SandPackage 失败升级恢复交付回写（不改变冻结分母）**：当前接受的 staging 为 `.staging/sandpackage-failed-upgrade-recovery-v1-20260901T121000Z`；最终独立验收为 **ACCEPT，P0/P1/P2=0**，backend **10/10**、行为断言 **672 PASS**、SHA manifest **16/16**，并分别通过冻结 PostgreSQL catalog 状态 `baseline_060` 与 `prefix_033_034`。对应 Gate A/B、恢复 UI 与受控同步证据见 `.staging/sandpackage-failed-upgrade-recovery-v1-20260901T121000Z/report/GATE_A_VERIFICATION.md`、`.artifacts/sandpackage-failed-recovery-ui-01-rework-2-20260907/report.md`、`.artifacts/recovery-controlled-sync-20260907/MANIFEST.md`。
>
> v7/v9 均已作废为历史 review-only 证据；v13 亦已被独立离线验收 **REJECT**（P1：builder/checker payload policy 不一致、authority descriptor stale；见 `.artifacts/sand-iam-v13-independent-acceptance-20260907/INDEPENDENT_ACCEPTANCE.md`），三者均不得复用、安装或同步。v14、v15、v16 均由后续候选取代；v17 是 P19 收口前的历史候选：`.artifacts/sand-iam-0.7.0-v17-20260907T200515Z/`，ZIP SHA-256 `70bfe0186ae6aec48b971107dcbc7b89d80862ba4a0b083b8466e3c8a9a7734a`、598 entries。v17 的 SDK `dist` 仅放行四个 TypeScript 导出文件（`index.js/index.d.ts/management.js/management.d.ts`），静态 package checker **23/23**，重复构建 bit-identical；从 ZIP 解包后由真实消费者完成默认 fetch 的 loopback allow/403/401 三种结果。该证据继续证明 P18，但 v17 不含 P19 最终源码，不得再安装或同步为当前候选。
>
> SandPackage catalog/035 修复已由 `.artifacts/sandpackage-recovery-catalog-independent-review-20260907/P0_P1_CORRECTION_EVIDENCE.md` 记录为 30/30、verifier 107/107；v15 的 production verifier/profile 已按 `.artifacts/recovery-controlled-sync-v15-20260907/MANIFEST.md` 完成 8/8 受控同步。该同步不等于候选上传、数据库恢复或部署完成。2026-09-07 的 83 表、catalog 与 `state=8` 仅是历史保留证据，不能再称当前状态；当前可复核宿主事实以 [`HOST-202609-001`](../../../docs/host-requests/HOST-202609-001-sandpackage-failed-recovery.md) 为准：它是 `local draft / not sent`，demo registry 为健康 `0.7.0`（`state=1`、`stage=completed`），精确数据库计数未在本轮重新验收。
>
> v17 的历史前端 build/type/lint/54 路由证据已通过；计划 v24 已完成 P19 前端隔离 ESLint/typecheck/build 和 ZIP 构建所需源码。SandPackage 6.1.4 demo static gate 已 ACCEPT：**`DEMO_SYNC_6_1_4_STATIC_PASS`**（lifecycle 10/10、lint 3/3、vue-tsc PASS、关键 SHA 5/5；digest `dbdec85c3558592f2af196a689a28fdaa89f9ce56e8e38ecfcf93e6f3de5f34f`，白名单外不变）。正式 Gate A 已 **PASS**：existing replacement `8ecc5cec…f9e9f` 绑定真实 identity，返回 `retry_safe`、101/101、`failed_assertion_ids=[]`、`audit_written=false`、fingerprint `714894c6…a16ce6`，且 READ ONLY + REPEATABLE READ + ROLLBACK/连接复用通过。P2 历史风险不阻断静态门。Gate A 仅使真实恢复阶段前进到 **1/5**；无 active registry/DB 写、replace/retry、服务、浏览器、commit 或 push。

> **本轮 FLOW 状态（2026-09-08）**：模块实现＝**20/20**（P14、P19 已独立最终复核 ACCEPT）；正式 FLOW 验收＝**0/7**；完整目标＝**28/48**。本次是 P 门槛归位，不将真实供应商、标准客户端、宿主 HTTP、浏览器、业务应用、恢复或部署提前计入 P；R08 仍仅因规格冻结门禁 **9/9** 且独立复核 ACCEPT 计入需求关口。本地业务闭环＝**0/4**（实际恢复 **2/8** 仅计恢复子步骤，不是业务闭环）；可部署/可上线＝**0/8**，当前仍是 dirty candidate；已部署/线上验证＝**未完成**，未执行宿主同步、数据库生命周期或线上验证。

> **2026-09-08 P19 与计划 v24 收口**：初始化草稿的保存、续改、停用、版本冲突、秘密拒绝、不可变历史及回滚公开行为已实现；后端和管理端分别独立复核 **ACCEPT（P0/P1/P2=0）**。管理端隔离 ESLint、`vue-tsc --noEmit` 与 Vite build 通过；七链 preflight **17/17**、simulated **38/38** 通过，但均不计真实 FLOW。6.1.3 connection blocker 已由 SandPackage 6.1.4 显式 `connect()` 修复，且既有 replacement Gate A 已 **PASS**。该只读 Gate 仍不授权或完成 registry/runtime recovery、replace/retry、浏览器和真实七链；不得以静态同步或 Gate A 替代其余验收。

> **SandAdmin v19 验收交接为历史编排**：完整执行顺序、候选身份、宿主阻断、七链验收、外部互操作、证据格式和 FLOW 回写规则见仓库级 [SandIAM v19 宿主验收交接单](../../../docs/handoffs/sandadmin-sandiam-v19-acceptance-handoff.md)。该交接不代表当前计划 v24 的 SandAdmin 已执行，因此 FLOW 仍为 **28/48**。

> **2026-09-08 P18-BLOCKER-TS-PACKAGE-01（独立 ACCEPT，分母不变）**：v16 因通用 `dist` 排除未携带 TypeScript SDK ESM 构建物，已由 v17 候选替代；其他 `dist` 继续拒绝。v17 ZIP 为 `.artifacts/sand-iam-0.7.0-v17-20260907T200515Z/`，SHA-256 `70bfe0186ae6aec48b971107dcbc7b89d80862ba4a0b083b8466e3c8a9a7734a`、598 entries，SDK `dist` 仅包含四个导出文件 `index.js/index.d.ts/management.js/management.d.ts`。独立验收为 **ACCEPT**：静态 package checker **23/23**、锁定本地双构建 bit-identical、SDK 原测试，以及 ZIP 解包后真实消费者的 loopback allow/403/401 结果均通过；恢复 descriptor 已重绑 596 文件的 payload SHA `be01909a…`，root/plugin descriptor byte-identical，`update.sql` 与 `035` hash 未改变。按 P 门槛归位，P18 现为**✅ 通过**；独立消费样例的当前业务闭环、宿主、浏览器、部署或线上验收仍未完成，分别留在 L/F/D。v17 历史候选未同步 demo；这不改变计划 v24 已完成的 17-file demo 静态白名单同步，也不构成运行时验收。无数据库、registry 或运行时写入，无 commit/push。详见同 artifact 的 `P18-BLOCKER-TS-PACKAGE-01-report.md` 和 `frontend-sync-plan-v17.json`。

### 历史 0.7.0 升级票据原子追踪（冻结分母 7）

下表是本轮升级票据的固定七项，不把历史记录、候选包或页面源码自动当作升级通过。`5/7` 只统计状态为 ACCEPT 的原子项；真实数据库升级、恢复和浏览器验收仍单独计分。

| 原子项 | 状态 | 当前证据与边界 |
| --- | --- | --- |
| 1. 权威迁移与目标结构 | ✅ ACCEPT | 当前 `001–038`、39 个迁移文件、生成目标 86 张 `sand_iam_*` 表；源码包完整性 24/24。仅静态/生成证据，不等于数据库生命周期。 |
| 2. 失败升级恢复 descriptor 与载荷绑定 | ✅ ACCEPT | v13 已 REJECT，不得引用其旧证据；v14–v23 已被计划 v24 取代。v24 从冻结 snapshot 构建的静态证据以构建输出为准；当前仍是 `candidate/dirty-not-release`，不等于 release 或宿主恢复。 |
| 3. C-IAM-HOTFIX-01 权威行为修复 | ✅ ACCEPT | `../../../.artifacts/sandiam-authority-hotfix-20260907/verification.md`：行为检查 5/5、PHP lint 415/415、diff check 通过；其外部 descriptor stale 仍按原子项 2 追踪。 |
| 4. SandPackage 失败升级恢复 staging | ✅ ACCEPT | [`GATE_A_VERIFICATION.md`](../../../.staging/sandpackage-failed-upgrade-recovery-v1-20260901T121000Z/report/GATE_A_VERIFICATION.md) 对应 Gate A/B、backend 10/10、行为断言 672 PASS、SHA manifest 16/16；它证明 staging 套件，不证明宿主数据库已恢复。 |
| 5. 受控同步与候选包材料 | ✅ ACCEPT | v15 production verifier/profile 及相关 SandPackage 运行材料按 `../../../.artifacts/recovery-controlled-sync-v15-20260907/MANIFEST.md` 完成 8/8 受控同步；当前 `17-file demo` 静态白名单同步已完成，候选材料仍只作离线证据，不能外推为 host/runtime 验收。 |
| 6. 官方 UI 升级入口 | ⏸ BLOCKED | 0.6.0→0.7.0 官方 UI 尝试在后端提交前因候选缺少兼容 `support` 字段被拒；没有绕过 UI，也没有生命周期写入。见 [`IAM-T08-real-upgrade-gate-20260831.md`](../../../.codex/autopilot/executions/IAM-T08-real-upgrade-gate-20260831.md)。 |
| 7. 当前宿主数据库/业务闭环 | ◻ NOT VERIFIED | 实际恢复阶段 **2/8** 是历史恢复子步骤，不构成当前候选通过；候选替换/重试、数据库生命周期、浏览器和七条业务链仍未通过。浏览器上下文与认证恢复流程尚未完成，不能写成“用户未登录”；本轮无数据库、registry 或运行时写入。当前可复核宿主 registry 为健康 `0.7.0`（`state=1`、`stage=completed`）；精确数据库计数未在本轮重新验收，见 [`HOST-202609-001`](../../../docs/host-requests/HOST-202609-001-sandpackage-failed-recovery.md)。 |

因此当前升级票据为 **5/7=71.4%**。`U-WIZARD-01` 的 Cursor 队列项记录为 **not started / superseded**，实际源码由 Codex takeover 并曾独立复核；当前权威四文件登记为 `index.vue` `4890158e…`、`WizardStepForm.vue` `94b335fb…`、`wizardState.ts` `83ff7012…`、state test `bd56e2d2…`。计划 v24 按唯一 payload policy 只分发前三个运行文件；state test 与七个只服务 behavior/viewport mock harness 的 helper 均排除。`17-file demo` 静态白名单同步已完成；真实组件/三视口、宿主运行和浏览器验收仍未执行。

冻结的 20 项模块现已在[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)正式登记为 P01–P20。旧分子中的 11 项逐一映射为 P01–P11；剩余 P12–P20 是 2026-09-08 基于权威能力域重建的向前冻结名称，不冒充遗失历史原文。[模块实现关口归位审计](sand-iam-module-implementation-gate-audit-2026-09-08.md)后模块实现为 **20/20**、完整目标 **28/48**；P14、P19 已独立最终复核 ACCEPT，P20 已以自助模块生产 service 的公开行为测试计分，R08 规格冻结门禁 **9/9** 与独立复核 ACCEPT 仍只计需求关口，向导源码 ACCEPT 不进入正式 FLOW 或本地业务闭环。

## 状态说明

- `✅ 已完成`：交付条件和证据已满足。
- `▶ 进行中`：负责人当前只执行这一项。
- `🟢 可领取`：无未解决依赖，负责人可直接开始。
- `⏸ 等待决策`：仅等待明确的外部权限或产品选择；不阻塞无关任务。
- `◻ 未开始`：仍有明确前置条件。

## 历史协作快照（2026-09-07，仅供追溯）

> 本节的 Agent 在线状态、Autopilot 开关和 watcher PID 都是当日瞬时记录，不是 2026-09-08 当前结论。当前不从这些记录推断 Cursor 在线、宿主可用或浏览器已登录；当前执行阻塞只表述为 Codex 可控 browser context 不可用。

| 已完成 | 进行中 | 可领取 | 等待决策 | 未开始 |
| --- | --- | --- | --- | --- |
| 协作启动、D-01、U-01～U-05A、U-05B（Cursor 证据）、UX-01A、UX-01B（Cursor 证据）、U-T00～U-T13、U-14～U-16、U-17（Cursor 源码）、UX-02（Cursor 证据）、IAM-01～IAM-05、IAM-07、IAM-T01～IAM-T03 | IAM-06（插件服务目录）、IAM-T04～IAM-T12 | 无新前端可领项；Cursor 协作通道 **已开启**（2026-09-07，Codex 已恢复） | DETECT-01（监视中；等 Codex 独立验收或解冻新前端面） | A-01、SandAI SAND-113F |

> **2026-09-07 Cursor 协作通道开启（给 Codex）**：用户确认 Codex 已经恢复运行（13:32 误记「cursor已启动」已更正）。Cursor 交互 Agent 在线，Autopilot `enabled=true`，DETECT watcher pid **37015**。U-05B / UX-01B / UX-02 / U-17 / SANDPACKAGE-FAILED-RECOVERY-UI-01 仍待 Codex 独立验收，**不自动上调 FLOW**。交接：看板 + `.cursor/autopilot/executions/CURSOR-PING-20260907.md`。
>
> **2026-08-31 DETECT 去重（不影响协作）**：用户授权清重复 watcher。已 `SIGTERM` 旧进程 14997；值班仅 pid **99262**。Autopilot 仍 `enabled=true`，DETECT-01 仍 WAITING，backoff 未改。未重拉、未关通道、未改 Codex 队列。

## 正在执行与可并行任务

| ID | 负责人 | 状态 | 交付条件 | 已冻结输入 / 交接物 | 任务完成时必须写入的证据 |
| --- | --- | --- | --- | --- | --- |
| IAM-01 | Codex | ✅ 已完成 | 冻结 P0 领域、PostgreSQL 模型、管理/运行时 API、错误码、SandAI Adapter 与 Cursor 开工输入 | [P0 契约 v0.2（可消费）](sand-iam-p0-contract.md) | 初版交付已完成；身份源采用显式双作用域：应用私有，或组织持有后显式挂载到应用。旧管理端 `provider_code` 仅兼容解析，不创建记录或猜测作用域。 |
| U-01 | Cursor | ✅ 已完成 | 管理端页面目录与诚实占位页；不依赖真实后端字段 | 产品对象已确认：organization / application / environment / workload_client / service_grant / audit | 见 `.cursor/autopilot/executions/U-01.md` |

**并行规则：** Codex/Cursor 互不阻塞。Cursor 在已冻结契约上可自领并行项；U-01 不因 IAM-01 停工。不得因 SandAI 演示站或 OCR 决策停工。

## 后续任务

| ID | 负责人 | 状态 | 前置 | 交付/验收条件 |
| --- | --- | --- | --- | --- |
| IAM-02 | Codex | ✅ 已完成 | IAM-01 | SaiPackage 只执行包根 lifecycle SQL；当前 `0.1.1` 在隔离库回归安装后为 18 表 / 213 约束 / 53 索引、无非 SandIAM 表；包根 update 成功；uninstall 后 0 表。解锁 IAM-03。 |
| IAM-03 | Codex | ✅ 已完成 | IAM-02 | 后端、路由与 17 表已安装；临时宿主服务的后台入口真实返回 401、无 signer 返回 503；回滚事务证明签发→校验→撤销拒绝→审计，且 0 残留。真实部署 signer 与已登录后台会话移交 IAM-05 端到端验收。解锁 IAM-04。 |
| IAM-04 | Codex | ✅ 已完成 | IAM-03 | [授权与数据范围契约 v0.1](sand-iam-authorization-contract.md) 已冻结；`0.1.1` 迁移、策略/范围/委派/审计控制面实现及真实宿主回滚验收完成。七操作均放行，跨组织、条件不匹配、同优先级 deny、无策略及范围不匹配均稳定拒绝；审计存在且测试无残留。宿主的 `saiadmin → sandadmin` 迁移通过 SandIAM 的按需类别名适配，不修改宿主核心目录。 |
| IAM-05 | Codex | ✅ 已完成 | IAM-04 | 已在真实 `sandadmin` 宿主同步插件并完整重启。Codex 已生成部署期 signer 并仅写入宿主 `.env`；运行时真实 HTTP 使用专用 `saiadmin` 验收库的临时服务凭证夹具，完成 `issue=200`、`verify=200`、错误 audience `403`、凭证撤销后 `401` 和审计存在验证。夹具及关联审计已精确清理，9 项残留检查均为 0；不输出 signer、凭证或 context。管理端接口交接已完成，见 [管理端接口交接 v0.1](sand-iam-management-api-v0.1.md)。 |
| U-02 | Cursor | ✅ 已完成 | IAM-01（页面信息架构/字段字典已标可消费） | 六个列表已按 P0 契约接入加载、空、失败、分页和冻结字段；无伪造 CRUD，验收宿主 `vue-tsc --noEmit` 与 Vite 构建通过。见 `.cursor/autopilot/executions/U-02.md`。 |
| U-03 | Cursor | ✅ 已完成 | U-02，且管理 API 已标可消费 | 列表筛选、权限码展示、401/403/503/`SAND_IAM_*` 诚实失败；已接入 identity / binding / user-type / role / resource / policy / 委派 / 身份关系只读列表。`vue-tsc` 与宿主 Vite 构建通过。见 `.cursor/autopilot/executions/U-03.md`。 |
| U-04 | Cursor | ✅ 已完成 | 管理写入 API 已冻结（不依赖 IAM-05） | save/update/disable、policy publish/revoke、grant revoke、关系 grant/revoke、service/action/credential；凭证明文只显示一次。见 `.cursor/autopilot/executions/U-04.md`。 |
| D-01 | Codex | ✅ 已完成 | 管理 API、产品对象边界与一次展示凭证规则已冻结 | [第一次使用 SandIAM](../user-guide/sand-iam-first-connection.md) 已交付行业中立的文字版操作手册；产品语言、单页向导和闭环要求以[体验契约](sand-iam-product-experience-contract.md)为准。真实页面与截图仍待后续任务验收，禁止使用虚构截图。 |
| U-05A / U-05B | Cursor | ✅ 源码完成 / ✅ Cursor 浏览器证据（Codex 独立验收待办） | 管理 API 与对象边界已冻结 | 名称优先和系统代码解释源码、宿主构建已完成，见 `.cursor/autopilot/executions/U-05.md`；U-05B `u05b-1787928603323` 三角色创建流 + 双视口通过，见 `.cursor/autopilot/executions/U-05B.md`。不上调正式 FLOW。 |
| UX-01A / UX-01B | Codex + Cursor | ✅ 源码完成 / ✅ Cursor 宿主证据（Codex 独立验收待办） | U-04、IAM-07 的 v0.2 管理契约 | 权威源码见 [UX-01 执行记录](../../../.codex/autopilot/executions/UX-01.md)；UX-01B `ux01b-1787929271052` 13 页双视口 + 三角色 allow/deny 通过，见 `.cursor/autopilot/executions/UX-01B.md`。宿主缺口：`SandIAMGettingStarted` 菜单未入库。不上调正式 FLOW。 |
| U-14 | Codex 兜底 | ✅ 源码交接完成 | [体验契约](sand-iam-product-experience-contract.md)第 1、2、4 节 | Cursor Autopilot STOPPED，Codex 直接完成总览用途、五种状态、行业中立文案、隐藏内部入口和布局的源码交接，见 `.cursor/autopilot/executions/U-14.md`。真实宿主三视口验收仍未完成。 |
| U-15 | Codex 兜底 | ✅ 源码交接完成 | U-14、体验契约第 3 节 | Cursor Autopilot STOPPED，Codex 直接完成 `/sand-iam/getting-started` 单页向导源码交接，见 `.cursor/autopilot/executions/U-15.md`。真实保存、刷新和错误恢复仍未验收。 |
| U-16 | Codex 兜底 | ✅ 源码交接完成 | U-15、Codex 菜单/动态路由契约 | Cursor Autopilot 已停止，Codex 直接完成入口与应用用户边界的前端交接，见 `.cursor/autopilot/executions/U-16.md`。首次使用入口按可管理范围显示；管理范围支持客户主体委派或应用委派任一入口。真实宿主菜单、权限和 404 复验仍未完成。 |
| UX-02 | Codex + Cursor | ✅ Cursor 七链证据（Codex 独立验收待办） | 体验契约第 5 节、受控宿主与夹具授权 | Cursor `ux02-1787930822471` 七链 7/7 通过，见 `.cursor/autopilot/executions/UX-02.md`。Codex 需独立验收，并将演示宿主热修（`PolicyAuthorizer` / `ApiResourceController` / `identity-group-role` / `simulate` 空规则）并回权威包。不上调正式 FLOW。 |
| U-17 | Cursor | ✅ 源码完成（宿主/浏览器待同步验收） | 体验契约第 4 节、U-14 布局规则、用户 2026-08-31 指派 | 去掉宿主 `art-full-height`/`art-table-card` 裁切；改为 `sand-iam-page` 最小高度容器。源码与文案门禁通过，见 `.cursor/autopilot/executions/U-17.md`。未改宿主；双视口滚动未验收。不上调正式 FLOW。 |
| IAM-06 | Codex | ▶ 进行中 | SandAI 发布包需要受信任的 SandIAM 安装前置 | `ServiceCatalog` 已补齐声明校验、行锁、重复安装不覆盖/不复活、`23505` 单次收敛重试和越界模型门禁；机器调用 quota/network/data_class 约束（030）已通过源码与隔离 PostgreSQL。SandAI 的 4/4 隔离生命周期是其自身证据，仍需真实 SandAI allow/deny/audit 业务闭环，不能替代 SandIAM 宿主验收。见 `.codex/autopilot/executions/IAM-06-service-catalog-draft.md`。 |
| IAM-07 | Codex | ✅ 已完成 | 应用账号独立，旧 `provider_code + subject` 全局唯一边界需要修正 | v0.2.0 已实现以 `identity_provider_id + subject` 为边界的身份绑定、显式 application/organization 两种身份源作用域及 application mount、兼容管理 API。PostgreSQL 18 新装为 19 表 / 228 约束 / 60 索引；隔离验收已证明 0.1→0.2 旧绑定回填、重复升级幂等、跨应用相同 subject 放行、同身份源重复拒绝、跨应用错绑拒绝，卸载后 0 张表且临时数据库残留为 0。 |
| IAM-T01 | Codex | ✅ 已完成 | IAM-T00 终极边界和应用身份隔离已冻结 | v0.3.0 已实现应用级注册、验证、登录、Argon2id 密码、认证策略、失败锁定、数据库限流、可撤销会话、刷新令牌轮换/重放撤销、退出、密码重置和审计。隔离 PostgreSQL 中使用真实 SandAdmin ORM 依赖执行服务级流程通过；跨应用相同邮箱独立注册通过，敏感材料审计泄露扫描通过，安装、两次升级、卸载通过，临时库残留为 0。契约见 [人类身份认证 API v0.1](sand-iam-human-auth-api-v0.1.md)，执行证据见 `.codex/autopilot/executions/IAM-T01.md`。真实宿主 HTTP、管理端和发布验收归 IAM-T07/T08。 |
| IAM-T02 | Codex | ✅ 已完成 | IAM-T01 | v0.4.0 已实现应用隔离的 TOTP、一次性恢复码、密码后 MFA challenge 和 ES256 WebAuthn/Passkey。绑定新认证器必须通过当前密码二次认证；MFA 验证有独立数据库限流；challenge、TOTP 时间步、恢复码与 signCount 重放均拒绝。隔离 PostgreSQL 已通过真实 ORM 服务流程、密钥 key ring 兼容、BE/BS 与 userHandle 校验、跨应用拒绝、fresh install、003→当前重复升级、复合外键反例和两轮卸载清理。契约见 [MFA 与通行密钥 API v0.1](sand-iam-mfa-passkey-api-v0.1.md)，执行证据见 `.codex/autopilot/executions/IAM-T02.md`。真实浏览器、宿主 HTTP、管理端和发布验收归 IAM-T07/T08。 |
| IAM-T03 | Codex | ✅ 已完成 | IAM-T02 | v0.5.0 已实现 issuer 全局客户端代码、授权码 + PKCE S256、服务端一次性授权交互与 CSRF、客户端凭证、RS256 access/ID token、发现文档/JWKS、userinfo、refresh 轮换与重放整族撤销、revoke/logout、audience/scope 与应用/组织停用 fail-closed。真实 ORM/服务/controller 测试覆盖跨应用、重定向、增量 consent、prompt/max_age、JWT、DB 关联和审计脱敏；主代理隔离库 fresh install→update→uninstall、003→current×2→隔离检查→uninstall 通过，临时库自动清理。契约见 [OAuth/OIDC Provider API v0.1](sand-iam-oauth-oidc-api-v0.1.md)，执行证据见 `.codex/autopilot/executions/IAM-T03.md`。真实 SandAdmin HTTP、标准客户端互操作、浏览器 Cookie/页面、并发、限流和密钥轮换验收归 IAM-T07/T08。 |
| IAM-T04 | Codex + Cursor | ▶ 进行中 | IAM-T03 | OIDC/OAuth、SAML、LDAP/SCIM、目录同步、映射、停用、解绑和审计已统一进入 lifecycle，并通过真实 ORM PostgreSQL 集成；仍缺真实外部 IdP/目录、标准客户端、宿主 HTTP 和管理端验收。 |
| IAM-T05 | Codex + Cursor | ▶ 进行中 | IAM-T03 | 接口目录、路由绑定、语义动作决策、PHP/TypeScript SDK 和 Webman 中间件已进入 `008` lifecycle；应用/组织隔离、幂等观察、路由冲突、停用关闭失败、OpenAPI 语义保留和审计已通过 PostgreSQL 集成。真实 SandAI/非 AI 应用授权和管理端闭环尚未验收。 |
| IAM-T06 | Codex + Cursor | ▶ 进行中 | IAM-T04、IAM-T05 | 应用级委派、自助 API、Webhook、审计导出已进入 lifecycle；委托与 Webhook PostgreSQL 集成通过。仍缺并发 worker、真实 HTTPS 接收端、三角色页面和浏览器闭环。 |
| IAM-T07 | Codex + Cursor | ▶ 进行中 | IAM-T04～IAM-T06 | 完整管理端交接契约和 Cursor U-T04～U-T13 源码/执行记录已交付；仍缺真实宿主同步、登录后的三角色流程与两个视口验收。 |
| IAM-T08 | Codex + Cursor | ▶ 进行中 | IAM-T04～IAM-T12 | `61a7f138…` 是未 push 的 0.7.1 候选提交，来源/事务修复仍未提交；normal 包排除旧 recovery descriptor。v12 内容自洽但因 62 个 ignored 来源文件未进入可追溯来源而正式 **REJECT**，不得作为发布来源。只读 DB 为 86 tables、ledger 38 rows、max revision 37、无038，runtime 仍 0.7.0；B′/C′/D′ 未执行，G 未授权。宿主同步、安装/升级/卸载、真实宿主 HTTP、浏览器、外部客户端、七条业务闭环和部署仍未通过。|
| IAM-T09 | Codex + Cursor | ▶ 进行中 | IAM-T06 | 应用登录体验、品牌、消息 Provider、独立用户门户后端候选与 `011/025` 已落；假驱动下的服务选择、密文配置、投递/Captcha、停用关闭失败和跨组织拒绝已通过 PostgreSQL 集成。真实供应商、HTTP、页面和浏览器未验收。 |
| IAM-T10 | Codex + Cursor | ▶ 进行中 | IAM-T04、IAM-T09 | 用户生命周期、组、邀请、访客升级、CSV 导入导出、通用 Syncer 与 `012–015/026` 已形成候选；生命周期/组/访客、邀请、Syncer 三组 PostgreSQL 集成通过，P14 scheduler/worker 已独立最终复核 ACCEPT。真实 HTTP、真实 worker/目录运行和页面未验收。 |
| IAM-T11 | Codex + Cursor | ▶ 进行中 | IAM-T03、IAM-T04 | DCR、OIDC 前/后通道登出、CAS、Kerberos/SPNEGO、RADIUS Access/Accounting 与 `016–018` 已形成候选，迁移已进入 lifecycle 并有 SandPackage 隔离安装记录；真实 Realm/NAS、标准客户端、宿主 HTTP 和页面未验收。 |
| IAM-T12 | Codex + Cursor | ▶ 进行中 | IAM-T05～IAM-T11 | Dart/Flutter SDK、CLI、管理 OpenAPI/事件目录、网络规则、安全运营和初始化包已进入 `019–020`；权限对账、网络规范化、初始化稳定策略键、默认路由封闭和敏感模型序列化已复核。当前 PHP lint 321/321、PHP SDK 通过、Dart 7/7、非 PG 47/47、PostgreSQL 集成 11/11；剩余真实业务应用、登录后宿主 HTTP、页面和发布验收。见 [开发者与安全运营契约](sand-iam-developer-security-operations-v0.1.md)。 |
| A-01 | Codex + Cursor | ◻ 未开始 | IAM-05、U-03 | 后台配置 → 身份上下文 → SandAI 拒绝/放行 → 审计的真实路径通过 |

### 文档收敛后的待办（不改源码）

- `plugin/sand-iam/config/process.php` 的过时迁移说明已不再作为任务项；worker 是否启用仍以部署配置和真实运行验收为准。
- 三条[开发者旅程](sand-iam-developer-journey-acceptance.md)已有可计时标准，但 SandIAM 与 Casdoor 都尚未在同等环境完成实测，不得宣称 SandIAM 已经更快或更顺手。
- [API 路由权威表](sand-iam-api-route-registry.md)已取代旧 `/account`、`/authorize`、`/context`、`/scim/v2` 草案路径；后续路由变更必须同步更新代码和该表。

## 给 Codex 的开工输入（IAM-01）

- 源码包：`/Users/code/project/sand_plugins/sand-iam`
- 演示与验收宿主：`/Users/code/project/sand_plugins/sandadmin-demo-host`（服务端为其 `server/` 子目录）；`/Users/code/project/sandadmin` 保持纯净通用宿主，不用于插件演示
- 产品/边界已确认：`docs/product/sand-iam-product-requirements.md`、`docs/architecture/sand-iam-access-boundary.md`
- 开发门槛：`docs/development/sand-iam-development-entry.md`
- Cursor 已占用：`sandadmin-artd/src/views/plugin/sand-iam/` 页面壳；请勿改该目录
- SandAI 等待：冻结 Adapter 后即可继续 `SAND-113C`（token/context 验证、audience、service grant、environment 引用）；缺省保持 fail-closed
- 业务拒绝统一：`plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`

## 给 Cursor 的消费规则

- 独占目录：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`
- IAM-01 未标「可消费」前：只做壳与诚实空态，禁止假 CRUD
- 契约文件名由 IAM-01 冻结后写入本看板「当前交接包」；DETECT 监视看板、契约与 `.codex/autopilot/tasks.md`

## 当前交接包

### 2026-09-12 Cursor 对接 Codex Goal `01a091cd`

- **Codex Goal**：thread `01a091cd`，「SandIAM 完整开源成品交付」，active。
- **Cursor**：交接契约已写入本看板；最近一次可复核的 Cursor 文件记录为 2026-09-12 10:22+08，本轮未取得当前进程或会话在线证据，因此不声称 Agent 仍在线。Cursor Goal 文件已对齐该 thread；旧 Autopilot/DETECT 保持 Codex 关闭后的 `enabled=false`，不重开 watcher。
- **边界不变**：Cursor 独占 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`；Codex 独占 `sand-iam/plugin/sand-iam/`。Cursor 不改 demo/宿主/PHP/SQL。
- **可领前端面**：T10 目录同步页增加 outbox failed 列表与精确重试；OAuth 客户端页增加 back-channel logout dead 脱敏列表与重新签发操作。目录调用 `GET sync-connector/outbox?id=<connector_id>&state=failed` / `POST sync-connector/outbox-retry`。OIDC 前端契约冻结如下：
  - 列表：`GET oauth-client/logout-delivery/index?id=<client_id>&state=dead&page=<page>&limit=<1..100>`，权限 `sand_iam:oauth_client:read`。分页 `data[]` 中显示 `event_id`（事件编号）、`state`（投递状态）、`attempt_count`（尝试次数）、`last_error_code`（稳定错误码）和 `update_time`（最后更新时间）；`id` 只作操作主键，`status` 只作恢复按钮资格判断且不显示。不得显示或读取 `auth_session_id`、`response_digest`，接口也不会返回 `encrypted_logout_token`。
  - 恢复：`POST oauth-client/logout-delivery/reissue`，权限 `sand_iam:oauth_client:update`，JSON body 精确为 `{ id: <client_id>, delivery_id: <dead_delivery_id> }`。每次新的人工操作生成新的 `X-Request-Id`；仅同一次网络重试复用原值。成功 `data` 为 `{ source_delivery_id, delivery_id, event_id, state, already_reissued }`；刷新 dead 列表，并分别提示“已创建恢复任务”或“该失败投递已有恢复任务”。页面不展示、缓存或索取 logout token/密文。
  - 操作与失败：只对 `state=dead && status=2` 显示“重新签发”；二次确认须说明保留原失败记录并创建新投递，不是重发旧令牌。稳定错误码必须分别呈现：`SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_DISABLED`（服务未启用）、`SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE`（客户端/应用/主体不可用）、`SAND_IAM_OIDC_BACKCHANNEL_URI_UNAVAILABLE`（未配置有效地址）、`SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_FOUND`、`SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_RECOVERABLE`、`SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED`、`SAND_IAM_OIDC_LOGOUT_RECOVERY_CONFLICT` 和通用 `SAND_IAM_IDEMPOTENCY_CONFLICT`；失败后保留列表与 request id，禁止乐观改状态。
  - Cursor 源码验收：为 DTO 解析器补正负行为测试，证明秘密/未知字段不会进入页面模型；目标 eslint、SandIAM 范围 `vue-tsc`、helper/contract test、overlay `vite build` 必须通过。真实 API、两种视口、平台管理员/应用管理员允许与无权限管理员拒绝仍归 demo 最终验收，源码构建不得替代。
  - 上述 OIDC 契约与 `OAuthClientController::logoutDeliveryPayload/reissueLogoutDelivery`、`OAuthOidcService::reissueBackchannelLogout` 和 `ManagementApiCatalog` 当前权威源码一致，现已可消费；不重开旧 Autopilot/DETECT watcher。

  v70 发布卫生 **11/14**；管理 OpenAPI `0.13.0-candidate` 已包含 OIDC 恢复精确 schema；Codex 已过 non-PG/contract **114/114**、PHP lint **507/507**、包完整性 **24/24**。配置参考与离线预检覆盖 85/85 环境键；24 小时 12 项指标的队列、不可恢复积压及两类 retention backlog 口径已冻结。PostgreSQL 顺序/并发/保留/Sync outbox/OIDC recovery 验证用例已编写但未获授权执行；FLOW 仍 **0/7**，无真实宿主、浏览器、协议、24 小时或独立终验证据。Cursor 不写 LICENSE、不执行 DB/宿主/同步。
- **证据**：`.cursor/autopilot/executions/CURSOR-PING-20260912.md`、`.cursor/autopilot/executions/DETECT-01.md`

### 给 Codex：IAM-03 可立即继续

- 协作约定、P0 schema 与 lifecycle 验收已完成；Cursor 不改 PHP/SQL。
- [P0 契约 v0.2](sand-iam-p0-contract.md) 已冻结并可消费；实现控制面、运行时身份上下文和 SandAI Adapter。SandAI `SAND-113C` 可据第 5 节实现 Adapter，仍必须保持 fail-closed。

### 历史：给 Codex 的 Cursor 协作通道记录（2026-09-07）

- **状态**：用户确认 Codex 已经恢复运行（13:32 误记已更正）；交互 Agent 在线；`enabled=true`、无 HARD_STOP、DETECT watcher pid **37015**（PPID 1）；`cursor agent` 已登录。
- **协作**：看板、`.codex/autopilot/tasks.md`、Cursor 队列、`.cursor/autopilot/executions/CURSOR-PING-20260907.md`。不要让用户口头中转。
- **边界不变**：Cursor 独占 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`；Codex 独占 `sand-iam/plugin/sand-iam/`。
- **Cursor 证据已交、待 Codex 独立验收**：U-05B `u05b-1787928603323`、UX-01B `ux01b-1787929271052`、UX-02 `ux02-1787930822471`（7/7）、U-17 滚动源码、SANDPACKAGE-FAILED-RECOVERY-UI-01 staging。不上调正式 FLOW。
- **请 Codex 处理**：权威包回同步（见 UX-02 执行记录）；`SandIAMGettingStarted` 菜单入库；U-17 权威前端滚动修复需同步到验收宿主后再独立验收；验收失败则写返工证据。无新冻结 API 面。
- **历史**：2026-08-31 见 `.cursor/autopilot/executions/CURSOR-PING-20260831.md`；2026-08-30 见 `CURSOR-PING-20260830.md`。

### 历史：给 Cursor 的体验闭环队列记录（2026-08-28 至 2026-09-07）

- [终极管理端信息架构](sand-iam-terminal-admin-ia.md) 4.3–4.8 已把动态注册、CAS、Kerberos/RADIUS、网络规则、告警、审计保留和初始化改为 frozen。
- U-T10 只消费 `oauth-registration-token/*` 与 OAuth client 登出字段；令牌明文只展示一次。
- U-T11 管理端走 `cas-service/*`；门户确认走 `/cas/interaction`，不用 check_admin。
- U-T12 Kerberos 走 `federation/configure`；RADIUS 走 `radius-nas/*`。不展示 keytab/共享密钥。
- U-T13 初始化必须 preview→确认→apply；哈希过期或漂移只能重新预检。
- U-14/U-15/U-16 原计划顺序为前端任务；由于当时 Autopilot STOPPED，Codex 已直接完成源码交接，执行记录明确标为 Codex 兜底，不归为 Cursor 成果。Cursor 已交付 `U-05B → UX-01B → UX-02` 证据；Codex 独立验收失败再以具体证据交 Cursor 返工。
- U-14 落地行业中立总览、五类状态与可达布局；U-15 落地“第一次使用”单页向导；U-16 落地入口的前端契约与运行面边界。它们不新增 PHP、SQL、菜单、路由、权限码或数据库表，真实宿主验收仍待完成。
- U-05B / UX-01B / UX-02 的 Cursor 浏览器证据已于 2026-08-28 交付；Codex 独立验收与权威包回同步仍待办，不上调正式 FLOW。
- Autopilot 通信通道已于 2026-09-07 再次开启（用户确认 Codex 已恢复）：`enabled=true`、HARD_STOP 无。DETECT watcher pid **37015**。证据：`.cursor/autopilot/executions/CURSOR-PING-20260907.md`、`.cursor/autopilot/executions/DETECT-01.md`。
- 2026-08-31 DETECT 自领 **U-17** 后源码已完成（体验契约第 4 节 / 去掉宿主全高裁切 class）。演示宿主副本未同步；Codex 独立验收需先同步权威前端。双视口滚动未验收，不上调正式 FLOW。

### 2026-09-12 0.7.1 提交锚点（未 push）

- 本轮可复核提交：`6e190953dc260f32e7428e751c3c6318dc9fcd8d`（HOST-202609-001）、`a7edcebb37ea06b2da3dc445d5b7307d3111a7ab`（生命周期）、`1aba9b444ae8ec69972faa2c1e6fe8e6ebc32d48`（外部验收）。这些是源码/离线证据提交，不改变正式 FLOW、业务闭环或发布门槛。
- `a7edceb` 的非 vendor whitespace 检查为零；完整检查仅报告 18 个原样第三方 vendor 文件的 whitespace，未改写第三方字节，不能称完整检查通过。
- 备份恢复 BLOCKED-B 的 7 文件仍未提交：`docs/user-guide/backup-and-restore.md`、`plugin/sand-iam/tests/backup_recovery_evidence_non_pg_test.php`、`plugin/sand-iam/tests/backup_restore_command_guard_non_pg_test.php`、`plugin/sand-iam/tests/external_acceptance_template_non_pg_test.php`、`tools/validate-backup-recovery.php`、`tools/prepare-external-acceptance.php`、`tools/README.md`；不计 G。
