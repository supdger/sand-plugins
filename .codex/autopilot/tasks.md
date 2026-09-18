# Sand 平台自动交付队列

## 当前唯一队列：SandIAM 完整开源成品（2026-09-12）

### 签名链初审 checkpoint（2026-09-12）

签名链初审为 **3P2**；修复后 Astra **ACCEPT（P0/P1/P2=0）**，helper SHA-256 `78f9…`。临时测试签名仅供非正式测试；受控目录、同 UID TOCTOU、真实密钥信任和 Git 独立重建仍未形成正式证据。正式 signing 未执行，发布 **0/10**、FLOW **28/48**、F/L/D 不变。

> 当前 Goal 直接推进本队列；遗留 Codex/Cursor Autopilot 与 DETECT 均保持关闭。
> 当前主责（2026-09-13）：前后端全部由 Codex 接管，Astra 负责前端设计与实现，不同上下文 Astra 独立验收；不向 Cursor 派发或等待交付。下方历史队列中的 Cursor 独占、禁止 Codex 修改、交付 Cursor 和恢复 Autopilot 指令均已失效。
> 下方旧队列只作历史证据索引，旧勾选和旧“进行中”不自动计入当前 FLOW。
> 执行策略以 [完整开源成品交付计划](../../sand-iam/docs/development/sand-iam-terminal-release-runbook.md) 为准；当前动作和证据以 [任务看板](../../sand-iam/docs/development/sand-iam-task-board.md) 为准。OSS 编号保持不变，下面已完成批次和来源快照不构成当前业务实现的串行前置条件。
>
> **2026-09-18 当前状态**：连续稳定性固定从
> `2026-09-18 00:44:34 +08:00` 起算，至 `09:29:40` 已连续
> `31506.007` 秒，**3682/3682** 检查通过，且不重启计时。用户已确认
> 编号业务步骤计数口径；J2 分类与 J3 缺少 `sand_iam:grant:save` 的两轮
> 403/零残留补证均已闭合，Astra/high 最终复核
> **ACCEPT（P0/P1/P2=0）**。当前 **FLOW 48/48、七链 7/7、发布门槛
> 10/10**。Casdoor 的安全差距只作相对观察，不阻塞 SandIAM。

- [x] OSS-00 · 只读重建基线并归位现有 README、看板与验收账本
  - 验收：锁定当前 SandIAM tree、SandAdmin H1 lock、review-only 包摘要、23/23 包内检查、旧循环关闭状态；历史 28/48 不自动继承，当前复核 0/48，发布门槛 0/10。
  - 执行记录：`.codex/autopilot/executions/OSS-00.md`
- [x] OSS-01 · 审计开源来源、依赖许可证、再分发条件和发布材料缺口
  - 验收：已形成 `sand-iam-open-source-license-audit.md`；源码现含 Apache-2.0 `LICENSE`、`NOTICE`、私密漏洞报告入口及唯一 DCO 贡献机制，不再等待 license/DCO 决策。最终候选的来源可追溯性、签名和运行验收仍未完成。
- [x] OSS-02 · 复核 R01–R09 与 P01–P20 的当前候选证据
  - 验收：当前完整集合 PHP lint 507/507、non-PG/contract 114/114；测试质量 139 项、`SOURCE_MATCH=0`、`heuristic_leads=1`；R08 9/9、PHP/TS/Dart SDK 与 portal 已通过。R=8/9、P=20/20，F/L/D 不变。
- [x] OSS-03 · 完成不需宿主写入的发布预门禁
  - 验收：历史 `0.7.0-v70/v71` 仅作追溯，不能作为 0.7.1 证据。v12 内容自洽，但其旧 verifier 对 `release/unsigned`、clean committed source/hygiene PASS 的自报已被独立 Astra 推翻：它只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件；v12 仅为历史快照，正式来源 REJECT，不能作升级包。当前工作树有 22 项 tracked changes 与 6 个 untracked path roots，final commit/tree/ZIP pending。源码已有 LICENSE、NOTICE、私密漏洞报告入口和 DCO；可信签名、clean provenance、宿主与 F/L/D 证据仍缺。默认关闭的 retention worker、目录 Sync outbox、OIDC back-channel dead 恢复、85/85 环境键预检及 24 小时零容忍口径仅有静态/行为证据；PostgreSQL 用例未获授权执行，不计运行验收。
  - 执行记录：`.codex/autopilot/executions/OSS-03.md`
- [x] OSS-04 · 推进首条真实业务链的必要实现与适用验收
  - 执行：每个实现切片围绕一条业务链；已完成切片交真实验收或独立复核后，继续无直接依赖的下一需求，不等待整链或发布门槛全部通过。已有实现转真实验收，未核查不当作全局阻塞；按现有授权推进，不重复索取同一有效授权。
  - 当前动作：以任务看板当前业务段为准，按登录与授权、非 AI 业务应用、机器服务及其必要依赖选择原需求。下列包与来源信息是既有时点记录，只限制实际依赖它的安装或发布动作。
  - 历史来源 checkpoint：A′ 已按授权提交为 `61a7f13821980deca8479f9c9e5e872be92cf72a`，独立范围复核 **ACCEPT**，未 push；A′ 只包含白名单 43 files（A=4、M=39、D=0），无 migration/Vue/TS/越界路径。当前工作树 non-clean，有 22 项 tracked changes 与 6 个 untracked path roots。v12 内容自洽，但旧 verifier 对 `release/unsigned`、clean committed source/hygiene PASS 的自报已被独立 Astra 推翻：它只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件；v12 仅为历史快照，正式来源 REJECT，不能作升级包。final commit/tree/ZIP pending；主树 integrity **25/26**，唯一失败为 clean/tracked。Composer **58** 与 TypeScript `dist` **4** 已完成双隔离重建及锁校验。两次测试选择器偏差和只读 DB 复核仅能证明时间窗口内未见可见写入，不能证明此前/窗口外未写入：86 tables、ledger 38 rows、max revision 37、revision 038 rows 0、runtime 仍 0.7.0。B′/C′/D′ 尚未执行，G 未授权；不得抬高发布 **0/10**、FLOW **28/48** 或任何业务链计数。
- [x] OSS-05 · 完成剩余真实链、外部互操作、独立体验、8 小时稳定性和发布包终验
  - 执行：剩余链的独立实现和具备条件的真实验收与 OSS-04 并行，避免共享文件/数据库/服务争用；最终仍须 FLOW 48/48、发布门槛 10/10、无发布阻塞缺陷。不包含 push、正式 Release、部署或线上验证。
  - 辅助工作只在证明解除当前业务动作的必要依赖时执行；已有 runner 不因存在就必须继续扩建，最终签名、完整生命周期、外部对端与独立体验全部保留；8 小时稳定性已通过。
  - 当前预备：已增加候选绑定模板、24 小时 runner/独立 verifier、Casdoor 3 旅程 × 双方 2 轮证据门禁、七类标准客户端/真实对端互操作验证器、隔离备份恢复验证器及未参与开发者公开文档交付验证器，回归 114/114；未实际运行，不计通过。记录：`.codex/autopilot/executions/OSS-05-preflight.md`。

---

## 历史队列（停止，不作为自动执行入口）

### 历史产品主线／目标纠偏（2026-09-08）

- SandIAM 权威源码的完成标准是七条真实业务闭环 **F01–F07 全部 7/7**，并继续完成适用的 **L/D** 关口；当前模块 **20/20** 只代表实现，不代表插件完成。
- SandPackage 6.1.4 下“既有 replacement `8ecc5cec…f9e9f` 的正式只读 Gate A”已由实施者执行并经独立复核 **ACCEPT**；它不等于当前候选替换、registry/数据库恢复、retry/runtime 或宿主完成。至此冻结恢复支线，不再占用 SandIAM 产品主线。新的宿主/SandPackage 问题仅一次性交接至 `/Users/code/project/sandadmin`，SandIAM 当前任务不得继续修改宿主。
- SandAI 明确排除，由 `/Users/code/project/sand_ai` 自行核验；SandIAM 可用行业中立或非 SandAI 的受控调用端完成本插件相应闭环。
- 以一条真实链端到端为批次：页面进入 → 保存 → 刷新 → 实际生效 → 允许/拒绝 → 撤销/恢复 → 审计 → 清理。每条只在完整闭环后做一次独立验收；禁止以静态、模拟、候选或文档计数作为业务完成。CLI 优先，浏览器仅用于最终真实页面验收。

### 历史 SandIAM 终极产品闭环

范围：SandIAM 是依托 SandAdmin 交付、但应用用户与运行时鉴权不依赖宿主后台账号的完整 IAM。能力对标 Casdoor 的 IAM 核心，并增加 Sand 原生的语义服务授权、数据范围、接口治理和开发者接入体验。正常插件演示与验收使用 `/Users/code/project/sand_plugins/sandadmin-demo-host`（服务端为其 `server/` 子目录）；`/Users/code/project/sandadmin` 保持纯净通用宿主，不作为 SandIAM 演示目标。本队列只处理 IAM；Cursor 独占 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/**`，Codex 不修改该目录。

- [x] IAM-T00 · Codex · 清理越界内容并冻结终极能力矩阵
  - 验收：插件仓只保留 IAM 自身内容；以 Casdoor 官方源码/文档和当前 SandIAM 代码形成逐能力现状、缺口、模块契约、端到端流程、异常分支和验收矩阵；同步修复版本与 FLOW 结论矛盾，不能用 P0 功能替代终极目标。
- [x] IAM-T01 · Codex · 完成人类身份认证核心与安全生命周期
  - 验收：应用内注册、登录、退出、密码哈希与策略、密码重置、邮箱/手机号验证、会话列表与撤销、失败锁定、限流和全审计均有 API、迁移、自动测试与隔离 PostgreSQL 运行证据；不复用 SandAdmin 后台账号。
- [ ] UX-01 · Codex + Cursor · 完成三角色真人可用性正式验收
  - 已完成：权威源码、管理总览和三条任务路径、隐藏稳定实体路由、远程名称选择与所属层级、编辑上级反查、组织→应用→环境→调用身份与服务→动作级联、结构化策略、折叠高级 JSON 不提交、一次性凭证清理、安全操作恢复说明、可恢复错误及受限角色路径入口迁移；隔离类型检查、生产构建和契约检查通过。
  - 当前：权威源码已同步独立 SandAdmin 验收宿主，前端 3006 与登录页控制台无错误；宿主库仍为 18 表旧结构，验证码登录、`1440×900`/`1280×720` 三角色页面清单、业务夹具闭环和清理核对未完成。完成前不得标记已验收或可上线。
- [x] IAM-T02 · Codex · 完成 MFA 与无密码认证
  - 验收：TOTP、恢复码、WebAuthn/Passkey 的注册、挑战、验证、撤销和恢复流程可运行；敏感材料加密或哈希存储，重放和跨应用错用被拒绝并审计。
- [x] IAM-T03 · Codex · 完成 OAuth / OpenID Connect 与单点登录
  - 验收：授权码 + PKCE、客户端凭证、刷新令牌轮换、发现文档、JWKS、userinfo、撤销、登出和 audience/scope 校验通过协议测试；不安全流程明确拒绝。
- [ ] IAM-T04 · Codex · 完成企业与第三方身份联合、目录和同步
  - 验收：外部 OIDC/OAuth、SAML、LDAP、SCIM 的配置、映射、同步、停用和审计闭环；身份源按应用或组织实例隔离，连接失败不破坏本地身份。
- [ ] IAM-T05 · Codex · 完成 API/路由治理、资源动作目录和 SDK/中间件
  - 验收：应用可登记接口与语义动作、版本和风险等级；路由扫描只作适配，不作为稳定权限键；PHP/Webman 与前端 SDK 可完成认证、授权、数据范围和稳定错误处理；允许/拒绝均可追溯。
  - 当前：接口目录、路由绑定、决策 API、Webman 中间件、PHP/TypeScript/Dart SDK、OpenAPI 扩展和 `008` 已进入生命周期；应用/组织隔离、幂等观察、路由冲突、停用关闭失败、OpenAPI 语义保留和审计已通过 PostgreSQL 集成。真实 SandAI/非 AI 业务允许/拒绝/数据范围和登录后宿主浏览器仍未验收。
- [ ] IAM-T06 · Codex · 完成委派管理、自助门户、Webhook 和审计治理
  - 验收：平台管理员、应用管理员、终端用户三种入口边界清晰；资料、凭据、会话、MFA 自助管理可用；Webhook 签名、重试、幂等、密钥轮换和审计查询/导出通过测试。
  - 当前：应用级后台委派、资料/连接/安全概况/改密自助 API、Webhook 加密密钥/签名/幂等队列/重试 worker、范围化审计查询与 CSV 导出及 `009/010` 已进入生命周期；委派与 Webhook PostgreSQL 集成通过。真实 HTTPS 接收端、并发 worker、登录后宿主 HTTP 和三角色页面尚未验收。
- [ ] IAM-T07 · Codex · 向 Cursor 交付并验收完整管理端任务
  - 验收：冻结页面信息架构、中文字段、只展示有用列、空/错/加载/无权状态、完整 DTO 与交互；Cursor 完成源码后由 Codex 在宿主真实浏览器按三角色全流程验收。
  - 当前：T07 交接与 U-01～U-05 源码/构建已完成，`008–010` 已进入生命周期；管理端载荷已同步宿主。Cursor 因后台验证码登录硬停止，登录后需说“恢复启动 Autopilot”继续三角色浏览器验收。
- [ ] IAM-T08 · Codex · 完成宿主安装、业务联调、安全与发布验收
  - 验收：空宿主安装/升级/卸载、SandAI 放行拒绝审计、注册登录与协议闭环、备份恢复、密钥轮换、并发与安全基线全部有真实证据；分别报告可部署、已部署和线上验证状态。
  - 当前：源码迁移范围已到 `001–032`；静态包 18/18、包契约 17/17、fresh install 82 表，最终隔离 PostgreSQL 18/18（含 031/onboarding/030）通过，临时库已停止清理。根/插件 update.sql×2 被 PreToolUse 以 `SQL write without safe WHERE clause` 拒绝，uninstall 未执行，不能写成完整 SandPackage 生命周期通过。源码同步宿主后修复 `Route::match()` 致命兼容问题，42/42 worker、401/503 和前端 200 曾通过；随后一次隔离目标误判使生命周期实际作用于宿主，当前宿主 SandIAM 表为 0；无 dump、WAL 归档关闭，2026-08-17 Time Machine 快照是唯一已发现恢复点。恢复完成前暂停后续宿主验收。
- [ ] IAM-T09 · Codex + Cursor · 完成应用登录体验、品牌与消息 Provider
  - 验收：应用可配置品牌、主题、登录/注册字段和方式；Email/SMS/Captcha/Notification Provider 可管理、测试、轮换并审计；独立运行面完成登录/注册/恢复/安全门户，秘密只写不读。
  - 当前：后端候选、`011/025`、登录编排与 Captcha 已接入真实认证路径；假驱动下服务选择、密文配置、投递/Captcha、停用关闭失败和跨组织拒绝已通过 PostgreSQL 集成，功能开关默认关闭。真实供应商、宿主 HTTP、管理端/独立运行面和浏览器尚未验收。
- [ ] IAM-T10 · Codex + Cursor · 完成用户生命周期、组、邀请、导入与通用 Syncer
  - 验收：邀请、激活、禁用、软删除/恢复、组层级、批量导入导出、访客升级，以及数据库/企业目录 Syncer 的冲突、游标、重试和停用传播均通过跨应用隔离验收。
  - 当前：生命周期/用户组、邀请、访客升级、CSV 导入导出和通用 Syncer 五阶段候选与 `012–015/026` 已进入生命周期。生命周期/组/访客、邀请和 Syncer 三组 PostgreSQL 集成通过，覆盖跨应用隔离、游标、双向 outbox、冲突、缺失保护和停用熔断。真实宿主 HTTP、定时调度、企业目录驱动和页面尚未完成。
- [ ] IAM-T11 · Codex · 补齐协议与会话互操作
  - 验收：CAS、Kerberos/SPNEGO、RADIUS、动态客户端注册、Guest 逐项明确启用条件并完成标准客户端正负测试；OIDC front/back-channel logout 可互操作。
  - 当前：RFC 7591 DCR、OIDC Front/Back-Channel logout、CAS 1/2/3、Kerberos/SPNEGO fail-closed 接口和 RADIUS Access/Accounting Server 候选已落，`016–018` 已进入统一 lifecycle，相关非 PG 正负测试通过。Guest 已在 T10 完成。协议专属 PostgreSQL 服务验收、标准客户端互操作、真实 Realm/NAS，以及 RADIUS CHAP/EAP/Challenge/MFA Client 仍未完成；详见 `sand-iam-protocol-interop-v0.1.md`。
- [ ] IAM-T12 · Codex + Cursor · 完成开发者生态与安全运营
  - 验收：Dart/Flutter SDK、CLI、完整管理 API/OpenAPI、事务 Webhook 事件目录/生产者、审计保留归档告警、IP allowlist/网络策略和初始化差异/回滚闭环有真实证据。
  - 当前：Dart/Flutter SDK 与 CLI 已通过 analyze、7 项单测、示例、原生编译和帮助冒烟；管理 OpenAPI、固定事件目录、安全运营、网络策略和初始化包已形成候选。当前 PHP lint 321/321、PHP SDK 通过、非 PG 47/47、PostgreSQL 11/11；宿主启动兼容已修复。worker 并发/故障、真实代理、律序/进销存 Flutter、登录后宿主 UI/浏览器和清除演练未验收。
- [x] LIC-A01 · Codex · 完成卡密签发的领域归属与安全迁移设计
  - 验收：明确独立服务或插件归属、与 SandIAM 的集成契约、现有代码必须废弃项、在线/离线验签、设备解绑、并发、审计和渐进迁移方案；不把商业许可错误建模成身份权限。
  - 证据：`docs/architecture/sand-license-boundary.md` 已冻结 SandLicense 独立领域、状态机/模型、管理与运行 API、SandIAM 动作、JWS/Ed25519/DPoP、设备释放与恢复、事务/幂等/并发、密钥治理、影子迁移和验收矩阵。用户已提供旧 `LicenseController::verify()`/`LicenseLogic::encryptData()` 完整代码；当前工作区仍未定位权威文件路径，实际迁移前须补旧服务/客户端/数据库逐文件盘点。

---

## 历史：SandIAM P0

范围：`sand-iam/` 是唯一源码包；插件安装、演示与功能验收宿主为 `/Users/code/project/sand_plugins/sandadmin-demo-host`。`/Users/code/project/sandadmin` 是纯净通用宿主，不作本插件演示。仅 PostgreSQL，表前缀 `sand_iam_*`。

协作边界与实时状态：

- 约定：`sand-iam/docs/development/sand-iam-pg-collaboration.md`
- 看板：`sand-iam/docs/development/sand-iam-task-board.md`
- Codex 负责 P0 契约、后端、迁移、运行时鉴权和宿主验收；本队列不含 Cursor 路径。
- Cursor 独占 `sandadmin-artd/src/views/plugin/sand-iam/`；U-01 页面壳已并行完成，不阻塞 IAM-01。
- IAM-01 冻结 Adapter 并标「可消费」后，SandAI `SAND-113C` 即可继续；源码路径 `/Users/code/project/sand_plugins/sand-iam`。

- [x] IAM-01 · Codex · 冻结 P0 领域、PostgreSQL 模型、管理/运行时 API、错误码、SandAI Adapter 与协作交接
  - 证据：[P0 契约 v0.1](../../sand-iam/docs/development/sand-iam-p0-contract.md)；已解锁 IAM-02、Cursor U-02 和 SandAI `SAND-113C`。
- [x] IAM-02 · Codex · 实现 PostgreSQL P0 schema 与插件安装、升级、卸载生命周期
  - 证据：隔离库 `saiadmin_iam_acceptance_20260813` 当前 `0.1.1` 回归安装为 18 张 `sand_iam_*` 表、213 个约束、53 个索引且无非 SandIAM 表；包根 update 成功；uninstall 后为 0 表。SaiPackage 源码证实只执行包根 lifecycle SQL。
- [x] IAM-03 · Codex · 实现控制面与运行时身份上下文 API
  - 验收：组织隔离、应用/环境/工作负载客户端/服务授权、凭证一次展示及轮换撤销、稳定错误码均有接口或自动化验证；Adapter 不跨库读 `sand_iam_*` 表。
  - 已验证：SandAdmin 临时验收服务的后台入口返回 401；运行时未配置 signer 返回稳定 503；回滚事务中已验证 context 签发、校验、授权撤销后的 403 与审计，且测试记录为 0 残留。
  - 边界：真实部署 signer 与已登录管理会话保留给 IAM-05 的宿主端到端验收；本项不生成或落盘密钥、不猜测密码。
- [x] IAM-04 · Codex · 实现策略、数据范围与审计的读写覆盖
  - 已实现：策略/范围契约、身份/角色/资源/策略/管理员组织委派/审计管理接口，以及控制面组织授权限制；`0.1.1` 升级已在隔离库和验收库创建委派表。
  - 已验证：真实验收宿主完成 `list/read/create/update/delete/export/batch` 七种操作允许、跨组织稳定拒绝 `SAND_IAM_ORGANIZATION_ACCESS_DENIED`、角色允许、条件不匹配拒绝、同优先级拒绝优先、无策略拒绝、范围拒绝及审计；两轮 PostgreSQL 测试均回滚且 `residual_org=0`。为兼容宿主进行中的 `saiadmin → sandadmin` 命名迁移，插件只在旧命名空间不可用时注册运行时类别名。
- [x] IAM-05 · Codex · 在 SandAdmin 完成 SandIAM 部署期上下文端到端验收，并向 Cursor 交付冻结接口
  - 已验证：官方 SandAdmin 宿主的插件载荷与源码一致，部署期 signer 已安全写入宿主环境并完整重启生效；临时且已清理的 `saiadmin` 夹具完成运行时 HTTP `issue=200`、`verify=200`、错误 audience `403`、凭证撤销后 `401` 和审计存在验证，9 项夹具/关联审计残留均为 0。交接包已冻结路由、DTO、权限码、错误码与字段字典。
  - 边界：SandAI Adapter 的实际业务 API 放行/拒绝属于 A-01 双插件联调，不把它写成 SandIAM P0 已完成证据。
- [ ] A-01 · Codex + Cursor · 以 SandAI 真实运行 API 验证 SandIAM Adapter 的放行、拒绝与审计路径
  - 前置：IAM-05、U-03；需要独立的 SandAI 联调夹具和验收授权。

### 2026-09-12 SandIAM 0.7.1 本地提交记录（未 push）

- 已提交：`6e190953dc260f32e7428e751c3c6318dc9fcd8d`（HOST-202609-001）、`a7edcebb37ea06b2da3dc445d5b7307d3111a7ab`（生命周期）、`1aba9b444ae8ec69972faa2c1e6fe8e6ebc32d48`（外部验收）。
- `a7edceb` 的非 vendor whitespace 检查为零；完整检查仅有 18 个原样纳入第三方 vendor 文件的 whitespace 报告，不作整体通过结论。
- 7 个备份恢复阻塞文件仍未提交且不计 G：`backup-and-restore.md`、`backup_recovery_evidence_non_pg_test.php`、`backup_restore_command_guard_non_pg_test.php`、`external_acceptance_template_non_pg_test.php`、`validate-backup-recovery.php`、`prepare-external-acceptance.php`、`tools/README.md`。
