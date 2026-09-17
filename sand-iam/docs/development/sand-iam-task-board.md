# SandIAM 任务看板

> 当前执行契约：[完整开源成品交付计划](sand-iam-terminal-release-runbook.md)。本看板保留实时动作和证据；OSS 编号沿用唯一队列，历史完成记录不决定当前执行先后。
>
> 当前主责（2026-09-13）：全部前后端由 Codex 接管；Astra 负责前端设计与实现，不同上下文 Astra 独立验收。不向 Cursor 派发、不等待其交付、不恢复旧 Autopilot/DETECT。历史作者、执行记录和旧分工仅用于追溯；当前接续任务为 `01a099f1-08ad-7c73-ad70-da84c7481331`。

## 2026-09-12 完整开源成品 Goal

### 当前状态（2026-09-17）

- **当前接续状态（v32 review-only）**：v24 的独立交付实跑已完成
  **8/8 步、14/14 断言**并由未参与开发者给出 D02 **ACCEPT**；正式卸载、
  签名包 fresh install、首次配置、人类 Consumer、机器 Provider、失败恢复、
  页面和最终清理均有记录。因此当前严格计分更新为需求 **8/9**、模块
  **20/20**、正式 FLOW **6/7**、本地业务闭环 **4/4**、可上线部署
  **8/8**，合计 **46/48（95.8%）**；七链仍为 **7/7**。剩余原子只有
  **R09、F07**。按当前已登记证据，十项发布门槛为 **8/10**，剩余
  Casdoor 对照与同一最终候选连续 24 小时；候选变化后仍须按影响范围重绑
  其他门槛证据。
- R09 v26 已执行 SandIAM/Casdoor 各三条旅程、每条两轮，共 12 轮核心
  运行，并完成六次 SandIAM 正式卸载/fresh install 与最终清理；但正式
  validator 仍为 **REJECT**。拒绝项包括人工操作/命令总量未完整计量、
  J1 缺少原生 OpenAPI 导入、J3 冻结断言与公开委派契约冲突，以及 Casdoor
  安全对照和截图证据缺口。该轮不能计入 R09。
- 权威源码已补原生 OpenAPI 3.0/3.1 预览/应用、管理 API 与 PHP/TypeScript/
  Dart SDK、管理端导入体验，并统一修复 SandIAM 敏感中间件把
  `Webman\Http\Response` 错收窄为 `support\Response` 导致可恢复错误变成
  500 的问题。v32 review-only 实跑确认 2 个 API、2 条路由绑定、指纹、
  过期预览 409、更新回读、缺失路由停用、3 条批次审计、原文不入审计和
  零夹具残留；独立复核 **ACCEPT（P0/P1/P2=0）**。v32 仍是 dirty、
  未签名、非发布候选，只关闭下一轮 R09 的产品前置，不增加 R09/F07 分子。
- 完整非数据库回归现为 **186/186**，PHP lint **644**；中间件 Response
  契约覆盖包含 SCIM 的 **13** 个实际 `json()` 使用者。下一步是冻结并提交
  最终源码候选，再在同一 clean、签名候选上重跑 R09、F07 和正式 86,400 秒
  稳定性门槛。当前未 push、公开 Release、生产部署或线上验证。

- 本轮业务与运行载荷基线为 clean 提交
  `4ff181d6cbfb99396fd0d1c933a3b32acc7047a4`、SandIAM tree
  `92fd46454dd87484e49b77c5cfe4364a78966428`。v16 unsigned 候选为
  700 项，ZIP SHA-256 `e7c93250…db801`，两套 Git stage 重建字节一致，
  package integrity **26/26**。v16 只修复 PostgreSQL 登录并发测试的
  聚合类型误判，发布载荷与已正式安装、完成运行目录对账的 v15 字节一致。
  本节进度回写属于发布包排除的内部开发记录，不改变运行载荷摘要。
- 当前同候选证据：C01/L01/L02 **23/23**、C02 **44/44**、C03
  **26/26**、C04 双 worker 并发通过、C05 **37/37**、C06 **17/17**、
  C07 **14/14**；L03 机器 Provider 和 L04 非 AI Consumer 均通过真实
  allow/deny/revoke、副作用、双侧审计和清理。失败轮次的 C02/C05 子对象
  已在固定前缀、禁用根锚点和精确对象全集断言后事务清除。
- 四角色、两个目标视口、真实宿主 API、允许/拒绝、审计、撤销、清理和
  重复复核已闭合。严格计分更新为需求 **8/9**、模块 **20/20**、正式
  FLOW **7/7**、本地业务闭环 **4/4**、可上线部署 **5/8**，合计
  **44/48（91.7%）**；七链 **7/7**，发布门槛 **5/10**。
- 已通过部署原子为 D01、D03、D05、D07、D08。D07 由登录重复竞争、
  Onboarding 双 worker、SecurityOperation 双连接、C04 配额竞争、C02
  两代同步、C07 Webhook worker、OIDC logout 恢复、MFA/Passkey、
  OIDC signing-key 轮换及 Webhook 密钥轮换/双连接重放竞争共同闭合；
  两个密钥轮换测试已保证失败同样进入 `finally` 清理。24 小时资源曲线
  仍是独立发布门槛，不由 D07 代替。
- 剩余原子为 R09、D02、D04、D06。剩余发布门槛为标准客户端协议
  互操作、并发加隔离备份恢复、同一最终候选 24 小时、未参与开发者 Casdoor
  对照，以及正式包外签名与独立文档交付。D06 需要预创建的隔离恢复目标；
  本任务不建数据库。D02 需要独立可信公钥/发布渠道和未参与开发者实跑；
  D04、R09 与 Casdoor 需要独立外部客户端/参与者。
- 证据见
  [`../../../.artifacts/sand-iam-0.7.3-current-candidate-evidence.md`](../../../.artifacts/sand-iam-0.7.3-current-candidate-evidence.md)。
  PostgreSQL 未被检查或启动；未 push、公开 Release、部署生产或线上验证。

### 签名链初审 checkpoint（2026-09-12）

- 签名链初审记录为 **3P2**；修复后由 Astra 复核 **ACCEPT（P0/P1/P2=0）**，helper SHA-256 记录为 `78f9…`（仅作该次 helper 身份锚点）。
- 初审结论仍受边界约束：临时测试签名仅为非正式测试材料；受控目录、同 UID 的 TOCTOU 防护、真实密钥信任与从独立 Git 来源重建必须分别具备证据。当前没有正式 signing、真实密钥信任或 Git 独立重建证据。
- 该 checkpoint 不改变任何 FLOW 或发布计分：正式 signing **未执行**，发布 **0/10**，FLOW **28/48**，F/L/D 计数不变。

- 当前 Goal 是唯一活跃执行目标；2026-09-08 及更早的 SandIAM/Cursor/Codex Autopilot 任务均为历史输入，不恢复其循环。
- 2026-09-12 当前态复核：Codex 任务列表中本工作区仅本 Goal 为 `active`，旧 SandIAM 任务均为 `notLoaded`；本机 automation 配置中没有 SandIAM/sand_plugins 定时项。该结论来自本轮只读任务列表与配置扫描，没有修改外部状态。
- 旧任务状态不得自动计入当前通过。可复用证据先绑定当前源码树、候选摘要和适用环境，再回写同一[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)。
- 当前复核计分：需求 **8/9**、模块 **20/20**、正式 FLOW **0/7**、本地业务闭环 **0/4**、可上线部署 **0/8**，合计 **28/48**；发布门槛 **0/10**。这是本轮逐原子复核后的新结论，不是旧任务自动继承。
- **2026-09-13 OSS 静态材料门禁批次**：W3C vendored schema 已补独立 `NOTICE` 与 SBOM component；SDK 已补独立 `LICENSE`/`NOTICE` 与 metadata；public path 漏检已修复。hygiene **16/16**、policy **16/16**、SBOM **80**，Astra 独立复核 **ACCEPT（P0/P1/P2=0）**。本批只修复开源材料静态门禁，不改变 R09、P **20/20**、F **0/7**、L **0/4**、D **0/8**、FLOW **28/48**、C **0/7** 或发布 **0/10**。
- 最终 clean 候选、正式签名、宿主 HTTP（默认关闭）、宿主生命周期、外部互操作、Casdoor 对照和同一最终候选 24 小时运行仍未通过；当前不得称为可发布、已部署或线上验证。
- A′ 已按授权提交为 `61a7f13821980deca8479f9c9e5e872be92cf72a`，独立范围复核 **ACCEPT**，未 push；仅含白名单 43 files（A=4、M=39、D=0），无 migration/Vue/TS/越界路径。当前工作树 non-clean，有 22 项 tracked changes 与 6 个 untracked path roots；来源/事务修复仍未提交。主树 integrity **25/26**，唯一失败为 clean/tracked。
- v12 archive 内容自洽，但其旧 verifier 对 manifest/validation 自报 `release/unsigned`、clean committed source 与 hygiene PASS 已被独立 Astra 推翻：它只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件。v12 仅是历史快照，正式来源验收 **REJECT**，不得作为正式来源或升级包。Composer **58** 与 TypeScript `dist` **4** 已完成双隔离重建和锁校验。
- 两次测试选择器偏差已记录；只读 DB 复核只在时间窗口内未见可见写入，不能证明此前或窗口外无写入：86 tables、ledger 38 rows、max revision 37、revision 038 rows 0、runtime 仍 0.7.0。B′/C′/D′ 尚未执行，G 未授权；发布 **0/10**、FLOW **28/48**、业务链计数均不变，当前仍未部署、未线上验证。历史 `0.7.0-v70/v71` 摘要不能作为 0.7.1 证据。
- **Endurance contract checkpoint（write-gate begin --replace 归档）**：v1 审计记录为 **4P1 + 3P2**；v2 分三批修复，最终由 Astra 工具复核 **ACCEPT（P0/P1/P2=0）**。该 ACCEPT 只证明离线 contract/tool 修复，不是实际长跑通过。协议明确为协作式可信环境边界：JSONL 哈希链只提供完整性/篡改可见性，不是签名、身份认证或防伪；Git 来源未独立重建，collector/probe 的真实性仍依赖受控环境、独立保管和可信对端。所有 fixture 均不是 86400 秒；真实同一最终候选 24 小时运行尚未开始（**0**）。external validators 的 fixture 修复即使离线结构 ACCEPT，也不表示 ready。发布门槛仍 **0/10**，FLOW **28/48**，F/L/D 计数不变。

### 2026-09-13 三批离线材料回写

- **vendor CRLF 规范化批次**：完成 vendor CRLF 规范化；package integrity **25/26**；Astra independent **ACCEPT**。唯一失败为 clean/tracked source，不能把 dirty clean-source 误报为正式来源通过；不改变 P **20/20**、F **0/7**、L **0/4**、D **0/8**、FLOW **28/48** 或发布 **0/10**。
- **public docs 13 文档批次**：`developer_quickstart` **PASS**、payload **16/16**、links **16/16**，Astra independent **ACCEPT**。本批静态 P1 关闭；`HOST-202609-002-sandpackage-frontend-activation-contract.md` 已发送，待宿主接收处理（不等于已接单或已修复），真实独立开发者旅程仍未通过；不改变 F/L/D、FLOW 或发布计分。
- **Consumer A / Provider B 离线接入批次（最终结果）**：Consumer A standalone 源码离线 Astra **ACCEPT（P0/P1/P2=0）**，autoload **6/6**、lint **11/11**、offline、payload **16/16** 及 early rejection audit 通过；Provider B provider+caller 源码离线 Astra **ACCEPT（P0/P1/P2=0）**，tests **13/13**、lint **15/15**、payload **16/16**，Git/无 Git offline Composer+autoload 及 failure audit 通过。两者均未完成真实 HTTP、PostgreSQL、撤权或审计，因此 **L04=0**，F/L/D、FLOW **28/48** 和发布 **0/10** 不变。临时目录 `provider-b-independent.6VkMjk` 清理被 hook 拒绝；该临时状态不写成发布包内容，也不改变上述计分。
- **Consumer live v2 整体离线契约批次**：nonPG **46/46**、schema static-rule **167**、lint **6/6**、payload **16/16**，Astra 独立复核 **ACCEPT（P0/P1/P2=0）**；完整 plan capability 绑定、信任文件/父目录隔离、正确 admin URL/effect 与 map 键序语义均已覆盖。无 live adapter、真实 cleanup、HTTP 或 DB，`real_l04=false`；同 UID TOCTOU 与标准 JSON Schema 尚未完全证明，因此 **F/L/D/发布门槛** 计数不变。
- **Consumer acceptance runner / 防循环核查**：consumer acceptance runner v1 strict validate **49/49**，Astra **ACCEPT（P0/P1/P2=0）**；live 明确 `unsupported`、`real_l04=false`，不计真实 L04。只读核查确认无 `automation.toml`、Codex/Cursor autopilot disabled、旧任务无近期 `active` 证据；App list 工具被 hook 拦截，按边界记录，不外推为运行或验收证据。`HOST-202609-002-sandpackage-frontend-activation-contract.md` 已发送，待宿主接收处理（不等于已接单或已修复）。所有计数不变。

### 当前执行顺序

#### 2026-09-16 Hook 修复与七链运行前置

- 目标原文的页面体验门槛明确包含平台管理员、客户主体管理员、应用管理员、独立应用用户四个角色。当前产品目标、真人可用性口径、发布执行单和终验定义已统一为四角色，并将客户主体管理员与应用管理员的范围、越权拒绝和撤权行为分别列出；历史快照中的“三角色”执行记录不改写。该修正只补齐需求与验收分母的一致性，不代表四角色浏览器已执行，D05、F/L 和发布门槛分子均不增加。
- 全局 Shell Guard 对路径名中的 `update`/`delete` 进行 SQL 误分类的问题已修复。普通 `git diff` 没有获得豁免；新入口只接受固定命令 `/usr/bin/python3 -I /Users/supdger/.codex/tools/safe_source_diff.py -- <受控源码路径>`，并校验工具固定 SHA-256 `546e2412496352d8a922f9c7dc8840aaa6016dffe4aff0e15130c913185e8807`、属主、链接数和写权限。工具从不可变 HEAD blob 与逐级 `O_NOFOLLOW` 的工作树文件生成差异，不调用 Git diff driver、textconv、clean/process filter 或 fsmonitor；末级以 `O_NONBLOCK` 打开后拒绝 FIFO 等非普通文件。工具/Guard 回归 **9/9**、独立攻击边界 **2/2**，Astra/high 复验 **ACCEPT（P0/P1/P2=0）**；安装后真实 `update*.sql` 源码差异命令通过，无 WHERE 的 `UPDATE` 和普通 `git diff -- .../update.sql` 继续拒绝。该修复只解除开发工具误判，不增加 SandIAM FLOW、业务链或发布计分。
- 当前 fresh-install demo 已通过 Webman PDO 只读事务盘点：客户主体、应用、环境、身份、角色、资源、工作负载客户端、认证策略和应用体验均为 **0**；仅内置 SandIAM 服务及 `identity.guest.upsert` 动作各 **1**。因此 C02–C07 示例计划中的 `__REQUIRED_*_ID__` 不能从当前宿主直接替换，必须先通过正常业务 API 创建受控前置，并为其准备失败中断时的精确清理；不得直接写表或用不存在的预置数据冒充。
- C01 当前固定前缀为 `sand_iam_acceptance_0916c01a7f3b2e8d_`，计划已在任何 HTTP/数据库写入前通过验证；执行器在 `finally` 中恢复 `.env`、重载 Webman 并执行部分/完整夹具清理。真实运行仍等待“C01 业务写入、临时配置重载及自动清理”的本次精确授权，未执行前 C01、F/L/D 和发布分子均不增加。
- 用户随后已批准 C01 业务写入、临时 Webman 配置重载及自动清理。首次执行因脚本未把 captcha 响应的文件会话 Cookie 带入登录而在业务写入前返回“验证码错误”；脚本已修复 Cookie 绑定并加强失败时 `.env` 字节恢复验证。复跑后组织、应用、环境、应用委派四项真实创建成功，范围内读取断言失败；清理在删除前因当前 ThinkORM 不支持 `Query->exists()` 抛错，事务回滚。Webman PDO 只读核对本轮对象 ID 均为 1、四条创建审计均由 admin 1 产生，当前四对象仍完整存在，没有部分删除；`.env` 已恢复原 SHA-256 `4a6ce68009af8c7d2073ff93347bcd523064e9ea9a630b940e2667a768cdf5b2`。
- 权威 `DatabaseAcceptanceFixtureStore` 的三处存在性判断已改为当前 ThinkORM 支持的 `count() > 0`，覆盖创建者审计、MFA 登录挑战审计和策略版本外部引用检查；数据库 store 与 fixture service 非 PostgreSQL回归均通过。为履行已经批准的自动清理，CLI 临时加载权威修复 Store 后调用原 `AcceptanceFixtureService`：四对象完整匹配，环境和应用委派各物理清理 1 项，组织和应用各停用 1 项；随后状态核对为 `environment=0`、`admin_application_grant=0`、`active_business_residual=0`，组织/应用保留审计锚点且 `status=2`。本轮夹具已安全收口。
- C01 业务失败另由宿主日志定位为 `CheckAuth` 在 SandIAM 范围守卫之前拒绝用户 2：该账号没有 `sand_iam:environment:read` 宿主权限。Webman PDO 只读盘点确认现有非超级管理员 23–52 中有多组账号已具备该权限，可复用现有账号而无需写宿主权限。正式复跑还需先把上述 Store 修复受控同步至 demo，并换用新前缀和具备宿主权限的范围内/范围外账号；在此之前 C01 仍为失败，七链、F/L/D 和发布分子不增加。
- C01 管理 API 真实链已于 `2026-09-16T02:56:48+0800` 使用工作树源码和当前 demo 通过 **13/13**：主体、应用、环境、应用委派创建，范围内放行、范围外拒绝、精确资源审计、委派停用后同一账号立即拒绝、环境停用、平台管理员读取停用对象、受控物理清理和零残留均通过。中途发现 live driver 的 fallback request ID 计数器被箭头函数按值捕获，导致每步重复 `${prefix}live_1`，第二条拒绝审计触发唯一键冲突；现改为按引用递增，并增加 C01 全请求编号唯一性回归，测试通过。最终前缀 `sand_iam_acceptance_0916c01f2a48bd70_`，捕获四类 ID 均为 `11`，cleanup `confirmed`；临时 `.env`、autoload 和 route 配置均恢复原 SHA-256。用户再次精确授权后，`2026-09-16T03:58:11+0800` 以新前缀 `sand_iam_acceptance_0916c01bed047c54_` 重跑仍为 **13/13**，四类 ID 均为 `12`、cleanup `confirmed`，配置恢复且 Webman `/core/captcha` 返回 HTTP/业务码 `200`；一次性计划和执行包装已清理。证据见 [`../../../.artifacts/sand-iam-c01-live-20260916.md`](../../../.artifacts/sand-iam-c01-live-20260916.md)。该结果确认 C01 的真实管理 API 切片，尚未补齐浏览器维度、clean 最终候选和其余六链，因此正式 F/L/D、完整目标 **29/48**、发布门槛 **1/10** 暂不增加。
- C03 登录/MFA live tooling 已于 `2026-09-16` 修复执行前缺口：应用用户 Authorization 仅可来自本轮注册、登录或 MFA 验证响应捕获；`sand_iam_auth_challenge` 已纳入按 identity/application 发现的完整派生集合、外键顺序物理清理和零残留；组织、应用、认证策略、注册、普通登录、MFA 启动、MFA 验证后中断及完整成功均有互斥清理分支。C03 现在自行创建本轮专用组织、应用和允许注册的认证策略，删除认证策略及全部认证派生物，最后以 C01 v2 语义停用组织/应用审计锚点并要求 active residual=0，不再依赖 fresh host 预置业务对象。认证策略跨应用边界、策略单独状态查询、注册/组织写响应丢失的 `not_confirmed` 语义，以及认证对象和根对象两组零残留 preflight 均已补齐；相关 PHP lint、human auth、MFA、fixture service、database store 和 terminal runner 非 PG 回归均通过，独立 Astra/high 对最新四项 P1 复验 **ACCEPT**。该批只证明 C03 执行工具源码准备；真实 HTTP/浏览器尚未执行，正式 **29/48、C 0/7、发布 1/10** 不变。
- C03 执行包复核随后发现未知认证异常会落入宿主 SandAdmin Handler：宿主在 `debug=true` 时回显完整请求参数，并在 `report()` 记录完整请求体。权威 SandIAM Handler 现覆盖 `report/render`：系统异常日志只保留方法、去查询串路径、异常类型/文件/行号，响应固定为通用 code 500；不记录请求参数、异常消息或堆栈，业务 `ApiException` HTTP 状态保持。新增回归证明 password、challenge、TOTP、查询串 secret 和 `request_param` 均不泄露；相关定向回归全部通过，当前全量非 PG 为 **163/164**，唯一失败是工作树变更使 release provenance 按设计拒绝非固定候选。Astra/high 对 Handler 及一次性 C03 包复核 **ACCEPT（P1/P2=0）**。真实 C03 仍等待固定脚本、前缀、数据库写入、临时配置与服务重载的精确授权，未执行前计数不变。
- C04 `service_grant_invocation_control_pg_integration_test.php` 的旧安全阻塞已解除：配额并发正常路径先 join 全部 worker 再读取结果；屏障、启动或结果异常时，`finally` 先 terminate＋`proc_close` 残余 worker，再删除临时文件，外层随后才清理数据库夹具，避免活跃子进程与清理竞态。执行入口显式加载宿主 `.env`，将临时或既有签名 key 同步至 OS 环境、`$_ENV` 与 `$_SERVER`，父/worker 写入前均验证权威源码/配置路径和预期数据库名；清理后按本轮精确 ID 对审计、配额、调用记录、授权、凭证、调用身份、环境、应用、组织、服务动作和服务逐表查零。lint **2/2**、空 key 行为自测和非 PG 保护回归通过，Astra/high 另以无数据库短进程验证父子 key 一致、正常等待、异常终止及重复调用并 **ACCEPT**。本批未运行 PostgreSQL/HTTP/服务，不能计作真实并发、C04、F/L/D 或发布通过；数据库名护栏也不替代实际连接主机身份核验。旧“Hook 阻止该必要补丁”的记录仅作历史，不再是当前阻塞。
- **C03/C04 授权实跑（2026-09-16）**：C04 已在现有 `sandadmin` PostgreSQL 运行两 PHP worker 并发回归并通过，覆盖配额竞争、耗尽、幂等、凭证轮换/撤销、授权撤销重验；按本轮 ID 对 11 类业务/审计记录逐项查零。C03 首轮把已撤销 token 的认证失败错误变成 HTTP 200；根因不是会话撤销失效，而是 `PortalSensitiveResponseMiddleware::secure()` 只接受较窄的 `support\Response`，真实异常分支返回基础 `Webman\Http\Response` 后触发 `TypeError`。权威源码已改用基础响应类型，执行器新增任何夹具写入前的无效 token HTTP 401 探针。新前缀最终实跑 **26/26**，覆盖注册、普通登录、TOTP、错误 MFA、MFA challenge、两类会话撤销、撤销后拒绝、审计归属、清理和零残留；三次前缀只读复核均为 active organization/application `0/0`，保留行均是契约声明的 `status=2` 审计锚点；临时 `.env`/autoload 已恢复原哈希，Webman 恢复守护运行且健康。证据见 [`../../../.artifacts/sand-iam-c03-c04-live-20260916.md`](../../../.artifacts/sand-iam-c03-c04-live-20260916.md)。C03 尚缺应用用户浏览器、Passkey、自助全路径；C04 尚缺真实 provider 业务副作用和双侧审计，因此严格 **C 0/7、F 0/7、L 0/4、D 1/8、29/48、发布 1/10** 暂不增加。
- **L04 实体范围双侧审计与真实执行准备**：独立 PHP Consumer 原 `authorizeEntity()` 只在 SDK 本地匹配实体 scope，跨范围拒绝时 SandIAM 已留下 `authorize.allowed`，业务侧却留下 deny，无法满足 L04 双侧一致。现将后端已加载对象解析出的 `entity_attributes` 与路由 `attributes` 分字段提交；SandIAM 在粗粒度允许后调用权威 `assertScope()` 写 `scope.allowed/denied`，范围拒绝返回正常 deny 决定，允许结果必须带 `scope_checked=true`，SDK 仍本地复核并对旧服务端失败关闭。新增 `non-ai-business-consumer` 受控清理链，覆盖业务动作、资源、接口目录、路由绑定、策略及完整策略版本；同前缀额外对象在任何清理写入前拒绝。standalone consumer 的读/关 API code 改为配置注入并贯穿业务审计；Composer 本地 SDK 约束由无法安装的 `*` 修成明确 `dev-main`，生成 lock 后 `composer install` 和 SDK autoload 实测通过。`standalone-consumer-live-acceptance.php` 已固化现有 `sandadmin` 数据库名/同名表拒绝、两张临时业务表、正常管理 API 建夹具、独立 8088 consumer、allow/scope deny、同一客户主体下第二应用 token 的跨应用拒绝、撤销后 deny、六组脱敏双侧审计摘要及受控清理；业务/认证子对象物理清理，组织和两个应用经正常 API 停用并作为审计锚点保留。明确无建库语句。完整插件 non-PG（排除按设计拒绝脏工作树的 provenance）为 **140/140**。自动审批因本轮精确授权仅覆盖 C03/C04，拒绝 L04 的 DDL、业务写入、服务重载及 consumer 启停；真实 L04 未执行，严格计数仍为 **C 0/7、F 0/7、L 0/4、D 1/8、29/48、发布 1/10**。
- **L03 机器服务真实执行准备**：Provider B 的 service code、audience、action 已改为部署时固定的受控配置，调用请求仍不能覆盖；SDK 上下文签发、服务端 claims 校验以及成功/失败业务审计均使用同一配置，默认示例值保持兼容。Composer 依赖锁定为本仓 PHP SDK `dev-main`，`vendor/` 明确排除，Provider 行为回归由 14 项扩为 **17/17**。新增 `machine-service-live-acceptance.php`，只允许现有 `sandadmin` 数据库并拒绝任何已有 `provider_b_*` 表，计划通过正常管理 API 创建组织、应用、环境、调用身份、服务、动作、授权和一次性凭证，启动独立 8089 Provider，验证允许调用、同键重放只产生一次业务副作用、错误 audience/action、精确篡改签名、凭证撤销后旧上下文拒绝、SandIAM/Provider 双侧审计及精确零残留；成功链先通过管理 API 撤销/停用，再物理清理机器子对象，保留 `status=2` 的组织/应用审计锚点与 SandIAM 审计，并输出不含秘密的双侧审计摘要。不含建库语句。运行器契约 **15/15**，完整插件 non-PG（排除 dirty provenance）为 **140/140**。本批只实现源码与执行工具，未运行 PostgreSQL、HTTP 或服务，L03/C04 及严格计数不增加。
- **L03/L04 授权实跑（2026-09-16）**：L03 最终前缀 `sand_iam_acceptance_ace6f88ea1221702_`，独立 Provider B 完成真实允许调用、幂等重放仅一次副作用、错 audience/action、签名篡改、凭证撤销后旧上下文 401、4 组双侧审计和机器子对象/三张临时表零残留，满足 L03。L04 在原始安全异常处理器下以最终前缀 `sand_iam_acceptance_2d275e59a71f7c41_` 再次通过：独立 Consumer 完成登录令牌读取 200、实体 scope 拒绝 403、真实关闭 `open/version=1 → closed/version=2`、越权关闭无副作用、跨应用令牌 403、会话撤销后 401、6 组双侧审计、业务/认证子对象和两张临时业务表清零，组织及两应用以 `status=2` 保留审计锚点，满足 L04。实跑修复了路由模板正则分隔符冲突、多创建请求审计集合误判以及 PostgreSQL `count(*) FOR UPDATE`；失败轮次经精确创建审计和正式清理 API 恢复，复核全部 L04 子对象为 0。完整插件 non-PG（排除 dirty provenance）**140/140**。证据见 [`../../../.artifacts/sand-iam-l03-l04-live-20260916.md`](../../../.artifacts/sand-iam-l03-l04-live-20260916.md)。严格计分更新为 **R 8/9、P 20/20、F 0/7、L 2/4、D 1/8，31/48（64.6%）**；C01–C07 仍为 **0/7**；发布门槛新增“非 AI 业务应用 + 机器调用服务接入”，更新为 **2/10**。未提交、未推送、未部署生产、未线上验证。
- **L01 管理切片 / L02 授权实跑（2026-09-16）**：最终复跑 **46/46**。平台、组织、应用管理员在 1440×900 与 1280×720 进入真实 SandAdmin 业务页面；应用管理员范围内读取成功、跨应用业务码 403，撤权后再次为 403。独立应用入口完成注册、资料更新、TOTP、恢复码、Passkey、因子撤销、改密、重新登录、会话撤销，旧会话业务码 401；L02 清理零残留。动态恢复器同步清理 5 组失败 A 夹具与 5 组 B 对照夹具，旧主体只保留 `status=2` 审计锚点，临时宿主用户/角色删除，三个临时端口关闭并恢复 `.env`/autoload 哈希。证据见 [`../../../.artifacts/sand-iam-l01-l02-live-20260916.md`](../../../.artifacts/sand-iam-l01-l02-live-20260916.md)。L02 满足冻结定义；L01 尚缺完整管理员身份/授权/通知配置，不提前计分。严格计分更新为 **R 8/9、P 20/20、F 0/7、L 3/4、D 1/8，32/48（66.7%）**；C01–C07 仍为 **0/7**，发布门槛仍为 **2/10**。未提交、未推送、未部署生产、未线上验证。
- **L01/L02 验收秘密清理加固（2026-09-16）**：复核发现旧包装器保留了包含短期令牌、TOTP/恢复材料和 Webhook 一次性密钥的 `0600` 原始报告，运行日志则以 `0644` 创建；应用用户截图还在恢复码重新生成后立即拍摄。现已精确删除旧报告、Cookie、响应头、C03/L03/L04 运行日志及两张可能包含恢复码的应用用户截图；管理员截图保留。包装器改为启动前删除同名旧文件、以 `0600` 预创建全部日志、成功或失败均删除计划/报告/日志/证书/私钥；应用用户截图改为重新加载页面并确认恢复码提示消失后生成。JS/PHP 语法检查通过，`/private/tmp/sandiam-l01-l02-*` 残留为零。替代应用用户截图须随下一次已授权 L01 实跑生成；本安全加固不改变 **32/48、C 0/7、发布 2/10**。
- **C05 路由清单预检/确认实现（2026-09-16）**：原 C05 计划通过 `/api-route-binding/save` 手工创建路由绑定，绕过了冻结业务链要求的“路由清单预检/确认”。现新增独立 `route-manifest/preview|apply` 管理接口：预检返回稳定 SHA-256，确认按哈希失败关闭并使用幂等请求；实际应用响应返回绑定 ID 与可追溯的创建审计请求号。管理页面同时支持完整 onboarding 和独立 `sand-iam.route-sync/v1`，冲突/未关联接口时禁止确认；PHP、TypeScript、Dart 管理 SDK 均使用同一公开接口。C05 live plan 已改为 `API 目录 → 路由清单预检 → 哈希确认应用 → 策略/真实调用`，受控清理消费实际绑定 ID 和实际创建审计号。后端行为、runner、PHP/TS/Dart SDK、前端 helper/context 定向回归全部通过；完整 non-PG **140/141**，唯一失败仍是工作树变化时按设计拒绝固定发布来源的 `release_provenance_non_pg_test.php`。该批完成的是 C05 缺失功能与可执行计划，尚未同步 demo 或执行 C05 真实 HTTP/PostgreSQL/浏览器链，因此严格计分仍为 **32/48、C 0/7、发布 2/10**。
- **C05 真实 Webman 路由闭环加固（2026-09-16）**：继续核查发现旧 `provider route` 只是外部 `auth:none` 占位调用，并仅回传 `route_binding_id`，没有让应用用户令牌经过实际路由中间件或留下业务侧审计。live driver 现增加受限 `business_app` target，只允许本链 `application_user` 凭证；C05 的允许请求必须命中与 route manifest 同模板的实际路径，返回同一 API code/request ID，路由停用后必须以 `SAND_IAM_ROUTE_NOT_REGISTERED` 拒绝。新增默认不装配的临时 Webman provider 模板：handler 前由 `ApplicationAuthorizationMiddleware` 加载真实 `standalone_work_item` 并复核 entity scope，允许/拒绝分别写精确业务审计。完整与中断分支均通过应用会话、两个捕获审计 ID、固定请求号、业务对象 ID 和本轮前缀做物理清理及零残留核对；原 IAM cleanup 不再伪称能清理业务审计。执行前复核按 Webman 的真实异常包装顺序读取响应中的原始 `ApiException`，只转换预期的路由未登记拒绝；业务审计写入明确指定 PostgreSQL identity 序列，避免取得错误插入 ID。PHP lint、runner、quickstart、API governance、route synchronizer、管理 SDK 定向回归通过；完整 non-PG（排除 dirty provenance）**140/140**。尚未获 C05 宿主同步、临时 provider 装配/重载和数据库夹具写入授权，因此未执行真实链，严格计分仍为 **32/48、C 0/7、发布 2/10**。
- **C06 三角色委派真实写入准备（2026-09-16）**：旧计划虽然声明 `out_of_scope_admin`，实际从未使用该身份，范围内操作也只是读取应用，追溯只验证委派创建。现固定为平台管理员创建应用委派、被委派管理员在范围内真实创建并停用本轮环境、同一被委派管理员访问范围外应用被拒、独立范围外管理员访问范围内应用被拒、平台管理员撤销委派、原被委派管理员再次被拒；环境创建/停用、委派创建/停用及三次拒绝均以主体、资源、请求号和 outcome 查询精确审计。受控清理同时绑定环境和委派 ID；若环境创建前中断，则走仅委派的独立清理与零残留分支。验收清理服务不再向非 C04 链附加无关的 service invocation residual。runner 会拒绝替换第三角色、缺失撤权审计、断开请求号或遗漏环境清理的伪绿计划。PHP lint、JSON、委派/审计/清理定向回归通过；未执行数据库写入、宿主同步、服务重载或真实 C06 HTTP 链，因此严格计分仍为 **32/48、C 0/7、发布 2/10**。
- **C07 真实事件源码闭环（2026-09-16）**：live plan 已改为正常 `credential.issue` 触发生产 `credential.changed`；一次性 Webhook 密钥以敏感 capture 配置到默认关闭的受控 HTTPS 接收器。接收器校验时间戳、HMAC、应用、事件类型、凭证编号和请求号，真实记录首轮 500 与重试 204，proof 不返回密钥。清理契约核验客户主体→应用→环境→调用身份，按 delivery→credential→endpoint 清理，并独立清理接收器临时配置；完整、未捕获 delivery 和仅 endpoint 三种中断阶段均失败关闭并有零残留查询。接收器状态目录/文件权限为 0700/0600。定向 PHP lint、接收器行为、受控清理及 live plan 验证通过；完整 non-PG（排除 dirty provenance）**141/141**。尚未运行真实公共 HTTPS receiver、worker 或 PostgreSQL 链，因此严格计数不变。
- **L01 + C02 + C05 + C06 + C07 授权实跑（2026-09-16）**：当前权威源码已用 `--allow-dirty` 受控同步至 demo；L01 **1/1**、C02 **44/44**、C05 **37/37**、C06 **17/17**、C07 **14/14**，合计 **113/113**。真实路径覆盖管理员通知配置、SCIM 与 HTTPS Keycloak 两代目录同步、OIDC PKCE/CAS/真实 Webman 业务路由、三角色委派、HTTPS Webhook 首投 500 与 worker 重试 204；允许、拒绝、撤权、逐请求审计及清理均有运行结果。运行中修复 SCIM Webman 路由参数、CAS principal、C05 Provider 路由/ORM、Webhook retry 计数和夹具审计动作；全量 non-PG（排除 dirty provenance）**143/143**。本批 53 个临时组织、56 个临时应用及全部子对象已按严格 ID/时间/前缀边界清理，SandAdmin API 后置残留 **0**；临时用户、角色、JWT、表、密钥、配置和服务均已清理或恢复，未检查或启动 PostgreSQL。L01 补齐此前唯一缺口后，本地业务闭环为 **4/4**，严格计分 **33/48（68.8%）**；其余四链的真实 API 切片不替代同一 clean 最终候选的全部浏览器、标准外部客户端和 F01–F07 纵向证据，发布仍 **2/10**。证据见 [`../../../.artifacts/sand-iam-l01-c02-c05-c06-c07-live-20260916.md`](../../../.artifacts/sand-iam-l01-c02-c05-c06-c07-live-20260916.md)。
- **发布候选来源门禁收口（2026-09-16）**：真实链新增的 TypeScript 管理 SDK 生成物已从当前源码重建，`release-build-contract.json` 的 6 文件树摘要同步为 `45a41f77…ea137`，SDK 回归通过，build contract 恢复一致。进一步复核发现系统 Git 不可用时，package checker 会把真实仓库误当成非 Git fixture，使“eligible release payload”伪绿；现通过逐级 `.git` 元数据识别真实 worktree，Git 不可用时明确失败。机器服务 Provider README 已声明本地 `vendor/` 不进入候选包，但 payload policy 只排除了 standalone consumer 的 `vendor/`；现统一排除两个可再生示例依赖树。payload policy **17/17**、package contract **23/23**、release provenance（可用 fallback Git）通过；完整 non-PG **144/144**，PHP SDK 通过、TypeScript SDK 通过、Dart SDK **63/63**，发布卫生 **16/16**。package integrity 在可用 Git 下为 **25/26**，唯一失败是当前 `sand-iam/` 尚未获授权提交，因此工作树非 clean。该批关闭发布伪绿和候选载荷漂移，不提前增加 D01/D02 或发布门槛分子。
- **C02 身份全生命周期执行计划补齐（2026-09-16）**：冻结顺序现落实为“身份导入及派生邀请 → SCIM 协议接入 → Keycloak 目录驱动两代同步 → 用户组/角色/策略允许拒绝”。导入使用不超过 64KiB 的内联 CSV；受控 Keycloak 夹具提供真实 Admin REST users 分页入口并证明 generation 1/2。SCIM 通过生产管理 API 创建应用级身份源、配置协议、签发一次性令牌，再走标准 Users 端点完成创建、读取、更新、停用、删除，最后撤销令牌并要求旧令牌 401；请求固定使用 `Accept/Content-Type: application/scim+json`，响应 ETag 用于 If-Match，生命周期审计按本轮请求号精确核对。live driver 仅为 SandIAM SCIM 路径开放 PATCH/DELETE、标准 SCIM 媒体类型和本轮敏感 token capture，并支持受限结构化 GET query。受控清理可发现并按外键顺序移除导入行/邀请、SCIM token/resource/binding/identity/provider mount、目录 run/resource/identity；导入、身份源或目录连接中途失败均有互斥 partial recovery 和零残留分支，完整成功仍强制要求全部派生对象存在。相关 runner、fixture service、SCIM admin、Keycloak 夹具定向回归通过；完整 non-PG（排除按设计拒绝脏工作树的 provenance）**167/167**。本批未同步 demo、未写 PostgreSQL、未重载服务，也未执行 C02 真实 HTTP/外部目录链，因此严格计分仍为 **32/48、C 0/7、发布 2/10**。
- **L01 通知配置闭环执行工具（2026-09-16）**：复核确认 L01 未通过的剩余范围不是后台页面，而是身份源、认证与通知配置尚未形成完整管理员业务证据。新增 `admin-notification-live-acceptance.php` 固化通知部分：平台管理员创建客户/应用及两级委派；组织管理员创建并加密配置消息服务；应用管理员挂载并读取通知用途；第三管理员越权拒绝；随后解绑、停用、撤销两级委派并证明原管理员立即拒绝。所有成功和拒绝均按 actor、action、resource、request ID、outcome 精确查审计；清理只接受既有 `sandadmin` PostgreSQL、固定前缀和明确确认，不建库、不输出一次性 token，应用/客户停用后物理清除本轮 provider、mount 和 grant 子对象。后续失败分支审计发现首个停用 API 异常会跳过其余清理；现改为每项清理独立尝试，始终删除本轮加密 provider/mount/grant，仅在确认无其他应用、环境、身份或调用身份时以精确 id/code 对客户和应用做数据库停用兜底，并汇总全部清理异常而不覆盖主错误。源码 lint 与非 PG 契约回归通过。该工具需与 C02 身份配置、已完成的认证配置证据和 C06 委派证据绑定到同一当前候选后才可关闭 L01；当前未获同步、服务重载、临时管理员、数据库写入和清理授权，未实跑，故严格计分仍为 **32/48、C 0/7、发布 2/10**。
- **D04 协议互操作证据绑定加固（2026-09-16）**：旧 `sand-iam.protocol-interop/v1` 只验证外部证据文件存在、哈希匹配和报告中的布尔断言，任意自称通过的 JSON 都可能成为唯一证据。现升级为 v2：OIDC、SAML、LDAP、SCIM、CAS、Kerberos-SPNEGO、RADIUS 每项必须同时提交结构化证据和分别哈希的标准客户端、SandIAM、真实受控对端、清理材料；结构化证据交叉绑定候选 ZIP、环境、客户端版本/项目、对端版本/地址和起止时间。37 个协议断言各自要求全局唯一请求号，并必须同时引用客户端、SandIAM 与对端材料；清理必须引用零残留材料。路径穿越、符号链接、重复材料、哈希漂移、候选错绑、缺少任一侧证据、残留非零和高置信秘密均失败关闭。生成器、校验器、证据负例和外部模板回归通过。该加固只修正 D04 证明能力；七类真实标准客户端/真实对端仍未执行，故 D04、严格 **32/48** 和发布 **2/10** 均不增加。
- **R09 Casdoor 对照证据绑定加固（2026-09-16）**：旧 v1 每轮只要求一个任意哈希文件，不能证明浏览器、产品侧状态和清理来自同一候选、旅程和轮次。现升级为 `sand-iam.casdoor-comparison/v2`：三条旅程 × SandIAM/Casdoor × 两轮共 12 次运行，每次必须有结构化证据及分别哈希的浏览器、产品系统、清理材料。结构化证据绑定候选 ZIP、环境、旅程、产品、轮次、起止时间和六项计量值，并要求全局唯一请求号、业务副作用引用、审计引用、四项结果断言与零残留；候选错绑、材料缺失、指标漂移、请求号复用、清理残留、符号链接、哈希漂移或高置信秘密均拒绝。比较证据与外部模板回归通过。该变更只关闭 R09 验证器的伪绿入口；独立 Webman 开发者尚未对两套产品真实完成 3×2×2 旅程，故 R09、严格 **32/48** 和发布 **2/10** 不增加。

#### 2026-09-16 SandPackage 生命周期接续

- demo 已通过 SandPackage 完成真实 **0.7.0 → 0.7.1** 升级，API 返回成功，registry 为 `version=0.7.1/state=1`；Webman PDO 核对迁移账本为 **39 rows / max revision 38**。历史 revision 035 的已安装 checksum 别名保持不变，revision 038 保持已发布源码 checksum，未手工改写 ledger。
- 首次 **0.7.1 → 0.7.2** 尝试在预检中失败并由同一数据库事务回滚，未写入 revision 039。原因是 v28 历史约束 `ck_sand_iam_service_grant_data_class` 的精确状态为 `CHECK ... NOT VALID` / `convalidated=false`，原预检错误要求已验证约束；同时原定义归一化未移除末尾 `NOT VALID`。
- 权威预检现只接受该历史精确状态：约束名称、类型和完整表达式必须一致，强制 `convalidated=false`，并仅在文本比较时移除末尾 `notvalid`。生成生命周期后，非 PostgreSQL 生命周期契约 **31/31**、事务回滚契约 **5/5**；Webman PDO 使用同一 catalog 条件得到 `service_grant_preflight_match=1`。Astra/high 独立复核 **ACCEPT（P0/P1/P2=0）**。
- 当前 v17 review-only ZIP 为 `.artifacts/sand-iam-0.7.2-v17-20260915T161658Z/`，689 项，SHA-256 `b214e3aefa6f2879f063fa97becd6116636ff84bda729a8bb83c749a70a3cdf8`，重复构建字节一致且 `unzip -t` 通过。它来自 dirty 工作树，package integrity **25/26**，唯一失败为 clean/tracked Git blob 来源，因此不是正式发布候选。
- 失败的 0.7.2 文件态已按批准编号 `3ced60746f24d7fe039584a9` 恢复到 SandPackage 生成的 0.7.1 备份。随后使用同一 v17 完成真实 **0.7.1 → 0.7.2** 升级；registry 为 `version=0.7.2/state=1`，迁移账本为 **40 rows / max revision 39**，历史 035/038 checksum 未改写，039 checksum 与权威源码一致，`data_class` 为可空 `varchar(32)`、默认 `internal`。
- 同版本 v17 再次上传被 SandPackage 在执行 SQL 和替换运行文件前以“升级包版本必须高于已安装版本”拒绝；registry、账本和结构保持 0.7.2 健康状态。正式卸载后 registry 为空、`sand_iam_*` 表为 **0**，其余表名集合保持 **48** 项且 SHA-256 仍为 `9f990d1c625b5edb58dc9d7d8798364451ee933a666013d4388d1b185dfb4ec6`；再用同一 v17 新装成功，恢复 **86** 张 SandIAM 表、40 条迁移账本和健康 registry。
- 当前安装后端、管理端载荷与 v17 snapshot 的 `diff -qr` 均为零；Webman captcha 和 Vite 根入口返回 HTTP 200。真实 SandAdmin 登录成功；同一平台管理员会话逐项打开 SandIAM 的 **12/12** 一级子入口：`overview`、`connection`、`people-access`、`audit-troubleshooting`、`api-governance`、`auth-session`、`event-notification`、`application-business-action`、`api-resource`、`api-route-binding`、`route-manifest`、`policy-simulate`，各自 URL 与页面标题匹配且无 404，管理总览显示 fresh-install 空态。控制台两条 error 是宿主默认头像 `/@imgs/user/avatar.webp` 缺失；根路由重定向和 ECharts 提示也来自宿主，不阻断 SandIAM 激活或数据加载。
- Astra/high 对预检修复和当前生命周期终态均独立 **ACCEPT（P0/P1/P2=0）**。D03“当前宿主生命周期”和发布门槛“安装升级”据此通过；可上线部署变为 **1/8**，完整目标变为 **29/48（60.4%）**，发布门槛变为 **1/10**。v17 仍来自 dirty 工作树，D01/D02、七链、四角色、协议互操作、备份恢复、24 小时稳定性、Casdoor 对照和最终签名发布包仍未通过；未提交、未推送、未部署生产。

#### 2026-09-15 完整目标恢复与 demo 登录修复

- **2026-09-15 解除暂停**：SandAdmin 已发布并推送 clean 宿主 `07d83d591b85deb83875473687a0d033a418c778`，`sand_plugins` 宿主锁提交为 `c7aaad447de551f4524044788814953b2cb468f5`；SandIAM PostgreSQL retention 索引结构校验修复提交为 `2f65ac6d964c385b4b7987cce4c6d6a831cd5c37`。当前宿主 dry-run 无差异，JWT 运行配置报告 `available`；SandIAM 从 clean consumer clone 重建的 unsigned ZIP SHA-256 为 `48fd6222bd1cb5f42677bdf16b9dd54d311d0b6f6365f74fe4c0a55de859f3a8`、689 项、重复构建字节一致，package integrity **26/26**，与 SandAdmin 独立宿主安装验收使用的修复包摘要一致。
- demo 发布副本已从上述 clean clone 受控导出并锁定 `2f65ac6`，随后 dry-run 无差异；这仍只是 source export，不是安装。当前 demo 服务未运行，旧活动 registry 仍缺失，事务停在 `backed_up`。新宿主官方只读命令 `sandpackage:recover inspect-pre-upgrade sand-iam` 已返回 `pre_upgrade_backup_restore_required`，唯一允许 `restore_interrupted_pre_upgrade_backup`，确认 0.7.0 备份、旧登记摘要与实际 deployment manifest；尚未执行恢复、数据库、安装或服务操作。
- 上述官方恢复已执行并返回 `state=restored`、`sql_executed=false`：0.7.0 registry 恢复为 `state=1` / `stage=completed`，登记摘要与实际 deployment manifest 一致，后端和前端 runtime 均存在，旧事务 journal 已清除，再次 inspect 明确无待恢复记录。随后尝试启动 demo Webman 时，进程因既有 PostgreSQL 18.4 实例未运行而退出；未进入 0.7.2 上传、数据库升级或部署。启动 `/opt/homebrew/var/postgresql@18` 的精确服务操作被自动审批要求本次明确授权，未绕过。
- clean `c7aaad4` checkout、SandIAM 最后源码变更 `2f65ac6`、package integrity **26/26** 及两处独立环境构建相同 `48fd6222…f3a8` ZIP，证明该包可重建且可由新 SandPackage 安装；但独立发布审查发现管理端四处用户可见法律业务示例，原检查器只扫描公开 Markdown，故该摘要已废止为发布候选。权威源码已改用通用 `work_item` 示例，检查器现按内容识别发布包全部非 vendor UTF-8 文本；行为回归、发布卫生 **16/16**、无扩展名文本负例和差异检查通过。独立复核确认 631 个非 vendor 文本及四个 `.env.example` 均受覆盖，P0/P1/P2=0。本批尚未提交和从新 clean revision 重建，D01 暂退回未通过。当前 FLOW 为 R **8/9**、P **20/20**、F **0/7**、L **0/4**、D **0/8**，合计 **28/48**；七链仍 **0/7**，发布门槛仍 **0/10**。
- **暂停记录（已解除）**：SandIAM 自身源码功能对账为 **20/20**；下一批能增加正式 FLOW、业务链、安装生命周期或发布门槛分子的工作，须绑定 SandAdmin 维护方发布的 clean revision 及其中按 SaiPackage 基线恢复的内置 SandPackage。等待期间按用户指示冻结 SandIAM 权威源码与 demo；上列 `07d83d5` clean revision 已解除该等待条件。
- 用户已结束“只实现功能、不执行 FLOW”的阶段限制，恢复本 Goal 的完整范围。当前继续推进 demo 正式生命周期、真实 HTTP/浏览器、七条业务链、48 项 FLOW、10 项发布门槛和独立终验；源码 20/20 不等于上述门槛已通过。
- 权威源码已按授权提交为 `213d42ef72d45388ce5813d287dd737bfbc33a34`，仅包含 `sand-iam/**`，未 push。当前 Git-blob-only unsigned 候选 ZIP SHA-256 为 `3917319c03fa9f44f4e20570a02ee46bea77bfc8df3ec96c078479e216fd9f48`；三次独立构建摘要一致，package integrity **26/26**。
- demo 前端和 Webman 后端均已运行；真实 `/api/core/captcha` 返回 200。此前后台登录失败根因是 demo 缺少宿主独立的 Tinywan JWT 运行配置，账号密码校验通过后在签发令牌时报 `JwtConfigException`。经用户明确授权执行宿主自己的 JWT 初始化和服务重载后，验证码、登录及携带新令牌读取用户信息均返回 200，临时 cookie、验证码和令牌材料已清理。
- `scripts/sync-sandadmin-host.sh` 原先直接 rsync SandAdmin 工作树，Git ignore 不会保护 `server/config/plugin/tinywan/jwt/`，存在复制源宿主密钥或因源缺失删除 demo 密钥的风险。消费侧现已排除该宿主独立目录，并只报告 `available`/`missing`/`unreadable`/`placeholder`；`available` 不能替代真实登录验证。脚本语法、diff-check 和当前 host dry-run 通过，Astra/high 独立复核通过并补齐 grep I/O 失败不误报。
- 当前权威源码为 0.7.2，demo 发布副本仍为 0.7.1，已安装运行文件仍为 0.7.0。SandPackage 活动 registry 实际缺失，候选事务停在 `backed_up`；官方只读 `sandpackage:recover inspect sand-iam` 复现 `Undefined array key "package_backup_id"`。SandAdmin 维护方正在按 SaiPackage 基线恢复 SandPackage 正轨；SandIAM 不再开发或交付 SandPackage 修复。待维护方完成后，从新的 clean SandAdmin revision 拉取包含 SandPackage 的宿主，再以正式安装路径重建 demo 并安装当前 SandIAM 候选。
- 当前 0.7.2 权威源码的全部 `*_non_pg_test.php` 已重新执行，结果 **137/137**；PHP SDK、TypeScript SDK 和 Dart SDK **62/62** 均通过，发布载荷卫生 **16/16**。包完整性 **25/26**，唯一失败为本节及本轮规格修正使 `sand-iam/` 不再与已提交候选 Git blob 完全一致，不能沿用旧 26/26。R08 静态门发现 T10-03 的 `outbox_id` 和 `SAND_IAM_SYNC_OUTBOX_NOT_RETRYABLE` 未同步进入字段/错误码对账，已修正两份权威规格并恢复 **9/9**；该修正只关闭需求冻结一致性缺口，不增加真实 FLOW、业务链或发布门槛分子。
- 早前按授权写入 `/Users/code/project/sandadmin` 的本地恢复补丁未提交、未同步、未重载，也不再作为 SandIAM 交付方案继续推进；保留现场供 SandAdmin 维护方自行判断和处理，SandIAM 不据此增加任何验收或发布计分。

#### 2026-09-15 本次 Goal 功能实现对账

本表是本次目标的当前源码功能对账，替代历史完成数及“尚未核准”描述。依据原 P01–P20 功能子项，核对当前权威源码的入口、调用链、实现和确定缺陷；不继承历史勾选，不以没跑 FLOW 判定源码未实现。**已实现（源码层）20/20，部分实现 0/20，无法确认 0/20。** 20/20＝100% 只表示该模块口径的源码实现确认比例，不表示无缺陷、业务验收、可发布或完整交付完成。

| ID | 原目标模块 | 本轮结论 | 当前证据或明确剩余 |
| --- | --- | --- | --- |
| P01 | 双平面边界 | 已实现（源码层） | `config/route.php`、HumanAuthService、IdentityContextProvider、门户 runtime 分离管理员、人类和机器会话，默认控制器路由关闭。 |
| P02 | 组织、应用、环境 | 已实现（源码层） | 三类控制器接通 CRUD/停用恢复、范围守卫及组织会话撤销。 |
| P03 | 本地认证与会话 | 已实现（源码层） | AuthController → HumanAuthService 注册、验证、登录、锁定、刷新、恢复、改密、撤销/退出；最近事务与联系方式修复已接入并独立复核。 |
| P04 | MFA/Passkey | 已实现（源码层） | MfaService 的 TOTP、恢复码、挑战、WebAuthn 注册/认证及设备管理接通；限已声明算法和认证器能力。 |
| P05 | LDAP/SCIM | 已实现（源码层） | NativeLdapDirectoryAdapter 的 LDAPS 服务账号绑定/目录读取；ScimController → ScimService 的 Users/Groups/token/ETag/停用传播。LDAP 最终用户密码登录不在本结论内。 |
| P06 | OAuth/OIDC Provider | 已实现（源码层） | OAuthOidcService 接通 code/PKCE、client credentials、refresh、userinfo、discovery/JWKS、revoke/logout。 |
| P07 | 用户类型、角色、RBAC | 已实现（源码层） | 关系管理和动态角色授权已实现；IdentityRoleController、IdentityUserTypeController 的 grant/revoke 写入与成功审计已纳入同一事务，故障回滚及重试离线回归与独立复核通过。用户类型分类不直接作为授权条件，不把未冻结的类型授权扩展算新增需求。 |
| P08 | ABAC/数据范围 | 已实现（源码层） | PolicyAuthorizer、ScopeMatcher、EntityScopeGuard 和应用中间件接通已声明条件/策略快照/拒绝优先/实体范围复核。 |
| P09 | 接口与路由授权 | 已实现（源码层） | 业务动作/API/路由管理 → ApiGovernanceService、RouteBindingSynchronizer、授权中间件，支持预检确认、冲突和模拟；业务应用显式装配。 |
| P10 | 机器身份上下文 | 已实现（源码层） | 凭证管理 → IdentityContextProvider，真实密码散列/HMAC、当前范围与授权重查、签发验证及撤销；本次生命周期服务回归已独立通过。 |
| P11 | 机器调用约束 | 已实现（源码层） | 配额、网络、数据分级和可信事实解析已接入 ServiceInvocationAuthorizer；0.7.2 的 039 迁移、fresh-install 基础结构和精确 0.7.1→0.7.2 升级准入统一支持可空 data_class，并保留 internal 默认值和既有数据。业务 resolver 由消费应用提供。 |
| P12 | 外部联合身份 | 已实现（源码层） | FederationService、NativeFederationHttpAdapter、OneLoginSamlAssertionVerifier 接通回调验签、属性映射、绑定解绑和冲突处理。 |
| P13 | 登录体验与认证消息 | 已实现（源码层） | 体验管理/公开 DTO、MessageProviderService 的受信驱动选择、加密配置、挂载、模板、测试/发送和 Turnstile 门户接线。邮件/SMS 驱动仍按已声明契约由部署方安装配置。 |
| P14 | 生命周期、组、邀请、导入导出、同步 | 已实现（源码层） | SyncConnectorService 包含入站/出站、冲突、组映射、缺失保护与重试；配置/版本/审计、单页数据/游标/进度及运行终态原子保存已接通。新增 SyncRunOwnership 固定会话锁及运行资格检查，支持遗留 running 恢复，每次事务重查并锁定记录；分页保存返回 false 明确失败并回滚。恢复开关默认关闭，启用前须排空旧版本运行者；不据此声称真实进程恢复已验证。 |
| P15 | 协议互操作补齐 | 已实现（源码层） | DCR、OIDC logout、CAS、RADIUS 的声明范围已实现；Kerberos 内置 PECL GSSAPI 验证器与直接 TLS 上下文解析器。2026-09-15 通过批准的只读请求核实上游 channel.c：公开 setApplicationData 按字节长度复制绑定数据，与包内调用一致；两组开发回归复跑通过。真实 Realm 未验，不据此宣称协议互操作已通过。 |
| P16 | 管理委派 | 已实现（源码层） | 两类 grant 控制器、AdminOrganizationAccess 和应用资源守卫接通范围读写/撤销/恢复门禁；最近关系事务和实时范围回归已通过。 |
| P17 | Webhook、审计、安全运营 | 已实现（源码层） | WebhookService/worker、AuditWriter → SecurityOperationsService、SecurityAlertController 接通 outbox、签名投递、重试、归档和阈值告警/处理。 |
| P18 | SDK、中间件、CLI、OpenAPI | 已实现（源码层） | PHP/TS/Dart 公共与管理客户端、应用中间件、Dart doctor/snippet、管理 API 描述及两类业务示例；最近封装缺口已补。当前最终包和独立消费仍需另验。 |
| P19 | 初始化包 | 已实现（源码层） | InitializationController → InitializationService 的导出、草稿、预检、确认应用、幂等、漂移拒绝及逆序回滚。 |
| P20 | 独立自助门户 | 已实现（源码层） | AccountPortalController、portal/src、SelfServiceService 和人类认证 API 接通资料、会话、改密、MFA/Passkey 与恢复。 |

对账分工：Astra/medium 核 P01–P04/P20；Sol/medium 核 P07–P11；另一 Sol/medium 核 P05/P06/P12/P15；主控核 P13/P14/P16–P19，并复读 P07 两控制器及 Kerberos 默认类确认缺口。此批为当前源码核对，没有运行数据库、服务、FLOW 或终验。完整目标的 48 原子、7 业务链、10 发布门槛尚未达成，不据本表增加验收账本分子。

P11、P14、P15 的已知源码缺口均已补齐。该“只实现功能”阶段现已结束，后续按上节恢复完整目标。

#### 2026-09-15 P11 可空数据分级约束源码闭环

- 新增 `039_service_grant_nullable_data_class.pgsql`：只解除 `sand_iam_service_grant.data_class` 的非空约束，保留 `varchar(32)`、`internal` 默认值、既有数据和检查约束；迁移以修订 39、包版本 0.7.2 和自校验摘要登记。
- fresh install 基础结构同步为可空列；正常升级只接纳精确完成 001–038 的 0.7.1 账本，再在同一外层事务执行 039，不重放或改写历史迁移。根目录与插件包内迁移、install/update 载荷由原生生成器保持一致。
- 版本元数据、构建契约、SBOM、README 与变更日志同步到 0.7.2。P11 相关非 PostgreSQL 回归九个行为段通过；后续全量非数据库 PHP **177/177**、Dart **62/62**，包完整性 **25/26**，仅余 clean/tracked HEAD 发布来源门槛。本批没有执行数据库、FLOW、插件同步、迁移、提交或部署。
- 0.7.2 随包 Composer 根包元数据已由两份锁定工具链离线重建结果逐字节确认并更新；vendor 仍为 58 文件，构建契约树摘要同步。公开验包示例、安装升级说明、包契约、许可证审计和发布运行手册已从“当前 0.7.1”对齐至 0.7.2，并补 `0.7.1 → 0.7.2` 的精确升级/失败关闭边界。发布卫生 **16/16**、SBOM check、包契约 **22/22** 和 0.7.2 生命周期契约通过；未执行数据库、FLOW、插件同步、迁移、提交或部署。
- 空 release 环境曾被配置预检误报为通过；现已要求机器上下文、人类认证、MFA、OIDC issuer/pairwise subject 和联合身份六项基础值，`checked_keys=0` 明确返回六个 `MISSING_RELEASE_VALUE` 并以非零状态结束。行为与 CLI 回归 **11/11**，release payload **16/16** 和包契约 **22/22** 复跑通过。
- 补齐 0.7.2 公开变更日志和 README 配置预检入口后，review-only 0.7.2 v13 已从当前工作树构建：689 entries，ZIP SHA-256 `3917319c03fa9f44f4e20570a02ee46bea77bfc8df3ec96c078479e216fd9f48`，snapshot SHA-256 `bc0e17c0db48d17f3c178f9392bba64b1c6366450e3e110cc44e8cdb55c8cccb`；同快照内部重复构建及按 `REBUILD_COMMAND.txt` 手工重建均得到相同 ZIP 摘要。该 `0.7.2-v13` 取代先前 `0.7.2-v12` review-only 物证，与本页记录的旧版本历史 v13 archive 不是同一候选；它仍是 `candidate/dirty-not-release`，不能送签、同步、安装或计入发布门槛。

#### 2026-09-15 P14 同步任务异常退出恢复实现

- 当前按用户要求只推进功能实现，不执行 FLOW 或验收。新增 SyncRunOwnership，使用固定 PostgreSQL 会话锁约束同一连接器的运行者；启动、分页、出站结果、缺失停用及结束登记均接入运行资格检查。
- 遗留 running 记录可在取得锁后原子登记失败并启动新任务；恢复开关默认关闭，升级时排空旧版本运行者后启用。连接丢失或替换后，旧任务拒绝继续写入；各事务重新锁定记录，不再刷新并复用失败页的持有对象。
- ownership、配置保存、分页和运行结束四项开发回归均已通过；后两项保留原行为断言并适配新接口。此条更新异常退出恢复的源码实现状态；未操作数据库或服务。
- 后续开发回归复现分页 save 返回 false 仍提交的缺陷；connector/run 保存均补显式检查，返回 SAND_IAM_SYNC_RUN_FAILED/503 并回滚。两类 false、既有抛错和多页重试全部通过，主控复跑通过。

#### 2026-09-15 机器 Provider 标识符边界

- 当前调用链核实 SDK 自带 request ID 截断与逐动作派生，排除“追加 -verify 必然导致长度拒绝”的初步判断；未修改该路径。
- 真实 ProviderApplication 的文档 ID 末尾换行红例失败。ProviderProtocol 三类标识符正则使用严格字符串结尾，新增测试逐项确认文档 ID、幂等键、request ID 非法尾换行在 verifier/store 调用前拒绝，无业务副作用。
- Provider 全套离线测试14/14、语法和 diff-check PASS；真实 HTTP/PG 未运行，不增加机器业务闭环验收分子。

#### 2026-09-15 非 AI 业务 Consumer 关闭请求契约

- 按目标主线核对 standalone Consumer，发现公开要求空对象的 close 请求实际接受 JSON 数组和未知字段。新增 [] 红例实际失败后，保留 JSON 对象类型并拒绝非空未知字段，范围篡改继续使用原 body_scope_forbidden 错误码。
- 原离线测试新增数组/标量/未知字段拒绝，断言业务对象读取、关闭及授权调用均未发生，且只有一条拒绝审计；合法 {} 原有成功路径保持。完整 offline_test、PHP 语法及 diff-check PASS。
- 本批未执行真实 HTTP、数据库、服务或 FLOW；Consumer 已有接入源码不重复实现，不增加真实业务闭环验收分子。

#### 2026-09-15 P14 同步运行终态与审计一致性

- 成功审计追加后抛错红例复现同一 run 留下 succeeded/failed 两条矛盾审计。成功状态与成功审计改为同事务提交，失败回滚后 refresh run，再登记失败状态。
- 失败状态独立持久化，避免失败审计不可用使本次异常路径永久保持 running；失败审计独立事务回滚，安全日志只含 run id/异常类型，日志异常不替换原同步异常。失败状态保存抛错或返回 false 明确暴露存储失败，不宣称已解除运行占用。
- 新 sync_run_completion_behavior_non_pg_test.php 覆盖正常成功、成功审计故障、双审计故障、日志故障、driver 失败恢复及失败状态保存异常/false。主控/Sol/独立 Astra 复跑 PASS、有界 ACCEPT；配置、页事务、静态契约和调度器回归通过。成功 save=false 仅源码保护，未注入测试。
- 未运行真实 PostgreSQL/外部目录/服务/FLOW；进程崩溃后的 running 占用恢复仍未完成，不能把捕获异常后的恢复扩大为全部运行恢复。

#### 2026-09-15 P14 同步配置与审计原子保存

- 审计追加后抛错红例证明 configure 已修改密文、字段权威设置与版本却未回滚。配置保存改为按 id 锁定最新行，确认应用/组织仍与授权对象一致，从最新版本递增，并与成功审计同事务提交；异常回滚后刷新传入 ORM 对象。
- 新 sync_configuration_atomic_behavior_non_pg_test.php 验证审计写后故障完整回滚、同 payload 重试仅增加一个版本、陈旧对象按数据库最新版本递增、两类范围漂移409拒绝，以及无效字段/权威/方向拒绝。主控与 Sol 复跑 PASS；独立 Astra/medium 复跑 PASS、有界 ACCEPT；既有页事务、控制器保护设置、静态契约与语法通过。
- ORM、Db、审计和驱动为严格替身，不证明真实 PostgreSQL 并发或对端运行。P14 仍部分实现，下一项为同步运行完成登记与审计的一致性；未执行 FLOW、数据库、服务、同步或部署。

#### 2026-09-15 P14 单页同步与进度原子提交

- 当前 SyncConnectorService 核心源码通过正式只读审批读取，关闭此前未核实项；未运行目录同步、数据库、宿主或 FLOW。
- 真实 pull 方法的 connector.save 写后失败红例复现身份/来源记录与游标部分残留。将 applyPage 的事务移到 pull，覆盖整页数据、connector 游标和 run 进度；远端读取留在事务外，计数在提交后才更新。
- 第二轮红例证明数据库回滚后持有的 ORM 对象仍带失败页数据；核实 ThinkORM refresh API 后，回滚分支重载 connector/run，防止外层失败登记携带脏进度。
- 新 sync_page_atomic_behavior_non_pg_test.php 验证两类保存写后故障、整页回滚、对象刷新、重试无重复，以及第二页失败保留第一页并从已提交游标继续。主控/Sol 复跑 PASS，独立 Astra/medium 复跑 PASS、有界 ACCEPT；旧静态契约、语法通过。
- Db/ORM/driver 为内存替身，未证明真实 PostgreSQL 并发或外部目录互操作。P14 仍部分实现，配置保存与运行完成审计的一致性尚待收口，不增加模块完成数。

#### 2026-09-15 P15 内置 Kerberos 适配器候选

- 新增 PeclSpnegoVerifier，按部署 keytab 引用映射获取明确 SPN 的 acceptor 凭据，检查机制、协商完成、mutual/replay/channel-bound 标志及有效期；回传 mutual response token。新增 DirectTlsSpnegoContextResolver，从直接 TLS 的实际连接、固定单服务器证书计算 RFC 5929 绑定，不读取身份/绑定转发头。
- 默认配置接入两类但启用开关仍关闭；协议文档说明 PECL/MIT 版本、KDC 访问及超时、replay cache、证书不可变和反代限制。未安装依赖或更改运行配置。
- 两组真实适配器加严格替身离线测试 PASS，原有静态契约 PASS；缺原生扩展时真实 PHP 返回 503。TLS/OpenSSL、PECL 使用替身，不证明真实握手或 Kerberos 认证。
- 独立 Astra/medium 已核官方 gssapi.c 并复跑两测试 PASS、有边界 ACCEPT；另补扩展方法完整性检查，类和常量存在但方法缺失时返回 503，命令回归通过。setApplicationData 尚未完成官方 API 核实，精确读取 channel.c 的 curl 被网络允许列表拒绝，未绕过。P15 保持部分实现，不增加功能完成数或验收分子。

#### 2026-09-15 P07 身份角色和用户类型关系审计原子性

- 修复前真实 IdentityRoleController 新建授予的审计写后抛错用例失败，关系残留；IdentityUserType 未单独运行旧实现红例。
- 两控制器的授予（含停用恢复）和撤销将关系变更与成功审计放入同一 Db 事务，异常回滚；原身份、应用范围及引用检查保留。
- identity_relation_atomic_behavior_non_pg_test.php 调用真实两控制器，覆盖审计写后失败完整回滚、重试成功且无重复关系、跨应用引用拒绝、授权守卫拒绝和其他应用身份关系不变。主控和 Sol/medium 复跑 PASS，两生产文件语法通过；独立 Astra/medium 复跑 PASS、有界 ACCEPT。
- ORM、Db、AuditWriter、AdminOrganizationAccess 为内存或可控替身；不证明真实 PostgreSQL 并发、实际委派规则或完整 HTTP 旅程。未执行 FLOW、数据库、宿主、提交或部署。

#### 2026-09-15 P03 注册联系方式与验证策略一致性

- 复现要求邮箱验证但自定义表单只收集手机号时，真实 register 仍创建无法完成验证的账户。创建前核对表单字段与验证策略，不匹配返回既有 CONFIGURATION_INVALID/503；默认字段模式缺策略所需邮箱/手机时返回 FIELD_REQUIRED/400。
- 实际 HumanAuthService 内存回归覆盖两种联系方式各自的自定义配置冲突/默认输入缺失四个拒绝场景，无账户或限流状态变化；双验证要求、两项联系方式完整时正常返回 verification_required。账户创建原子性旧回归保持通过，语法及 diff 检查通过。
- 公开认证契约说明错误和管理员修正字段的办法；未做真实浏览器、数据库、邮件/SMS 验证，不计完整注册旅程通过。
- 独立 Astra/medium 复跑两组测试 PASS、有界 ACCEPT；质量扫描 `sandiam-registration-contact-quality-20260915.txt` 为 0 FAIL、44275 REVIEW、6 项未完成。

#### 2026-09-14 P03 / P14 账户创建审计原子性

- 注册的成功审计写后抛错红例复现账户已提交；将 register 与 activateInvitation 的成功审计放入各自原创建事务。邀请外层管理模式保持原事务所有权，没有新增嵌套事务。
- 真实 HumanAuthService 离线测试覆盖注册 `verification_required`、自管邮件邀请与外管手机邀请的失败回滚/重试、密码散列、已验证联系方式和注册关闭拒绝；注册红例已运行，邀请修复前红例未单独运行。主控新测试与密码恢复回归 PASS、PHP 语法通过。
- publisher、ORM 与事务为内存替身；不证明实际 outbox、PG、session/MFA、供应商或 HTTP。公开认证契约明确账户创建事务不包含后续独立会话签发。未执行 FLOW、数据库、宿主、提交或部署。
- 独立 Astra/medium 复跑新测试与密码恢复测试均 PASS，源码和新增文档有界 ACCEPT；不将调用方事务模拟算作完整邀请接受旅程。

#### 2026-09-14 P03 密码恢复失败原子性

- 真实 `resetPassword` 故障回归复现成功审计在提交后失败，密码、验证码消费与会话撤销已经生效。将唯一 `identity.password_reset` 成功审计移入原事务，接口和数据库结构不变。
- 新增实际 HumanAuthService 离线回归，内存审计先追加再抛；验证密码/验证码/session/refresh/审计整体回滚、同验证码与相同请求体重试、新旧密码真实散列校验、目标会话族撤销、其他应用与身份不变，以及验证码重放拒绝。限流准入计数独立保留，不纳入密码事务回滚。
- 主控新回归与原会话撤销回归 PASS，语法及相关 diff 检查通过；公开 API 契约补充事务边界和超时结果确认。没有运行 PostgreSQL、HTTP、FLOW、宿主、提交或部署，不计完整账户恢复旅程通过。
- 独立 Astra/medium 复跑两项测试并审核新增契约，有界 ACCEPT；真实登录、网络超时和 PostgreSQL 并发仍未验证。

#### 2026-09-14 P10 机器上下文到期边界

- 后续新增真实 `IdentityContextProvider::issue → verifyForService` 生命周期回归：实际密码散列、HMAC、环境引用、网络及授权约束；内存 ORM 执行模型条件和服务授权关联过滤。覆盖正确作用域/约束、每次验证重读与审计、篡改/错误受众动作服务、凭证与机器停用、组织应用环境停用、授权撤销/替换、网络收紧及同实例恢复。
- 回归发现签名失败和环境引用直接拒绝缺少审计；补齐签名失败的 unknown/null 范围审计，以及签发/验证环境引用失败审计，原异常保留、不记录原始上下文。主控复现缺失审计红例后，生命周期与原到期/动作/撤销测试全部通过。
- 公开接入指南澄清 context 可重复验证，业务防重放由幂等键保障，每次幂等重试仍先验权；现有 provider 离线 13/13 通过。不将服务级内存验证算作 PostgreSQL、HTTP、配额执行或完整业务闭环。
- 独立 Astra/medium 复跑生命周期、expiry、revocation 三项测试 PASS，并复核指南有界 ACCEPT。审计自身抛错仍可能替换原拒绝异常；未验证该故障路径及并发撤销与业务执行原子性。

- 固定时钟和真实 HMAC 签名复现 `exp == 当前秒` 仍进入凭证查询；改为 `exp <= 当前秒` 即拒绝，消除到期秒仍继续验证的窗口。
- 新增 `context_expiry_behavior_non_pg_test.php`：过期及恰好到期返回 401 / CONTEXT_EXPIRED，发出拒绝审计且不查询凭证；剩余一秒仍进入后续凭证查询。新测试和既有撤销静态契约通过，语法及相关 diff 检查通过。
- 独立 Astra/medium 复跑新测试 PASS，源码边界有界 ACCEPT。
- 后续复现合法签名的字符串 `actions` 在 `in_array` 触发 TypeError；将非空列表、非空字符串元素及去重校验前移至凭证查询之前。8 类畸形声明均返回 SERVICE_ACTION_FORBIDDEN/403、记录拒绝审计且不读凭证，新旧回归通过。测试不证明当前签发器会产生畸形声明。
- 动作声明修复经独立 Astra/medium 复跑两项测试 PASS、有界 ACCEPT，不据此推定完整正常授权。
- 未来一秒用查询哨兵结束，不证明完整允许、真实数据库或机器服务接入；未执行 FLOW、数据库、同步、提交或部署。

#### 2026-09-14 P15 CAS 票据客户主体状态

- 复现 validate 在应用仍启用、客户主体已停用时返回用户身份；新增应用所属 organization 的存在/启用门禁，保留票据一次消费和失败 null 响应。
- 新增实际 CasProtocolService 与内存 ORM 回归：正常主体允许，停用/缺失主体拒绝且无成功审计，事务结束；恢复主体后已消费票据仍拒绝。主控及独立 Astra PASS、有界 ACCEPT，PHP 语法通过，协议契约同步。
- 同一回归补齐应用/身份/服务停用或缺失、身份/服务跨应用绑定、错误服务 URL，以及票据过期/已消费/停用/缺失共 13 个拒绝场景；绑定恢复不能复活已消费票据，无成功审计。实际服务内存回归两组 PASS，相关 diff 检查通过；此扩展由主控验证。
- 不证明真实 HumanAuth、PG 并发或 CAS HTTP/标准客户端互操作；未执行 FLOW、数据库、同步、提交或部署。

#### 2026-09-14 P17 Webhook 事件 ID 严格结尾

- 复现事件 ID 末尾 LF 被 `$` 正则接受并入队；补 PCRE `D` 严格结尾，保留 8–96 位 ASCII 字母数字及 `_.:-` 的原约定。
- 实际 enqueue 夹具验证 LF/CRLF、7 位、97 位均以 EVENT_ID_INVALID/400 拒绝且无投递写入；合法 8/96 位原样通过。原重放及回滚回归保持通过，两组 PASS，语法及相关 diff 检查通过。
- 未执行真实 HTTP、接收端、PG 或 FLOW，不宣称已复现网络层故障；未同步、提交或部署。

#### 2026-09-14 P16 委派范围撤权与恢复

- 管理员操作指南与排障指南已同步双来源授权、撤权后仍有独立来源的处理、状态/成功审计事务及超时查询约定；不把菜单可见当作管理操作获准，保留真实角色/浏览器未验证的说明。

- 读取并执行实际 AdminOrganizationAccess：每次范围判定查询有效委派及主体/应用状态，没有实例内范围缓存；本轮无需修改生产代码。
- 扩展既有范围审计夹具，同一 resolver 验证双来源去重、撤销组织委派后保留独立应用授权、两源均撤销后 403、恢复组织委派重新授权、主体及应用停用后的拒绝。原请求号审计测试保持通过，两组 PASS，相关 diff 检查通过。
- 查询存储和审计为内存替身，不证明宿主会话、真实 HTTP 或 PG 事务可见性；未执行 FLOW、数据库、同步、提交或部署。

#### 2026-09-14 P16 客户主体管理员委派原子性

- 客户主体委派创建、更新、停用原先未启用父类状态/审计事务；创建故障红例复现委派和审计残留。现仅启用既有 atomicCreateAudit/atomicMutationAudit，保留 requiresSuperAdmin。
- 扩展同一管理委派内存夹具，三动作非超级管理员 403 无变更，审计写后失败完整回滚、解除故障重试成功，审计归属 organization=700/application=null。与应用委派合计三组 PASS，PHP 语法通过。
- 独立 Astra 三组复跑 PASS、有界 ACCEPT，相关 diff 检查通过；未单独验证组织创建成功重放。身份权限判断为替身，不证明真实超级管理员认证、PG、并发或撤权后 HTTP。
- 扫描 `sandiam-org-delegation-quality-20260914.txt` 为 0 FAIL、44269 REVIEW、6 项未完成，不能计为全仓通过；未执行 FLOW、数据库、同步、提交或部署。

#### 2026-09-14 P16 应用管理员委派更新/停用原子性

- 按原账本 §3.1/3.2 纠正当前三条 SCIM 记录为 P05，客户主体停用记录为 P02；P16 始终表示管理委派，未改变原需求或计分。
- 复现应用管理员委派更新审计失败后状态残留；复用父类 atomicMutationAudit，为更新和停用开启既有状态/审计事务。原创建事务不变。
- 实际控制器与父类的内存回归两组 PASS，更新/停用审计写后异常完整回滚，故障解除后同请求成功；PHP 语法和差异检查通过。不证明真实权限、PG 并发或撤权后真实 HTTP 拒绝。
- 原组合命令曾被 Hook 误判 SQL，现已修复并完成独立复核：SQL 检查仅排除严格识别的源码读取段，其他守卫保留原命令；隔离候选独立 ACCEPT 后应用，live SHA `bb90139673706cd1a9b566531df047f43b4a960160aef056214c60e47e9b7b54`，六组安装后边界测试通过，回滚副本 `/private/tmp/sandiam-mixed-read-hook-20260914/pre-install.py`。
- 主控及独立 Astra 均完整重跑原组合命令 exit 0，两组测试 PASS，P16 本批获有界 ACCEPT；权限检查仍在事务外。扫描 `sandiam-delegation-atomic-quality-20260914.txt` 为 0 FAIL、44269 REVIEW、6 项未完成；Hook 修复不增加业务/发布计分。

#### 2026-09-14 机器凭证名称校验

- 日期校验已接续完成：核对 ResourceEditor 的 `YYYY-MM-DD HH:mm:ss` 后，签发服务校验固定格式和日历往返一致性；拒绝越界日期/时分秒、相对时间、时区格式、NUL 和零年份。保留 null/空字符串及合法历史时间，正常时间和闰日通过。四组回归、PHP 语法通过，公开管理契约同步；未运行真实 PG 日期解析。

- 后续修复有效期隐式转换：非字符串/非 null 输入及字符串 `0` 返回 400，避免 false、数值 0、空数组和字符串 0 被当成无期限。仅 null/空字符串保持无期限语义，正常时间字符串原样传递；尚未新增时间格式或未来范围校验。
- 扩展同一签发回归，7 种错误有效期无凭证/审计写入，null、空字符串、正常时间保留既有契约，四组 PASS；语法及相关 diff 通过。未验证真实数据库日期解析。

- 公共 CredentialIssuanceService 原先直接保存名称，轮换可传空名称，超长值超过 credential.name 的 varchar(128)；现统一去除首尾空白并拒绝空值及超过 128 字符，沿用 SAND_IAM_VALIDATION_ERROR/400。
- 既有实际控制器/签发服务测试先复现空名称被接受，修复后空白/空值/129 中文字符无凭证及审计写入，128 中文字符正常保存且明文与哈希匹配。应用隔离、审计范围、新增名称边界三组 PASS，PHP 语法及相关 diff 检查 PASS。
- 本批为内存存储验证，未运行真实 PostgreSQL、完整轮换接口或服务请求，不证明轮换事务及旧凭证即时失效。未执行 FLOW、数据库、同步、提交或部署。

#### 2026-09-14 邀请接受审计原子性

- 已补已有成员关系分支：启用/停用两种初态在审计失败后均恢复完整快照，解除故障后复用原成员 ID 20、状态为启用，不插入重复关系；实际服务离线回归现四组 PASS。身份激活仍使用固定身份替身，此证据不证明真实重复账号或访客升级，PG 唯一约束和并发仍待验证。

- 后续将接受夹具扩展为初始组 1 和多邀请：实际服务创建应用内成员，撤销同目标 pending/delivery_failed/sending 三条邀请并清空投递令牌，保留已接受、错应用、不同目标记录；审计故障快照同时覆盖成员及全部邀请回滚。修正内存查询 update 为全部匹配记录，补齐夹具字段后无警告，三组 PASS。已有成员恢复、访客升级、真实认证和 PG 仍未验证。

- 复现接受成功审计失败后邀请已消费、身份已创建的缺陷；将既有接受审计移到同一事务提交前，接口与成功后重放语义不变。
- 扩展现有邀请投递夹具：审计写后故障恢复邀请、模拟身份和审计快照，同 token 故障解除后接受成功，成功重放 400 无写入；两组回归与 PHP 语法 PASS。使用认证/存储替身、无初始组的普通邀请，不证明真实身份激活、访客升级、入组、其他邀请批量撤销、PG 或 HTTP。
- 公开生命周期契约同步，独立 Astra 两组复跑 PASS、有界 ACCEPT，相关差异检查通过；质量扫描 `sandiam-invitation-accept-quality-20260914.txt` 为 0 FAIL、44268 REVIEW、6 项未完成，不能计为全仓通过。未执行 FLOW、数据库、外部投递、同步、提交或部署。

#### 2026-09-14 MFA 因子管理审计原子性

- 公开 MFA/自助接口文档已按当前源码同步：因子范围及 404、状态与审计同事务、TOTP 恢复码失效范围、已有会话不自动退出、成功后重复撤销与超时查询约定。未把源码行为描述计为真实设备或数据库验收。
- 后续补齐独立复核指出的隔离行为证据：TOTP/Passkey × 重命名/撤销 × 错应用/错身份/已停用/不存在，共 16 个实际服务用例均返回相同 404，因子、恢复码、审计快照不变且无事务残留。既有故障回滚回归仍 PASS；认证与存储为替身，不外推真实 HTTP、数据库或认证器验证。

- 修复 MfaService 重命名先保存再审计、撤销先提交再审计的问题：管理状态与成功审计现在同事务提交，审计异常整体回滚。
- 新增 `mfa_management_atomic_behavior_non_pg_test.php`，实际服务搭配内存存储、认证和审计替身；旧实现红例出现状态残留，修复后 TOTP/Passkey 重命名、撤销、审计写后回滚及恢复通过，TOTP 恢复码停用、其他应用记录保护、错误密码无写入通过。
- PHP 语法与相关 diff 检查通过，独立 Astra 复跑 PASS、有界 ACCEPT；跨应用/身份因子拒绝仅源码核对，未覆盖行为测试。不证明真实密码认证、PostgreSQL、并发或设备运行。
- 质量扫描 `sandiam-mfa-management-quality-20260914.txt` 为 0 FAIL、44268 REVIEW、6 项未完成；新增测试 9 处分支提示对应故障捕获与类型/动作矩阵，已结合独立复验核对。未执行 FLOW、数据库、服务、同步、提交或部署。

#### 2026-09-14 P15 RADIUS Accounting 实际入口行为回归

- 新增 `radius_accounting_behavior_non_pg_test.php` 直接调用生产 handle、编解码及 CIDR 匹配，存储/事务和测试 secret 使用替身。覆盖 Start/interim/Stop、完整报文重放不变、回退计数和重复 Start 冲突、缺失 Start 的 orphan、未知 NAS 和错误认证拒绝。
- 事件写入后注入异常，经过生产 rollback，验证新会话/事件全部恢复且不 ACK；解除故障后同报文成功。每次 ACK 均核验 identifier、Response Authenticator 和事务清空。测试 PASS，独立 Astra 有界 ACCEPT，相关 diff 检查 PASS；生产代码无需修改。
- 质量扫描：`/private/tmp/sandiam-radius-accounting-behavior-quality-20260914.txt` 为 `0 FAIL / 44263 REVIEW / 6 ERROR`，本次测试无命中；六项错误仍为历史 staging 依赖目录读取问题，整仓扫描不算通过。
- 补齐的是此前缺失的实际服务离线行为，不是数据库验证：PostgreSQL 锁、唯一约束、并发和 UDP 尚未验证；计数倒退用例同时降低多项值，不证明每一分支独立覆盖。未执行 FLOW、数据库、服务启停、同步、提交或部署。

#### 2026-09-14 P15 RADIUS 会计处理边界复核

- 读取实际 RadiusAccountingService：认证及应用/主体门禁通过后，事件与会话同事务处理；重复完整报文指纹直接返回，`orphaned/conflict` 事件持久化后也返回 Accounting-Response，未更新正常会话计数。存储或校验异常返回 null。
- 同步公开协议契约与排障指南，明确响应不等于会话计数已更新，以及完整报文指纹与按会话 ID 去重的区别。
- 当前 `radius_accounting_non_pg_test.php` 仅执行报文验证及私有 event 规范化，不覆盖实际 apply/handle 的数据库状态转换，本轮未运行该宿主自动加载测试。P15 服务行为与真实 NAS/数据库验证仍缺；相关文档 diff 检查 PASS，未改生产代码、未执行 FLOW 或外部状态变更。

#### 2026-09-14 P15 RADIUS 编解码权威源码回归

- 既有 `radius_packet_codec_non_pg_test.php` 改为直接加载权威 `app/radius/RadiusPacketCodec.php`，仅替代异常类型；移除纯内存报文测试对 demo 宿主自动加载的依赖，避免源码归属歧义。
- 原有报文解码、User-Password 解码、Message-Authenticator、篡改/截断拒绝、响应认证和响应 Message-Authenticator 检查全部 PASS。未改生产协议代码，相关 diff 检查 PASS。
- 质量扫描：`/private/tmp/sandiam-radius-codec-source-quality-20260914.txt` 为 `0 FAIL / 44263 REVIEW / 6 ERROR`；本次测试无命中，六项错误仍为历史 staging 依赖目录读取问题，整仓扫描不算通过。
- 此结果只证明编解码器本地行为；真实 NAS、UDP 服务、会话和计费链、数据库及协议互操作仍未验证。未执行 FLOW、服务启停、同步、提交或部署。

#### 2026-09-14 P13 消息驱动公开契约同步

- 根据实际 `MessageProviderService` 补充应用体验/消息服务契约：false 的未启用/无匹配挂载语义、true 仅为驱动正常返回、驱动失败需抛错、服务层不自动重试或切换、超时可能已被供应商接收。
- 同步上一批保留上下文覆盖规则，以及 purpose 模板选择和 fallback；公开排障指南增加验证码/认证通知未收到的处理，避免把调用成功当送达或连续重发。
- 本批仅文档同步，相关 `git diff --check` PASS，未新增供应商、HTTP 或数据库验证证据；未执行 FLOW、同步、提交或部署。

#### 2026-09-14 P13 消息与验证码保留上下文修复

- 已修复 `MessageProviderService` 四处上下文合并：扩展 context 原先能通过 PHP 数组并集覆盖明确应用/动作、已选 provider 类型、已解析模板及 test 标记；改为服务已有保留字段覆盖冲突值，不新增保留字段。
- 扩展 `captcha_public_challenge_non_pg_test.php`，实际服务向替身驱动传参：验证 app/action/provider/template/test 权威值、ip/trace 等扩展字段保留、purpose 模板及 fallback 不变、错误 token 仍拒绝。先复现失败，修复后 `16 checks PASS`，独立 Astra 有界 ACCEPT。
- 质量扫描：`/private/tmp/sandiam-message-context-quality-20260914.txt` 为 `0 FAIL / 44263 REVIEW / 6 ERROR`；本次两文件无命中，六项错误仍为历史 staging 依赖目录读取问题。相关 diff 检查 PASS，整仓扫描不算通过。
- 边界：只证明服务参数契约，不证明真实验证码或消息投递；尚未证明外部调用者可控制冲突字段，不宣称已有可利用跨应用攻击。未执行 PG/HTTP/FLOW、同步、提交或部署。

#### 2026-09-14 P12 OIDC/SAML 回调审计原子性

- 已完成上一批列出的同类回调修复：`completeOidc`、`completeSaml` 的成功审计各自移到 commit 前，与新绑定、state 消费、PKCE/断言记录和 handoff 原子提交。协议外部请求与验证位置保持不变，失败审计在回滚后。
- 既有联邦事务测试扩展 OIDC、SAML 的 `purpose=link` 场景：精确注入 callback 成功审计故障，完整内存状态恢复，正常恢复后提交，已消费 state 重放不触发外部请求或验证。OIDC 用纯内存临时测试 RSA/JWT 调用生产验签代码，未读取或改变宿主密钥；SAML 验证器仍为替身。三组输出均 PASS，独立 Astra 有界 ACCEPT。
- 质量扫描：`/private/tmp/sandiam-oidc-saml-callback-quality-20260914.txt` 为 `0 FAIL / 44263 REVIEW / 6 ERROR`。六项错误仍是历史 staging 依赖目录读取问题，本次仅测试场景分支命中，已结合独立复验审查；相关 diff 检查 PASS，整仓扫描不算通过。
- 限制：不证明登录目的的新身份创建、XMLDSig、真实 IdP/标准客户端、上游授权码重用、PostgreSQL 或并发。三种 callback 的本地审计原子性缺口已修复，不据此宣告整个 P12 或协议门槛通过；未执行 FLOW、真实数据库、同步、提交或部署。

#### 2026-09-14 P12 OAuth2 回调审计原子性

- 已修复 `completeOauth2`：原成功审计位于提交之后，审计故障会留下已消费 state 和调用方拿不到的 handoff；现在成功审计随身份关联、state 消费和 handoff 同事务提交。外部 token/userinfo HTTP 仍在事务外，失败审计仍在回滚后。
- 扩展既有 `federation_unlink_audit_behavior_non_pg_test.php`，调用实际 OAuth2 callback 的 `purpose=link` 路径，精确在 callback 成功审计写后注入异常；完整比较内存状态，验证新绑定、state、PKCE、handoff 及此前 account_link 成功审计全部回滚，仅保留失败审计。解除故障后使用不同 external code 验证本地恢复；已消费 state 重放在 HTTP 前拒绝。原实现先失败，修复后两组测试 PASS，独立 Astra 有界 ACCEPT。
- 质量扫描：`/private/tmp/sandiam-oauth2-callback-quality-20260914.txt` 为 `0 FAIL / 44263 REVIEW / 6 ERROR`；六项错误仍是历史 staging 依赖目录读取问题，本次仅既有测试的场景分支命中，已独立复核。相关 diff 检查 PASS，整仓扫描不算通过。
- 限制：上游 authorization code 可能已被消费，本地回滚不能恢复它，真实用户可能需要重新发起授权；不宣称原 code 可重用。尚未证明登录目的、新身份创建、真实 IdP、PostgreSQL 或并发；OIDC/SAML 相似回调的提交后审计路径仍待处理，不将本批覆盖外推。未执行 FLOW、真实数据库、同步、提交或部署。

#### 2026-09-14 P12 联合身份解绑审计原子性

- 已修复 `FederationService::unlink`：原先绑定停用、关联会话及刷新令牌撤销先提交，成功审计失败后接口报错却无法重试完成审计。现在成功审计在同一事务提交前写入；异常回滚后记录失败阶段，区分 `validation_failed/audit_failed/commit_failed`。
- 新增 `federation_unlink_audit_behavior_non_pg_test.php`，实际运行 FederationService，内存查询/事务/审计及认证/MFA替身。原实现先复现三类状态残留，修复后通过成功审计写后异常回滚、失败审计保留、双审计故障无业务残留、最后登录方式拒绝、同请求失败重试和无关会话保护；独立 Astra 有界 ACCEPT。
- 质量扫描：`/private/tmp/sandiam-federation-unlink-quality-20260914.txt` 为 `0 FAIL / 44263 REVIEW / 6 ERROR`。六项错误仍为历史 staging 依赖目录读取问题，本次仅新测试场景分支命中，已独立复核；相关 diff 检查 PASS，整仓扫描不能计为通过。
- 边界：认证/MFA、PostgreSQL、并发、真实提交失败和审计事件发布未验证。同请求重试只证明失败恢复；失败审计再次抛错仍覆盖原异常，为保留的既有行为。其余联邦流程不据此计为完成；未运行 FLOW、真实数据库、同步、提交或部署。

#### 2026-09-14 P09 路由同步批量失败回滚证据

- 补齐既有 `route_binding_synchronizer_non_pg_test.php`：原事务替身三方法为空，现使用快照栈覆盖实际同步器调用治理服务的嵌套事务；分别在第 2、3 次审计注入异常，断言全部路由与审计恢复、事务栈清空，再用同 operationId 成功重试。包含新增/刷新及停用步骤，原有外部绑定保留和输入拒绝用例继续运行。
- 测试 PASS，独立 Astra 有界 ACCEPT，确认执行真实 `RouteBindingSynchronizer` 与 `ApiGovernanceService` 回滚路径。本轮未发现需修改的生产实现问题，未改生产代码。
- 质量扫描：`/private/tmp/sandiam-route-sync-transaction-quality-20260914.txt` 为 `0 FAIL / 44262 REVIEW / 6 ERROR`；本次测试无命中，六项错误仍为历史 staging 依赖目录读取问题。相关 diff 检查 PASS，整仓扫描不能算通过。
- 边界：这是失败恢复证据，不代表成功请求重放幂等；内存快照栈不证明 PostgreSQL 嵌套事务、锁或并发。未执行 FLOW、真实数据库、同步、提交或部署，P09 全模块不据此重新宣告完成。

#### 2026-09-14 P07/P08 用户组角色授予、撤销锁序修复

- 失效原因：授予按用户组→角色→关系加锁，撤销原先按关系→用户组加锁；同一关系并发授予/撤销可形成互相等待。独立 Astra 已确认交错可达。
- 已修复：撤销先无锁定位关系并保存组/角色标量，再锁用户组，按 ID、应用、用户组、角色锁定重查关系后撤销。停用用户组仍可撤销，状态和审计继续同事务。授权契约同步锁序与重查要求。
- 行为证据：新增 `identity_group_role_behavior_non_pg_test.php` 实际调用服务，以内存模型、事务及审计回调覆盖两操作首锁一致、授予/撤销/恢复、审计失败回滚、错应用拒绝、停用组撤销、定位后组/角色/应用变化或删除拒绝、重复撤销原审计语义。原实现锁序断言失败，修复后 PASS；独立 Astra 有界 ACCEPT，既有组角色契约 `15/15` PASS。
- 质量扫描：`/private/tmp/sandiam-group-role-lock-quality-20260914.txt` 为 `0 FAIL / 44262 REVIEW / 6 ERROR`，六项错误仍是历史 staging 依赖目录读取问题；本次仅新测试场景分支命中，已结合独立审查复核。相关代码 `git diff --check` PASS，整仓扫描不算通过。
- 限制：未运行 PostgreSQL 并发或真实业务请求；注入变化随测试快照恢复仅属夹具，不证明能回滚其他事务。未执行 FLOW、数据库写入、同步、提交或部署，不将离线锁序通过当作整个并发门槛通过。

#### 2026-09-14 P07/P08 直接身份授权运行回归

- 新增 `policy_authorizer_behavior_non_pg_test.php`：直接执行生产 `PolicyAuthorizer::authorize/assertScope` 与 `ScopeMatcher`，查询数据及审计收集使用内存替身，未替换授权决定。覆盖发布快照、草稿字段隔离、同一实例停用/撤销后拒绝及恢复、最低优先级与同级 deny、快照目标和版本归属隔离、应用/身份/资源停用、数据范围允许和拒绝。测试 PASS，独立 Astra 有界 ACCEPT；本轮未修改生产授权逻辑。
- 契约纠正：授权契约旧文案要求 `state=published`，与现有管理 API 的不可变版本约定不一致。现已统一为所属应用、`status=1` 和有效发布版本指针定位运行快照；草稿编辑不改变当前运行版本，撤销/停用通过 `status=2` 排除策略。
- 质量扫描：`/private/tmp/sandiam-policy-authorizer-quality-20260914.txt` 为 `0 FAIL / 44261 REVIEW / 6 ERROR`，新测试无命中；六项错误仍为历史 staging 依赖目录读取问题，整仓扫描不算通过。相关文件 `git diff --check` PASS。
- 证据边界：角色关系明确为空，仅证明直接身份；不证明组角色 JOIN、真实 PostgreSQL、并发或 HTTP。撤销用例承接 `status=2`，不是 `state=revoked` 单独门禁；审计断言覆盖允许版本、拒绝策略和数据范围拒绝，未逐场景覆盖全部字段。P07/P08 全模块及发布门槛仍未宣告通过。

#### 2026-09-14 P07/P08 策略撤销、停用审计原子性

- 已修复：`PolicyController` 的撤销、停用现在先完成权限检查，再将状态保存和成功审计一起提交；失败整体回滚。撤销保留 `state=revoked/status=2`、停用只改 `status=2`，均不修改发布版本和指针；未改变父类或策略更新接口。
- 行为证据：扩展 `policy_publication_audit_behavior_non_pg_test.php`，实际运行三个控制器层，使用内存模型/事务和可失败审计，覆盖两动作失败无残留、失败后重试成功、动作审计正确且仅一条、版本及指针不变、越权拒绝审计保留和无事务泄漏。原实现先失败，修复后 PASS。
- 独立复验：不同上下文 Astra 有界 ACCEPT；上述行为测试、版本列表授权测试、版本契约 `10/10` 均 PASS。授权器实际查询 `status=1` 的策略，源码确认两动作提交后的策略会被筛除；未把源码过滤条件当作真实撤权请求验证。
- 质量扫描：`/private/tmp/sandiam-policy-revocation-quality-20260914.txt` 为 `0 FAIL / 44261 REVIEW / 6 ERROR`。错误均为既有 staging 依赖目录读取问题；本次命中仅新测试的预期异常捕获及场景分支，已结合后续状态断言复核。不能声明整仓扫描通过。
- 未验证：PostgreSQL、并发和真实业务请求；未执行 FLOW、同步、提交或部署。下一项继续策略授权实际运行行为的原需求核查。

#### 2026-09-14 P07/P08 策略发布与回滚审计原子性

- 已修复：发布、回滚原先先提交不可变版本及运行指针，再写审计；审计异常会留下已生效版本，同请求重放又跳过审计。现在控制器传入事务内审计回调，新版本、指针和成功审计一起提交或回滚，权限检查仍在事务外；既有三参服务调用保持兼容。
- 行为证据：新增 `policy_publication_audit_behavior_non_pg_test.php` 调用实际服务，以内存事务覆盖发布/回滚审计失败回滚、同请求失败重试、成功重放不重复审计、语义冲突及不存在的回滚版本拒绝、回调在事务内读取新版本。旧实现先失败，修复后 PASS。
- 回归与独立复验：版本契约 `10/10`、版本列表及授权行为测试 PASS；不同上下文 Astra 有界 ACCEPT。旧静态契约不再把提交后审计的代码语法当成重放证据，改由上述实际服务行为测试证明。
- 质量扫描：`/private/tmp/sandiam-policy-publication-quality-20260914.txt` 为 `0 FAIL / 44261 REVIEW / 6 ERROR`，六项错误仍为历史 staging 依赖目录读取错误，不能声明整仓扫描通过。本次两项人工复核均在新行为测试：预期 ApiException 的空捕获之后验证全状态不变，分支用于失败/重试/重放场景；生产改动无扫描命中。
- 边界：未执行真实 HTTP、PostgreSQL、并发或 FLOW；不补造历史缺失审计，不将本切片通过计为 P07/P08 全模块完成或可发布。

#### 2026-09-14 P07/P08 授权内核范围复核

- 实跑 entity_scope_guard_non_pg_test、application_business_action_runtime_non_pg_test 均通过；前者检查真实对象属性和批量守卫，后者检查动作目录。它们不证明完整 RBAC/策略发布。
- 直接调用实际 ScopeMatcher::matches 的 14 个独立输入/期望场景通过：空规则、严格 equals/in、缺失字段与显式 null、字符串/整数混淆、空 in、点路径、数组 equals、未知操作符及两个条件交集。
- 未修改源码，未发现上述匹配边界的新缺口；优先级、发布快照、拒绝优先及数据库强制执行仍须各自完整证据，不把匹配器结果升级为 P07/P08 全部通过。未执行真实授权请求、数据库或 FLOW。

#### 2026-09-14 P02 组织/应用/环境原定义复核

- 按原 P02 核心对象 CRUD、隔离、停用影响和审计核对；实跑 environment_lifecycle_behavior、application_recovery_authorization、admin_organization_access_audit 三项离线回归，3/3 通过。
- 当前证据支持环境生命周期、应用恢复授权、管理范围拒绝审计和此前修改的失败回滚；不能从这些夹具外推停用后真实登录会话、机器调用、所有 CRUD 或 PostgreSQL 行为全部通过。
- 本轮未发现所覆盖行为的新缺口，未修改源码；完整停用影响仍须当前认证/运行证据，保留既有局部限制，不更新历史正式计分，不执行 FLOW 或真实停用。

#### 2026-09-14 P20 自助服务原定义复核

- 当前实跑 self_service_behavior_non_pg_test 与 self_service_controller_behavior_non_pg_test：2/2 通过。实际 SelfServiceService 的 profile/update/connections/securityOverview 覆盖身份与应用隔离、连接脱敏、活动会话/MFA/Passkey计数、资料事件审计及更新期间会话失效；控制器覆盖显示名类型。
- 认证和数据库为替身。原 P20 还要求会话撤销、改密、MFA、Passkey、恢复等用户操作；不以统计字段或本次两个测试替代这些操作的真实认证实现验证。既有门户模拟交互证据可保留，但不当作设备/浏览器验收。
- 本輪未修改源码、未发现这四个自助方法的新增缺口；真实认证候选独立复核仍受既有 Hook 限制，未绕过，未执行 FLOW、真实会话或数据库操作。

#### 2026-09-14 P19 原定义针对性复核

- 按原验收账本 P19“预检、差异、确认、草稿保存/续改/停用、幂等应用、漂移拒绝、逆序回滚”核对，保留原定义和分母，不新增功能范围。
- 实跑 5 项针对性检查通过：initialization_package、initialization_idempotency_behavior、initialization_draft_controller_behavior，以及管理端 initialization 的 context.behavior、applicationCandidates.behavior。它们分别提供静态契约、实际服务/内存依赖、控制器和实际页面脚本离线行为证据。
- 本轮未发现新增实现缺口，未修改源码。完整浏览器、真实 PostgreSQL/宿主与独立当前候选终验仍未完成；不将此结论升级为模块全部验收或全局完成率。

#### 2026-09-14 P19 初始化草稿修订号校验

- 更新/停用原修订号强转可将布尔、小数、混合文本当作当前版本；现仅接受正整数或纯数字字符串，拒绝溢出，保留原错误码。
- 11 类输入分别覆盖更新和停用（22 个拒绝场景），验证未进入修改服务；两个合法数字字符串场景返回下一版本。先红 `invalid draft revision reached mutation`，修复后控制器回归通过。
- 既有真实 InitializationService/IdempotencyService 的内存回归同时通过，涵盖草稿保存重放、版本冲突、秘密拒绝和停用等；PHP 语法及差异检查通过。未应用真实初始化包、未操作数据库或执行 FLOW。

#### 2026-09-14 投递排障说明同步

- 公开排障指南补充 Webhook/OIDC 至少一次语义、delivered 仅代表 HTTP 2xx、接收端业务结果核对及 lease_lost 的处理方式；保留 OIDC dead 记录重新签发入口，不引导重置记录或复用旧 token。
- 批量统计说明与两个实际服务返回字段一致；明确不能将 claimed 当作送达数量，也不把租约丢失当作 dead。
- 仅文档同步和差异检查，未增加运行验收证据、未操作服务或数据。

#### 2026-09-14 OIDC 后台退出通知领取保护

- 独立 `astramedium__hook_final_review` 源码审查并运行新测试有界 ACCEPT；请求及实际 `gpt-6-astra / medium / openai`。已核对内置 Worker 忽略返回值，但未核验所有范围外精确结构消费者是否兼容新增 lease_lost 字段。
- 后台退出通知改为逐条领取后发送，每批最多 limit 次；发送前验证原租约匹配且未过期，成功/失败写回绑定原 state/locked_until，失去领取资格计入 lease_lost，不覆盖新结果或写投递审计。
- 保留退出令牌 envelope、表单字段、10 秒 HTTP 参数、原五次尝试和退避常量；审计辅助应用查询纳入原异常保护，查询故障不改变已持久化结果。worker 和运行配置未改。
- 新 `oidc_delivery_lease_non_pg_test.php` 先复现预领问题，修复后覆盖批量上限、发送前过期、三类 HTTP 结果期间重新领取、审计查询故障、重试/耗尽、过期回收和空队列；行为、PHP 语法和差异检查通过。
- 仅内存时钟、存储、令牌与 HTTP 替身，未产生真实退出令牌或外发；真实 PostgreSQL、标准对端、性能与长稳未验。仍是至少一次投递，保留 DNS、时钟回拨及时间戳版本限制，不计 FLOW 或发布通过。

#### 2026-09-14 Webhook 投递领取与旧结果保护

- 返工后独立 `astramedium__hook_final_review` 重跑租约测试通过并有界 ACCEPT；请求及实际 `gpt-6-astra / medium / openai`。本项未获真实运行/性能验收，未改变 worker 默认关闭状态。
- 独立复核发现审计辅助应用查询在 try 外，已持久化结果可能被误计 lease_lost；现将查询纳入原审计异常保护，三类结果故障注入先红 `audit lookup changed delivery outcome`、修复后通过，保持实际 delivered/retried/dead 统计。
- 原整批预领统一 120 秒租约后串行发送，后排任务可能尚未发送就到期。改为每次只领取一条、提交领取事务后立即发送，循环最多配置的批量上限；未增加 worker 或外部并发，不改运行配置。
- 发送前确认原租约匹配且未过期；成功/失败结果按 id、status=2 和原 locked_until 条件更新，失去领取资格不修改投递状态/次数或写投递审计。新增 `lease_lost` 统计，保持原四字段含义；现有集成测试期望已同步但未运行。
- 新离线测试模拟慢投递、发送前过期、三类 HTTP 结果期间被重新领取、正常重试/耗尽、过期回收及空队列；旧代码红例 `bounded immediate claim failed`，修复后通过，原入队回归和 PHP 语法通过。
- 真实 PostgreSQL 并发、负载与长稳未验；DNS 在 curl 超时外，时间戳不是永久唯一领取标识，仍为至少一次投递，接收端需按事件 ID 去重。本项不代表消息恰好一次或生产性能通过，未执行服务、数据库、外发或 FLOW。

#### 2026-09-14 Webhook 重复事件广播入队

- 独立 `astramedium__hook_final_review` 源码审查及新离线测试有界 ACCEPT，无必须返工问题；请求及实际 `gpt-6-astra / medium / openai`。不包含真实并发或外部投递验证。
- 原 `enqueue` 捕获唯一冲突后继续使用已失效的 PostgreSQL 事务；内存事务夹具复现 `transaction aborted`。现复用已有应用锁，按端点/事件键先查已有投递，存在则保留原状态、payload 和次数，不存在才创建；真实存储异常交外层整体回滚。
- 新 `webhook_enqueue_behavior_non_pg_test.php` 加载实际服务及 EventCatalog，覆盖重复事件零新增、仅补缺端点、已投递/最终失败不重置、不同应用同事件、两种入队入口顺序去重、存储失败/唯一冲突回滚与重试。新测试、PHP 语法及差异检查通过。
- 未改数据库唯一约束；不遵守现有锁的写者或更高隔离级别冲突仍会明确失败。内存回归不证明真实 PostgreSQL 并发；投递租约竞争仍需另行核查，未执行数据库、外发或 FLOW。

#### 2026-09-14 IAM-T10 公开门户邀请入口复核

- 当前门户已具备接受成功后明确提示登录、清除旧会话资料、失败保留账号/显示名并清空密码、提交互斥，以及迟到响应不清除新登录状态；本轮未发现必须修改的邀请入口缺口。
- 实际运行 `node sand-iam/portal/tests/portal-contract.test.mjs` 和门户 `tsc --noEmit` 均通过；指定邀请行为覆盖重试、防重复和会话隔离，其他原门户离线回归同时通过。
- 未修改门户源码或生成物；过期/撤销的真实服务响应、目标浏览器体验、消息链接与 HTTP 激活仍未验，不以本轮结果计入正式业务链或 FLOW。

#### 2026-09-14 P18 三语言接受邀请入口

- 独立 `astramedium__hook_final_review` 有界 ACCEPT；实际复跑 PHP SDK、TypeScript security-flow 通过，Dart security-flow 9/9 通过。请求及实际模型均 `gpt-6-astra / medium / openai`；真实邀请激活不在结论内。
- 确认 PHP、TypeScript、Dart SDK 均缺少既有 `/api/sand-iam/v1/invitations/accept` 封装，现新增 `acceptInvitation`。匿名 POST，仅传邀请令牌及账户资料，保留请求号和 no-store；应用由邀请确定，不附组织/应用参数。
- 成功只返回用户 ID/显示名，不返回登录令牌；三语言测试覆盖路径前缀、精确 body/headers、成功投影、5 类畸形结果、过期错误及无自动重试、空输入拒绝。三语言 README 已补调用方式及接受后仍须登录说明，TypeScript dist 已由原编译器生成。
- PHP、TypeScript 全套测试通过，Dart 58 项通过、analyze 无问题。均为离线传输替身；真实邀请消息、HTTP、客户端平台和数据库未验，未执行 FLOW 或宿主操作。

#### 2026-09-14 IAM-T10 邀请初始用户组输入

- 复现布尔、小数和混合文本经 `intval` 转成真实组 ID；现在仅允许正整数或纯数字字符串，拒绝溢出，保留去重、50 组上限及应用/启用状态检查。
- 原邀请行为测试扩展 12 类非法或无效组输入，断言拒绝后邀请、审计和发送次数均不变；合法整数/数字字符串去重通过。先红例 `invalid group accepted`，修复后全组离线回归、PHP 语法及差异检查通过。
- 本项是实现者离线证据，不扩大上一项投递竞争独立 ACCEPT 的范围；未执行真实邀请发送、数据库或 FLOW。

#### 2026-09-14 IAM-T10 邀请投递与撤销竞争

- 不同上下文 `astramedium__hook_final_review` 源码审查并实际运行新离线测试：有界 ACCEPT；请求及实际 `gpt-6-astra / medium / openai`。真实数据库竞争和外部投递不在通过结论内。
- 复现发送返回将已撤销邀请覆盖为 pending/delivery_failed；原 status=2 仍会阻止接受，但旧重发逻辑未检查 status，导致撤销后仍可重发。
- 投递成功/失败按固定邀请、应用、sending/status=1 和原 token_hash 条件写回；旧结果不能覆盖撤销或新令牌版本。重发增加停用拒绝；撤销锁定重取并与成功审计共同提交或回滚。
- 新 `identity_invitation_delivery_behavior_non_pg_test.php` 加载实际服务，覆盖正常投递成功/失败、投递与撤销交错、新令牌版本保护、停用拒绝且无发送、撤销审计故障回滚和重试、跨应用及已接受拒绝。先红例 `delivery completion overwrote revocation`，修复后通过；PHP 语法和差异检查通过。
- 仅内存模型、事务和消息替身；未执行真实外发、PostgreSQL、HTTP 或 FLOW。已在途消息不能召回，不能将本项解释为外部投递取消保证。

#### 2026-09-14 审计保留策略首次创建失败恢复

- 创建、更新、停用组合候选已由不同上下文 `astramedium__hook_final_review` 独立复核并运行指定离线测试，有界 ACCEPT，无必须返工问题；实际 `gpt-6-astra / medium / openai`。拒绝审计保留及更新失败后成功重试仍只有源码依据，未声明对应动态验证；真实 PostgreSQL、并发和 HTTP 未验。
- 首次创建启用父类现有 `atomicCreateAudit`，复用 `IdempotencyService` 的事务，将策略、成功审计和操作记录共同提交或回滚。
- 扩展原离线控制器测试，加载真实 `IdempotencyService`，用内存模型验证审计失败不残留策略或操作记录，以及失败后同一请求重试成功；先复现 `create audit failure left policy or operation`，修复后通过。
- 保留已有“组织已存在策略”拒绝语义，本项不声明成功创建后的重复请求重放可用。未执行真实 PostgreSQL、策略清除或 FLOW。

#### 2026-09-14 审计保留策略更新、停用失败回滚

- 已复现审计写入失败后策略仍被修改；控制器启用现有父类事务开关，将更新/停用与成功审计共同提交或回滚，权限检查仍在事务之前。
- `audit_retention_policy_input_non_pg_test.php` 从 `policy audit failure left mutation` 失败转为通过，覆盖更新、停用审计故障回滚及停用重试；控制器与测试 PHP 语法检查、`git diff --check` 通过。
- 证据仅为离线控制器和内存事务测试；未修改真实策略，未执行清除、数据库操作或 FLOW。创建操作的原子性不包含在本项结论内。

#### 2026-09-14 审计保留与告警数字参数校验

- 复现策略参数先强制转换校验、却保存原始值的问题；四项天数/窗口/次数现仅接受整数或纯数字表单字符串，保存前规范化。
- 32 个非法类型场景拒绝且策略/审计不变；四个合法字符串规范化、保留期与归档期关系及既有清除开关回归通过。沿用原范围和错误码分类，PHP 语法和差异检查通过。
- 仅内存策略与实际控制器验证，未执行真实策略配置、归档清除、数据库、HTTP、FLOW、提交或部署。
- 全仓扫描 0 FAIL、44255 REVIEW、6 个历史目录 ERROR；本测试分支提示已结合非法类型和字段关系断言复核。输出 `/private/tmp/sandiam-retention-integers-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 审计保留策略清除开关校验

- 复现 purge_enabled 任意值直接进入策略写入；控制器现只接受明确 0/1、对应字符串或布尔值，并规范化为整数；省略字段保持现值。
- 离线实际控制器测试覆盖 8 类非法值拒绝且策略/审计不变、6 种合法表达及省略字段兼容。行为、PHP 语法与差异检查通过。
- 仅内存策略夹具，不涉及真实清除开关配置、归档或删除；未执行数据库、HTTP、FLOW、服务、同步、提交或部署。
- 全仓扫描 0 FAIL、44254 REVIEW、6 个历史目录 ERROR，本批两文件无命中；输出 `/private/tmp/sandiam-retention-switch-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P20 Unicode 空白显示名称

- 复现全角空格在原 trim 后被规范化成单个空格并保存；改为先统一 Unicode 空白、再 trim，沿用已有字符过滤及长度限制。
- 三类纯 Unicode/不可见混合空白输入返回 400 且资料/事件/审计不变；带全角及不换行空格的中文姓名规范化为可见文本。原自助服务、控制器输入行为及 PHP 语法检查通过。
- 仅离线实际服务配合内存依赖验证；未执行数据库、HTTP、FLOW、同步、提交或部署。
- 全仓扫描 0 FAIL、44254 REVIEW、6 个历史目录 ERROR，本批两文件无命中；输出 `/private/tmp/sandiam-profile-unicode-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P20 资料接口显示名称类型校验

- 自助资料控制器原来将任意 display_name 强制转换为字符串；现明确只接受文本，拒绝数字、布尔、null、数组等，避免非文本进入资料写服务。
- 独立控制器夹具覆盖 7 类非法输入均返回 400 且不调用写服务、缺令牌优先 401、合法文本/Bearer/请求号传递不变。控制器行为、静态契约、PHP 语法与差异检查通过。
- 测试服务为捕获调用的替身，不声明真实持久化或 HTTP 已验；未绕过会话撤销候选的独立复核限制，未执行数据库、FLOW、服务、同步、提交或部署。
- 全仓扫描 0 FAIL、44254 REVIEW、6 个历史目录 ERROR，本批两个文件无命中，实际差异已复核；输出 `/private/tmp/sandiam-profile-input-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P03 会话退出/撤销失败一致性

- 新离线测试加载真实 HumanAuthService，复现刷新令牌更新失败后会话已撤销的部分状态。退出/撤销现在按固定 ID、应用、身份锁定目标，将会话、刷新令牌与成功审计一起提交或回滚。
- 为避免单纯加事务引入 session/refresh 锁反转，刷新及重放家族撤销统一先无锁定位会话，再锁 session，最后按 token_hash+session_id 锁定重查 refresh；初读不是授权依据，已消费令牌仍可触发家族撤销。
- 行为覆盖两操作各两类存储故障、失败后重试、撤销后访问及刷新拒绝、定位后删除/改挂不会误撤销、已消费重放撤销自身会话；幂等服务原异常路径先回滚再抛出已核对。新行为、认证静态契约、自助原回归和 PHP 语法通过。
- 模型/事务/幂等/审计为内存替身；真实 PostgreSQL 竞争、全局死锁及正常刷新端到端未验，不计整模块通过。未执行数据库、HTTP、FLOW、服务启停、同步、提交或部署。
- 方案判断已获独立 Astra/medium 支持，但固定候选独立复核未完成：原 `git diff -- .../HumanAuthService.php` 与测试 `rg -n 'require|include|lock|rebind|delete|rollback|function|assert' .../human_session_revocation_behavior_non_pg_test.php` 组合命令及原命令正式升级均遭 PreToolUse SQL 误拒。没有绕过或独立执行测试，不把主控 PASS 写成独立 ACCEPT；此限制仅对应本候选复核。
- **恢复复核结果**：Hook 修复后，主控和独立 Astra/medium 原组合命令均正常执行；独立运行 `human_session_revocation_behavior_non_pg_test.php` PASS，logout/revokeSession 原子撤销与 refresh family 锁序获有界 ACCEPT。上述候选复核阻塞已解除，不再作为当前阻塞。故障注入发生在刷新更新/审计写入前，幂等为替身；不证明成功刷新端到端或 PG 并发。captchaConfiguration 不在本批复核范围。
- 主控只读核对门户：撤销当前会话成功后清空本地认证状态，撤销其他会话后重新加载列表；logout 成功后清空本地会话，异步响应检查原认证上下文。此为调用契约核对，未操作浏览器，不计四角色真实体验通过。
- 全仓扫描 0 FAIL、44254 REVIEW、6 个历史目录 ERROR；认证服务与新增测试的分支提示已由主控结合故障/重放/绑定变化边界复核，独立复核限制仍在。输出 `/private/tmp/sandiam-session-revocation-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P20 资料更新会话与返回结果一致性

- 复现资料更新初验后会话被撤销，资料/成功审计已提交但末尾重新鉴权返回 401 的问题。请求重叠本身不证明撤权失效，本批修复的是操作结果与副作用不一致。
- 写事务按组织、应用、身份、原会话顺序锁定并核对归属，持会话锁后复用原 authenticatedSession 校验；事务内形成资料快照，提交后直接返回，不再提交后鉴权。
- 离线行为覆盖初验后撤销、轮换、过期返回 401 且资料/事件/审计无变更，以及提交后撤销仍返回已完成的成功快照；原自助行为、静态契约及 PHP 语法通过。
- 令牌校验使用测试替身，真实 PostgreSQL 并发、死锁与 HTTP 未验；未执行数据库、服务、FLOW、同步、提交或部署。
- 独立 Astra/medium 源码与指定离线行为复验 ACCEPT。全仓扫描 0 FAIL、44252 REVIEW、6 个历史目录 ERROR；本批两个文件无命中，实际差异已复核。输出 `/private/tmp/sandiam-profile-session-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P14 同步连接停用失败恢复

- 复现停用审计失败后连接仍为停用状态；现仅将状态变更和成功审计纳入事务，权限检查及既有运行中检查保持原位置。
- 离线行为测试覆盖运行中返回 409 且无变更、审计失败回滚、随后停用重试成功；创建、更新与类型校验既有回归通过，PHP 语法和差异检查通过。
- 本批不改变调度器/worker 协议，不证明并发启动与停用互斥；真实数据库、目录同步、HTTP、FLOW、服务启停、宿主同步、提交和部署均未执行。
- 全仓扫描 0 FAIL、44252 REVIEW、6 个历史目录 ERROR；控制器/测试累计分支提示已结合事务失败和运行中拒绝断言复核。输出 `/private/tmp/sandiam-sync-disable-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P14 保护参数整数校验

- 复现小数保护参数被强制转换为整数后保存；更新入口现拒绝小数、布尔、null、数组及带杂字符/小数/指数的字符串，保留整数和纯数字表单字符串。
- 两个字段合计 18 个非法类型场景均返回 400 且配置/审计不变；范围上下界、数字字符串、既有配置与创建重试回归通过，PHP 语法和差异检查通过。
- 不改变时长 1–720、比例 1–100 的范围或冲突策略；仅离线实际控制器证据，未执行数据库、真实同步、HTTP、FLOW、提交或部署。
- 全仓扫描 0 FAIL、44252 REVIEW、6 个历史目录 ERROR；当前控制器和测试的累计分支提示已对照类型校验、事务异常和拒绝断言复核。输出 `/private/tmp/sandiam-sync-integers-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P14 同步连接创建失败恢复

- 复现创建审计失败仍留下连接记录的问题；连接创建和成功审计现纳入同一事务，权限及参数检查保持在事务外。
- 离线实际控制器测试覆盖创建审计失败无残留、随后相同代码重试成功、真正重复创建返回 409 且无额外记录/审计；原有设置更新、归属冲突和拒绝审计回归通过，PHP 语法和差异检查通过。
- 唯一冲突转换仅包裹连接创建，审计异常保留原异常。仅内存事务夹具证据，未执行数据库、目录同步、HTTP、FLOW、宿主同步、提交或部署。
- 全仓扫描 0 FAIL、44251 REVIEW、6 个历史目录 ERROR；本测试分支提示对应创建冲突/故障和先前权限边界断言，已人工复核。输出 `/private/tmp/sandiam-sync-create-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P14 同步保护设置拒绝审计保留

- 复现同步连接 update 的事务包住权限检查，导致拒绝审计被回滚；授权移到事务之前，事务内仍按原 ID 锁定连接，并比对授权时的组织/应用标量。
- 归属发生变化时返回 `SAND_IAM_SYNC_CONNECTOR_SCOPE_CHANGED`（409），不保存设置。成功写入与审计继续原子提交；权限拒绝留下拒绝审计，连接不变。
- 离线行为测试覆盖拒绝审计、组织/应用归属变化、成功审计失败回滚、四种冲突策略与输入边界；PHP 语法和差异检查通过。未读取受限 SyncConnectorService，未执行真实目录同步、数据库、HTTP、FLOW、宿主同步或部署。
- 独立 Astra/medium 源码及离线行为复验 ACCEPT。全仓扫描 0 FAIL、44251 REVIEW、6 个历史目录 ERROR；当前测试分支提示已结合拒绝/故障断言复核。输出 `/private/tmp/sandiam-sync-denial-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 安全告警处理拒绝审计保留

- 有界剩余需求复核发现告警 resolve 的授权检查在事务内，拒绝审计随异常回滚；离线测试已复现后修复。
- 授权检查移到写事务之前，事务内仍锁定原告警并核对组织/应用归属；归属变化返回 `SAND_IAM_SECURITY_ALERT_SCOPE_CHANGED`（409），避免用旧授权处理新范围记录。成功状态写入和成功审计仍保持原子性。
- 离线实际控制器测试覆盖组织/应用越权拒绝审计、归属变化、重复处理、成功审计失败回滚；PHP 语法和差异检查通过。真实数据库并发与 HTTP 未验，不计 FLOW 或安全运营模块全部完成。
- 同批修正终极验收账本：历史 20/20、28/48 不再标为当前全需求完成率，保留原历史证据，当前实现与未验证层以本任务板为准。
- 独立 Astra/medium 源码与离线行为复验 ACCEPT。全仓扫描 0 FAIL、44251 REVIEW、6 个历史目录 ERROR，本测试分支提示已结合故障和拒绝场景复核；输出 `/private/tmp/sandiam-alert-denial-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P02 独立复核返工：保留拒绝审计

- 独立审查发现此前应用停用、环境更新/停用的外层事务将权限拒绝审计一起回滚，本批立即纠正；此前局部通过不代表该边界通过。
- 父资源控制器增加默认关闭的写入审计事务选项，仅应用/环境启用，授权与载荷检查在事务外，数据变更与成功审计在事务内。其他控制器行为不变；应用自定义更新、客户主体普通更新保留已有授权后事务。
- 应用测试使用真实 AdminOrganizationAccess，环境权限替身补上对应拒绝审计；覆盖跨应用修改、无权更新/停用时拒绝审计保留且数据不变，同时保留成功审计失败回滚验证。两个行为测试、两个静态契约和 PHP 语法检查通过；独立 Astra/medium 复验两行为测试 2/2 通过，当前源码及离线边界 ACCEPT。
- 漏项原因是原环境权限替身只抛错，没有写真实拒绝审计；防复发断言已落实到现有测试。仍为离线证据，未执行数据库、HTTP、FLOW、同步或部署。
- 最终扫描 0 FAIL、44251 REVIEW、6 个历史目录 ERROR；两行为测试的分支提示对应故障注入和权限拒绝断言，已结合差异及独立复验复核。输出 `/private/tmp/sandiam-p02-audit-boundary-quality-20260914.txt`，不记为全仓扫描通过。

#### 2026-09-14 P02 客户主体更新审计原子性

- 复现并修复客户主体普通更新在审计失败后仍保存状态；数据更新与成功审计现为同一事务。启用转停用继续调用原有会话撤销服务。
- 离线行为测试验证改名/恢复审计失败后记录与审计均不变，以及随后恢复成功且保留原名称；应用更新、恢复、停用和名称校验原有回归通过。PHP 语法与差异检查通过。
- 仅内存事务夹具驱动实际控制器验证，真实 PostgreSQL/HTTP 和客户主体停用会话链不计本批通过；未执行 FLOW、数据库写入、服务启停、同步、提交或部署。

#### 2026-09-14 P02 客户主体与应用名称校验

- 更新入口补齐名称非空字符串校验及首尾空白规范化，保留未提供名称的状态操作行为；复用两控制器既有 normalizePayload 扩展点，不改变通用资源规则。
- 离线行为测试覆盖客户主体和应用各 6 类非法名称拒绝且记录/审计不变、合法名称规范化；原有恢复授权、失败回滚、停用后访问拒绝回归通过。应用范围静态契约、PHP 语法与差异检查通过。
- 本批验证使用平台管理员执行客户主体改名，未扩大现有管理授权；新增创建行为未单独验证，不计整模块或真实 PostgreSQL/HTTP 通过。未执行 FLOW、数据库写入、服务启停、同步、提交或部署。
- 全仓扫描 0 FAIL、44250 REVIEW、6 个历史目录 ERROR，本批三个文件无命中；差异已人工复核。输出 `/private/tmp/sandiam-parent-names-quality-20260914.txt`，全仓扫描不记为通过。

#### 2026-09-14 P02 应用停用审计原子性

- 复现并修复停用审计失败后应用仍变为停用状态；停用现以事务包裹原有权限检查、状态写入及审计，异常回滚。
- 同一离线行为测试覆盖失败时状态/审计不变且普通访问保持有效、重试成功后状态停用且普通访问拒绝。恢复/更新原有回归、应用范围契约、PHP 语法与差异检查通过。
- 证据仅为内存事务夹具驱动实际控制器及权限逻辑，不代表 PostgreSQL/HTTP 或整模块验收；未执行 FLOW、数据库写入、服务启停、同步、提交或部署。
- 全仓扫描完成：0 FAIL、44250 REVIEW、6 个历史目录 ERROR，本批两个文件无命中，实际差异已人工复核。输出 `/private/tmp/sandiam-application-disable-quality-20260914.txt`；全仓扫描不记为通过。

#### 2026-09-14 P02 应用恢复与更新审计原子性

- 已复现恢复应用的审计失败仍留下启用状态；更新写入与审计现纳入同一事务，失败回滚并保留原异常。
- 离线行为测试覆盖恢复失败后保持停用及普通访问拒绝、随后成功恢复、普通改名审计失败回滚，以及原有管理员恢复权限与禁止恢复时夹带字段约束。行为测试、应用范围静态契约、PHP 语法及差异检查通过。
- 仅验证实际控制器配合内存模型和事务夹具；真实 PostgreSQL/HTTP 未验，不计模块整体完成。未执行 FLOW、数据库写入、服务启停、同步、提交或部署。
- 全仓扫描 0 FAIL、44250 REVIEW、6 个历史目录 ERROR，本批两个文件无命中；事务异常路径及新增断言已人工复核。输出 `/private/tmp/sandiam-application-recovery-atomic-quality-20260914.txt`，不代表全仓扫描通过。

#### 2026-09-14 P02 环境更新与停用审计原子性

- 已复现：审计写入抛错后，环境更新仍留下数据变更（离线行为测试失败：`update left environment or audit changes after failure`）。
- 已修改：环境更新、停用将原有数据写入和审计纳入同一事务，异常回滚并保留原异常；未扩大到其他资源控制器。
- 已验证：实际控制器配合内存事务夹具覆盖更新/停用审计失败回滚、随后成功操作、跨应用拒绝、名称与代码约束；生命周期行为测试、静态契约、两个 PHP 语法检查及差异空白检查通过。
- 限制：内存夹具证明控制器事务调用及异常行为，不代表真实 PostgreSQL/HTTP 验收；未执行 FLOW、数据库写入、服务启停、同步、提交或部署。
- 全仓质量扫描结束：0 FAIL、44250 REVIEW、6 个历史目录 ERROR；本批行为测试分支提示已人工复核，新增分支用于故障注入及回滚断言。输出 `/private/tmp/sandiam-environment-atomic-quality-20260914.txt`；不记为全仓检查通过。

#### 2026-09-14 P02 环境名称更新校验

- 现有生命周期行为测试复现环境更新接受空名称；环境控制器现对显式提交的名称要求非空字符串并去掉首尾空格。未提供名称的状态恢复保持原名，不改通用父类或其他资源行为。
- 覆盖空白、null、数字、数组拒绝且数据/成功审计不变，正常改名、系统代码不可改、跨应用越权拒绝、停用与恢复。行为测试、既有环境范围/审计契约检查及 PHP lint 均通过；仅隔离内存模型，无数据库、HTTP、FLOW、同步或部署。
- 全仓扫描 0 FAIL、44250 REVIEW、6 个历史目录 ERROR；本批行为测试的 1 条分支提示对应非法输入与越权断言，已人工复核。输出 `/private/tmp/sandiam-environment-name-quality-20260914.txt`，全仓扫描不能记为通过。

#### 2026-09-14 P14 同步保护设置编辑已实现

- 管理端已补齐所选连接的名称、缺失保护时长、停用比例阈值和四种冲突策略编辑入口，调用已有 `sync-connector/update`；字段范围和权限已对照后端控制器。切换上下文取消草稿，保存失败保留输入，提交与同页写操作互斥，停用确认不再改写当前选择。
- 缺失入口红例已复现；新增及既有 4 组 SFC 脚本行为回归通过，scoped vue-tsc 通过。主控对照后端契约和模板、复跑新增行为测试通过。仅修改权威页面与测试，未执行真实浏览器、目录同步、数据库、FLOW、宿主同步或部署，不计 P14 真实平台验收通过。
- 全仓质量扫描完成：0 FAIL、44249 REVIEW、6 个历史目录 ERROR；本页 1 条累计分支提示已结合实际差异复核，新增判断用于输入校验和请求/草稿上下文隔离，未引入新接口或解析层。输出 `/private/tmp/sandiam-sync-settings-quality-20260914.txt`；不声称全仓扫描通过。

#### 2026-09-14 Hook 上下文读取修复已生效

- 实际安装 `shell_safety_guard.py` 的 6 行 `rg -A/-B/-C` 有界参数识别修复，SHA256 `6cc01f5fb9f4c4aba86944ebe0d10fb70f4c22c97ed9f156b1e01720e7243722`。独立审查通过、8 组回归通过；原同步页面/控制器读取命令退出 0，环境父类原查询退出 1（无匹配，不再被 Hook 拒绝）。
- 同步保护设置编辑可以继续实现。包含有限 brace、缺失源码和固定控制器读取例外的较大候选虽通过 16 组验证，但安装遭自动审批拒绝，未应用；不能将机器身份和 DDL 对照限制记为解除。回滚副本为 `/private/tmp/sandiam-source-read-hook-20260914/baseline.py`。

#### 2026-09-14 P18 公开认证接口文档对齐

- 补齐公开认证文档中的验证码配置、改密、密码/MFA 二次验证及联合身份解绑；修正“所有接口均从应用代码解析身份”和“MFA 验证总是签发新会话”的描述。
- 已逐项对照路由、AuthController、HumanAuthService、MfaService 与 FederationService：明确验证码三分支、二次验证只更新原会话、解绑最后登录方式保护及绑定会话撤销。文档 diff 检查通过；未改后端、SDK 或执行运行验收，不计新增功能或全需求完成。

#### 2026-09-14 P18 CLI 示例配置校验与字符串转义

- 修复 `snippet` 将 URL 单引号直接拼入 PHP/TypeScript/Dart 代码的问题，并转义 Dart 的 `$` 插值；输出前复用真实 SDK 构造校验与规范化 URL，不新增解析规则、不发起网络请求。
- 两个行为红例分别证明旧版未转义及无效配置仍输出代码；修复后新增引号/插值、配置拒绝、URL 换行规范化回归，全 Dart **57/57**、analyze 通过。主控用 PHP/Node、实现者用 Dart 实际执行生成片段，由测试构造器确认含单引号和 `$` 的原 URL 精确传入；这不是实际业务 HTTP 验收。
- P14 只读参数 Hook 修复仍为隔离候选，独立测试遭拒且未安装，本批不将该限制记为解除。
- 主控复跑 CLI 专项 5/5 通过；全仓扫描已完成，0 FAIL、44249 REVIEW、6 个历史目录 ERROR，本批 CLI 两文件没有扫描命中，实际变更已人工复核。扫描输出 `/private/tmp/sandiam-cli-quality-20260914.txt`，不据此声称全仓检查通过。

#### 2026-09-14 P18 Captcha、升级验证与联合身份解绑 SDK 已落地

- 本切片 TS/Dart 各实现 `captchaConfiguration`、`stepUpPassword`、`startMfaStepUp`、`unlinkFederation`，合计 **8/8 封装已实现并通过离线验证**。验证码匿名 GET 固定组织、应用及 login/register 用途，保留无需/不可用/可用组件三分支；其余接口必须带会话，MFA 挑战提交后保留 `step_up:true`，不伪造登录令牌或自动重试。
- TypeScript 构建与默认测试入口通过；Dart 全套 **54/54**、analyze 通过。主控已独立复跑 TS security-flow 和 Dart 新增 8 项并对照后端契约通过；两语言 README 已同步。不将本批离线通过计为整个 P18 或全产品完成。
- 主控全仓扫描结果为 **44249 REVIEW、6 个历史目录 ERROR、0 FAIL**，输出 `/private/tmp/sandiam-sdk-security-quality.txt`；SDK 5 条累计分支已结合实际变更人工复核，不能宣称全仓扫描通过。指定 SDK 文件 diff 检查通过。未执行真实 HTTP、设备、数据库、FLOW、同步、提交或部署。

#### 2026-09-14 TS/Dart Passkey SDK 已落地

- TS、Dart 各补齐通行密钥注册 options/finish、认证 options/finish，共 8 个调用。注册要求登录态与当前密码，完成返回 void；认证匿名绑定 SDK 配置的客户主体及应用，返回现有认证结果。平台负责 WebAuthn 与二进制编码，SDK 不伪造设备操作、不自动重试。
- 四个源码文件、两份新增行为测试、TS 默认测试入口及两份 README 已写回权威目录；9 个文件与审查候选逐字一致。TS 构建与默认测试通过；Dart 全套 46/46、analyze 通过；主控独立复跑 TS 专项和 Dart 专项 10/10，后端契约及差异检查通过。
- 本轮未执行真实设备、HTTP、数据库或 FLOW。SDK 已知剩余封装从 16 个降为 8 个：TS/Dart 各自的 Captcha 配置、密码升级验证、MFA 升级验证发起与联合身份解绑；P18 仍未全部完成。
- 全仓质量扫描已执行，但历史 `.staging` 中 6 个以代码扩展名命名的目录导致 ERROR，不能宣称全仓扫描通过；本批 SDK 相关 4 条为累计分支 REVIEW，已结合冻结差异人工核对输入校验、响应判别与生成物，无新增必修项。扫描输出保存在 `/private/tmp/sandiam-sdk-passkey-candidate/quality-scan.txt`，不据此扩改历史目录。

#### 2026-09-14 P05 SCIM Group 显式清空 externalId

- 实际服务行为红例复现 PATCH 移除属性后被 replaceGroup 当作省略而保留旧值；保留显式 null 至持久化处理，修复三种清空请求，PUT 省略字段的约定不变。
- 同一 SCIM 行为测试三组 PASS：三种清空后的响应/重新读取、资源 ID、成员与版本，以及既有 Group/User 审计回滚与来源撤销。PHP 语法通过；属于实际服务与内存 ORM 验证，真实 SCIM HTTP、PostgreSQL 和并发仍未验证。
- 后续同一夹具补齐操作顺序与拒绝原子性：先清空后设置、先设置后清空均按顺序生效，单请求只增一次版本及审计；第二操作非法、值类型错误、缺失版本 428、旧版本 412 均无部分写入，事务关闭。当前四组 PASS，未新增生产逻辑或扩大真实运行验证结论。

#### 2026-09-14 P05 SCIM User 生命周期审计原子性

- 复现 User 创建审计失败仍保留身份/绑定/来源记录；将 user create、patch、delete 三处既有审计移至各自提交前，replace 继续委托 patch。
- 扩展同一 SCIM 行为夹具，Group/User 两组通过；User 创建、替换、PATCH、删除均验证审计写后全量回滚和恢复，来源会话/刷新令牌撤销、其他来源会话不变、共享 Identity 仍启用、旧版本 412/删除后 404 无写入。
- PHP 语法通过，公开 API 契约同步。当前是实际服务与内存 ORM 证据，不证明真实数据库、HumanAuth、SCIM HTTP 或并发。未执行数据库、服务、FLOW、提交或部署。
- 独立 Astra 两组复跑 PASS、有界 ACCEPT，差异检查通过；扫描 0 FAIL、44267 REVIEW、6 个历史目录 ERROR，当前测试 14 处分支提示已按组/用户失败与旧版本断言复核。输出 `/private/tmp/sandiam-scim-user-quality-20260914.txt`。

#### 2026-09-14 P05 SCIM 组生命周期审计原子性

- 复现创建审计写后失败仍保留组/成员；将 group create、replace、delete 既有成功审计移到提交前，PATCH 沿用 replace。无接口、权限或版本语义变更。
- 新增实际 `ScimService` 与内存 ORM 回归覆盖创建、替换、PATCH、删除的审计写后完整回滚及恢复，成员移除、版本递增和旧版本 412/已删除 404 无写入。主控和独立 Astra 复跑 PASS、有界 ACCEPT，PHP 语法通过。
- 差异检查通过；扫描 0 FAIL、44267 REVIEW、6 个历史目录 ERROR，新增测试 8 处分支提示已结合故障及旧版本断言人工复核。输出 `/private/tmp/sandiam-scim-group-quality-20260914.txt`。
- 证据不证明真实 ORM 软删除、PostgreSQL 并发、SCIM HTTP 对端或 User 分支；User 的同类审计边界留作下一原需求。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P02 客户主体停用与会话撤销

- 核实现有应用/环境变更事务与组织专用撤销服务，不重复实现。新增 `organization_session_revoker_behavior_non_pg_test.php`，运行实际 `OrganizationHumanSessionRevoker` 配合内存持久化。
- 两应用活动会话和刷新令牌各 2 条撤销，其他主体及已撤销记录不变；刷新令牌写后、会话写后、审计写后三种故障完整回滚。成功审计归属与计数、重复停用无额外修改、目标不存在后事务结束均通过。
- 行为测试与差异检查通过；扫描 0 FAIL、44266 REVIEW、6 个历史目录 ERROR，本测试无扫描提示，输出 `/private/tmp/sandiam-organization-revoker-quality-20260914.txt`。
- 本批不验证管理端真实权限、HumanAuth 登录入口、PostgreSQL 并发或真实浏览器会话，不计整个 P02 完成。未修改生产代码，未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P18 CLI 接入诊断失败路径

- 核实现有 TS/Dart 已知认证封装已补齐，未再新增无依据接口。扩展原 `sdk/dart/test/cli_test.dart`，覆盖网络异常、非 JSON 响应、发现文档单独失败、应用配置单独失败。
- 实际 CLI 与 SDK 请求处理在四种失败下均完成两项检查、返回退出码 2；不读取访问令牌、无 Authorization、组织/应用查询绑定保持正确，不输出异常或响应中的敏感标记。
- CLI 专项 9/9、Dart analyze 无问题；使用现有 Dart 二进制和单次 `--suppress-analytics`，未修改全局工具设置。无新增生产代码，未执行真实宿主请求、数据库、FLOW、提交或部署。公开契约补充诊断范围与退出码。
- 差异检查通过；扫描 0 FAIL、44266 REVIEW、6 个历史目录 ERROR，本测试无扫描提示。输出 `/private/tmp/sandiam-cli-doctor-quality-20260914.txt`，不宣称全仓完整通过。

#### 2026-09-14 P11 调用授权拒绝后的事务恢复

- 扫描阻塞后续解除：固定扫描脚本与限定报告读取的 Hook 分类经 21 组回归及独立审查后应用，原 `/private/tmp/sandiam-service-grant-update-quality-20260914.txt` 生成命令实际完成并读取。结果 0 FAIL、44266 REVIEW、6 个历史 `.staging` 目录 ERROR；当前调用回归 13 处分支和服务授权控制器 12 处分支提示已结合差异/故障测试审查。该结果覆盖本节及服务授权更新的当前源码，不表示全仓扫描完整通过。

- 实际 `revalidateInvocation` 验证授权停用、撤销时间、过期及凭证停用均拒绝，恢复记录后允许；上下文校验使用固定声明替身，不据此证明签名或完整接入链。
- 复现首次授权及重验入口在拒绝审计写后异常时遗留事务/部分审计；为 catch 内审计与提交补故障回滚，并在已有 operation 但未提交的 ApiException 路径回滚。已提交的拒绝记录保持提交。
- 新增 `service_invocation_revalidation_behavior_non_pg_test.php` 运行实际授权器、事实解析、网络与约束校验，故障后全量恢复，下一调用仍拒绝撤销授权。PHP 语法及差异检查通过；真实 PostgreSQL、并发和业务副作用未执行。全仓扫描的前轮 Hook 拒绝仍未解除，未换命令规避或宣称扫描通过。
- 合并候选独立 Astra 复跑 PASS、有界 ACCEPT；已有 operation 的审计抛 ApiException 分支仅源码审查，尚无行为覆盖。
- 同切片补齐上述行为缺口：新增真实首次授权成功、同 operation 重放复用、更换上下文/凭证冲突、撤销后重放拒绝及恢复；首次创建 operation 后审计抛普通异常或 ApiException 均完整回滚。两组离线回归通过，无新增生产代码；配额为空，未覆盖 PostgreSQL 配额争用或真实上下文验签。
- 补充候选独立 Astra 两组复跑 PASS、有界 ACCEPT；当前切片已完成离线故障恢复与重放证据收束。扫描仍保留既有 Hook 拒绝缺口。

#### 2026-09-14 P11 服务授权撤销审计原子性

- 同切片补齐更新：实际 update 审计写后故障曾保留配额/期限变更；启用父类现有 `atomicMutationAudit` 后全量回滚。创建、撤销/停用、更新三组回归及独立 Astra 复跑通过；三个不可变绑定字段仍为 409 且无写入。
- 本次全仓扫描命令因临时报告名含 `update` 被 Hook 判为 SQL write，原命令升级执行仍拒绝；未换命令绕过、未沿用旧扫描冒充本轮通过。改动仅控制器一项布尔配置及更新行为测试，已人工核对父类事务接入和实际差异。

- 原 revoke 在状态写入后单独审计，故障回归复现审计失败仍保留撤销状态。现将状态与审计纳入同一事务；disable 继续调用 revoke，权限校验、返回与审计动作保持原契约。
- 复用实际管理控制器和原创建测试夹具；创建回归及撤销/停用回归通过。新增审计写后失败全量回滚、重试撤销、审计组织/应用/资源绑定、403 无状态改变证据。
- 独立 Astra 两组复跑 PASS、有界 ACCEPT；PHP 语法及差异检查通过。扫描 0 FAIL、44265 REVIEW、6 个历史目录 ERROR；控制器累计 12 处分支提示已复核，本批仅新增事务异常回滚。输出 `/private/tmp/sandiam-service-grant-revoke-quality-20260914.txt`。
- 本批不证明 PostgreSQL 并发或真实机器服务调用撤权，也不解除数据分级清空的迁移缺口。未执行数据库、迁移、服务、FLOW、提交或部署。

#### 2026-09-14 P06 登出输入与撤销原子性

- 同切片补充：启用前/后通道后，通知写后、令牌写后、审计写后故障均全量回滚；恢复只生成一条待投递记录，重复登出不重入队，无有效记录且无 redirect 时返回 null。使用真实 Cipher 加解密及 RSA 验签核对通知客户端、会话、事件绑定，七组离线回归通过；无新增生产代码修改。
- 补充独立 Astra 七组复跑 PASS、有界 ACCEPT；差异检查通过。扫描 0 FAIL、44265 REVIEW、6 个历史目录 ERROR，本测试累计 35 处分支已结合故障断言复核；输出 `/private/tmp/sandiam-oauth-logout-notification-quality-20260914.txt`。
- 重复登出不补发前通道地址的现有行为已写入公开接入文档；不把通知入队当作真实 RP 收到。本补充未调用 HTTP 投递器或 PostgreSQL。

- 实际入口复现非法 state 在会话撤销后才拒绝；现前置校验，同时将有会话分支的成功审计纳入撤销事务，避免审计失败后留下已提交撤销。
- 六组离线回归及独立 Astra 复跑通过：非法 state 无副作用，令牌/审计写后故障回滚，重试成功撤销会话及刷新凭证，无关记录保留，原访问令牌经实际资源校验入口拒绝。
- 差异检查通过；扫描 0 FAIL、44265 REVIEW、6 ERROR，当前测试累计 33 处分支提示已按故障/拒绝用例人工复核，6 ERROR 仍为历史 `.staging` 目录。输出 `/private/tmp/sandiam-oauth-logout-quality-20260914.txt`，不宣称全仓扫描通过。
- 使用内存 ORM 与真实服务/JWT；通知启用时的投递记录、无会话分支、真实 PostgreSQL 并发及协议互操作未由本批证明。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P06 用户访问令牌实时身份与会话失效

- 已复现：旧 `verifiedAccessToken()` 用户分支只核对 grant 与 JWT 身份声明，身份停用后实际校验入口仍允许。新增同应用有效身份及 grant 绑定会话校验，会话必须属于同一身份、启用且未撤销；记录缺失或范围不符返回原 token-invalid 401。
- 不改变机器令牌分支，不把用户会话访问时限新增为 OAuth 令牌期限；令牌自身期限继续按原验证。公开资源接入契约同步说明。
- 现有实际 OAuth 入口回归扩展为五组并通过；新增 6 项身份/会话状态与归属变化拒绝及恢复，2 项记录缺失拒绝。旧代码在身份停用用例失败，修复后通过，机器签发与资源验证回归保持通过。
- 独立 Astra 五组复跑 PASS、有界 ACCEPT；差异检查通过。扫描 0 FAIL、44265 REVIEW、6 ERROR，本测试 30 处分支提示已结合故障与拒绝路径人工及独立复核，ERROR 仍为历史 `.staging` 目录，不宣称全仓通过。输出 `/private/tmp/sandiam-oauth-live-user-context-quality-20260914.txt`。
- 当前为内存 ORM 与真实 JWT/服务代码证据，不证明 PostgreSQL 并发或真实资源 HTTP，也不保证校验后的并发撤销与业务操作原子。未执行 FLOW、数据库、服务、提交或部署。

#### 2026-09-14 P06 机器令牌签发、资源校验与撤销接续

- 在既有实际 OAuth 入口回归中接入生产 `verifyAccessTokenForAudience()`，使用实际签发的 JWT 完成允许 → 实际 `revoke()` → 原令牌校验 401；签名、JWK 生成和 JWT 校验均由生产服务执行，未用返回预定 claims 的验证替身。
- 主控四组回归通过，新增 8 类令牌/授权/客户端/应用/组织实时状态失效与恢复；错误受众 401、缺少 scope 403、保留旧签名篡改合法 JSON claims 时 401。内存查询夹具仅补充 `whereNull`、`whereIn`。
- 独立 Astra 四组复跑 PASS、有界 ACCEPT，差异检查通过；扫描 0 FAIL、44265 REVIEW、6 ERROR，本测试 28 处分支提示已结合故障/拒绝路径人工及独立复核；ERROR 仍来自历史 `.staging` 目录，不宣称全仓通过。输出 `/private/tmp/sandiam-oauth-resource-verification-quality-20260914.txt`。
- 本批生产代码未改；证明的是实际服务入口通过内存持久化的接续，不能替代真实 PostgreSQL、HTTP、中间件或非 AI 业务副作用和双侧审计，也未覆盖密钥轮换。未执行 FLOW、数据库、服务、提交或部署。

#### 2026-09-14 P06 授权码交换成功审计原子性

- 修复 `exchangeAuthorizationCode()` 成功审计在提交后执行的问题，将其移入授权码消费与令牌签发的原事务，不改变回调地址、PKCE、客户端或有效会话检查。
- 扩展实际 `token()` 入口回归，旧代码在审计故障后遗留授权码消费/令牌状态而失败；修复后四组通过。授权码场景覆盖错误 PKCE/回调/未知码、8 项应用客户端与过期/失效会话身份边界、令牌/审计故障回滚、原请求恢复后仅创建一个授权与三种令牌、已消费码重放零新增状态。
- ID token 使用进程内 RSA 公钥验签，并核对 nonce、client audience 和 session 标识；不加载宿主或使用实际密钥。独立 Astra 四组复跑 PASS、有界 ACCEPT，差异检查通过；扫描 0 FAIL、44265 REVIEW、6 ERROR，本测试 23 处分支提示已结合故障/拒绝路径人工及独立复核，ERROR 仍来自历史 `.staging` 目录，不宣称全仓通过。输出 `/private/tmp/sandiam-oauth-code-exchange-quality-20260914.txt`。
- 真实客户端、PostgreSQL 并发及响应丢失恢复仍未验证，未执行 FLOW、数据库、服务、提交或部署。

#### 2026-09-14 P06 OAuth 接入恢复契约同步

- 将已实现的刷新、机器签发与撤销事务边界同步至公开 OAuth/OIDC 契约；明确撤销已知 token 作用于整条 grant 及关联令牌，不是只删单个 token。
- 补充刷新超时排障：事务回滚后的重试与已提交响应丢失不同，当前没有请求号恢复已提交响应，旧 refresh 重放仍撤销整条授权。机器签发也不能用事务原子性推导成功请求幂等。
- 本批仅修改公开文档与任务板，差异检查通过；不增加功能或发布计分，未执行 FLOW、数据库、服务、提交或部署。

#### 2026-09-14 P06 OAuth 刷新失败恢复与锁顺序

- 修复刷新成功审计在提交后执行的缺陷：旧令牌消费、新令牌创建和成功审计同事务完成。成功审计故障不再留下已经消费的旧令牌，故障解除后原刷新请求可重新处理。
- 刷新由令牌 → 授权锁序改为先根据令牌定位授权、锁授权，再按原令牌 ID/授权/hash/应用/客户端/类型重新读取并锁令牌，统一与撤销的授权 → 令牌顺序；使用重读后的状态判定，保留真实重放时提交整组撤销及拒绝审计。
- 扩展实际 OAuth 入口回归，旧代码在成功审计故障后留下状态而失败，修复后三组通过。新增覆盖签发/审计故障回滚、恢复后仅一对替换令牌、明确锁顺序、消费后再次重放 401 及整组令牌撤销。
- 独立 Astra 三组复跑 PASS、有界 ACCEPT；PHP 语法及差异检查通过。扫描 0 FAIL、44265 REVIEW、6 ERROR，本测试 19 处分支提示已结合故障/拒绝路径人工和独立复核；ERROR 仍为历史 `.staging` 目录读取错误，不宣称全仓通过。输出 `/private/tmp/sandiam-oauth-refresh-quality-20260914.txt`。
- 未证明 PostgreSQL 并发、锁等待、定位至锁定间绑定变化或客户端丢失成功响应的幂等恢复；重放拒绝仍会撤销授权，不能将事务失败恢复解释成成功请求可任意重放。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P06 OAuth 机器令牌签发校验与原子性

- 修复 `client_credentials` 在受众校验前创建授权的问题，将受众校验前置；授权创建、JWT 生成、令牌记录与成功审计放入同一事务。失败不留下授权或令牌半成品，不改变客户端类型、允许 scope 或受众规则。
- 扩展 `oauth_revoke_behavior_non_pg_test.php`，通过实际 `token()` 入口复现旧代码错误受众遗留授权；修复后两组回归通过。覆盖非法受众/用途无副作用、令牌及审计写后故障回滚、恢复仅创建一组记录；用进程内临时 RSA 公钥验证响应 JWT 签名、客户端主体/应用/受众，并与持久化令牌指纹对应；实际撤销入口能撤销新签发的令牌和授权。
- 独立 Astra 两组复跑 PASS、有界 ACCEPT；差异检查通过。扫描为 0 FAIL、44265 REVIEW、6 ERROR，本测试 15 处分支提示已结合故障与拒绝路径人工及独立复核；历史 `.staging` 目录仍导致扫描 ERROR，不宣称全仓通过。输出 `/private/tmp/sandiam-oauth-machine-issuance-quality-20260914.txt`。
- 未向机器客户端添加 refresh/id token；本批不提供成功响应丢失后的请求幂等恢复，也不证明真实 PostgreSQL、资源服务器或 HTTP 接入；未注入签名或 commit 故障。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P06 OAuth 令牌撤销原子性

- 修复 `OAuthOidcService::revoke()`：已知且属于当前客户端/应用的令牌，其授权撤销、关联令牌撤销及成功审计纳入同一事务；任一步失败回滚。客户端认证仍在事务外，未知/空值/其他客户端令牌保持原有成功及 `known_token=false` 语义。
- 新增 `oauth_revoke_behavior_non_pg_test.php`，直接调用生产服务，内存 ORM/事务/审计替身及进程内临时 RSA 夹具不读取宿主密钥。旧代码在令牌写后故障留下部分状态，测试失败；修复后通过令牌/审计故障回滚、成功重试整组撤销、无关授权不变、重复请求行为、未知令牌及无效/停用客户端边界。
- 独立 Astra 复跑 PASS、有界 ACCEPT；PHP 语法及差异检查通过。扫描为 0 FAIL、44265 REVIEW、6 ERROR，本批新测试 12 处分支提示已结合故障与拒绝路径人工及独立复核；6 个 ERROR 仍来自历史 `.staging` 目录，输出 `/private/tmp/sandiam-oauth-revoke-quality-20260914.txt`，不宣称全仓通过。
- 本批不证明真实 PostgreSQL 回滚、并发刷新或资源服务器即时拒绝，也不计整个 P06 通过。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P07 / P08 成员移除后的实际授权回归

- 扩展 `policy_authorizer_behavior_non_pg_test.php`：保留已有直接身份场景，将空角色夹具替换为执行实际关联条件、过滤及投影的内存关系夹具，直接调用生产 `PolicyAuthorizer`，不预计算授权结论。
- 主控两组回归通过。12 项成员/组/组角色/角色的状态、应用或关联字段变更均使同一个授权器实例拒绝，恢复关系后允许；拒绝审计不残留已撤销的角色来源。直接角色和组角色并存时审计保留两种来源，移除组成员后仍保留独立直接授权，全部来源撤销后拒绝。
- 同步排障说明：移除单个成员关系不能替代撤销其他独立授权，应按实际命中策略和审计来源判断。独立 Astra 两组复跑 PASS、有界 ACCEPT；质量扫描 0 FAIL、44264 REVIEW、6 ERROR，本次测试无命中，ERROR 仍为历史 `.staging` 目录读取错误；输出 `/private/tmp/sandiam-group-authorization-quality-20260914.txt`，不宣称全仓通过。
- 生产授权器本批未改；通过变更内存关系模拟撤销，没有串联实际 `removeMember()` 写入，不证明 PostgreSQL JOIN、并发或真实 HTTP 链路。未执行 FLOW 或外部状态变更。

#### 2026-09-14 P07 用户组成员增删锁顺序修复

- 已修改 `IdentityGroupService::removeMember()`：先锁当前应用的用户组，再锁身份，最后锁有效成员记录，与 `addMember()` 一致。原顺序为成员记录 → 身份，存在加入方持身份等待成员、移除方持成员等待身份的反向等待路径。
- 保留停用用户组/身份仍可移除成员，以及无有效成员返回 404 的语义；成员状态、身份变更事件和审计仍在原事务中，不改变 API 参数或新增表。
- 新增 `identity_group_membership_behavior_non_pg_test.php`，旧源码因锁顺序不一致失败，修复后通过；覆盖实际服务的相同行锁顺序、事件/审计写后故障回滚、跨应用拒绝、停用对象移除和恢复复用成员。独立 Astra 复跑 PASS、有界 ACCEPT，PHP 语法检查通过。
- 质量扫描为 0 FAIL、44264 REVIEW、6 ERROR；本批仅新测试的 14 处分支累计提示，已结合故障注入和拒绝路径人工及独立复核，无必修项。6 个 ERROR 仍为历史 `.staging` 目录读取错误，不宣称全仓通过；输出 `/private/tmp/sandiam-group-membership-lock-quality-20260914.txt`。
- 内存锁记录不证明真实 PostgreSQL 并发或全局无死锁；事件/审计为替身，成员撤销后实际授权查询仍需单独证据。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P15 RADIUS 登录重试公开说明

- 将已验证的 Access 入口行为同步到协议接入说明与用户排障指南：原响应不会缓存重发，指纹登记发生在密码验证之前，随后发生后端异常也不解除同报文的重放限制。
- 明确超时不能单独判断为密码错误；区分新请求在故障解除后恢复与相同已登记报文可重试，标注真实 NAS 丢包/重传兼容性仍未完成。Accounting 的重复响应不再可能被误读为 Access 登录同样具备。
- 本批仅修改公开文档与原任务板；不把当前实现限制改写成已完成互操作，也不增加 P15 或发布计分。未执行数据库、服务、FLOW、提交或部署。

#### 2026-09-14 P15 RADIUS Access 实际入口行为回归

- 新增 `plugin/sand-iam/tests/radius_access_behavior_non_pg_test.php`，直接调用权威源码 `RadiusAccessService::handle()`、`RadiusPacketCodec` 和 `RadiusNetwork`；ORM、共享秘密解密及密码服务为内存替身，不加载宿主或实际 `HumanAuthService`。
- 主控离线回归通过：有效请求的应用、用户名、解密后密码、来源 IP、请求号及协议上下文传给密码服务；接受与拒绝响应均复算 Response Authenticator 和 Message-Authenticator；相同报文重放不重复验证密码。
- 非法用户名、错误密码、篡改/截断/错误 Code 报文、未知/重叠 NAS、关闭服务及停用应用均按当前入口拒绝或静默丢弃；无效报文不改变重放存储。重放存储异常不调用密码验证，故障解除后同一请求可成功；密码服务异常不产生接受响应，新请求在故障解除后恢复。
- 独立 Astra 复跑通过并给出有界 ACCEPT；PHP 语法及差异空白检查通过。质量扫描为 0 FAIL、44263 REVIEW、6 ERROR，本次新测试无命中；6 个 ERROR 均为历史 `.staging` 中代码扩展名目录的读取错误，不能宣称全仓扫描通过，输出为 `/private/tmp/sandiam-radius-access-behavior-quality-20260914.txt`。
- 本批未发现需要修改该入口的生产缺陷；不将内存替身视为真实密码校验、PostgreSQL 唯一约束/并发或 UDP 对端互操作证据，不计整个 P15 完成。密码后端异常后的恢复使用新报文，不证明原报文可重试。

#### 2026-09-14 P11 / C04 数据分级清空：结构缺口已定位，尚未修复

- 原被拒的机器身份字段/控制器组合读取已正常执行；`data_class` 原查询也执行到 `rg`，其中两个不存在的路径返回文件错误，不再误判 SQL 写入。
- 当前源码证据：`ServiceGrantConstraintNormalizer::dataClass()` 将 `null` / 空字符串规范为 `null`，`ServiceGrantController` 原样保存；`install.sql` 的服务授权列却是 `varchar(32) NOT NULL DEFAULT 'internal'`，后续没有解除该列的非空约束。调用操作表的同名可空列不能作为授权表已兼容的证据。
- 现有 `service_grant_invocation_control_non_pg_test.php` 本轮仍通过；它覆盖规范化与调用约束等离线检查，没有覆盖授权表接收空值，不能排除上述结构冲突。
- 修复方向经独立 Astra 有界判断：保留默认值和历史数据、保持已发布迁移不可变，新增后续迁移解除授权列的非空约束，并完整接入新版本安装、升级准入、账本和生成物。当前 `0.7.1` 升级载荷只接纳至 037 的账本并执行 038，不能仅追加安装迁移列表就称升级已修复。
- 尚未修改迁移或数据库：登记 039 源文件编辑范围的 `amend` 被 Hook 的关键目录确认规则拦截，正式升级重试结果相同；不是实际执行迁移失败。此项保留为已证实的源码缺陷，不计需求完成。
- 本次恢复核查确认生成器仍显式列到 038，并将 0.7.1 升级输入固定为 038。针对两个 `039_service_grant_nullable_data_class.pgsql` 权威/包内源文件的范围登记再次被关键目录确认拦截（普通调用及正式升级均相同，操作 `63945866494917304ac61ba0`）；无源码迁移或数据库写入。后续组合只读核查也被拦截（`6e70871fec958f895095f518`），不据此声称升级准入已完成核对。以上属于关键目录审批，与已修复的 SQL 搜索误判不同；仅阻塞本项，其他原需求继续。
- 同轮发现服务授权撤销先保存再审计，需要进一步验证审计失败时的原子性；对应通配符源码/测试检索仍被 Hook 拒绝，未绕过读取。该局部限制不代表全部需求阻塞。

#### 2026-09-14 当前功能进度口径校正

沿用既有 P01–P20 的 20 个模块范围，不修改历史验收账本，不新增 FLOW 队列。下表是当前缺口索引，不将历史通过记录自动变成本轮已验证。

2026-09-15 已完成新的当前源码对账，见本看板“本次 Goal 功能实现对账”。以下保留为当时的核查记录，不再用“未核准”或历史分子回答当前源码功能完成数。

| 原模块 | 当前实现判断 | 未完成或证据限制 |
| --- | --- | --- |
| P14 目录同步 | 已知保护设置入口缺口已补齐 | 四项编辑已有权威源码、SFC 行为和类型检查证据；真实浏览器及目录对端验收仍未完成。 |
| P18 开发者生态 | 已知 SDK 封装缺口已补齐，完整性待核 | TS/Dart Passkey 及 Captcha 配置、密码/MFA 升级验证、联合身份解绑均已落地；本批后四项合计 8/8 通过离线验证，PHP 对应已有。不据此将整个开发者生态计为完整通过。 |
| P02 组织/应用/环境 | 环境写入行为已补验证，模块完整性待核 | 已修复环境空名称更新，生命周期离线测试覆盖创建、改名、停用/恢复、冲突和应用范围拒绝及审计；不能外推为组织、应用或真实宿主全部通过。 |
| P10 机器身份上下文 | 当前完整性待核 | 字段/控制器原组合读取已恢复；尚未完成全部机器上下文和撤销行为核验，不能据读取成功计模块通过。 |
| P11 机器调用约束 | 已证实结构缺陷，未修复 | 接口清空为 `null`，授权表仍为 `NOT NULL`；新增后续迁移及安装升级接入待完成，源码范围登记当前被关键目录确认规则拦截。 |
| P07 / P08 角色、策略与数据范围（2 项） | 发布/撤销审计原子性及组角色、成员锁顺序已修复 | 实际授权器直接身份、组角色、多来源撤销/恢复已有离线证据；成员服务与授权器尚未串成真实数据库流程，不计两个完整模块通过。 |
| P09 接口与路由治理 | 路由同步批量失败回滚已补行为证据 | 实际同步器的批次异常恢复通过内存事务回归；真实路由、中间件与业务调用仍需覆盖，不能用回滚测试代替整个模块。 |
| P12 联合身份 | 解绑及 OAuth2/OIDC/SAML 回调审计原子性已修复 | 实际入口故障回滚与本地恢复已有离线证据；SAML 验证器为替身，真实身份源、签名互操作及数据库竞争未验证。 |
| P13 应用体验与认证消息 | 保留上下文覆盖错误已修复，公开驱动契约已同步 | 应用、用途、模板及测试标记不能被调用上下文覆盖；真实 Email/SMS/Captcha 供应商、投递回执及页面体验仍未验证。 |
| P15 协议互操作 | RADIUS Access/Accounting 实际入口已有离线证据 | 不是完整协议模块通过；Access 相同报文不重发响应，真实 NAS 重传兼容性仍待验证，其他标准客户端/Realm/登出链也未据此关闭。 |
| P17 Webhook 与安全运营 | 重复事件入队、投递租约及过期结果保护已修复 | 保留策略写入回滚已有行为证据；实际接收器、PostgreSQL 并发、归档恢复和完整运营链未验证。 |
| P19 初始化包 | 原定义已针对性复核，草稿修订号校验已修复 | 实际服务应用/回放/漂移/回滚及草稿入口有离线证据；真实 PostgreSQL 和浏览器流程仍未完成。 |
| P20 自助门户 | 资料校验、会话检查与更新结果一致性已修复 | 实际资料/安全概览服务已有离线证据；完整登录用户旅程、真实会话撤销和多视口平台验证仍未完成。 |
| P06 OAuth/OIDC | 已修复签发/撤销/登出审计原子性、登出输入校验顺序、刷新锁序及用户令牌实时身份会话检查 | 实际入口已有七组离线证据，含登出通知入队回滚及重放；真实资源服务器、数据库并发及完整客户端接入未据此关闭。 |
| P01、P03–P05、P16（5 项） | 保留历史及已记录的局部证据，当前完整性待核 | 未作当前全量核验；不据历史计分声称双平面、本地认证、MFA、LDAP/SCIM 或管理委派全部需求完成。 |

按“模块内原需求全部闭合才算一个完整模块”的口径，P14 保护设置入口和 P18 已知封装缺口均已补齐，但各模块尚未逐一完成当前完整性核验，因此当前准确需求完成率仍未核准。不继续沿用此前模块缺口推算的上限，也不推算新的完成百分比。旧 28/48＝58.3% 仅为历史综合验收基线，旧模块 20/20 不能继续用作当前功能完成结论。

2026-09-14 索引归位：上表仍覆盖原 20 个模块，每个编号仅出现一次；拆出最近已经执行的模块，纠正原先将 15 项一并列为未重验的过时描述，不改变验收账本分子。下一实现核查优先回到本地登录授权和 OAuth/OIDC 接入的公开入口；P11 已知结构缺陷与受限命令保留，未获批准不重试，也不再仅为已通过的 RADIUS 局部切片重复扩建测试。

#### 2026-09-14 机器服务中文接入说明

- 将 Provider B 完整示例说明补为中文，明确业务处理、验权、幂等和审计行为；纠正上级入口将整个目录描述为“没有目标服务”的歧义，区分基础脚本与完整 provider/caller。已对照业务处理源码，文档 diff 检查通过，不新增运行验收结论。
- 三类管理页（机器身份、凭证、服务授权）及公共操作组件有界检查未发现确定新缺陷；后续字段与控制器契约组合只读命令被 SQL Hook 拒绝，原命令升级审批仍拒绝，后端对照未完成。另示例配置模板组合读取被敏感材料 Hook 拒绝，原命令升级仍拒绝；本批仅依据已读文档与业务源码澄清说明，未改配置或绕过读取。

#### 2026-09-14 应用业务动作待认领报告隔离修复

- 修复切换应用后保留上一应用报告及迟到响应覆盖的问题；按应用、请求版本、读取权限与页面生命周期失效，校验返回的 `application_id`，支持失败重试。
- 新增真实 SFC 行为测试，旧实现已复现切换应用仍保留报告（1 !== 0），修复后通过；主控独立检查后端契约并复跑本页测试及五页应用候选回归，均通过。子 Agent scoped vue-tsc 通过。
- 仅权威前端源码与离线验证，未执行 FLOW、浏览器、数据库、同步或部署；不计全需求完成。

#### 2026-09-14 当前实现离线汇总回归

- 对当前权威管理端 `*behavior.test.cjs` 执行汇总回归，27/27 文件通过；排除被拒读取的 `sync-connector` 和需额外运行条件的 `developer-docs` 示例，未借测试读取被拒源码。
- 当前 PHP、TypeScript SDK 全套测试，以及非 AI 独立业务示例、机器 HTTP 调用示例的既有离线测试通过。未修改的 Dart 36/36 历史回归不伪称本次重新执行。
- 核查初始化组织数据仅用于记录名称回显，不是组织选择入口，因此不据“首 100 条”推导不存在的操作缺口。本批没有新增产品功能。
- 后续实现仍需处理已记录的同步保护设置前端及两项 TS/Dart SDK 待批准写入。上述回归是源码/离线证据，不能替代真实环境、完整需求逐项核验或发布结论；未执行 FLOW、HTTP、数据库、服务或同步。

#### 2026-09-14 用户、组、业务动作、联合身份与审计的范围选择

- 继续修复固定前 100 条候选限制，覆盖应用用户、用户组、业务动作历史认领、联合身份应用挂载及审计五页；搜索保留当前选中对象和业务草稿，并提供错误重试、迟到响应和卸载保护。
- 联合身份初始身份源请求失败不阻断应用候选，应用搜索不清空敏感配置；审计仅在有组织列表权限时请求组织目录，否则继续从可见应用派生组织。未更改服务端授权范围。
- 新增合批实际 SFC 回归 5/5，主控复跑通过，覆盖第 101 个候选及五页实际业务函数的应用 ID、原选择/草稿保留、错序失败、重试和卸载；原页面/ResourceList 回归通过，strict scoped vue-tsc 通过，主控核实五个类型检查副本与权威源码一致。
- 连同此前邀请、导入、策略模拟、初始化四页，已修复九页的应用候选入口；此计数仅描述本项功能，不代表全部需求或页面验收完成。未执行 FLOW、真实浏览器、HTTP、数据库、服务或同步；待批准 SDK 和同步读取未重试。

#### 2026-09-14 C03 PHP SDK 验证码配置与敏感操作

- PHP 新增 `captchaConfiguration`、`stepUpPassword`、`startMfaStepUp`、`unlinkFederation`，接入既有四接口。Captcha 使用匿名 GET 查询固定客户端组织、应用和 login/register 用途；其余操作需要当前用户会话，不自动验证或重试解绑。
- 回归覆盖 Captcha 三种配置分支、两种用途、URL 前缀/查询、无 GET 请求体、无匿名认证头，以及升级/解绑的会话、字段、请求号、非法参数和服务端拒绝。PHP 全套 SDK 测试、lint、diff-check 通过；独立 Astra 源码审查未见必修问题，README 同步。
- 本批只完成 PHP SDK；TS/Dart 对应调用仍待补，既有 Passkey 待批准操作未绕过。未执行真实挑战/设备、HTTP、数据库或 FLOW。
- 接续核对真实公开 widget 为 `kind/site_key/action/application_binding`，已纠正 PHP 离线夹具字段；PHP 全套回归及后端 Captcha 配置 13 项、公开投影 9 项通过。独立实施 TS/Dart 四项调用的具体写入同样被认证关键区域 Hook 拒绝，原命令正式 `require_escalated` 仍拒绝，新增批准编号 `77231487b9afa04411870f44`；TS/Dart 未落地。这与 Passkey 的 `26b2ae9ad85188b36030cb4c` 为两个独立待批准操作，均未通过改写命令或换工具执行。

#### 2026-09-14 跨邀请、导入、策略模拟与初始化的应用选择

- 有界核对四页确认共同缺口：应用候选固定前 100 条且仅本地搜索，后续应用无法选择。四页现已使用既有应用 API 的 `keywords` 远程搜索，保留选中应用和业务草稿，补加载、错误重试、迟到响应及卸载保护。
- 新增跨四页实际 SFC 回归 4/4 通过，主控复跑；覆盖第 101 个应用检索和选中，以及邀请发送、导入预检 FormData、策略模拟、初始化导出实际函数绑定正确应用。四页原回归通过，strict scoped vue-tsc 通过，主控核实四个类型检查副本与权威源码逐字一致。
- 后端搜索仍在既有应用授权范围内，未新增 API 或扩大权限；应用范围契约回归通过。本批未执行真实浏览器、HTTP、数据库或 FLOW。
- 本次有界核对不构成全需求完成结论。已知仍待补：同步保护设置前端入口、TS/Dart Passkey 封装；Captcha 配置读取、密码/MFA 升级验证发起及身份源解绑的 PHP 封装已由上方批次补齐，TS/Dart 仍待补。

#### 2026-09-14 C06 同步连接保护设置（进行中）

- 确认后端已有更新连接名称、冲突策略、缺失保护时长和停用阈值的接口，但页面没有对应编辑入口；前端入口尚未实现。
- 已修改后端更新路径：事务内按连接 ID 锁定记录并核对应用权限，保护设置保存与审计同时提交，异常回滚。保持既有字段范围、错误码和其他连接操作不变。
- 真实 Controller 加内存持久层的离线回归通过，覆盖审计失败回滚、四种冲突策略、上限、非法范围、不可修改应用/系统代码、无权及不存在；PHP lint 与既有同步契约测试通过。未验证真实 PostgreSQL 或并发。
- 前端 Agent 的两条 `sed` 加一条 `rg` 源码读取被 Hook 误判为 `SQL write without safe WHERE clause`，原命令通过 `require_escalated` 正式提交仍拒绝，未拆分或换工具读取，前端测试/类型检查及本批独立复核未完成。此为局部读取阻断，未扩大为整体项目阻塞；未执行 FLOW、数据库、服务或同步。

#### 2026-09-14 C07 Webhook 失败投递管理

- 解除通知名称查询对投递列表的强依赖：名称权限缺失或查询失败不阻断投递查看；最多读取 100 个名称作可选回显，未知通知显示编号，不误称已删除。应用支持远程名称搜索，投递支持现有四种状态筛选。
- 详情展示接收端 HTTP 状态，关闭、切换记录/应用、撤权及卸载清理载荷并拒绝旧响应；重试仅接受当前记录且状态为等待/最终失败，保持写入互斥。明确提示重新排队不等于送达，排队成功但列表刷新失败单独反馈。
- 主控复跑真实 SFC 离线回归通过，覆盖名称 403/无权、分页、远程搜索错序、状态筛选、重试拒绝/失败恢复、排队后刷新失败和详情生命周期。类型检查副本与当前源码一致，实现侧 strict vue-tsc 通过；后端既有委派/Webhook/审计及加密传输离线回归通过，本批未改后端。
- 管理员指南同步查看与恢复步骤，并移除不存在的独立通知密钥撤销操作说明。真实 HTTPS 接收端、浏览器和数据库未验证；未执行 FLOW、服务、同步或部署。

#### 2026-09-14 C07 安全告警筛选与处理

- 页面补齐后端已有的状态、等级、规则代码精确筛选，以及组织/应用远程名称搜索；组织切换清除应用，分页、权限变化和卸载使旧请求失效。处理只接受当前可见行并保持写入互斥；处理成功但刷新失败时单独说明已保存状态。
- 后端处理改为事务内按告警 ID 锁定当前行，核对范围与状态，再保存处理信息及审计；异常回滚，重复处理仍返回 409，读取接口不加锁。
- 新实际 Controller/内存存储回归先复现“审计失败但告警已处理”，修复后通过，另覆盖权限拒绝、重复处理、不存在及请求号。主控复跑实际 SFC 测试通过，类型检查副本与当前源码逐字一致，实现侧 strict vue-tsc 通过；独立 Astra 后端源码/离线复核通过。
- 本批完成源码与离线验证。真实 PostgreSQL 事务/并发及浏览器角色操作未验证；未执行 FLOW、数据库、服务、同步或部署。此前 TS/Dart Passkey 写入的待批准操作未重试或绕过。

#### 2026-09-14 C03 SDK Passkey 注册与认证（进行中）

- PHP 已新增注册 options/finish、认证 options/finish 四方法。注册要求会话，认证匿名且固定客户端应用；保留平台序列化响应和请求号，注册完成返回空值，认证返回认证结果。认证响应要求 `userHandle`，与服务端无账号选择登录契约一致。
- PHP 全套 SDK 离线测试、语法及 diff 检查通过；覆盖四调用、应用/认证边界、必填字段、错误传播及无自动重试。PHP README 已补平台 API、Base64URL 和结果不确定时的处理说明；不同上下文源码核对与服务一致。
- TS/Dart 首次源码写入被 Hook 要求认证关键区域确认，操作 `26b2ae9ad85188b36030cb4c`；原命令逐字不变通过 `require_escalated` 正式审批后仍被同一 Hook 拒绝，尚未落地此四项能力，等待该具体操作获批，不换工具绕过。该限制不代表业务依赖未满足，不能宣称三语言完成。
- 本批未执行 FLOW、真实设备、HTTP、数据库、服务或同步。

#### 2026-09-14 C03 三语言 SDK MFA 自助管理

- PHP、TypeScript、Dart 补齐 `mfaFactors`、`startTotp`、`confirmTotp`、`renameMfaFactor`、`revokeMfaFactor`、`regenerateRecoveryCodes`，使用当前应用用户会话；设备操作同时传编号与 TOTP/Passkey 类型。
- 开始绑定使用 `current_password`，撤销和更新恢复码使用 `password`，按实际 Controller/Service 契约调用。保留绑定秘密、确认及恢复码结果；明确无秘密的幂等重放返回 `secret_available=false`，不伪造秘密、不自动重试。三个 README 已同步。
- 主控复跑 PHP、TypeScript 全套 SDK 测试及 Dart 全套 36/36 通过；TS 发布目录重新构建、Dart analyze 通过，PHP lint 和 diff-check 通过。主控与实现 Agent 交叉核对源码；测试覆盖认证头、请求字段、请求号、六项调用、非法输入、错误和无秘密重放。
- 完成范围为 SDK 接入能力与离线验证，尚未完成真实 TOTP 设备、HTTP、数据库和密码验证联调；未执行 FLOW、服务、同步或部署。Passkey 注册/认证等其余 SDK 接入仍需继续核对补齐。

#### 2026-09-14 联合身份源预设接入

- 联合身份配置页已接入预设目录，GitHub、Google、Entra 可填写非秘密参数并生成配置及属性映射；补充 Scope 字段，保留预设声明的授权范围。其余目录项明确显示需人工配置，协议不匹配不能生成。
- 草稿不自动保存或挂载；覆盖已有配置先确认并清除已填密钥，密钥不提交到预设接口。支持失败重试，切源、改参、权限变化与卸载后拒绝迟到结果。
- 主控复跑真实 SFC 行为测试及实际 PHP 目录生成的三个草稿映射测试通过；三个草稿另通过实际 FederationService 配置/映射纯校验器。实现侧 strict vue-tsc 通过，主控源码复核；配置说明已同步。
- 本批完成源码接入与离线验证。真实浏览器和厂商登录互操作未执行，不计成品验收；未执行 FLOW、数据库、服务、同步或部署。

#### 2026-09-14 C07 Webhook 配置管理完整切片

- 配置页补齐分页及总数、远程应用名称搜索，支持访问第 21 条通知和检索第 101 个应用；编辑固定原通知与所属应用，不使用新筛选值改写归属。
- 创建、编辑、轮换和停用均增加内部权限、当前记录与并发锁检查，确认后再次核对上下文；应用、页码、权限变化使旧列表/操作结果失效。保存期间的新草稿不会被旧成功响应清空。
- 一次性密钥保留创建/轮换时的原通知归属，切换范围后的迟到结果仍可保存；未确认保存前禁止新创建/轮换覆盖。弹窗关闭明确确认，取消保留密钥，卸载清理内存。
- 主控实跑真实 SFC/parser 离线行为测试与 SandIAM scoped strict `vue-tsc` 均通过，并确认类型检查副本与当前页面源码一致。后端 Webhook/委派/审计及事件目录源码契约检查通过，未修改后端或事件目录。
- 本批是功能实现与离线验证完成，不是目标平台成品验收；真实浏览器、HTTP 投递与接收服务尚未联调。未执行 FLOW、数据库、同步或部署。

#### 2026-09-14 C03 验证码入口内部标记隔离

- 普通验证码申请入口此前允许请求体携带 `_password_reset_endpoint=true`，使服务接受本应走专用找回入口的密码重置用途。控制器现强制覆盖为 `false`；专用 `forgotPassword` 继续固定密码重置用途和 `true`。
- 新增真实 Controller、捕获式服务替身的离线入参回归，先复现失败再修复通过；覆盖三种伪造标记、真实 IP 覆盖、正常手机验证和专用恢复入口。PHP lint、diff-check 通过，独立 Astra 源码/离线 ACCEPT。真实服务的用途拒绝分支经过源码核对，未动态执行服务、HTTP、数据库或 FLOW。
- `data_class` 安装/升级结构核对仍未完成：本轮对原只读 `rg` 命令使用正式升级请求后仍被 Hook 误拒为 SQL 写入；该局部缺口不阻断其他需求实现。

#### 2026-09-14 C04 SDK 默认传输禁止重定向

- TypeScript 的运行端/管理端 Fetch 均设置 `redirect: 'error'`；Dart 两个默认 HTTP 传输均设置 `followRedirects = false`。文档说明须配置最终端点，自定义传输不能自行转发凭证。
- TypeScript 全套 SDK 测试及构建通过；Dart 全套 33 项与静态分析通过，主控另复跑默认传输 15 项。Dart 使用 `runWithClient` 截获真实默认 HTTP Request，覆盖会话/机器/管理员三类凭证与五类 3xx；TS 捕获两客户端 RequestInit 并验证拒绝，不连接网络。
- 交叉源码检查未发现必修问题。PHP SDK 现有 cURL 未开启自动重定向，本批未改其传输。真实客户端与重定向对端联调未执行，不计真实链完成；未执行 FLOW。

#### 2026-09-14 C04 机器示例凭证请求重定向

- `MachineServiceHttpClient` 显式禁用 PHP 流自动重定向，避免携带凭证的请求被转发；3xx 响应保持拒绝。URL 校验拒绝用户信息、查询、片段、空主机、空白及反斜杠，修正 IPv6 本机地址判断。README 已说明须配置最终端点。
- 新增进程内流包装器离线测试，实际调用 `postJson` 捕获重定向选项、请求头/请求体和超时，未打开网络；覆盖合法/非法 URL 与五类 3xx 拒绝。新测试、开发者 quickstart 回归、PHP lint、diff-check 通过；独立 Astra 源码/离线复核 ACCEPT。
- 未进行真实 HTTPS/重定向服务器联调，不计真实机器业务链完成；未执行 FLOW、数据库、服务或部署操作。

#### 2026-09-14 C05 非 AI 工单示例编号边界

- 独立工单应用此前直接把路由数字转换为 PHP 整数，超出整数范围会截为另一编号。已在读取和关闭工单前校验正整数范围，越界返回 `400 invalid_item_id`，不读取或修改工单；合法最大整数编号保持精确。
- 新增真实 Controller 与内存 Repository 的离线回归，先复现失败，再修复通过；覆盖 GET/POST 越界、无存储访问、拒绝审计及合法边界。既有授权拒绝、撤权、请求体范围伪造与允许关闭回归均通过；PHP lint、diff-check 通过。
- 未新增示例登录体系：独立示例仍消费业务应用取得的访问令牌并对实际加载对象授权。真实 PostgreSQL、HTTP、副作用及双侧审计联调未完成，不计 C05/L04 通过；未执行 FLOW。

#### 2026-09-14 C03 三语言 SDK 注册验证

- PHP、TypeScript、Dart 均新增 `requestVerification` / `confirmVerification`，支持邮箱、手机验证码申请与确认；验证用途由合法渠道推导，不开放密码重置用途。请求固定客户端应用且匿名，结果不自动签发会话。
- 主控核对请求实现并复跑 PHP、TypeScript SDK 全套测试及 Dart 全套 18 项测试通过；实现侧 TypeScript 构建、Dart 静态分析通过。回归覆盖两渠道、用途隔离、请求号、无会话认证、错误传播与非法输入不请求。README 已同步，交叉源码检查未发现必修问题。
- SDK 已补齐本次发现的注册验证、验证码参数、MFA 响应及提交、密码恢复调用缺口。该结论仅限源码接入能力；真实消息、设备和 HTTP 联调仍未完成，不计 C03 真实业务链通过，未执行 FLOW。

#### 2026-09-14 C03 三语言 SDK 密码找回与重置

- PHP、TypeScript、Dart 均新增 `forgotPassword` / `resetPassword`，接入既有匿名密码恢复端点，支持 `email` 和 `phone`，固定客户端应用范围。重置使用服务端 `password` 字段；成功返回无账号存在信息的完成状态，不自动登录、不创建会话。
- 主控复跑 PHP、TypeScript 全套 SDK 测试及 Dart 全套 17 项测试通过；实现侧 TypeScript 构建和 Dart 静态分析通过。覆盖两渠道、请求号、精确请求字段、无会话认证、错误码传播、非法输入不请求与无额外登录请求。三种 SDK README 已同步；交叉源码核对未发现必修问题。
- 真实消息发送、验证码消费、旧会话撤销和新密码登录仍未联调；本批不计真实恢复链完成，未执行 FLOW、数据库或宿主操作。

#### 2026-09-14 C03 三语言 SDK MFA 挑战提交

- PHP、TypeScript、Dart 均新增 `verifyMfaChallenge`，对接既有 `/api/sand-iam/v1/auth/mfa/challenge/verify`，覆盖 TOTP、恢复码和序列化 Passkey 响应。请求绑定客户端配置的客户主体与应用，不附带现有会话令牌；保留请求号与服务端错误码。
- 登录成功会话与 `step_up` 升级结果分别保留；TypeScript/Dart 增补升级标志。三个 README 已给出调用说明和请求重试边界。
- 主控复跑 PHP、TypeScript SDK 全套测试及 Dart 全套 15 项测试通过；实现侧 TypeScript 构建、Dart 静态分析通过。检查范围是实际 SDK 加离线传输，不是真实 MFA 对端。
- C03 仍缺真实挑战与密码登录、验证码、TOTP/恢复码/Passkey 的 HTTP/平台联调；未执行数据库、FLOW、宿主同步或部署。

#### 2026-09-14 C03 SDK MFA 挑战响应

- TypeScript 与 Dart 的认证结果现在保留服务端 `methods`、`expires_in`、`public_key`；此前只保留挑战令牌，业务应用无法从 SDK 返回值取得验证方式和 Passkey 请求参数。新增字段类型检查，不改变后端挑战或会话策略。PHP 原样返回响应，无此字段丢失。
- TypeScript SDK 全套测试与构建、Dart 客户端 9 项测试及静态分析通过。回归覆盖合法 MFA 挑战、六种错误字段、挑战不产生访问令牌；两种 SDK README 已说明挑战与会话区别。
- MFA 挑战验证提交能力已由上方三语言提交批次补齐；当前字段修复及提交能力仍不等于真实 MFA 接入完成。真实认证组件、HTTP 与供应商联调未执行，不更新正式验收分子。

#### 2026-09-14 C03 独立应用 SDK 验证码登录与注册

- 已补齐 PHP 登录、TypeScript 登录/注册、Dart 登录/注册的可选 `captchaToken` 参数，映射服务端已有 `captcha_token` 请求体字段。PHP 注册原本接受该字段，无需新增接口。PHP 参数追加在末尾，旧位置参数调用保持兼容。
- 当前验证：PHP、TypeScript SDK 全套测试通过，TypeScript 发布代码与声明已重新构建；Dart 客户端 8 项测试和静态分析通过。回归覆盖挑战令牌传递、应用绑定、不附带现有会话凭证、旧调用省略参数。三种 SDK 接入说明同步。
- 仍待完成：真实挑战供应商、HTTP 登录/注册和独立业务应用联调。本批只补接入能力，不改变服务端验证策略，不计真实登录业务链完成；未执行 FLOW 或宿主操作。

#### 2026-09-14 C04 SDK 凭证传输地址校验

- 已修复 PHP、TypeScript 的运行端与管理端共四处基础地址校验：按解析后的协议和主机识别本机 HTTP，拒绝 `localhost.example.com`、伪装本机的用户信息地址、查询/片段及反斜杠等。TypeScript 额外要求显式 `http(s)://`，避免相对地址在浏览器中解析到外部 HTTP 站点；运行端空字符串同源模式保留。
- 两种 SDK 全套测试、PHP 语法检查、TypeScript 构建通过；开发者页面三种语言示例使用真实 SDK 与离线传输再次通过。TypeScript 发布目录由当前源码重新构建，两种 SDK README 同步说明地址要求。
- 独立 Astra 复核先发现非显式协议地址绕过，修正并补回归后确认关闭，当前安全切片源码/离线 ACCEPT。未执行真实 HTTP、数据库或 FLOW，不计真实机器服务链完成。

#### 2026-09-14 C04 机器服务授权：编辑已有空约束

- 当前执行范围为需求源码实现与必要离线验证，遵守用户“不执行 FLOW”的要求；本节不变更验收账本计分。
- 已修复：管理端编辑已有服务授权时，后端返回的空 `quota_policy` / `network_policy` 数组回显为 JSON 对象，打开高级配置后可正常生成保存请求。处理仅限服务授权的这两个字段；已有非空对象原样保留，非空数组仍拒绝。
- 已验证：真实 ResourceEditor 脚本的七组行为回归通过，覆盖空配置回显、保留已有约束、清空约束和非法数组拒绝；当前权威前端复制到独立临时目录后的 SandIAM 范围 `vue-tsc --noEmit` 通过；`service_grant_invocation_control_non_pg_test.php` 通过。均为源码/离线证据，不证明数据库保存或真实服务调用。
- 尚未完成：`data_class` 的接口空值语义与安装/升级表结构一致性仍待核清；真实机器调用的成功、无权拒绝、凭证撤销及双侧审计仍未完成。本批未执行数据库、服务启停、宿主同步、提交或部署。

#### 2026-09-13 IAM-T09 / F03 Captcha 登录与注册接续

- 已确认产品缺口：独立门户只有手填 Captcha token 输入，启用 `require_captcha` 后普通用户无法仅靠门户完成挑战；不是所有登录 API 均不可用。下一业务结果是挑战交互 → 登录/注册 → 真实授权。
- 本任务已用指定 `read_thread` 核实旧交付任务 `01a091cd-56d0-7483-8781-b19a6e86a0c3` 为 idle、两个已知旧任务为 notLoaded；不恢复旧任务。用户已确认只读任务查询与认证源码读取。Hook 修复安装后，本任务原样三段 sed 加一段 rg 命令已实际 exit 0，认证源码读取阻塞解除。
- 已修改权威源码：新增 `TurnstileClient`，复用 `MessageProviderService` 的挂载选择新增公开挑战四字段投影；新增门户 `captchaWidget.ts`。客户端验证供应商结果、域名、用途及应用绑定；组件处理单次内存令牌、过期、失败和应用切换旧回调。公开配置端点已在下述增量接入；尚未接入 `app.ts`，当前门户缺口未关闭。
- 本批验证：权威目录客户端 **19/19**、公开投影 **9/9**、组件 **12/12** 离线行为断言通过；PHP lint、全门户严格 TypeScript 检查通过。不同上下文 Astra 源码/离线复核 **ACCEPT**，六文件与接受的隔离候选逐字一致。全仓质量扫描已中断（exit 130，untracked whole-file 扫描未完成），不计扫描通过；本批实际 diff 已人工及独立复核。
- 端点增量：已在既有 AuthController/HumanAuthService/auth 路由接入独立 no-store Captcha 配置入口，复用组织/应用、网络、策略和登录方式检查；品牌体验不存在时仍可查询。独立源码复核 ACCEPT，配置行为 **13/13**、PHP lint **3/3**；没有真实 HTTP 或数据库验收。`app.ts` 接线尚未完成。
- 前端读取增量：已在现有范围内用最后一次合法编辑新增 `loadPortalCaptchaConfiguration` 及实际 fetch 回归，严格解析三种配置分支、用途匹配、参数编码及异常拒绝；权威门户合同测试三段 PASS、全门户严格类型检查 PASS，独立 Astra 复跑 ACCEPT。门禁实际已到 **3/3**，后续代码编辑必须先完成检查点；不重试误判命令或借新文件绕过。尚未建立表单控制器或移除手填 token，仍不计真实登录闭环通过。
- 当前写者为本任务；子 Agent 已交还文件。下一步是门户登录/注册及无品牌体验的 OAuth/CAS 页面接线。门禁 checkpoint/amend 的说明文本新遭 SQL 误判，回传协调任务的消息又遭生产环境误判；两项均未绕过。它们是新的门禁元数据/消息问题，不恢复已解除的认证源码读取阻塞。源码读取、组件和端点的已有结果仍有效。
- 未执行数据库、服务启停、密钥配置、真实供应商请求、宿主同步、提交或部署。真实浏览器/供应商与完整业务链未验收，F/L/D、七链及发布计数不变，不可发布。

2026-09-13 计划与 Agent 执行契约对齐：按原需求中的可执行业务结果选择动作，替代“材料 → 候选 → 整链全部验收后才能下一链”的串行安排。历史授权和证据仅在原范围与适用条件仍成立时复用。

1. **OSS-04 首链 / OSS-05 剩余链**：以登录与授权、真实非 AI 业务应用、机器服务及其必要依赖为优先结果。已有实现转真实验收；缺实现则完成一个业务切片后交验收，并继续无直接依赖的下一需求，不砍剩余能力。
2. **沿用当前成果**：下方 C04、C02、OIDC 等增量保留原验证层级，不重跑已证明未受影响的项，也不计作完整链通过。每次选择明确需求 ID、下一行为、最小改动或验收、直接依赖、写者和授权；不能把静态/材料完成数当作业务推进。
3. **局部阻塞与结束前检查**：宿主重建和受拒登记仅限制有直接依赖的动作。按原需求区分可执行、已检查且受阻、未核查；找到安全且获授权的项即执行，不扩大搜索。曾误判的全局阻塞再出现时做一次有界独立复核；无新依据不重复审查，Goal 状态仍服从工具规则。
4. **OSS-00～03 历史完成与静态复核**：编号及已完成证据保留，按变更影响复用；不得因下一轮重新开始而重复全量基线、锁重建或验证器扩建。辅助项须绑定受阻业务动作与结束条件，完成即返回业务。
5. **OSS-05 完整收口**：来源、许可、签名、SandPackage 生命周期、标准协议对端、Casdoor、未参与开发者体验、同一最终候选连续 24 小时及全部 10 项发布门槛仍须完成；本次计划修订不增加任何验收分子，不授权 push、正式 Release 或生产部署。

本批真实运行增量（2026-09-13）：

- **C04**：生产 `IdempotencyService` PostgreSQL 串行回归已通过首次执行、同请求仅回调一次、重放不返回秘密、持久结果无明文秘密、不同内容复用请求号返回 409 共 5 项行为断言。执行现有 `security_operation_idempotency_pg_integration_test.php`，启动进程先用宿主现有 Dotenv 加载配置，再加载权威源码；Service/Model Reflection 与 `current_database()=sandadmin` 保护均经过实际执行，finally 查零以及随后独立只读 `residual_c04_rows=0` 通过，Astra 有界独立复核通过。可选双连接段显式关闭并跳过，其错误库清理保护尚未修复（文件写入门禁拒绝），不可执行或计为并发通过。本结果不是实际凭据轮换、Controller 授权创建原子审计或 C04 整链通过。
- **C02**：新增 `identity_group_audit_transaction_pg_integration_test.php`。初跑暴露测试清理缺陷：继承宿主 SoftDelete 的模型查询删除不能作为物理清除；诊断明确落在 `cleanup left owned memberships`。自产精确链已事务清理，后将测试 finally 改为原始表精确 ID/父关系删除及查零，不改生产源码。最终受控复跑输出 `IdentityGroupService audit-failure PostgreSQL rollback test passed`、`outer cleanup=zero`（执行句柄 `b7f1e4`），随后独立原始表查询 `igpg` 专属组织/应用/身份/用户组/端点/审计均为零（`52a657`）。覆盖实际生产 Service/AuditWriter 的 create/update/addMember/removeMember 审计失败回滚；add/remove 均断言故障前事件 delivery 为 1、回滚后为 0。不证明完整目录同步、角色策略授权、HTTP 或页面旅程；无 connector 场景的 SyncOutbox=0 不是同步回滚证据。
- **边界**：两测试均未新建数据库、迁移、启停服务或外发事件；C/F/L/D/发布计数不变。全仓质量扫描包含大量历史 `.artifacts`/`.staging` 产物，已中断，不能记为扫描通过；本批改动按精确文件 diff 与独立源审核对。仍需保留启动时 Dotenv 加载及受控清理执行条件，不把单独 `php test.php` 宣称为已验证入口。
- **独立复核与最后增量**：Astra 已有界验收 C02/C04 上述真实 PG 证据，未重复写库。其后 C02 仅补潜在 create 失败产生额外组时的清理：提前定义 `createCode`，按本次 application ID + 精确 code 物理删除及查零；语法、关闭开关 SKIP、diff 检查和独立源审通过，未重跑 PG，不将此清理增量描述为另一次业务实跑。
- **接续动作 C04**：执行既有 `service_grant_invocation_control_pg_integration_test.php`，验证生产身份上下文签发、允许/隔离拒绝、资源与网络策略变更、双进程争用最后配额以及授权撤销后的重验拒绝。原测试直接改变 grant 状态，因此不证明管理端撤销接口或业务 HTTP 副作用。直接执行前仅补父/worker 一致配置及源码/数据库保护、子进程异常收束和清理查零；保留全部既有业务断言，不扩大为安装、宿主或测试框架工程。尚未执行，不计通过。
- **2026-09-13 本任务 SandAI 前置复核（C04 / IAM-06）**：用户要求优先交付机器调用前置。主控与不同上下文 Astra 已只读复核两处消费端契约缺口：`sand-ai/config.json` 登记 service code 为 `sand_ai`，但 `HostSandIamIdentityContextProvider` 和 `SandAiInvocationFactResolver` 使用 `sand-ai`，与 IAM 严格 service 匹配冲突；audience 是独立字段，不应随 service code 混改。其次 `HostSandIamInvocationAuthorizer::scopeFromContext` 将 context 四级范围传入 resolver，后者只按 environment 查询资源并原样返回范围，不满足 P0 契约 §5.1 的资源所属事实来源要求。修复应落在 SandAI 权威消费端，不能放宽 IAM 隔离校验；本任务尚未修改 SandAI，也未对外派发。两项 action `sand_ai.chat.complete` / `sand_ai.document_parse` 不能据冻结契约直接声明可用，需修复后完成真实允许、隔离拒绝、撤权后 Provider 零调用及双侧审计。现有运行入口已具备环境校验、grant 重读及 Worker 前复验，保留这些源码证据，不增加 C/F/L/D 或发布通过数。
- **C04 接续的局部阻塞**：该测试原版在 worker 启动/屏障异常后没有先收束子进程即清理夹具，不能安全原样执行。精确单文件 write-gate 登记普通与提权两次均被工具拒绝，未落补丁、未运行数据库，不再重复登记或绕过。此阻塞只限制该测试；转而核对已有 C02 策略版本真实 PG 回归是否具有无需改文件的安全执行入口，不恢复宿主支线。
- **替代动作核查结果**：`policy_versioning_pg_integration_test.php` 使用真实生产发布服务，但未调用 `rollbackVersionId`，外供 application/resource/role ID 且 Policy 清理会软删；不能原样计真实回退验收。需补自产依赖、实际回退断言与物理清理，不将无写 SKIP 算进展。C04 门禁拒绝已反馈现有规则维护任务 `01a0998d-cfc9-7c30-894c-9b50047b6c16`，请求受支持的登记方式或明确解除条件；未授权修改/关闭全局门禁，不以该反馈代替业务完成。
- **连续阻塞收口**：上述门禁阻塞持续三个连续 Goal 回合。维护任务现已完成只读调查，明确没有已确认可用的自助登记入口；解除条件为工具维护方通过受支持审批放行精确请求，或受控修复分类器后验证原请求正常登记。旧 demo 两个核心运行时类及 context controller 与权威源码一致，但只提供 context issue/verify，缺少承载完整事实解析、配额和撤销重验的已运行 provider，不能替代受阻验收。当前标记 Goal blocked，保留全部需求与已有 PG 证据，不继续重复命令、换任务绕过或重建宿主；恢复后从 C04 单文件必要安全修复及真实回归接续，不重做已通过检查。外部协议对端、独立参与者、最终候选长稳与发布终验仍未满足，未声明可发布。
- **阻塞范围纠正与实际接续**：后续有界调度检查找到不依赖 C04 登记的 `oidc_logout_recovery_pg_integration_test.php`，因此上段“当前确无可执行业务动作”的推断不成立，不作为后续调度依据。只读确认签名密钥表 raw count=0、PostgreSQL 嵌套 savepoint 不提交外层事务后，以现有 host vendor/Dotenv 加载及权威源码执行原测试，未改文件。实际输出 `OIDC logout recovery PostgreSQL integration passed`（句柄 `3800bc`），回滚后原始表 organization/application/identity/oauth_client/auth_session/logout_delivery/audit/security_operation/audit projection delivery/signing_key 十项均为 0；写前目标 `sandadmin` 与 `OAuthOidcService` 权威来源经过校验。覆盖 dead 登出通知重新签发、唯一后继与请求重放、待处理/跨客户端/未撤销会话/停用客户端拒绝，以及审计唯一和秘密不泄露；不证明真实 RP 收取、HTTP 重试、浏览器或完整协议互操作，不改变完整 F/C/L/D/发布分子。
- **本次检查边界**：已核查邀请、API 治理测试缺少安全清理；MFA 并发测试缺异常子进程收束；消息测试缺清理及可靠不外发保证；服务目录测试会修改 SandAI 目录；T12 重放测试存在 exit 跳过清理及软删除问题。两 retention 测试生产淘汰查询不限夹具，现有库可命中行数量尚未核实，不能写成“已证实无安全环境”。本轮找到 OIDC 可执行动作后即停止扩查；其余未检查路径保持未知，不用未知证明全局阻塞，也不新增平行队列。
- **OIDC 独立终核**：Astra 核对既有执行 `3800bc` 的退出码为 0，十项 raw 查零发生在测试 finally 回滚并返回之后，非零会抛错；有界独立验收通过，未重跑数据库。真实 RP 发送/接收仍未验收。

当前进展：OSS-00 已完成；OSS-01 的依赖/许可证审计已完成，且本批补齐 W3C vendored schema 独立 `NOTICE`/SBOM component、SDK 独立 `LICENSE`/`NOTICE`/metadata 与 public path 漏检。当前 hygiene **16/16**、policy **16/16**、SBOM **80**，经 Astra 独立复核 **ACCEPT（P0/P1/P2=0）**。源码已具备 Apache-2.0
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

**2026-09-13 当前并行执行记录（不改变分母或正式通过计数）**：需求实现与独立验收并行，安装只阻宿主实测。C01 源码与离线独立复核获 Astra 综合 **ACCEPT（P0/P1/P2=0）**；既有 C01/F04 的 `AdminOrganizationAccess` 请求号关联源码修复、新行为/恢复回归，以及四 create 原子事务两批均独立 **ACCEPT**。既有真实 Service 响应已跑完整 13 步并 `passed`、cleanup confirmed；四阶段部分清理、四类全集、真实 creator 审计、真实 grant allow/revoke/env 停用均有记录。C01 v2 保留 disabled org/app audit anchors；真实 retained ID/status=2，environment/grant 物理零且 active 残留为 0，不宣称全行物理零。三项用户可观察源码行为已完成；zero-capture 因凭证 transport=0、firstwrite unknown 仍为 **blocked/not_confirmed**，不是完成或正式 C01/F/L/D 通过。C01 原子创建继续保持 `data.id`、权限先验证和生产 `IdempotencyService` 语义；实际 PostgreSQL 并发尚未验收，额外 dynamic probe 被 hook 阻挡，仅作源码核对。C02 四操作审计事务源码修复及非 PG 回归已获独立 ACCEPT；F05 OAuth failed logout UI 五文件已实现，DTO test 由主控与独立 Astra 通过，目标 eslint、SandIAM scoped `vue-tsc`（exit 0、日志 0）和隔离 Vite build 均通过（21.71s）；独立 `astramedium__logout_review` 源码/离线复核未发现确定阻止缺陷。主控与独立 Astra 已核对 OAuth HTTP 200 业务错误的稳定 message/code；“必丢错误码”假设已撤回，未修改兼容代码。真实浏览器与 API、两视口、角色验收仍未完成；全量宿主 types 仍单独受 SandPackage 测试配置 TS2307 阻断，因此不计正式 F05 通过。sync-connector/index.vue 与 `failedOutbox.behavior.test.cjs` 已修复连接切换旧响应污染/错配重试（原代码红例 `1010!=2020`，修后主控及独立 `astramedium__logout_review` 实际 Vue 脚本延迟回归 PASS）；目标 ESLint、格式与 diff 检查 PASS，独立源码离线未发现确定缺陷。既有 OAuth 临时 overlay 仅覆盖 sync 两文件；SandIAM scoped `vue-tsc` exit 0，Vite build exit 0（4487 modules，18.91s）；原源码已冻结。该结果仍不是浏览器/HTTP、完整页面、正式 F 或业务链通过。文件隔离已落实：audit Access 两文件停止写并完成验收；atomic 范围仅为 base + 4 controllers + test；C01 范围为 `AcceptanceFixture*`、`live-driver`、`plan`、`tests`；F05 前端范围为五文件。共享 write gate 存在同 session 冲突，按 checkpoint 短交接；分析与验收并行且不争用全局 gate。HOST 已成功送达；本节点未扩展 DB、HTTP、服务、同步或迁移授权；门户本批 IAM-T01/F03 的注册验证源码、既有测试、受控 DOM/fetch 回归、type、隔离 build、主控复跑及独立 Astra 源审均 PASS，权威 `account.js`/`index` 原生生成物与独立 `write:false` 构建字节一致，生成物缺口已关闭，公开用户指南已补；真实 API、短信/邮件与浏览器仍未验收，F03/C/L/D/发布计数不变。C04 service grant controller 已开启原子 create；审计失败三类记录回滚、同请求重放不重复，变更 payload 返回 409、撤权返回 403、引用错误返回 400；既有服务调用控制回归 PASS。该批为非 PostgreSQL/HTTP 证据，正式计数不变。

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
> SandPackage catalog/035 修复已由 `.artifacts/sandpackage-recovery-catalog-independent-review-20260907/P0_P1_CORRECTION_EVIDENCE.md` 记录为 30/30、verifier 107/107；v15 的 production verifier/profile 已按 `.artifacts/recovery-controlled-sync-v15-20260907/MANIFEST.md` 完成 8/8 受控同步。该同步不等于候选上传、数据库恢复或部署完成。2026-09-07 的 83 表、catalog 与 `state=8` 仅是历史保留证据，不能再称当前状态；当前可复核宿主事实以 [`HOST-202609-001`](../../../docs/host-requests/HOST-202609-001-sandpackage-failed-recovery.md) 为准：已发送至 SandAdmin，待宿主接收处理（不等于已接单或已修复），demo registry 为健康 `0.7.0`（`state=1`、`stage=completed`），精确数据库计数未在本轮重新验收。
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

下表及后续任务的负责人表示当前接续责任；历史实现者和已有证据保持原记录，不因接管而改记成果。

| ID | 负责人 | 状态 | 交付条件 | 已冻结输入 / 交接物 | 任务完成时必须写入的证据 |
| --- | --- | --- | --- | --- | --- |
| IAM-01 | Codex | ✅ 已完成 | 冻结 P0 领域、PostgreSQL 模型、管理/运行时 API、错误码、SandAI Adapter 与 Cursor 开工输入 | [P0 契约 v0.2（可消费）](sand-iam-p0-contract.md) | 初版交付已完成；身份源采用显式双作用域：应用私有，或组织持有后显式挂载到应用。旧管理端 `provider_code` 仅兼容解析，不创建记录或猜测作用域。 |
| U-01 | Codex | ✅ 已完成 | 管理端页面目录与诚实占位页；不依赖真实后端字段 | 产品对象已确认：organization / application / environment / workload_client / service_grant / audit | 见 `.cursor/autopilot/executions/U-01.md` |

**并行规则：** Codex 主控在已冻结契约上安排互不争用的子任务；U-01 不因 IAM-01 停工。不得因 SandAI 演示站或 OCR 决策停工。

## 后续任务

| ID | 负责人 | 状态 | 前置 | 交付/验收条件 |
| --- | --- | --- | --- | --- |
| IAM-02 | Codex | ✅ 已完成 | IAM-01 | SaiPackage 只执行包根 lifecycle SQL；当前 `0.1.1` 在隔离库回归安装后为 18 表 / 213 约束 / 53 索引、无非 SandIAM 表；包根 update 成功；uninstall 后 0 表。解锁 IAM-03。 |
| IAM-03 | Codex | ✅ 已完成 | IAM-02 | 后端、路由与 17 表已安装；临时宿主服务的后台入口真实返回 401、无 signer 返回 503；回滚事务证明签发→校验→撤销拒绝→审计，且 0 残留。真实部署 signer 与已登录后台会话移交 IAM-05 端到端验收。解锁 IAM-04。 |
| IAM-04 | Codex | ✅ 已完成 | IAM-03 | [授权与数据范围契约 v0.1](sand-iam-authorization-contract.md) 已冻结；`0.1.1` 迁移、策略/范围/委派/审计控制面实现及真实宿主回滚验收完成。七操作均放行，跨组织、条件不匹配、同优先级 deny、无策略及范围不匹配均稳定拒绝；审计存在且测试无残留。宿主的 `saiadmin → sandadmin` 迁移通过 SandIAM 的按需类别名适配，不修改宿主核心目录。 |
| IAM-05 | Codex | ✅ 已完成 | IAM-04 | 已在真实 `sandadmin` 宿主同步插件并完整重启。Codex 已生成部署期 signer 并仅写入宿主 `.env`；运行时真实 HTTP 使用专用 `saiadmin` 验收库的临时服务凭证夹具，完成 `issue=200`、`verify=200`、错误 audience `403`、凭证撤销后 `401` 和审计存在验证。夹具及关联审计已精确清理，9 项残留检查均为 0；不输出 signer、凭证或 context。管理端接口交接已完成，见 [管理端接口交接 v0.1](sand-iam-management-api-v0.1.md)。 |
| U-02 | Codex | ✅ 已完成 | IAM-01（页面信息架构/字段字典已标可消费） | 六个列表已按 P0 契约接入加载、空、失败、分页和冻结字段；无伪造 CRUD，验收宿主 `vue-tsc --noEmit` 与 Vite 构建通过。见 `.cursor/autopilot/executions/U-02.md`。 |
| U-03 | Codex | ✅ 已完成 | U-02，且管理 API 已标可消费 | 列表筛选、权限码展示、401/403/503/`SAND_IAM_*` 诚实失败；已接入 identity / binding / user-type / role / resource / policy / 委派 / 身份关系只读列表。`vue-tsc` 与宿主 Vite 构建通过。见 `.cursor/autopilot/executions/U-03.md`。 |
| U-04 | Codex | ✅ 已完成 | 管理写入 API 已冻结（不依赖 IAM-05） | save/update/disable、policy publish/revoke、grant revoke、关系 grant/revoke、service/action/credential；凭证明文只显示一次。见 `.cursor/autopilot/executions/U-04.md`。 |
| D-01 | Codex | ✅ 已完成 | 管理 API、产品对象边界与一次展示凭证规则已冻结 | [第一次使用 SandIAM](../user-guide/sand-iam-first-connection.md) 已交付行业中立的文字版操作手册；产品语言、单页向导和闭环要求以[体验契约](sand-iam-product-experience-contract.md)为准。真实页面与截图仍待后续任务验收，禁止使用虚构截图。 |
| U-05A / U-05B | Codex | ✅ 源码完成 / ✅ Cursor 浏览器证据（Codex 独立验收待办） | 管理 API 与对象边界已冻结 | 名称优先和系统代码解释源码、宿主构建已完成，见 `.cursor/autopilot/executions/U-05.md`；U-05B `u05b-1787928603323` 三角色创建流 + 双视口通过，见 `.cursor/autopilot/executions/U-05B.md`。不上调正式 FLOW。 |
| UX-01A / UX-01B | Codex | ✅ 源码完成 / ✅ Cursor 宿主证据（Codex 独立验收待办） | U-04、IAM-07 的 v0.2 管理契约 | 权威源码见 [UX-01 执行记录](../../../.codex/autopilot/executions/UX-01.md)；UX-01B `ux01b-1787929271052` 13 页双视口 + 三角色 allow/deny 通过，见 `.cursor/autopilot/executions/UX-01B.md`。宿主缺口：`SandIAMGettingStarted` 菜单未入库。不上调正式 FLOW。 |
| U-14 | Codex 兜底 | ✅ 源码交接完成 | [体验契约](sand-iam-product-experience-contract.md)第 1、2、4 节 | Cursor Autopilot STOPPED，Codex 直接完成总览用途、五种状态、行业中立文案、隐藏内部入口和布局的源码交接，见 `.cursor/autopilot/executions/U-14.md`。真实宿主三视口验收仍未完成。 |
| U-15 | Codex 兜底 | ✅ 源码交接完成 | U-14、体验契约第 3 节 | Cursor Autopilot STOPPED，Codex 直接完成 `/sand-iam/getting-started` 单页向导源码交接，见 `.cursor/autopilot/executions/U-15.md`。真实保存、刷新和错误恢复仍未验收。 |
| U-16 | Codex 兜底 | ✅ 源码交接完成 | U-15、Codex 菜单/动态路由契约 | Cursor Autopilot 已停止，Codex 直接完成入口与应用用户边界的前端交接，见 `.cursor/autopilot/executions/U-16.md`。首次使用入口按可管理范围显示；管理范围支持客户主体委派或应用委派任一入口。真实宿主菜单、权限和 404 复验仍未完成。 |
| UX-02 | Codex | ✅ Cursor 七链证据（Codex 独立验收待办） | 体验契约第 5 节、受控宿主与夹具授权 | Cursor `ux02-1787930822471` 七链 7/7 通过，见 `.cursor/autopilot/executions/UX-02.md`。Codex 需独立验收，并将演示宿主热修（`PolicyAuthorizer` / `ApiResourceController` / `identity-group-role` / `simulate` 空规则）并回权威包。不上调正式 FLOW。 |
| U-17 | Codex | ✅ 源码完成（宿主/浏览器待同步验收） | 体验契约第 4 节、U-14 布局规则、用户 2026-08-31 指派 | 去掉宿主 `art-full-height`/`art-table-card` 裁切；改为 `sand-iam-page` 最小高度容器。源码与文案门禁通过，见 `.cursor/autopilot/executions/U-17.md`。未改宿主；双视口滚动未验收。不上调正式 FLOW。 |
| IAM-06 | Codex | ▶ 进行中 | SandAI 发布包需要受信任的 SandIAM 安装前置 | `ServiceCatalog` 已补齐声明校验、行锁、重复安装不覆盖/不复活、`23505` 单次收敛重试和越界模型门禁；机器调用 quota/network/data_class 约束（030）已通过源码与隔离 PostgreSQL。SandAI 的 4/4 隔离生命周期是其自身证据，仍需真实 SandAI allow/deny/audit 业务闭环，不能替代 SandIAM 宿主验收。见 `.codex/autopilot/executions/IAM-06-service-catalog-draft.md`。 |
| IAM-07 | Codex | ✅ 已完成 | 应用账号独立，旧 `provider_code + subject` 全局唯一边界需要修正 | v0.2.0 已实现以 `identity_provider_id + subject` 为边界的身份绑定、显式 application/organization 两种身份源作用域及 application mount、兼容管理 API。PostgreSQL 18 新装为 19 表 / 228 约束 / 60 索引；隔离验收已证明 0.1→0.2 旧绑定回填、重复升级幂等、跨应用相同 subject 放行、同身份源重复拒绝、跨应用错绑拒绝，卸载后 0 张表且临时数据库残留为 0。 |
| IAM-T01 | Codex | ✅ 已完成 | IAM-T00 终极边界和应用身份隔离已冻结 | v0.3.0 已实现应用级注册、验证、登录、Argon2id 密码、认证策略、失败锁定、数据库限流、可撤销会话、刷新令牌轮换/重放撤销、退出、密码重置和审计。隔离 PostgreSQL 中使用真实 SandAdmin ORM 依赖执行服务级流程通过；跨应用相同邮箱独立注册通过，敏感材料审计泄露扫描通过，安装、两次升级、卸载通过，临时库残留为 0。契约见 [人类身份认证 API v0.1](sand-iam-human-auth-api-v0.1.md)，执行证据见 `.codex/autopilot/executions/IAM-T01.md`。真实宿主 HTTP、管理端和发布验收归 IAM-T07/T08。 |
| IAM-T02 | Codex | ✅ 已完成 | IAM-T01 | v0.4.0 已实现应用隔离的 TOTP、一次性恢复码、密码后 MFA challenge 和 ES256 WebAuthn/Passkey。绑定新认证器必须通过当前密码二次认证；MFA 验证有独立数据库限流；challenge、TOTP 时间步、恢复码与 signCount 重放均拒绝。隔离 PostgreSQL 已通过真实 ORM 服务流程、密钥 key ring 兼容、BE/BS 与 userHandle 校验、跨应用拒绝、fresh install、003→当前重复升级、复合外键反例和两轮卸载清理。契约见 [MFA 与通行密钥 API v0.1](sand-iam-mfa-passkey-api-v0.1.md)，执行证据见 `.codex/autopilot/executions/IAM-T02.md`。真实浏览器、宿主 HTTP、管理端和发布验收归 IAM-T07/T08。 |
| IAM-T03 | Codex | ✅ 已完成 | IAM-T02 | v0.5.0 已实现 issuer 全局客户端代码、授权码 + PKCE S256、服务端一次性授权交互与 CSRF、客户端凭证、RS256 access/ID token、发现文档/JWKS、userinfo、refresh 轮换与重放整族撤销、revoke/logout、audience/scope 与应用/组织停用 fail-closed。真实 ORM/服务/controller 测试覆盖跨应用、重定向、增量 consent、prompt/max_age、JWT、DB 关联和审计脱敏；主代理隔离库 fresh install→update→uninstall、003→current×2→隔离检查→uninstall 通过，临时库自动清理。契约见 [OAuth/OIDC Provider API v0.1](sand-iam-oauth-oidc-api-v0.1.md)，执行证据见 `.codex/autopilot/executions/IAM-T03.md`。真实 SandAdmin HTTP、标准客户端互操作、浏览器 Cookie/页面、并发、限流和密钥轮换验收归 IAM-T07/T08。 |
| IAM-T04 | Codex | ▶ 进行中 | IAM-T03 | OIDC/OAuth、SAML、LDAP/SCIM、目录同步、映射、停用、解绑和审计已统一进入 lifecycle，并通过真实 ORM PostgreSQL 集成；仍缺真实外部 IdP/目录、标准客户端、宿主 HTTP 和管理端验收。 |
| IAM-T05 | Codex | ▶ 进行中 | IAM-T03 | 接口目录、路由绑定、语义动作决策、PHP/TypeScript SDK 和 Webman 中间件已进入 `008` lifecycle；应用/组织隔离、幂等观察、路由冲突、停用关闭失败、OpenAPI 语义保留和审计已通过 PostgreSQL 集成。真实 SandAI/非 AI 应用授权和管理端闭环尚未验收。 |
| IAM-T06 | Codex | ▶ 进行中 | IAM-T04、IAM-T05 | 应用级委派、自助 API、Webhook、审计导出已进入 lifecycle；委托与 Webhook PostgreSQL 集成通过。仍缺并发 worker、真实 HTTPS 接收端、三角色页面和浏览器闭环。 |
| IAM-T07 | Codex | ▶ 进行中 | IAM-T04～IAM-T06 | 完整管理端交接契约和 Cursor U-T04～U-T13 源码/执行记录已交付；仍缺真实宿主同步、登录后的三角色流程与两个视口验收。 |
| IAM-T08 | Codex | ▶ 进行中 | IAM-T04～IAM-T12 | `61a7f138…` 是未 push 的 0.7.1 候选提交，来源/事务修复仍未提交；normal 包排除旧 recovery descriptor。v12 内容自洽但因 62 个 ignored 来源文件未进入可追溯来源而正式 **REJECT**，不得作为发布来源。只读 DB 为 86 tables、ledger 38 rows、max revision 37、无038，runtime 仍 0.7.0；B′/C′/D′ 未执行，G 未授权。宿主同步、安装/升级/卸载、真实宿主 HTTP、浏览器、外部客户端、七条业务闭环和部署仍未通过。|
| IAM-T09 | Codex | ▶ 进行中 | IAM-T06 | 应用登录体验、品牌、消息 Provider、独立用户门户后端候选与 `011/025` 已落；假驱动下的服务选择、密文配置、投递/Captcha、停用关闭失败和跨组织拒绝已通过 PostgreSQL 集成。真实供应商、HTTP、页面和浏览器未验收。 |
| IAM-T10 | Codex | ▶ 进行中 | IAM-T04、IAM-T09 | 用户生命周期、组、邀请、访客升级、CSV 导入导出、通用 Syncer 与 `012–015/026` 已形成候选；生命周期/组/访客、邀请、Syncer 三组 PostgreSQL 集成通过，P14 scheduler/worker 已独立最终复核 ACCEPT。真实 HTTP、真实 worker/目录运行和页面未验收。 |
| IAM-T11 | Codex | ▶ 进行中 | IAM-T03、IAM-T04 | DCR、OIDC 前/后通道登出、CAS、Kerberos/SPNEGO、RADIUS Access/Accounting 与 `016–018` 已形成候选，迁移已进入 lifecycle 并有 SandPackage 隔离安装记录；真实 Realm/NAS、标准客户端、宿主 HTTP 和页面未验收。 |
| IAM-T12 | Codex | ▶ 进行中 | IAM-T05～IAM-T11 | Dart/Flutter SDK、CLI、管理 OpenAPI/事件目录、网络规则、安全运营和初始化包已进入 `019–020`；权限对账、网络规范化、初始化稳定策略键、默认路由封闭和敏感模型序列化已复核。当前 PHP lint 321/321、PHP SDK 通过、Dart 7/7、非 PG 47/47、PostgreSQL 集成 11/11；剩余真实业务应用、登录后宿主 HTTP、页面和发布验收。见 [开发者与安全运营契约](sand-iam-developer-security-operations-v0.1.md)。 |
| A-01 | Codex | ◻ 未开始 | IAM-05、U-03 | 后台配置 → 身份上下文 → SandAI 拒绝/放行 → 审计的真实路径通过 |

### 文档收敛后的待办（不改源码）

- `plugin/sand-iam/config/process.php` 的过时迁移说明已不再作为任务项；worker 是否启用仍以部署配置和真实运行验收为准。
- 三条[开发者旅程](sand-iam-developer-journey-acceptance.md)已有可计时标准，但 SandIAM 与 Casdoor 都尚未在同等环境完成实测，不得宣称 SandIAM 已经更快或更顺手。
- [API 路由权威表](sand-iam-api-route-registry.md)已取代旧 `/account`、`/authorize`、`/context`、`/scim/v2` 草案路径；后续路由变更必须同步更新代码和该表。

## 历史开工输入（IAM-01，非当前指令）

- 源码包：`/Users/code/project/sand_plugins/sand-iam`
- 演示与验收宿主：`/Users/code/project/sand_plugins/sandadmin-demo-host`（服务端为其 `server/` 子目录）；`/Users/code/project/sandadmin` 保持纯净通用宿主，不用于插件演示
- 产品/边界已确认：`docs/product/sand-iam-product-requirements.md`、`docs/architecture/sand-iam-access-boundary.md`
- 开发门槛：`docs/development/sand-iam-development-entry.md`
- Cursor 已占用：`sandadmin-artd/src/views/plugin/sand-iam/` 页面壳；请勿改该目录
- SandAI 等待：冻结 Adapter 后即可继续 `SAND-113C`（token/context 验证、audience、service grant、environment 引用）；缺省保持 fail-closed
- 业务拒绝统一：`plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`

## 历史 Cursor 消费规则（已失效，不执行）

- 独占目录：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`
- IAM-01 未标「可消费」前：只做壳与诚实空态，禁止假 CRUD
- 契约文件名由 IAM-01 冻结后写入本看板「当前交接包」；DETECT 监视看板、契约与 `.codex/autopilot/tasks.md`

## 历史交接包（契约证据可复核复用，旧分工和任务状态不执行）

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

### 历史 IAM-03 接续记录

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

### 2026-09-12 当前宿主阻塞与下一输出

- 正式 unsigned 候选仍绑定 `0d210a99c397a480e63fc488847820e578e8e117`；当前应用恢复源码修复在 `553a0145e380cd7ccf01ac42d85421b8df0d0dc1` 与 `644359839e425abce2e5d470f73aa1258826c028`。后者已从 clean clone 受控同步到 demo 的源码副本，但该副本不是安装运行树，不能构成升级或正式验收证据。
- 实际正常 `0.7.0 → 0.7.1` 升级在 SandPackage 的 manifest 身份缺陷处停止：`markInstalled` 留下旧登记摘要，`backupPackage` 在 rename 后才核验并写入 `backed_up`。catch 在 rollback rename 前的恢复前身份断言即失败，故 runtime registry 当前不存在；未进入 SQL/deploy。详情与精确 digest/backup ID、源码顺序冻结在 [HOST-202609-001](../../../docs/host-requests/HOST-202609-001-sandpackage-failed-recovery.md)。唯一下一输出是 SandAdmin 提供含修复版本的官方恢复验收：恢复该 journal 后再按正规上传升级。
- C01 第一次探索于 2026-09-12 20:44:47 +08 留存于本会话记录：创建 `organization=102`、`application=72`、`environment=40`，完成更新/allow/停用后的 `400` deny；旧运行树的恢复返回 `403`，随后 API cleanup 返回 `cleaned=true`。第二次于 21:13:06 +08 使用 `/private/tmp/sand-iam-c01-http-driver.php`，夹具前缀 `sand_iam_acceptance_e36b8b2f26b372ec_`，对象 `103/73/41`；恢复夹带 `name` 的拒绝仍为旧运行树 `403`，cleanup 同样为 `cleaned=true`。两次均未触碰 pending journal；`.env` 已恢复原始缺失 cleanup flag 的 SHA-256 `4a6ce68009af8c7d2073ff93347bcd523064e9ea9a630b940e2667a768cdf5b2`。
- 证据复用边界：候选源码/可复现构建及 `6443598` clean-clone 导出不受当前安装态影响，可继续作为候选和同步取证；两次 C01 的创建、更新、allow、停用 deny 与零残留仅可复用为旧运行树的观察。恢复成功、恢复 payload `400` 行为和加载 `6443598` 的业务链必须在宿主官方恢复并正规升级后重验；对应 host 证据为上述交接单与 pending backup journal。**C01 未通过；七链 0/7。** 宿主恢复并正规升级加载 `6443598` 前不得重跑。
- 既有授权位置仅作范围留存，不扩展权限：`.codex/autopilot/executions/OSS-04-authorization.md`，以及本会话两次“授权”和“持续授权”指令。它们已覆盖当时受控同步/C01 的限定操作；本节点未据此执行 SandAdmin、数据库、服务或 SandPackage 写入。

### 2026-09-13 backup v3 离线证据契约批次

- 本批仅登记离线 backup v3 证据契约：`schema v3` / `plan v2`，覆盖 typed ownership + staged reconcile，以及 generator/template roundtrip。
- 核心验证结果为 **1 个正例 + 9 个 hash 重算负例**；六文件独立 Astra 验收为 **ACCEPT（P0/P1/P2=0）**。validator SHA-256：`fece73aa13ec4917f5c122f563716c2ab0945572ecd84d88245968022aa1206f`。
- `real_g=false`。本批未执行真实 PostgreSQL、KMS 或恢复演练；不构成 D06、正式 FLOW、业务闭环、部署或线上验证通过。
- 本批不改变冻结统计：需求/架构/票据 **8/9**、模块实现 **20/20**、正式 FLOW **0/7**、本地业务闭环 **0/4**、可上线部署 **0/8**，完整目标仍 **28/48**；发布门槛仍 **0/10**。除本节与对应 terminal ledger 外，不回写其他过时全仓统计。
