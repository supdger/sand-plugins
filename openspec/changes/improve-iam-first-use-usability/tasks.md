## 1. 规划与兼容基线

- [x] 1.1 固定 UX-01–UX-09、COMPAT-01–COMPAT-03、角色任务和无数据库/新API边界；以本变更 proposal/specs/design 文件齐备并通过 openspec validate 为完成证据。
- [x] 1.2 核对 SandAI 精确锁定的 IAM 版本/摘要、真实负载路径与源码 metadata，运行适用无数据库消费回归；记录原始证据在 /private/tmp，任务内只保存结论和路径，区别未执行宿主升级。

## 2. 管理端实现（UI 工作包）

- [x] 2.1 UX-01/07/08：首次向导保留客户主体/应用/环境，增加连续任务入口；TaskPath 仅在归属可验证时按当前应用读取，记录存在不宣称可用；helper 回归覆盖无上下文、正整数校验、不匹配上下文、应用切换、空应用、跨应用已有记录、无权限和失败。
- [x] 2.2 UX-02/03/05/08：修正账号开通、资源/动作/条件/受众等说明与示例，ResourceEditor 受控归属预填、切换清理、凭证归属摘要；审查实际diff确保没有新增机器字段或修改只写不读行为，随后由独立验收从入口检查。
- [x] 2.3 UX-06：识别具体安全校验码及不可变冲突并给正确恢复动作，未知错误安全回退；helper 行为回归覆盖已识别码、未知409和敏感文本不泄露。
- [x] 2.4 UX-09：在现有 equals/in 协议内修复值类型往返保真、类型选择与非法输入阻断；helper 行为回归验证数字/布尔/null/字符串及无效数字，保持后端严格匹配语义。
- [x] 2.5 运行已安装依赖下 iam-helpers、适用隔离类型检查/构建和差异检查；记录实际通过与现有环境缺口，不将构建当作浏览器可用证据。

## 3. 人员及服务接入文档（文档工作包）

- [x] 3.1 UX-02/03/04：核对现有注册/邀请/门户/登录SDK/API，修正受影响最小README和源码相邻示例；管理员→员工设密登录→开发者取token/鉴权步骤及静态数值范围有真实字段来源、预期结果和失败恢复；沿说明运行适用离线示例并记录真人/HTTP未验证边界。
- [x] 3.2 UX-05/08：完善行业中立服务provider配置单、受众一致、凭证安全交接、调用允许/错误受众/无权动作/撤销说明；运行适用离线示例，核对不改协议或安全校验。
- [x] 3.3 按当前README/Wiki边界只同步受影响内容，详细Wiki草稿留仓库外；独立文档读者模拟从公开入口查找输入来源，保留发布权限与未实际公开状态。

## 4. 独立验收与交付核对

- [x] 4.1 固定前端候选，由未参与实现的 Astra 在真实浏览器中验收本轮本地 fixture 组件与引导的入口、角色、空/失败/无权、相关尺寸及键盘；勾选只表示该有界本地验收通过，真实宿主、后端授权及员工完整任务未验收，详见 Evidence。
- [x] 4.2 核对本任务实际文件差异：数据库/迁移/生命周期SQL、runtime/SDK、SandAI消费契约均无本次变更；结合1.2结果说明源码兼容与包/真实升级未验证，不据此宣称已适配生产。
- [x] 4.3 核对独立文档与 UI 验收结果，修复本轮本地切片缺陷并定向复验至无已知 must-fix；记录已修改/本地已验证/未提交/未打包发布或部署/未线上验证及模型。此勾选不覆盖真实宿主、业务闭环、升级和公开 Wiki 验收。

## Evidence

- 3.1–3.3（2026-09-30）：10 个受影响 Markdown 固定 v2 候选，原始字节、任务差异与摘要位于 `/private/tmp/sandiam-usability-docs-20260930/{baseline,candidate-v2.patch,candidate-v2.json}`。核对既有 API/SDK 后补齐邀请或注册、登录取令牌、双员工允许/拒绝、静态数字范围及旧令牌撤销的分工与预期；服务说明补齐 provider-b/document.process 配置单及调用/撤销路径。8 个 PHP/shell 代码块语法通过；真实 PHP SDK 以替代 transport 离线验证允许、拒绝、登录失败和 MFA 分支，通过且不打印密码/令牌。既有 PHP SDK、独立工作项 consumer、provider（17/17）及机器 HTTP client 离线测试通过；未实际运行登录/注册/业务 HTTP、数据库或升级。
- 3.3：主控交回独立文档读者 v2 复验通过：旧会话撤销与重新登录语义、可查审计的非敏感 requestId 两项修复关闭，10/10 候选摘要一致；该结果是文档任务模拟，非真人 HTTP 操作。48 个源码相对链接、14 个源码标题目标检查通过；Wiki 草稿仅置于 `/private/tmp/sandiam-usability-docs-20260930/wiki`，基于本地 clean Wiki revision `0c12d8948dba607781964284edaac7c0ed9b64e7` 替换 9 页，24 页/156 本地链接检查 0 错误。外链与公开 Wiki 渲染未验证，未推送发布；证据及映射见同目录 `validation/` 和 `wiki-source-map.json`。
- 1.1：OpenSpec strict validate 通过，apply ready；此结果仅证明规格齐备。
- 1.2（2026-09-30）：SandAI 锁定的本地 `sand-ai/dependencies/sand-iam-0.7.5.zip` SHA-256 与 config 相符：`7d18eb8370e878291eb6c4223ad4b97798329edfa8422a4ac5272649ed7125cf`。本次实现前保存的管理端基线中，ZIP 所含 105 个产品源文件全部同字节；另 37 个本地测试不在包中。294 个后端 app 文件中 293 同字节，唯一差异是路由同步器局部变量名；SandAI 所消费的七个身份/授权/引用/目录端口同字节。授权消费离线回归的工作流 15/15、知识查询 3/3、任务操作 12/12、文件权限 8/8、签名上下文 5/5 通过；IAM 授权/重验/拒绝恢复、目录和调用身份引用（11/11）通过。相邻 Agent 来源重验测试未通过：既有 SandAI 测试夹具缺 `content_sha256/content`，在应用事实校验处被拒绝，尚未进入 IAM 授权；未扩改 SandAI。原始包 provenance、摘要核对及已执行测试结果摘要：`/private/tmp/iam-usability-compat-20260930/`。
- 1.2 版本/升级限制：本仓 metadata 为 0.7.3；0.7.5 包 provenance 为 `source_clean=false`、source commit `0239981af57155c67af321c4255a33609eb7fed0`，本地仓库无该 Git 对象，不能据此宣称可从 clean ref 重现发行包。本地旧源码 lifecycle 与锁定 0.7.5 有差异；0.7.5 update 是只读核验已发布 0.7.3 的 43 条账本（042 为 permission_menu_hierarchy），不能拿当前旧 update 替换。当前 `/Users/code/project/sand_ai/server` 未发现 SandIAM 安装负载；历史 `.acceptance` 副本不计当前安装。未执行数据库、安装、升级、服务、打包、发布或 SandAI 宿主修改，真实升级仍待独立验收。

- 4.2（初版固定 UI 候选，历史证据）：manifest SHA-256 `1b8b0f12e20063d2c98abf3659ea754864b77a313d54f27c2196d5268d231868`。与本轮保存的 607 项保护源基线逐字节摘要核对：SQL/pgsql、后端 app/config、metadata 及 SDK 代码零变化/新增/缺失；SDK 仅 README 受本轮文档优化影响。没有 SandAI 产品写入或依赖锁变更；没有数据库或宿主操作。报告 `fixed-candidate-protected-diff.json` 位于 1.2 证据目录。15 项应用计数 endpoint 逐项核实真实过滤：11 项继承通用 writeFields application_id 过滤及应用访问范围，client/grant/credential 使用应用→环境→调用身份关联 whereIn，identity-provider 使用应用挂载 whereIn；均与访问范围相交。现有 machine_resource_filters_non_pg_test 实际通过，覆盖机器三类过滤及越权拒绝。源级兼容成立；未安装或升级候选，保留 COMPAT-02/03 的发行来源和真实升级缺口。

- 2.1–2.5（2026-09-30，UI 实现）：固定源清单位于 `/private/tmp/iam-ux-build/candidate-manifest.json`，147 项文件，SHA-256 `1b8b0f12e20063d2c98abf3659ea754864b77a313d54f27c2196d5268d231868`。已实现向导归属传递、可见层级复核、应用范围计数与“有记录，待验证”、连续登录/权限/调用步骤、创建预填及应用切换清理、一次性凭证非敏感摘要、精确安全错误恢复和 equals/in 标量类型保真。原始源基线在 `/private/tmp/iam-ux-implementation-baseline/frontend`；没有新增后端机器字段或改变凭证明文生命周期。
- 2.1/2.3/2.4：使用已安装依赖执行 `node --import /Users/code/project/sand_demo/sandadmin-artd/node_modules/tsx/dist/loader.mjs sand-iam/sandadmin-artd/tests/human-ux-helper-contract.test.ts` 与同目录 `error-recovery-contract.test.ts`，均通过。新增回归覆盖无上下文、非法 ID、不同应用的过滤参数、归属不匹配、停用/无权/网络失败、数字/布尔/null/文字及混合列表逐值往返、无效数字/布尔、受众不一致、不可变授权、未知 409 与敏感文本安全回退。原 `scripts/test-local.sh iam-helpers` 的相对依赖路径 `sandadmin-demo/` 当前不存在，因此复用真实已安装 loader 执行同一 helper 文件，未宣称原脚本通过；错误回归以宿主 HTTP 错误识别适配器替身隔离 UI 映射，不访问后台。
- 2.5：权威源复制至仓库外 `/private/tmp/iam-ux-build` 后，执行 `node_modules/.bin/vue-tsc --noEmit -p tsconfig.json` 通过；`node node_modules/vite/bin/vite.js build --configLoader runner` 通过（3167 模块，15.35 秒），日志 `/private/tmp/iam-ux-build/build.log`。仅隔离副本补充 runner 所需 `__dirname`，避免默认 loader 向只读宿主依赖写 `.vite-temp`；宿主源码与依赖未修改。构建保留既有宿主菜单组件静态/动态导入警告。`git diff --check -- sand-iam/sandadmin-artd` 通过。
- 2.1/2.2 证据边界：以上勾选记录前端实现、helper 与构建结果；空应用/跨应用计数的真实呈现、导航、预填、凭证窗口、角色和异常交互须由不同上下文 Astra 在固定 fixture 浏览器候选中继续验收，当时 4.1/4.3 保持待完成；后续本轮本地切片的最终状态见下文 V4 验收记录。未进行真实宿主登录、业务调用、数据库、部署或线上验证。

## Final bounded acceptance

本变更的 13 项勾选表示本轮源码优化、离线回归、文档模拟及本地 fixture 真实浏览器组件/引导验收完成。该分母不是实际宿主安装、员工真实登录、业务/服务允许与拒绝、数据与授权持久化、升级或生产发布的完成率，不能据此宣称 IAM 或 SandAI 的生产闭环完成。

- 2.1–2.5 最终 V4：`/private/tmp/iam-ux-build/candidate-manifest-v4.json` SHA-256 `abef68168080ce3585286bdd63cf59d59ef224d306081fc32604b168994f346d`；最终工作区 147/147 项源摘要一致。最新生产构建日志 `build-v4.log` 显示构建成功，耗时 14.75 秒；实现者报告直接 helper/error 恢复回归和完整 vue-tsc 已通过。已通过项未重复全量执行，V1→V2→V3→V4 保留原始候选和定向返工证据。
- 4.1 首轮 cold failure 保留：独立 Astra/high 从首页首次使用入口操作时，完成环境选择触发向导 slot 生命周期空值异常，阻断目标按钮。V2 修复局部步骤快照后，从清空 fixture 草稿重走三层选择，目标按钮可用且 query 保留 organization=1/application=10/environment=100；零浏览器错误/警告。该记录是首次失败后的修复复验，不改写为首次成功或真人测试。
- 4.1 V3：修复注册帮助与邀请/手工用户无密码之间的矛盾，复验可见说明一致。独立 Chromium 验收覆盖空应用不被其他应用记录标记完成、应用归属传递/预填、fixture 邀请保存、标量及混合列表类型往返、非法数值阻断、一次性凭证非敏感归属摘要、Escape 关闭确认和明文离开 DOM、角色控制、503/通用409恢复、1440×900/1280×720及键盘选择。
- 4.1 V3 新失败与 V4 修复：补齐既有 fixture 服务/动作/受众前提后，特定不可变授权及受众错误提示被当前编辑窗口遮挡。V4 将提交错误置于活动窗口内，自动聚焦并滚入视野，保留输入；独立复验两种专用错误和相邻无效数值分支通过。先前 fixture 前提缺口与 V3 失败均保留为历史记录，最后无已知本轮 must-fix。完整独立报告及原始截图/DOM/console 位于 `/private/tmp/iam-ux-browser-review/acceptance-report.md`；fixture 控制器响应不证明真实后端授权或协议成功。
- 3.1–3.3/4.3：独立文档 V2 读者模拟完成且无剩余必修项；10/10 工作区文档摘要与 `candidate-v2.json` 相同，其 manifest SHA-256 为 `169abff3904ccd06b8c41f11b8fa319d275e33412e51c36c72ae8493aad2836b`。旧令牌撤销与重新登录语义、非敏感 requestId 两项修复已关闭。离线代码块/SDK/工作项/provider及本地链接结果沿用前文已执行证据；公开 Wiki 尚未推送或验证。
- 4.2 最终 V4+docV2：607 项保护源基线中，数据库/SQL/迁移/lifecycle、后端 app/config、metadata 及 SDK 代码零本轮变化/新增/缺失，SDK 只允许 README 文档修改；SandAI 锁定仍为 IAM 0.7.5、bundle SHA-256 `7d18eb8370e878291eb6c4223ad4b97798329edfa8422a4ac5272649ed7125cf`，包内容摘要未变。机器消费契约未改。本轮终核结果 `/private/tmp/iam-usability-compat-20260930/final-v4-docv2-integrity.json` 为 PASS；历史 dirty 不归因本轮。
- 最终状态：已修改权威管理端、相关 helper 测试与受影响说明；本地源码/离线/文档模拟/fixture 浏览器有界验收已验证；本任务未提交、未生成升级发行包、未部署、未修改 SandAI 宿主或依赖版本、未公开 Wiki，未线上验证。真实员工邀请送达/注册/登录、实际业务和机器服务允许/拒绝/撤销/审计、实际后端隔离和升级仍未验证。0.7.3 metadata 与锁包0.7.5的来源/生命周期差异保留前文限制，不能拿旧目录直接构包升级；用户后续真实升级仍须针对准确基线验证。

## 0.7.6 preview 发布后续（2026-09-30）

用户在上述有界 UX 验收后明确要求发布。以下为新增发布结果，此前未发布状态保留为当时历史：

- 官方独立仓库 clean commit `5c5e19c06ba39d45e670d9d5b80e5d0dd965e8bf`、feature `codex/iam-usability-0.7.6` 与固定 tag `v0.7.6-preview` 已推送；未推 main。公开 Release：https://github.com/supdger/sand-iam/releases/tag/v0.7.6-preview 。
- 从该 commit 两次独立 Git blobs 构建，706 条目及 ZIP 字节相同。公开回下载 ZIP、manifest、SHA256SUMS 与候选逐字相同，ZIP SHA-256 `7a04aff8a3fa6c9cd3ea537f459f40fc739cb7266c204f9aa46e37a6026814c5`。本次 unsigned preview，未执行旧 formal Composer/TypeScript 依赖重建或 Ed25519 签名，不宣称完整产品正式发行门槛通过。
- 43 个历史迁移与官方历史逐字一致；后端运行代码与已核验 0.7.5 锁包相同。076 更新仅只读核对已发布 0.7.3 的完整 43 条账本及结构，不增加业务 DDL/DML。根/插件 metadata 与 lifecycle 对齐。
- 公开 SandPackage 0.1.9、固定宿主 `7c1113255fc8c3105517e9ce262302c60931e6d9` 与任务专用 PostgreSQL 18 中，最终包普通 073→076、075→076 均通过；基线是真实来源 SQL 加已有安装登记，073 SQL 来自官方 Git 0239981，非 073/075 fresh 上传验收。108 张表（IAM 86）和 43 条账本、结构/数据/序列摘要前后相同，420 backend 与 103 UI 源码文件摘要匹配。
- 错误确认、同版本重复升级、缺失 042 及 042 摘要冲突均拒绝；异常账本下数据库与旧运行文件不变，公开 019 留下 state 8 且直接重试拒绝，未证明普通升级自动恢复。服务重启禁用，未构建/激活宿主前端、未验真实 HTTP 业务或 SandAI 在线消费。
- 独立 Astra 核验来源、包、限定文档修复及运行证据后允许公开 preview。UI V4 未改；README 版本/升级说明、安装升级和包核验两页为本次必要发布增量。
- 证据：`/private/tmp/iam076-release-final`、`/private/tmp/iam076-upgrade-20260930/evidence`。目录条目仅备于 `catalog-entry.proposed.json`；未推 SandAdmin catalog、Wiki，未修改 SandAI 0.7.5 锁，未部署生产。

## 发布后跨平台核验（2026-09-30）

用户提醒 macOS/Windows 的标点、路径与执行差异。本轮只修复构建工具和两页受影响文档，不改业务 PHP、SQL、迁移、SandAI 依赖锁或已发布 ZIP。

- 已修复旧构建器拒绝 Windows 盘符路径和 shell 参数转义风险，Git 命令直接接收参数数组，读取 NUL 分隔的 Git 文件名及原始 blob。文档分别给出可执行 macOS/PowerShell 命令，说明旧 `5c5e19c` 构建器限制、可信修复源码与固定旧包载荷复核的区别，以及 BOM SQL 的安全拒绝。
- 修复源已公开在官方 feature `codex/iam076-cross-platform-regression`，固定 commit `e1b2af06f5ee578303999344fd7ca70b244ed9eb`；未推 main。CI：https://github.com/supdger/sand-iam/actions/runs/36734826308 。前两轮只分别失败于测试入口未识别缩进代码块、Windows 临时 Git 对象只读清理；失败记录保留，不改写为产品 SQL 或构建器执行失败。
- Windows Server 2022 原生 CI 已通过，PHP `8.2.34`、PostgreSQL `14.24`、zip `1.21.1`、libzip `1.11.4`：公开 076 下载与实际 PowerShell 文档命令通过；固定旧源码仅替换构建工具后的全部 706 项载荷摘要与原发布清单一致，重复构建 ZIP 字节一致。不同环境 ZIP SHA-256 不同不冒充跨环境整包字节相同。
- 原生 Windows 的中文/空格/`%`/`!` 路径、二进制、CRLF、BOM 与数据标点保真，错误目标路径、盘符相对路径、当前驱动器根路径、仓库内目标和 dirty source 均拒绝。公开 SandPackage `0.1.9` 执行器在真实 PostgreSQL 中执行原 LF/CRLF 076 SQL，两者均为 `READ ONLY`，108 张表、43 条账本及结构/数据/序列摘要前后相同；BOM `42601`、缺失/冲突账本 `P0001`、只读写入 `25006` 按预期拒绝，SQL 字面量中的 `; : / \` 保留。临时夹具与 PostgreSQL 实例已清理。
- macOS 本机真实 PHP 的最小正反例、中文路径和字节保真通过，完整修复候选的 706 项双构建通过；SQL 专用 Docker PostgreSQL 18 回归通过。当前同轮 macOS CI 仍在准备环境，不能据此报告 CI 已通过。Windows 用户实机、UNC 网络共享、管理器 HTTP/界面、完整业务链和生产部署不属于已验证结果。
- 原始证据：`/private/tmp/iam076-windows-native-evidence/iam076-sql-evidence/sql-summary.json`、`/private/tmp/iam076-windows-native-evidence/iam076-release-evidence/rebuilt-manifest.json`、`/private/tmp/iam076-windows-third-job.log`、`/private/tmp/iam076-cross-platform-localbuild/manifest.json`、`/private/tmp/iam076-cross-platform-sql/local-summary.json`。公开 `v0.7.6-preview` ZIP 与 tag 保持原值，此源码/文档修复尚未随新 Release 或 Wiki 交付。

## 5. 升级后登录/GSS 故障跟进

- [x] 5.1 固定版本及升级重载链，核对官方 libpq 与发布差异；保留默认两组 PASS 的因果限制，不把规避成功当完整 native 根因。
- [x] 5.2 在公开 SandAdmin main 隔离候选补注释化 .env、最小 README/Wiki 草稿与实际重载回归，不新增空 helper 或全平台默认。
- [x] 5.3 零插件 Worker 初始→SIGUSR1→新 Worker 的 disable/prefer/require 三组通过；disable 只 connect/disconnect 2/2，无 SQL，其余只核对值。
- [x] 5.4 appender 的私密备份、拒绝裸/quoted/export 显式值、重复拒绝和不输出凭据 fixture 通过；不自行应用实际宿主。
- [x] 5.5 不同上下文 Astra 复核通过：配置边界、真实重载证据、quoted key 必修后 appender 与私密备份通过，Wiki 已收窄为重载配置回归。新目标发布权限与真实宿主配置批准仍由主控管理，不在本子任务执行。
- [ ] 5.6 获授权后完成真实持久配置、原账号登录与升级/完整启动后登录验收；连接和源码结果不能替代。

## 6. SandAI 既有 schema 接入声明跟进（SandIAM #5）

- [x] 6.1 将根 existing-schema 声明绑定当前 0.7.6 SQL 与已发布073的43条账本；保留 embedded073 的42条基线供核对，不修改生命周期SQL。
- [x] 6.2 将声明纳入统一payload政策，添加版本/SQL/账本/结构正反例与打包入口检查。
- [x] 6.3 在现有sandai数据库只读事务中确认身份及42条账本、86表和旧指纹匹配；记录它尚不满足076接入，不执行bridge或升级。
- [ ] 6.4 正式SandPackage/宿主接入基线可用后，备份并受控接入已冻结embedded073工件，明确授权执行现有bridge，再核对43条账本并升级076；本轮未执行。

定向契约7/7及payload hygiene16/16通过；完整旧checker的5项预存失败已用修改前快照复跑确认（21/26→本轮新增通过后22/27），不作为正式发行通过。候选仍为dirty工作树审查快照，不提交、推送或改变公开preview工件。

### Issue5 packaging-tool follow-up

Current076 lifecycle generation now exactly matches all3 frozen SQL files; legacy embedded073 generates the same3 digests as the untouched historical builder in an isolated fixture. Current checker distinguishes076 read-only upgrade and043 baseline from historical072→073, validates042 self-checksum, current dependency root version and declared host-support syntax. Frozen dependency bytes remain locked; explicit rebuild-toolchain mode still rejects this machine's differing PHP/zip versions. Clean tracked HEAD remains a required, unmet source-provenance gate in this mixed working tree. No lifecycle SQL, vendor or SDK bytes were changed.

## 7. 安装后根路由 404 的 Core 修复

- [x] 7.1 固定公开 Core 与宿主本地补丁差异，以真实路由链中立复现并经独立 Astra 提供方通用性审查通过，详见 design。
- [x] 7.2 独立 Core 候选落实两文件修复，补真实注册链回归、类型检查、独立验收与物理 Windows 同代码回归。
- [ ] 7.3 获明确授权后提交/合并 Core、发布及备份后宿主消费更新，核对清单与浏览器原入口；不把离线通过当真实插件安装通过。

7.2 证据（2026-10-03）：独立 Core 候选 `/private/tmp/sand-core-root-recovery-20261003` 基于公开 main e903c1b；macOS 和物理 Windows Node 20.14.0 均 7/7，未参与实现的 Astra 固定候选复验 7/7。完整 vue-tsc --noEmit 在复用同依赖生成声明后通过，差异检查和 OpenSpec strict 通过；未宣称 Vite 构建通过。四文件固定摘要与 patch 在 `/private/tmp/sand-root-route-diagnostic-20261003/`。Windows 是源码/memoryHistory 验证，不是 HTTP/浏览器/插件安装。Core 尚未提交、发布、同步宿主，7.3 保持未完成。

7.2 构建补验（2026-10-03）：将候选 node_modules 的缓存目录隔离到本任务临时目录，正式 Vite 配置和冻结四文件均未改；Vite 7.1.7 production build exit 0，2976 模块，16.58 秒，338 个产物。随后使用该构建自行生成的声明执行完整 vue-tsc --noEmit，exit 0。构建仅使用官方 example 环境副本且已精确删除副本，未写宿主依赖或缓存、未启停服务。产物摘要清单 `/private/tmp/sand-root-route-diagnostic-20261003/build-dist-sha256.json`，SHA-256 `99c4b562ffaad2fea0c7d373e3da8a849ffe82c2646398f8ddf0a0dd08437db6`。此前构建缺口已补齐，发布与宿主消费仍未执行。

## 8. 0.8.0 目标引导

- [x] 8.1 按已知授权与可见应用缩小范围；六目标拆分，复用对象和第二目标。
- [x] 8.2 当前步骤说明输入来源、责任方、结果、恢复和下一步；跨页恢复，无权不可操作。
- [x] 8.3 helpers、适用类型与构建通过；固定候选提供隔离浏览器入口。
- [ ] 8.4 独立冷读浏览器验收及缺陷复验，记录真实业务与宿主未验范围。

7.3 发布与消费进展（2026-10-03，用户已明确确认本轮完整范围）：Core 源码 ae7c66f 经 PR https://github.com/supdger/sand-core/pull/8 正常合并，公开 main 与 tag 0.1.9 均指向 b2353810d8c634c9c47f2636f8719f68ea17c02f；正式 Release https://github.com/supdger/sand-core/releases/tag/0.1.9 与 Packagist 版本/ref 已回读一致。ttt 宿主备份于 `/private/tmp/sand-core-host-backup-20261003-0.1.9`，Composer 精确只更新 Core 0.1.7→0.1.9；lock、installed、前端清单一致，399 个前端文件和 Core 后端对 vendor 校验通过，IAM/Package/环境保持摘要不变。无 SQL、无服务重启。主控在原已登录页面验证 `/#/` 到工作台、刷新和 IAM 管理总览，warn/error 为空；截图及更新日志在 `/private/tmp/sand-root-route-diagnostic-20261003/`。实际退出重登等待用户完成，真实 IAM 重新安装未执行，7.3 暂不勾选。已清理本任务 Composer 缓存及候选依赖链接目录；保留源码、构建产物、证据和私密回滚备份。

8.1–8.3 证据（2026-10-03）：V3 管理端149项固定源码清单 `/private/tmp/iam080-guidance-20261003/harness-v3/candidate-v3-manifest.json`（SHA256 `f5e68c346856f2130cc9c8fb5994bb35770260e47737cf4449be64b50861adcb`），完整 vue-tsc、Vite 7.1.7 production build 3171模块/22.12秒、既有UX helper与新增goal分支/无权/环境/受众行为case通过；同源及build逐项对账通过。独立浏览器V1已发现重复选择调用身份/受众，V3修正后待复验；8.4未勾选。fixture不连接真实后端，developer文档由真实静态目录生成器导出，stub只覆盖界面响应形状。首次client受众在现有service/action目录中无发布字段，仍由提供方交付；grant受众从已读client带入，不凭名称猜测。

8.4 信息边界：实现者曾向独立验收上下文提供V1已知缺口及V2修复摘要，因此受该提示影响的后续检查必须记为独立复验，不追认成无提示首次冷读。原始首次路径、失败与提示时间由主控的验收记录保留。

8.4 真实宿主第三处阻断返工（2026-10-03）：app20 权限目标遗漏动作声明，策略手填 `inspect` 后真实保存返回 HTTP409 `SAND_IAM_APPLICATION_ACTION_UNDECLARED`，表单与通用 toast 原因冲突。已补权限目标动作前置、接口目标资源前置，并将策略/接口动作改为当前应用真实目录选择；停用/未声明旧值可见但不可提交，缺失时保留草稿打开应用动作目录后刷新。已有动作复用，机器目录缺失说明对应提供方职责。表单创建/编辑精确错误统一在弹窗内呈现。

本轮定向 helper、目标分支/导航、动作选择应用隔离/迟到响应/停用旧值/分页/关闭、编辑器准确代码与无效阻断、ResourceList 409 恢复检查通过；完整 vue-tsc 通过，Vite 7.1.7 构建 3175 模块/14.91 秒通过。默认 helper 脚本引用的旧 sandadmin-demo 依赖目录不存在，实际通过已安装的 `/private/tmp/iam080-live-frontend-20261003/new-application-typecheck/node_modules/tsx/dist/loader.mjs` 运行同一测试入口，未安装新依赖。冻结清单及增量 patch 在 `/private/tmp/iam081-prerequisite-fix/`，叠加前两处既有修复。未改 PHP、SQL、宿主、数据库或服务，未提交/发布；新版标准包安装后的独立实际复验待主控继续，8.4 不勾选。

8.4 Windows 实际内置门户第四处阻断返工（2026-10-03）：标准 0.8.1 安装的独立 UI 验收证实，应用认证策略与部署注册开关配置后，缺少启用登录外观仍返回 `EXPERIENCE_NOT_FOUND`；手工登记既有体验页面后才实际注册登录。源码已在内置邀请/注册目标的认证策略后补必要“内置门户登录外观”步骤，以 status=1 核对当前应用，优先复用已有配置；自有登录页面分支改走公开开发接入及原页面验证，不要求内置外观或重复开户。

局部门户错误仅在密码登录请求尚未完成且 HTTP401/`SAND_IAM_AUTHENTICATION_FAILED` 时显示“登录未成功”，不改变其他会话或接口的 401 语义。相关 goal 分支/权限/应用筛选/导航与既有 helper 通过；门户完整 TypeScript 与既有生产代码行为测试通过，新增错误密码实际提交及 token-expired/其他操作反例断言通过；管理端完整 vue-tsc 及 Vite 3175 模块/15.86 秒构建通过。生成的 account.js 从隔离冻结源码运行现有 build.mjs 得到，差异仅本轮错误提示与请求阶段区分，index.html 未变。清单/增量补丁及门户测试日志位于 `/private/tmp/iam081-login-experience-fix/`。未修改后端、数据库、权限引擎、默认配置、安装宿主或 immutable 0.8.1 包，未提交发布；不同上下文源码审查及新候选真实 UI 复验待继续，8.4 保持未勾选。

8.4 策略状态误导第五处返工（2026-10-03）：Windows 实际业务先保存 state=published/status=1 后仍 POLICY_DENIED、历史0；执行正常发布后历史1且本人允许/范围外拒绝实测通过，隔离了“保存≠发布”的前置。仅策略页面已移除手填 state，列表依据现有 API 发布指针显示尚未发布/已发布启用/已发布暂停/未知；保存与发布反馈分开，并指向现有版本历史确认。共享列表只增加可选列格式化和成功文案，默认其他页面行为不变；不改任何发布或授权后端语义。

新增 helper 覆盖 null/0/正整数/数字字符串/缺失异常/停用与未知状态；既有编辑器行为证实创建、修改 payload 不带隐藏 state，既有列表行为证实编辑保存不自动发布、不替换运行指针，正常发布请求及单独反馈保留。上述定向回归、完整 vue-tsc 通过；Vite 3176 模块/15.85 秒构建通过。单独 9 文件增量与最终 OpenSpec 摘要在 `/private/tmp/iam081-policy-publication-fix/`；上一批登录外观/门户 12 文件中的非 OpenSpec 字节全部保留。未改后端/数据库/宿主/原 immutable081 包，未提交发布；仍待不同上下文审查与实际安装 UI 复验，8.4 未勾选。

8.4 空条件往返第六处返工（2026-10-03）：Windows 已安装 0.8.2 的真实策略留空创建、发布及四项业务允许/拒绝通过后，API 返回 `condition: []`，再次编辑被前端误判无法读取且无 HTTP。条件/范围共用解析现只接受合法空数组作为空约束，提交规范为 `{}`；普通对象解析、非空数组和未知/畸形规则保持严格阻断。

实际 ConditionEditor 与 ResourceEditor 脚本联动回归覆盖空创建→模拟已观察到的 `[]` 回读→修改范围/优先级→submit，以及两字段的非法规则不发 submit、不清空原值；同一新增回归在冻结旧解析器上复现失败，在修复后通过。既有 helper、完整 vue-tsc、Vite 3176 模块/15.76 秒构建及 OpenSpec strict 校验通过。此组件与 helper 回归不代替真实 HTTP/UI 验收。产物冻结于 `/private/tmp/iam083-empty-condition-fix/`，仅前端解析器、相关测试及本 OpenSpec；后端、SQL、元数据和 immutable 0.8.2 包未改，独立源码审查及 0.8.3 标准安装后的原生 UI 复验待继续。

## 9. 新克隆的默认 Core 消费入口

- [x] 9.1 核实 SandAdmin 公开 main 5d9cb4e 仍锁 Core 0.1.8；独立候选将最低约束改为 ^0.1.9、锁定 Core 0.1.9@b235381，其余包记录不变。
- [x] 9.2 在无 vendor、无 server/.env 的独立 fresh 副本执行 README 的 composer install，保留全部标准脚本；macOS 102 包安装 exit 0，399 个前端及两修复文件摘要、安装版本/ref 一致，独立 Astra 只读复核通过。无数据库/服务操作，不宣称业务登录或真实插件安装通过。
- [x] 9.3 用户确认后，SandAdmin 两文件修复经 PR #33 正常合并；公开 main bdc8f8e6a1ff4165b04331d795be5338f942ccb8 的两文件摘要等于冻结。再次从公开 main 新克隆按 README 执行 composer install，102 包及标准 hooks 全部成功，Core 0.1.9@b235381、399 前端文件与两修复摘要一致。物理 Windows 的同一冻结候选 fresh 安装由独立子任务验证通过；未把安装验收写成业务登录验收。

本轮候选 `/private/tmp/sandadmin-core-minimum-20261003`，fresh 副本 `/private/tmp/sandadmin-core-fresh-install-20261003`，证据 `/private/tmp/sandadmin-core-minimum-evidence-20261003`。只修改 server/composer.json 和 server/composer.lock；README/Wiki 现有默认命令未改变，无额外文档改写或 Wiki 发布。Composer validate --strict --no-check-publish 通过。源码提交 d6b22eb6225d31e7cc10dd9d7e334f58301019c6，公开 PR https://github.com/supdger/sandadmin/pull/33 已合并。最终公开 main 安装结果及原生日志分别为证据目录中的 public-main-install-result.json、public-main-composer-install.log；最终 fresh 副本为 /private/tmp/sandadmin-public-main-install-20261003。未执行数据库、服务或运行宿主操作，7.3 实际退出重登边界保持原记录。
