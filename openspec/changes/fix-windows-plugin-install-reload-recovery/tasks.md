## 1. 提供方边界审查

- [x] 1.1 完成入口、HTTP 错误、路由单飞、菜单处理及现有测试框架核对，形成 proposal/design/spec，证据见 design 的源码及 Windows 冻结记录。
- [x] 1.2 由未参与实现者核对 Windows 证据及最小提供方必要性，在 design 的 Independent Review 记录通过或退回；通过前不修改提供方。

## 2. Core 有限恢复

- [x] 2.1 在现有 HttpError 保留明确传输分类，初始化 GET 透传窄类型选项，复用静默请求配置；测试区分网络、取消、超时、非空业务响应、401/403。
- [x] 2.2 在初始化读取阶段加入共享 15 秒预算、最多 6 次尝试、1 秒间隔、单请求剩余超时及共享终止状态；测试暂态恢复、耗尽停止、部分成功不重发、不可恢复失败停止兄弟重试。
- [x] 2.3 保持单飞与 generation 检查，初始化最终错误统一反馈，验证等待中退出/重登、并发导航、无重复路由及无中间三重 toast。
- [x] 2.4 运行现有路由行为回归及受影响类型检查，审查实际差异；记录命令、结果与具体未覆盖项，不能以文本匹配代替行为。

## 3. Windows 实机验收

- [x] 3.1 候选冻结并通过独立源码审查后，由 runtime 执行者受控投送 Windows 隔离宿主；记录实际文件摘要和运行版本，不由设计者写宿主。
- [x] 3.2 从标准插件管理入口重走安装，确认安装后无需手动刷新、未进入 `/500`、无暂态错误连弹、已安装状态与菜单可用；捕获暂态响应体/content-type 补充空代理响应分类证据，原始证据留 `/tmp`。
- [x] 3.3 独立验收权限失败、持续不可用和相邻登录导航路径；分别记录源码、Windows 运行及尚未发布状态，任何未实跑项保持未完成。

## 4. 本地候选验证记录

- 2026-10-04：`FRONTEND_NODE_MODULES=/Users/code/project/sandadmin/sandadmin-artd/node_modules node --test sand-core/tests/login-route-recovery-test.cjs`，30/30 通过（约 0.72 秒）。运行真实守卫、API、错误转换及 Axios 拦截器；虚拟定时器覆盖窗口，不等待真实 15 秒。
- 同步本轮四个源文件到 `/tmp/windows-reload-core-typecheck` 后，`node /Users/code/project/sandadmin/sandadmin-artd/node_modules/vue-tsc/bin/vue-tsc.js --noEmit -p /tmp/windows-reload-core-typecheck/tsconfig.json` exit 0；该目录是完整 src/tsconfig 镜像，复用现有依赖，不修改宿主。
- 本轮源码差异 `/tmp/windows-reload-core-source.diff`，原始源码 `/tmp/windows-reload-core-before/src`，冻结候选与 SHA-256 `/tmp/windows-reload-core-candidate/sha256.json`。本地阶段之后的 Windows 独立验收见下节；未提交或发布。

## 5. 独立源码与 Windows 成品验收

- `astramedium__reload_recovery_review` 独立核对四文件 SHA-256 并重跑现有行为回归，30/30 通过，约 0.699 秒。
- 原生 Windows Chrome 真实市场安装 0.8.4：三 Core GET 两轮真实空体 HTTP 500 后第三轮 200，自动回到插件页显示已安装，未进入 `/500`，未观察到错误 toast；实际点击 IAM 总览，并记录应用列表 API HTTP 200；当时未保存业务 code，因此不作为该业务成功证明。
- 原生浏览器 fixture：持续空 503 六次停止；403、HTTP 200/业务 500、401 均一次即保留原失败边界，401 确认退出登录。fixture 只作用当前浏览器，不改真实服务或权限。
- 完整时序、限制与截图路径见 design 的“固定候选与 Windows 成品验收结论”；本次只验收 Windows 安装后恢复切片，不代表 Core 已公开发布。

## 6. Core 0.2.1 提供方公开发行

- 2026-10-04：独立发行门槛确认冻结四源码及行为测试与 Windows 已验收候选摘要相同。干净独立仓库只提交八个必要文件；实际 pre-commit 接入并通过与 index tree `7184d16dd2e345150721cd7788f81b48c78dcb48` 绑定的维护性审查。
- [Sand Core PR #12](https://github.com/supdger/sand-core/pull/12) 正常合并，main 合并提交 `9bfdfa6682831fe0bdace9734d751dd6b7627b59`；[正式 0.2.1 Release](https://github.com/supdger/sand-core/releases/tag/0.2.1) 标签精确指向已验收提交 `d054e79ba964ecc1770ff50b71d71384b35ec640`。未直接推 main，未包含 SandPackage 变更。
- `system-update.json` 绑定同一发行提交，547 个载荷文件逐项摘要一致，声明无数据库或宿主骨架变更；公开回下载 SHA-256 `17ff6cb1070a6ce8eb4b7d6da52aa103ea9e460b079b6c30c524f9a839516ca7` 与独立核验资产一致。Packagist 及正常 `composer --no-cache show --all supdger/sand-core 0.2.1 --format=json` 已发现该版本，source/dist reference 均为固定发行提交。
- 当前权威源码仅对齐 app.version、该 release 日志及必要前端升级提示，未整体覆盖共享工作区或提交其既有 dirty 内容。SandAdmin 默认消费约束/锁文件更新、公开 clean checkout 默认 Composer 安装及匹配前端消费验收仍由消费工作包验证；本发行不代表生产部署。

## 7. 公开宿主消费、Windows 验收与收尾

2026-10-04：[SandAdmin PR #40](https://github.com/supdger/sandadmin/pull/40) 正常合并至公开 revision `d8c21930e8437364189d2dfd7d5b754aba2f26b3`，Core 最低约束为 `^0.2.1`、锁定本节上方正式发行引用，Package 仍为 0.2.1。新 Windows 隔离宿主从该公开 revision 正常 `composer install`，102 个依赖及默认脚本通过；实际 vendor、受管四源码及 Core 0.2.1 清单摘要一致。Node 22.23.3 / pnpm 10.17.1 正常 frozen 安装 870 依赖，默认报告六个依赖 build scripts 被忽略，不声称其 postinstall 已执行；项目 Vite 构建及 vue-tsc exit 0。独立原生 Chrome 正常验证码登录后，从真实市场按钮安装公开 IAM 0.8.4，三个初始化 GET 空 500 后约一秒恢复 200，自动显示已安装，无 `/500`、无错误 toast。用户授权的 Wiki 仅同步 Core 段与核对日，公开 SHA `4bbf9709b993214f356b1bec08ed51924ecc2a37`：[更新说明](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

相邻业务另记录 HTTP 200 / `code=404,msg="404 Not Found"`：IAM 总览应用列表业务未通过；已安装 0.8.4/state=1/公开包 SHA 正确，route/controller 存在，前后端前缀同为 `/app/sand-iam/admin`，无可见 PHP 堆栈，根因与归属未定；没有为此修改源码、数据库或重启。安全安装、构建、摘要和清理证据位于 `/tmp/sandadmin-core021-public-evidence/`，独立脱敏 events/screenshots 由验收工作包保留。任务 Webman/Vite、两条 SSH 转发、专属 Docker PG 及匿名卷均已清理；最终 19484–19487 无监听、无任务服务进程、临时 auth/login/.env/JWT 密钥不存在。保留用户项目目录、公开 host、候选基线、工具、安全证据及预有镜像/daemon；未触既有 SandAdmin、其它任务或生产部署。

## 8. 2026-10-05 遮罩与仓库恢复修复

- [x] 8.1 修复 Core 实际遮罩所有权及成功、失败、取消、会话重置、HMR 收尾，避免守卫状态脱节及旧回调关闭新遮罩。
- [x] 8.2 扩展现有真实模块行为回归，证明旧版失败/新版通过，并通过受影响类型检查。
- [x] 8.3 实现 Package 两个只读 GET 有限恢复、取消与代际隔离，通过暂态、耗尽、权限、写请求及旧响应回归。
- [x] 8.4 冻结基线、最小补丁与摘要，经独立源码审查后由 runtime 投送 Windows TEMP 专属宿主。
- [x] 8.5 原生 Windows 标准入口实际安装，独立验证无需刷新、遮罩消失、已安装及仓库恢复，记录边界并完成授权临时资源清理。

本轮 Core 本地结果：真实 loadingService、Vue Router 与前后守卫行为回归 36/36 通过（约 0.70 秒）；新增模块重新求值、旧 nextTick、logout 及 HMR 四项在冻结旧源码全部按可观察遮罩状态失败、新版通过。另覆盖真实并发路由取消、重定向与异步组件失败收尾。`FRONTEND_NODE_MODULES=/Users/code/project/sandadmin/sandadmin-artd/node_modules node --test sand-core/tests/login-route-recovery-test.cjs`；完整 src/tsconfig 临时镜像 `/tmp/core-spinner-owner-20261005/typecheck` 复用现有依赖，vue-tsc --noEmit exit 0。OpenSpec strict validate 通过；这些不等于 Windows 实际安装通过。

本轮 Package 与独立源码结果：两个 GET 的恢复、持续失败最多五次尝试、15 秒重试发起窗口、catalog 正常慢响应、401/403/非空业务失败/POST/timeout 不重放、取消和页面/会话代际隔离行为回归 18/18，通过定向 vue-tsc。独立 Astra 已亲自重跑 Core 36/36、Package 权威源码与公开基线精确补丁各 18/18，并核对冻结摘要，无源码阻断；runtime 已按受控补丁投送 Windows TEMP 专属宿主。Package 本轮 source patch SHA-256 `af16f836bcd530d9cef45e7ff84e06020f6411629cf6ea5569e98c26941bbf62`，public patch SHA-256 `d5188d627d1a8f494674105a87e740a2971cb81388b337dd23b74f19de434af7`。

Package 标准分发补验位于 `/private/tmp/package-read-publisher-check/`：CLI 前端发布、Composer 类发布和实际 Composer archive ZIP 均包含新增 read-recovery helper，三份实际文件 SHA-256 均为 `d0e56a288b7f50f62ca9e7a97ce061e7e6ed9e45598f1c21603ced8b337e24fe`。此记录确认新增文件能随既有发布入口交付，不代表已公开发布。

8.5 Windows 独立验收事实已核对：435 条 events 支持 IAM 原页实际安装恢复（首次明确采样 58226ms，采样有缺口，不推测准确退出时刻；后续超过 90 秒稳定）、License 两轮真实空 500 后第三次 GET 200（11301ms 已恢复、阶段末 125010ms 稳定）、正常退出/登录与 403/业务错误/持续失败/取消/旧响应/401 边界。详细数值、scope 与证据见 design 的 Follow-up Windows acceptance。最终 `/private/tmp/sandadmin-spinner-fix-cleanup-20261005/cleanup-verified.json` 已亲自核对：凭据、任务目录、临时 PG 集群已清理，任务进程和四个监听端口均不存在；8.5 完成。本轮仅权威源码候选和专属临时宿主验收，用户既有宿主未更新，未提交或发布。

## 9. 2026-10-05 修复公开发行与默认消费链

用户“发布”承接已独立通过的本次修复。只在各公开仓库的任务隔离 clone 上应用已验收精确差异，未将聚合工作区的无关 dirty 内容纳入；feature branch、PR 合并后发布，未直接推 main。

- [Core PR #15](https://github.com/supdger/sand-core/pull/15) 合并至 `7ea906743f9c912d03cc9f0fc1e68366c310c393`，[正式 0.2.4](https://github.com/supdger/sand-core/releases/tag/0.2.4) tag 与发行契约绑定该提交。`system-update.json` 549 个载荷文件，SHA-256 `e769d7546b9fd229021e00075a7d295d457666ff8f2dc96e0a9d7a2a36a21664`。
- [Package PR #19](https://github.com/supdger/sand-package/pull/19) 合并至 `f30ac91684a3f00874e027d5e4964cd969ac7a4f`，[正式 0.2.4](https://github.com/supdger/sand-package/releases/tag/0.2.4) 契约 85 个载荷文件包含新增 helper，SHA-256 `74b8fdef867bea493df6f73ef30a69e0fea3bd0ce2e73893e44ba6278a94b1a3`。最低 Core 约束 `^0.2.4`；两个契约 host_min 为 `0.2.0` 且无上限，明确无数据库或宿主骨架变更。Packagist 已直接索引相同固定引用。
- [SandAdmin PR #47](https://github.com/supdger/sandadmin/pull/47) 合并至 `a45cb75ec1452a20e5addb61eed7cdd4bafea736`，[正式 0.2.3](https://github.com/supdger/sandadmin/releases/tag/0.2.3) 锁定上述两包，最低约束均 `^0.2.4`。正常 Composer 定向更新只改变这两包；前端受管清单与匹配载荷由正常安装钩子发布。冻结前端锁安装 870 个依赖全部复用，Vite 构建 14.35 秒及 vue-tsc exit 0；既有登录 CSS :deep 与大 chunk 告警保留，不属于本修复。
- 不同上下文 `solhigh__release_candidate_acceptance`（实际 `gpt-6.1-sol/high/openai`）独立核对两提供方和消费者固定候选，Core 36/36、Package 18/18、发布器检查通过，六个运行文件与上一轮 Windows 固定验收完全相同。公开 tag、Release、契约资产摘要与全部文件逐项回读通过；合并树与验收候选一致。
- 独立 clean clone 读取上述公开 SandAdmin revision，在初始无 vendor、无 .env 的 macOS / PHP 8.4.18 环境按 README 正常 `composer install --no-interaction`：102 installs、0 updates/removals、默认 `support\Plugin::install` 全部运行、exit 0。lock、InstalledVersions、installed.json 固定引用正确；vendor 549/85 契约文件、397/10 前端受管文件和 153/62 后端发布文件全部摘要一致，六个 Windows 验收文件完全相同。原始证据 `/private/tmp/sand-spinner-public-independent-20261005/public-consumer-acceptance.json`、`composer-install.log`。

发行汇总 `/private/tmp/sand-spinner-release-20261005/release-result.json`；实际暂存内容的维护性审查与 pre-commit 校验在三个目标仓库均通过。权威 source 仅回写本次版本、更新日志、最低约束及受影响 README，未全量覆盖其既有历史漂移。已有 14 项任务保持完成，本节记录发行收尾而不新增虚构验收计数。Windows 成品验收复用上一轮固定同摘要载荷；本轮没有重新运行 Windows 或部署、修改用户既有宿主。Wiki 的 `plugin-updates` 最小草稿补丁位于 `/private/tmp/sand-spinner-release-20261005/wiki-plugin-updates-pending.patch`，未获该不同目标推送授权，故未推送；README 与三个公开 Release 均已同步本次升级影响。
