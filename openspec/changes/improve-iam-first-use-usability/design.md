## Context

范围与动机见 proposal.md。受影响页面位于 SandIAM 管理端；首次向导只跳路径，TaskPath 当前统计所有可见记录。后台 AdminResourceController 对 writeFields 中的 application_id/environment_id 直接筛选；WorkloadClientController 和 ServiceGrantController 另支持 application_id 关联筛选，均与访问范围相交。凭证的自定义 index 也已提供 application→environment→client 关联筛选，身份源按应用挂载关系筛选，二者均与管理访问范围相交。全局服务等对象不能据此推断支持 application_id，因此仅对白名单中已逐项核实的 endpoint 进行任务计数。

SandAI 通过 HostSandIamRuntime 消费公开端口。authorizeInvocation、revalidateInvocation 各九参数；SandAI 校验 service_code=sand_ai、audience=sand-ai、作用域和授权返回类型。当前 SandAI 锁 IAM 0.7.5 与 SHA-256，而 IAM 源码 metadata 为 0.7.3，需要核查包及真实加载路径。

## Goals / Non-Goals

**Goals:** 在现有能力内修复八项审计断点，为管理员和开发者分别给可理解的顺序、输入来源、结果和恢复；以当前应用可验证范围组织状态与新建默认值。

**Non-Goals:** 新接口、字段目录自动发现、部门动态比较算法、密码或账号绑定协议变更、数据库/SQL变更、SandAI代码改造、锁定依赖更新、测试宿主升级或公开发布。

## Decisions

1. 查询参数承载当前客户主体/应用/环境的已选上下文。仅接受正整数，读取既有可见 API 核对归属；无权限、篡改、不匹配或过期上下文显示明确错误并清除不相容默认。沿用原路由，不新增 API 或假定前端是授权边界。
2. 复用现有任务 definitions，补充连续任务说明/下一入口。TaskPath 使用受支持筛选；不能范围查询的步骤要求进入页面检查。状态用“有记录，待验证”，不依据数量或 localStorage 声称登录、权限、调用完成。
3. ResourceEditor 的归属预填来自受控初始值或页面筛选上下文；切换应用同步清理依赖对象。一次性凭证沿用只显示一次、只写不读的安全行为，附非敏感归属摘要。
4. 条件和数据范围说明使用应用开发者明确提供的字段和值；以真实支持的静态字段/范围为例，不许宣称 IAM 自动执行未证实的同部门查询。必须说明应用侧可信事实与列表过滤责任。
5. 错误映射仅精确稳定码与已确认非敏感校验白名单；未知服务端文本不直出。人员注册/邀请及服务受众的文档必须核对源码/SDK，缺口说明前提，不发明接口。
6. 条件编辑器在既有 equals/in 标量协议内为字符串/数字/布尔/null 提供类型选择与保真 hydration。修复原字符串化导致严格匹配改变的问题；非法输入阻止保存，不改 PHP matcher 或数据库。
7. UI 工作包只改管理端与适用 helper 测试；文档工作包只改受影响 README、既有必要源码相邻说明及 Wiki 草稿（仓库外）。两者都读同一spec。兼容工作包只读实际包/安装路径与版本、运行无DB离线测试，并检查最终SQL/runtime无差异。

## Risks / Trade-offs

- [记录计数仍不能证明业务可用] → 用中间状态展示，并列出实际登录/允许/拒绝/撤销/审计成功标准。
- [查询参数伪造导致错误默认] → 正整数解析、可见归属读取、匹配检查及服务端既有授权；不以URL参数拓权。
- [源码旧metadata与依赖新包混淆] → 精确包摘要和有效路径核对，禁止降级或改SandAI依赖锁；发布阶段单独固定兼容基线。
- [缺真实已安装环境] → 先完成源码/离线检查和独立文档模拟，按本轮实际结果报告界面及业务验证缺口。
- [文档示例被误当自动业务能力] → 明确示例责任方、真实字段来源、现有支持边界和应用侧过滤职责。

## Migration Plan

本次不新增数据迁移，不执行SQL或宿主升级。后续发行采用现行可信包生成及 SandPackage 备份升级路径；以已安装版本为前提，核验版本前进、生命周期摘要、旧凭证/授权/上下文消费及回退备份。源码整改完成不能替代这些发行检查。

## 升级后 PostgreSQL/GSS 崩溃跟进（2026-10-01）

- 消费实例锁定：ttt SandAdmin，Core 0.1.7@6412b78、Package 0.1.5@62bd832、IAM 0.7.6、PHP 8.4.18、Workerman 5.2.2。升级最终发送 SIGUSR1，master 保持并 fork 替换 worker；无重载丢弃启动参数的源码证据。
- 五份 macOS 原生崩溃报告显示 PDO→libpq PQconnectPoll→pg_GSS_have_cred_cache→Kerberos→CoreFoundation，并明示 fork 子进程崩溃。官方 PGGSSENCMODE 对应 gssencmode 默认值；disable 禁止 GSS 加密协商，不关闭 TLS 或所有 GSS 认证。
- 中立反证：仅 autoload/Dotenv/native PDO、无应用/插件 bootstrap、无 SQL 的 default rawfork 1/1、父 PDO 默认初始化后关闭再 fork 1/1 均通过，disable 3/3 通过。不能宣称默认 fork 必现或完整 native 根因已定论。073→076 包含服务目录预检、拒绝审计与引用验证变化，并非仅 UX；未见 PDO/DSN 或重载实现变化。
- 权威落点为 SandAdmin server/.env.example、README、server/tests/PostgresWorkerReloadTest.php，候选基于公开 main f1ad75be；现有 App::run/worker bootstrap 的 Unsafe Dotenv 已在首 PDO 前 putenv，因此取消重复 runtime helper 与自动全平台 GSS 默认。
- 提供方通用性证据：零业务插件、无监听 Worker 使用真实宿主 support/bootstrap；disable/prefer/require 三组均观察真实 SIGUSR1 后同一 master、不同 child PID。disable 初始与重载两次仅 connect/disconnect 通过，无 SQL；prefer/require 只验证值、不承诺连接成功。配置与验收属于 PostgreSQL 宿主生命周期，不包含 IAM 领域字段或权限。
- 最小规避是在已确认 GSS 崩溃的 macOS 本机 TCP PostgreSQL、且不依赖 Kerberos 时显式持久化 PGGSSENCMODE=disable；模板保持注释，远端/混合连接及其他平台不自动改值，既有显式值不覆盖。一次启动环境不能代替完整停止后仍可加载的持久配置。
- 回归与回滚：隔离 Worker 回归已完成；真实原账号登录、后续升级及完整停止/启动后的登录仍须单独验收。appender 只追加该键，裸/单双引号及 export 的显式键均拒绝覆写；原文件备份在任务私密 0700 目录、0600 文件，实际应用和恢复备份仍受宿主配置授权限制。
- 证据在 /private/tmp/sandadmin-runtime-fork-20261001（neutral-pdo-fork.php、reload-result.json、persist-local-gss-mode.py）；源候选 /private/tmp/sandadmin-pg-gss-runtime-20261001，Wiki 仅既有 troubleshooting 页草稿 /private/tmp/sandadmin-gss-wiki-20261001。原生修复、真实登录、源发布与 Wiki 推送未完成；不同上下文 Astra 复核通过，主控已核对固定候选。持久宿主配置写入被平台 auto-review 拒绝，主控已请求用户批准；本子任务未执行真实宿主写入。

## 根路由恢复提供方论证（2026-10-03，独立审查通过）

- 所有者与基线：Core 公开 main `e903c1b3b3a5cebfc8d12cea29e0bacc9f1e5f96` 与最新 0.1.8 均未修复；ttt 宿主锁 Core 0.1.7@6412b78、Package 0.1.5@62bd832，其两文件本地补丁不匹配 vendor 或发布清单。不能称公开包已修复。
- 通用性：真实 Vue Router、Registry、Transformer、Validator、MenuProcessor、menu/user store 和首页选择，使用无 IAM 字段的 System/User 菜单；仅隔离 HTTP、组件模块、UI 服务。公开 main 1/7 通过，宿主已有补丁 7/7。根因是初始化失败后将 catch-all matched 当有效页面、重新登录未清旧失败状态、旧计时器/请求污染新会话。证据 `/private/tmp/sand-root-route-diagnostic-20261003/`。
- 方案：IAM 菜单配置不能修复宿主状态机，不在调用方绕过权限或注入根路由。最小提供方范围为 `sandadmin-artd/src/router/guards/beforeEach.ts`、`src/store/modules/user.ts`、`tests/login-route-recovery-test.cjs`，不改 API、数据库、IAM 领域权限或依赖方向。保持未登录转登录、403/空菜单拒绝且不自动重试、未知路径404、新登录重建、旧会话隔离。
- 未参与实现的 Astra `astramedium__repository_independent_acceptance` 独立复跑 public main 1/7、host patched 7/7，确认通用性和 fail-closed，审查通过后才实施独立源码候选。
- 验收/回滚：macOS 与物理 Windows 同代码回归、独立 Astra 验收及主控已登录浏览器根路由验证分别记录；代码可回退两文件。发布/宿主消费需明确授权和备份校验，不执行迁移或重启。当前未重跑真实 IAM 安装，离线/API200不能代替。

## 0.8.0 目标引导设计（2026-10-03）

使用 hasAuth 和服务端可见应用，不自选管理员身份。唯一可见应用重新读取核验后复用，多应用选择，空与失败分开。目标主区域仅展示当前一步，全部步骤折叠。已有记录只标待验证；URL/sessionStorage仅保存目标、步骤和非敏感对象编号，恢复重新读取归属。配置页TaskContinuation返回目标及下一步；第二目标复用同一应用。机器路线才需要环境，不含人员登录；企业登录与目录同步分开。开发者说明明确HTTP decide需要本地或OAuth用户令牌。候选fixture浏览器交互和真实后端消费分层验收，原始证据在/private/tmp/iam080-guidance-20261003。

本轮推导顺序是产品初衷与已具备能力 → 用户要达成的目标 → 对应必需及可选能力 → 按依赖与已有状态提供引导。权限与范围是办理约束，不是要求用户每次先选角色的固定模板。六目标分别对应身份认证、授权决策与数据范围、机器服务访问、联合认证、目录生命周期和接口治理；邀请/注册、连接器/SCIM是具体业务分支。

### 8.4 真实空应用前置配置修复（2026-10-03）

应用管理员从总览选择“控制权限和数据范围”，使用开发者公开交付的资源与动作代码，须能先复用或登记资源、动作，再给已有身份配置策略。动作属于应用内真实目录，声明不授予权限，不将身份选择当成提权。原真实 app20 证据 `/private/tmp/iam080-ui-cold/.playwright-cli/page-2026-10-03T05-40-22-161Z.yml` 显示策略保存因未声明动作返回 409；原 8 步遗漏此前提。

权限目标在资源后增加业务动作目录步骤；接口目标按后端必填引用补资源前置。策略和接口复用同一动作选择控件，以当前应用查询已有目录、显示名称及准确代码、禁用停用项，并解释未声明历史值；不手填猜测。缺失时提供保留应用上下文的新标签目录入口，返回刷新继续原草稿。已有启用动作直接复用，配置不要求重复创建。机器目标复用现有调用身份的服务动作候选，缺失能力交由服务提供方或平台管理员准备。

表单保存由页面内准确业务错误统一反馈，不触发把 HTTP409 映射成服务器错误的通用 toast。选项加载、跨应用旧响应和无效动作不得形成可提交的假有效状态。后端严格声明与授权契约保持不变。源码检查和组件逻辑测试不能替代标准包安装后真实冷路径独立复验，8.4 继续保持未通过。

### 8.4 内置门户前置条件与自有登录页面（2026-10-03）

Windows 标准 0.8.1 安装后的独立实际登录目标发现：认证策略与部署注册开关具备时，内置门户仍因当前应用缺少启用的 application experience 返回 404；手动配置现有“登录外观”后才可实际注册登录。本轮仅修正目标引导和相关提示，不改后端默认、权限、数据库或宿主。

应用管理员先按现有条件选择“使用内置门户，邀请开户”“使用内置门户，自行注册”或“使用已有的自有登录页面”。内置两分支在认证策略后核对当前应用启用的登录外观，复用既有页面/API、权限和应用上下文；有停用记录先核对恢复，无记录才创建，注册方式/登录方式与认证策略及部署开关一致，保存后需实际打开门户验证。自有页面分支不要求门户外观或重复开户，复用公开开发接入后从自有入口验证用户登录。登录外观是门户前置配置，不能视为可选装饰步骤，也不适用于所有接入应用。

门户错误反馈只对尚未完成的密码登录请求、HTTP401 且准确 `SAND_IAM_AUTHENTICATION_FAILED` 组合提示“登录未成功，请核对应用、账号和密码”；不区分账号不存在或密码错误，不修改其他接口、失效会话或已成功登录后后续请求的 401 语义。原 0.8.1 包和安装副本保持冻结，生成的门户脚本由当前源码构建并一起交独立审查；真实 UI 复验另行执行。

### 8.4 策略编辑状态与真实运行版本（2026-10-03）

Windows 独立真实业务验收证实：新建策略仅保存 state=published/status=1 时，版本历史为空且本人允许用例仍拒绝；执行既有“发布”后生成版本/当前指针，相同路由、身份、token 的本人允许与范围外拒绝均按预期。Policy/index 完整行含 published_version_id；运行加载按 status=1 与正发布指针选不可变快照，编辑 state 字符串不是已运行的证据。

策略页面专用编辑字段不再提交 state；创建沿既有数据库 draft 默认，更新沿既有仅处理显式 payload 字段行为，不修改后端语义。列表以真实发布指针区分尚未发布、已发布启用、已发布暂停、缺失/异常无法确认；不把指针 ID 误当业务版本序号。保存反馈说明当前编辑内容不会替换已发布运行版本，启停立即生效；用户执行现有权限保护的发布按钮后，到既有版本历史核对当前指针，再执行真实允许/拒绝。通用列表仅支持可选列格式化和可选保存/发布成功文案，其他页面默认行为保留。

### 8.5 策略空条件保存后再次编辑（2026-10-03）

Windows 0.8.2 实际策略创建与发布通过后，列表返回合法 `condition: []`；再次编辑仅调整范围或优先级时，条件编辑器却按非法对象阻断提交。Policy 的关联 JSON 解码会把空对象读回 PHP 空数组，既有 ScopeMatcher 将空约束视为不限制；前端必须支持这条往返。

仅条件/范围专用解析入口将合法 JSON 空数组 `[]` 规范为既有提交形状 `{}`，ConditionEditor 读取与 ResourceEditor 提交继续共用该入口。普通 JSON 对象解析仍拒绝数组；非空数组、`null` 字面量、未知操作符与畸形内容仍显示错误、保留原值并阻止提交。行字段 null/undefined、空字符串和空对象的既有处理保持不变。不修改表达式能力、后端授权或保存/发布语义。
