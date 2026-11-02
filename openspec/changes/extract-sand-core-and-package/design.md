## Context

本仓是 Sand 插件权威源码集合，当前工作树包含大量其他任务修改；`sand-core` 与 `sand-package` 目录尚不存在。当前 SandAdmin 宿主仍内置核心、安装器、兼容层和完整管理端前端，迁移基线及 SHA-256 清单已由宿主 change 固定。

## Goals / Non-Goals

**Goals:**

- 只新增两个互不覆盖现有目录的权威包。
- 使用统一的顶层 `server/`、`sandadmin-artd/`，不引入顶层 `src/`。
- Composer 包同时携带后端和前端源码，但不运行 pnpm 或编译。
- 逐文件核对宿主来源，保留现有 PHP 命名空间。

**Non-Goals:**

- 不在本 change 修改 SandAdmin 宿主 Composer 或删除宿主内置源码。
- 不修改现有业务插件、数据库或演示宿主。
- 不提交、推送或发布。

## Decisions

### 1. 目录和所有权

```text
sand-core/
+-- server/plugin/sandadmin/
+-- sandadmin-artd/
+-- composer.json

sand-package/
+-- server/plugin/sandpackage/
+-- server/compat/Saithink/Saipackage/
+-- server/tests/SandPackage/
+-- sandadmin-artd/src/views/plugin/sandpackage/
+-- composer.json
```

核心前端排除 SandPackage 专属页面；SandPackage 只携带自己的前端载荷。现有其他脏文件不在本次写入范围。

### 2. Composer 加载

首轮保持 `plugin\sandadmin`、`plugin\sandpackage`、`Saithink\Saipackage` 命名空间。包内路径直接映射到统一 `server/` 目标布局，避免同时进行 namespace 重构。

### 3. 前端源码发布

`sand-core` 提供显式 CLI 工具，将包内 `sandadmin-artd` 复制到用户指定目录。工具以清单记录发布基线：空目录可发布、相同基线可幂等执行、未知修改时拒绝覆盖。工具不安装依赖、不编译、不修改数据库或服务。

### 4. 复制验证

以宿主 change 的四份 SHA-256 清单为来源基线；复制后按目标路径归一化再逐项比对。先保留宿主副本，待宿主 change 完成消费验证后才由宿主侧移除。

## Risks / Trade-offs

- [现有工作树很脏] → 仅新增当前不存在的两个目录及更新明确架构入口，验证 diff 不含其他文件。
- [Composer 包体积增加] → 排除 `node_modules`、缓存、构建输出、环境文件和本地产物。
- [配置发现依赖 Webman 行为] → 提供包级 autoload 探针和后续干净消费者验证，不能只靠 composer validate。
- [发布工具覆盖开发代码] → 默认拒绝未知修改，不提供强制覆盖捷径。

## 2026-10-01 SandAI issue #21 的候选整合边界

此轮针对已存在的 SandPackage host-payload 候选与正式 `0.1.9` 的交付差距。正式版本源码锁定为 `3d5a25363ff75298b7e2fa4689984cb8ac89a287`；本地候选入场时已有 `HostPayload*` 与 `ExistingSchemaManifest`，但缺少正式 Composer 前端自动发布入口并回退了部分 Windows 路径行为。本轮只移植这些正式行为、修正受影响测试夹具和核对候选 Composer 加载，不重新引入 SandAI 业务逻辑，也不修改调用方插件或宿主。

提供方继续负责声明清单、摘要、文件归属、安装/升级/卸载与失败恢复；专用 AI 运行实现归 SandAI。`config/<plugin_stem>_*.php` 的公开泛化校验和中立夹具保持不变。本轮未新增提供方业务契约；已有 host-payload 与 schema 重接实现均作为待独立审查的候选，不能以本轮修复或录制测试替代其正式发布门槛。

SandAdmin #20 关注核心宿主接入已有数据库；本轮不修改核心安装器。已有 schema 重接目前只支持不附额外 host-payload 的插件包，明确拒绝组合包。目标现有数据库为 `sandai`，没有清空、重建或隐式建库路径。回滚以本轮入场文件摘要和正式 `0.1.9` 源码为依据；提交、推送、发布、宿主同步、真实数据库及服务操作仍不在本轮执行范围。

## 2026-10-04 SandPackage issue #11：依赖执行器必要性论证（独立审查通过）

- 真实需求：SandAI 统一 ZIP 在 Core 0.2.0 / Package 0.2.1 / IAM 0.8.4 的标准安装链完成 Composer 依赖，再由安装器进入已安装状态。调用方是 SandAI，提供方是 SandPackage；安装器权威源码归 `sand-package/`。消费端已完成 120 条迁移但停在 Composer，证据为 `/Users/code/project/sand_ai/openspec/changes/unify-sandai-product/evidence.md:4198`。本地执行器入场 SHA-256 由本轮原始验证记录冻结，不把本地未提交候选当作公开 0.2.1。
- 现有能力：公开 SSE 仅接受固定 command key、module-install 标识和 SandAdmin 超级管理员身份；宿主配置只能固定命令和 cwd。真实 `groupLauncherCommand()` / `CHILD_LAUNCHER` 的无数据库最小探针证明：外层只传 PATH 时 HOME / COMPOSER_HOME / COMPOSER_CACHE_DIR 均丢失；即使仅在外层传入正确变量，内层 `pcntl_exec` 仍丢失。探针同时确认实际参数成为 `composer composer update --no-interaction`。不存在调用方可修的公开环境扩展点。
- 替代方案：重启宿主或设置其 COMPOSER_HOME 无法穿透两次环境替换；命令配置添加 env/包装器会被固定 argv 校验拒绝；手动 Composer 或修改宿主已安装副本绕过标准 SSE、进程账本和完成 nonce，不能作为标准安装成功。必要性依据为上述真实失败，不要求虚构第二消费者。
- 最小提供方变更：`TerminalRunner.php` 的 `proc_open` 使用 `null` 继承宿主执行进程环境；内层 `pcntl_exec` 以 `getenv()` 传递相同的进程环境，并使用 `array_slice($args, 1)` 去除已由 executable 参数承担的首项。不将 HTTP 请求、JWT、插件数据或 PHP `$_ENV` 合并为环境；不输出环境内容，不新增 API/依赖/配置。宿主已有 Composer HOME/cache/auth、代理、CA 和前端包管理配置继续有效，宿主进程仍须由管理员合理配置。
- 安全与兼容边界：固定命令、cwd 根及符号链接拒绝、超级管理员权限、进程组隔离、GO 确认、nonce/租约/锁、脱敏、输出与时限、失败守卫均保留。专用 AI 业务不进入 SandPackage。该变更影响所有受支持的 Composer 与前端依赖命令，不改变数据库、载荷归属或安装/升级/卸载协议；状态 4 未完成依赖时拒绝卸载/恢复本属守卫，不放宽以掩盖安装失败。
- 验收：新增无数据库真实进程测试覆盖宿主环境两层传递、精确 argv、命令不支持时拒绝；覆盖 Composer 和 npm/pnpm/yarn 固定任务、非零退出失败补偿以及成功 nonce 回调。复跑终端和已有非数据库生命周期回归。原始日志、诊断脚本在 `/private/tmp`，仅维护必要行为测试与 OpenSpec 摘要。没有本轮真实 PostgreSQL / HTTP / 业务闭环 / Windows / Linux / TypePHP 证据时明确保留缺口。
- 回滚：仅恢复本轮执行器差异和新增测试，不回退既有无关 dirty 修改，不执行宿主同步、数据库、服务、提交、推送或发布。独立审查者和通过结论由主 Agent 安排未参与实现的上下文核对后记录；通过前不改提供方源码。

独立必要性审查：`solhigh__provider_review`（实际 gpt-6.1-sol / high / openai）在不同上下文独立实跑原始 launcher probe，exit 0、耗时 0.466 秒，核对入场 SHA-256 `5262f92aa662e98b39de88307502c5cdb7e3d87dc68b442a76e57dff0b259b8c` 与参数/环境失败。2026-10-04 通过上述最小源码实施范围；该结论不证明修复候选、发布包或真实宿主通过，固定候选后另行核验。

固定候选独立验收：`solhigh__provider_review` 在不同上下文核对精确基线及仅两处生产差异，三文件 SHA-256 验收前后相同。Native / Contract / Outcome / legacy 非数据库 lifecycle 共 4/4 通过，分别 1.764 / 0.104 / 0.103 / 4.851 秒，总 6.822 秒，2026-10-04 通过执行器源码切片。额外 UpstreamPostgresLifecycleTest 的旧 metadata 夹具不调用 TerminalRunner，在兼容预检失败，保留漂移缺口、不声明全包回归通过。真实宿主 PostgreSQL、SSE HTTP、state 4→1 后卸载、公开发行、Windows / Linux 实机与 TypePHP 均不是本项源码验收证据，由真实生命周期任务另行记录。


## 2026-10-10 自动依赖激活与恢复 CLI 的提供方边界

SandPackage issues [#22](https://github.com/supdger/sand-package/issues/22)、[#23](https://github.com/supdger/sand-package/issues/23) 分别属于自动依赖的当前 worker 激活和独立恢复命令的宿主控制。修复复用现有安装/恢复协议，不把 IAM 业务加载 hook 放到 SandAI，也不增加跨宿主扫描式重启。

依赖仅在既有安装状态、版本和摘要通过后按 Webman scoped config 加载，并对 config/autoload 路径逐项拒绝链接与包外文件；目标 SQL 前的目录拒绝保持。fresh continue 重新核对依赖/目录，在部署后登记服务，原候选、confirmation 和 committed SQL checkpoint 不变，重复 continue 不重放 SQL。

独立 CLI 从配置 PID 文件绑定精确宿主、master 启动身份及 HTTP worker，信号成功还须在 15 秒内观察 worker 轮换；失败抛出供官方 Recover 返回非零，保留现场。Unix 需要 POSIX、`/usr/bin/pgrep` 与 `/bin/ps`；独立 Windows CLI 重载拒绝，原 HTTP 分支保留。未知 PID/其他宿主/调用者父进程均不允许作为重载目标。

固定修复和 0.2.6 发行契约经不同上下文 Astra / medium 验收并公开，真实可消费版本、载荷及正常 Composer autoload 已核对；完整验收与未验层见 tasks §6。已发布源码 `2795e1b12b046058c1f3f3bdf26486751b23783e`，未把录制 SQL 或任务专属进程验收扩称 SandAI/真实 PostgreSQL 业务闭环。


## 2026-10-10 插件 ZIP 容量统一契约（issue #25，0.2.7 已公开消费验证）

插件作者通过现有归档命令构建完整安装包，管理员从正式上传或仓库下载入口接入；不按业务插件建立白名单，不裁剪承诺载荷，不通过内部安装函数绕过容量门。

压缩 ZIP 共用硬上限 16 MiB（16,777,216 bytes）。宿主现有 `plugin.sandpackage.upload.size` 只允许降低运行接收上限；仅缺失配置采用默认，非法或超过硬上限的配置明确失败。归档器直接使用同一硬限，不新增 CLI 参数或容量配置。正式上传、仓库流式下载、archive/document 读取、异常清理、旧恢复 replacement 及内部安装核心入口均须满足有效上限。

测量失败、actual/max bytes 超限及内容/摘要校验失败分别报告；流式下载中断的 actual 只表示已接收字节，不推称完整文件大小。保留 2,048 成员、64 MiB 解压大小、路径/重复成员/链接、CRC 与载荷/摘要安全门。

验收沿 tasks §7：完整中立大于旧 5 MiB 的包经用户可得归档命令及正式 controller 上传/下载路径接入；宿主降低限制与硬限边界拒绝给 actual/max，配置与测量错误不冒充超限；内容/摘要及旧解压安全负例继续拒绝。测试不使用真实业务数据库或修改用户宿主。

本契约已由 0.2.7（`3482b8c9c8a1908901dbd063e0670d1095ce58d9`）交付，事实见 tasks §7。实际读取文档和 info.ini 同样在既有大小门后验证 CRC；测试只清理创建时绑定且位于系统临时目录的专属根。HTTP 请求包络独立于 ZIP 压缩容量，正式 16 MiB multipart 验证使用任务专属 20 MiB 包络；公开宿主配置不由本包调整。权威源同步保留其他未发布候选，Wiki 只准备草稿，不扩展为跨仓发布授权。


## 2026-10-11 SQL 尚未开始时的升级恢复切片（issue #27，0.2.8 已公开消费验证）

新候选因未登记宿主文件冲突在 SQL/新文件事务开始前失败时，管理员仍应能从正式恢复 inspect 取得绑定当前候选和真实现场的安全结果，并执行对应无 SQL 回滚；不得因上一轮 complete change 记录被拒绝进入恢复，也不得将历史 change 当作当前候选事务。仍须保留外部冲突文件、旧 owned 记录及数据库未变的事实，篡改、其他候选和已提交 SQL 的不同状态不能被统一放宽。

本切片沿用 tasks §8 与公开 issue #27。SQL 未开始分支必须在给出回滚动作或写入旧候选前核对非空数据库身份和结构证明，拒绝缺失证明及与未开始 SQL 自相矛盾的提交后记录。初次独立审查发现上述证明门缺口，第二轮固定源码已补足身份/结构证明及矛盾记录拒绝，正式恢复 CLI 96 项录制检查通过；完整候选与 clean 合并 ref 的 86 载荷契约均已通过不同上下文 Astra 独立验收，0.2.8 已公开，正常 Composer 从 0.2.7 仅更新 Package、实际 vendor/部署摘要及正式恢复 CLI 96 项录制回归通过；权威源码任务差异已精确同步，保留先有候选。不操作原消费宿主、真实 PostgreSQL、服务或密钥，不以源码/公开 Composer 验收替代其业务恢复闭环。


## 2026-10-11 仅上传的未开始升级候选（issue #29，0.2.9 已公开消费验证）

管理员上传升级包后尚未启动生命周期操作的 WAIT_INSTALL 候选，与 #27 已生成生命周期记录的 SQL 未开始失败属于不同阶段。提供方需通过现有候选身份、备份和归属协议，在正式入口安全撤回或替换当前未开始候选；没有生命周期记录不应被猜测为 SQL 已回滚，不能借手工删除记录或内部入口绕过状态门。

本切片沿 tasks §9，公开 0.2.8 中立正式入口已复现，最终 004 的 raw candidate tree 确认和 final-save 仅补完日志恢复经不同上下文独审，119 项正式 CLI/API 回归通过；0.2.9 已公开并完成正常 Composer 仅 Package 更新、实际公开载荷和入口验证。已开始/已提交 SQL、外部修改、备份或候选不可信仍保持拒绝；不操作原消费宿主、真实数据库或共享服务。正式命令为 inspect-candidate / discard-candidate，确认使用当次 `DISCARD app fingerprint`；已有 restored 无动作时禁止重放，finish-journal 仍用同一入口仅收尾已有记录。不证明上传前数据库未变，不回滚其他插件依赖。


## 2026-10-11 依赖执行中断的原任务恢复（issue #31，0.2.10 已公开消费验证）

恢复沿原任务身份、进程记录、候选与 nonce 绑定。无终态不等于成功；`reset-dependency-waiting` 只在原进程组/启动进程/记录后代确认不存在时归档原占用、保留依赖等待，不重跑依赖、插件安装或SQL。持久reaped证明与callback成功/失败登记先于marker清理；`finish-dependency-cleanup`处理登记后清理中断，只保留既有结果。恢复自身检查点每步可再inspect，新确认绑定最新现场，活进程、未知权限/身份、载荷/依赖漂移和旧确认拒绝；历史nonce缺原进程marker仍无证据恢复，禁止事后补写或手删现场。

安装state=4持久保留原重载意图，到最后依赖成功登记才处理；原false不重载、旧unknown不猜。依赖全完成而重载待处理仅提供显式fresh确认加--restart的restart-only，signal_requested不等于worker轮换。公开vendor123中立检查和NoPOSIX拒绝、87payload/hash、正常Composer onlyPackage升级分别记录于tasks§10。SQL为录制层，未恢复真实PG消费宿主。最早隔离retry断言失败无保存诊断、原因未知保持公开限项。权威精确10同步保留原dirty/版本比较，无额外PluginVersion生产feature，Wiki仅草稿。

## 2026-10-11 提供方自升级与原依赖恢复（0.2.11 已公开消费终态验证）

原complete dependency entry保留完整Composer after摘要。只有原备份/事务/候选精确一致、root require中仅provider单raw token变化、当前constraint/lock/installed/实际canonical代码一致时，官方dead-job恢复接受有限兼容；other_lock_history_verified=false不发明其他依赖历史。恢复本身不改Composer产物/旧journal、不重放SQL或依赖、不声明成功。confirmed transition绑定原closedcheckpoint/markerarchive/journal/candidate/provider，新显式owned任务nonce/type及durable completion proof记录全部当前依赖，后续普通lifecycle可精确归档原事务字节和transition，并续接写证明/归档中断；普通apply/restore没有证明仍严格。runtime先按原SHA重新完整检查归档，普通0210持久checkpointshape保持。

公版真实验收保留同原0210pending跨正常onlyprovider Composer升级，再actual0211官方恢复、真实TerminalRunner Composer重执行至state1和普通guard归档；录制SQL0与真实DB业务验收分开。authority只8三方hunks保留既有diff，无额外旧公版feature。完整层级/35retry更新区别/未知#31历史断言限制见tasks§11；不原宿主/服务/Wiki推送。

## 2026-10-11 依赖启动异常的安全诊断（0.2.12 已公开消费验证）

依赖启动catch不应只给不可关联的exec-error。受影响决策仅为安全阶段分类、随机incidentID与白名单日志关联：不把raw异常、命令、环境、nonce或trace参数放进SSE/日志，不猜消费方真实失败原因；日志组件失败也不能阻断原failure callback/进程finally。故障注入与正常成功/清理分支通过才声明诊断切片有效，不改变既有恢复证明、授权边界或原宿主状态。tasks§12记录唯一进度，Wiki另仓仅草稿。

已confirmed reset且无新job的pending provider transition跨进一步工具更新时，仅正式fresh inspect/recover的refresh-provider-binding写独立sidecar，绑定候选/载荷/全部当前依赖和实际provider来源；原transition.original/closed recovery history/依赖journal不改，不新nonce/command/SQL或success。之后显式普通Runner成功才记录callback和清理任务、普通upload据精确证明归档。实际onlyprovider公开消费与同pending真实Composer0变化完成、故障注入诊断50分层留证于tasks§12；原HTTP启动根因unknown，预检readonly_only不证明真实启动。

## 2026-10-11 执行身份提示与插件依赖下限（候选准备中）

插件声明中的version仍绑定随包固定ZIP及SHA身份；兼容意义对已安装依赖仅是最低版本，有效更高版本直接复用，不自动降级。未安装/WAIT_INSTALL候选仍须与随包声明version+SHA精确一致，bundle元数据、摘要和路径安全继续严格。纯数字prerelease禁止前导零，合法0/alpha01和build metadata保留；使用仓库已有版本排序，不增加通用解析层。只读worker执行身份投影与恢复执行身份门分开，不能由提示放宽控制记录；原启动原因unknown。受影响公开安装示例只设下限，准确发行ref/hash不是兼容上限；tasks§13为唯一进度，不扩旧依赖库全版本审计或宿主操作。
