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
