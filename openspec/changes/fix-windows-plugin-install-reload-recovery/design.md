## Context

动机见 proposal。调用方为标准 ZIP 安装入口中的管理员，提供方为 Core 的管理端初始化；权威源码所有者分别是本仓 `sand-core/`、`sand-package/`，IAM 业务不拥有宿主路由守卫。

冻结现场由独立 runtime 执行者提供：Windows 10 build 19045、PHP 8.2.12（任务 php.ini）、Node 22.23.3、Chrome 154、Core 0.2.0、Package 0.2.1、clean 宿主 revision `533ca1`。宿主 `D:/htdocs/sand_plugins/iam084-windows-repro/host` 仅由 runtime 执行者操作。本阶段无宿主写入。

已读取 `/tmp/sandiam084-install-repro/evidence/windows-native-events-safe.json`：登录初始化成功；安装期间 01:52:46–47Z 页面被重新载入；01:52:52Z 三个 `/core/system/{user,dictAll,menu}` GET 返回 HTTP 500，52.557Z 转 `/500`。runtime 报告同时存在 Vite ECONNRESET，安装 SQL 与文件阶段完成、插件登记 state=1/version=0.8.4，随后 user HTTP 200；此数据库与进程证据由独立审查者复核，不能由浏览器事件的 `installed:false` 推定数据库安装失败。截图 `windows-native-500.png` 由主控核实有三条内部错误提示。

源码核对：`beforeEach.ts` 在三个初始化请求完成后才写入 Store 和注册路由；现有 routeInitPromise 单飞及 routeGeneration 隔离旧会话。`HttpError` 有 code/data/url/method，但取消和无响应均失去原始传输分类。现有 HTTP 请求支持 `showErrorMessage:false`，全局 MAX_RETRIES=0。`MenuProcessor` 在后端菜单 GET 后处理菜单；已有 `sand-core/tests/login-route-recovery-test.cjs` 运行真实守卫配合隔离服务及虚拟定时器，无需新增测试框架。Package Windows restart 使用 1 秒 Timer 调用 Worker::stopAll。

## Goals / Non-Goals

**Goals:** 管理员从插件管理安装，遇到短暂重载时继续现有加载反馈；恢复后留在有效目标页面、看到已安装状态并可进入插件菜单。暂态不出现三条错误弹窗；持续故障仍结束等待并显示原失败页。

**Non-Goals:** 不重试安装 POST，不伪报安装成功，不改 IAM、数据库或权限，不取消必要服务重载，不加平台或插件名称分支，不改 Package 的 HMR 协议，不建立全局重试、轮询器或新覆盖层。

## Decisions

### 提供方必要性与替代方案

本次采用必要性路径。失败在 Core 登录后初始化，IAM 页面及其 API 尚未取得执行机会；既有公开接口、插件菜单、安装配置不提供接管 Core 初始化错误分类或重试的扩展点。让 IAM 自行重试无法影响前三个 Core GET；修改 IAM 菜单数据不能修复代理断连；要求用户刷新只绕过失败状态且仍可能撞重载窗口。全局 HTTP 重试会涉及有副作用请求并掩盖业务错误；延后 Package 重载或禁用 Vite reload 只移动竞争窗口且改变安装协议。本次仅修复 Core 所拥有的初始化可恢复性，不搬入调用方业务。

### 有限且只读的恢复窗口

沿用正常 GET 默认 15 秒超时上限，不缩短首次正常请求。初始化的三个 GET 各自重试失败请求，共享本次初始化的 15 秒截止时间；重试间隔 1 秒、每个请求最多 6 次尝试。单次超时不超过剩余预算；预算到期后不启动新请求。请求成功一次即保留结果，不整组重复成功 GET。菜单本地转换、校验、Store 提交和路由注册不置于重试闭包内。

只接受明确传输网络失败，以及 HTTP 层的 500/502/503 且响应体为空字符串或 null/undefined；其他 HTTP 错误、非空 JSON/文本错误响应、取消、超时、权限错误、业务错误和结构错误不重试。扩展现有 HttpError 的可选传输分类即可，不根据本地化 message 文本猜测错误。GET 方法及来源需明确，只有初始化调用显式使用恢复逻辑。

明确网络失败只按原 Axios 已知网络错误代码与无响应联合判断，不能把所有无 response 的配置、解析异常都归类为网络故障。任一 GET 不可恢复失败或耗尽使本次初始化终止时，设置共享终止状态；其余 GET 的等待结束和请求前均检查该状态，停止后台重试。在途请求可完成但不能提交状态，后到 401 保留既有登出作用。

API 的用户、字典、菜单 GET 及 MenuProcessor 仅透传初始化所需的窄类型选项（timeout、showErrorMessage 等），默认行为不变。复用 showErrorMessage:false 静默初始化尝试，在最终不可恢复或预算耗尽时由初始化边界统一保留一次真实失败反馈；401 沿用现有自动登出，不静默权限处理。不得全局关闭错误提示。

每轮请求前、等待后及全部读取完成时检查 generation/isLogin；退出或重登后旧轮不得继续请求、写状态或注册路由。单飞仍覆盖整个恢复窗口，路由只在全部有效结果齐备后注册一次。

## Risks / Trade-offs

- 空 HTTP 500 不保证是重载 → 限定初始化幂等 GET、空响应、严格次数及期限，持续错误回原失败页；不将空 500 当安装成功信号。
- 网络请求接近期限仍在途 → 透传剩余超时，过期结果或会话失效结果不写入状态；自动测试验证预算和代际。
- 部分成功、部分永久失败 → 不提交半套状态；后续认证错误仍按原契约处理，测试混合失败与登出竞争。
- Windows 恢复时间可能超过预算 → 以真实安装复验确认，未通过前不宣称解决；不无界放宽预算。
- 源码目录存在大量既有未跟踪内容 → 只修改明确文件并保存本轮差异，不执行 reset/clean 或覆盖他人改动。

## Migration Plan

独立审查通过后才改提供方。先在权威 Core 源码及现有行为测试验证，候选冻结后由 runtime 通过已授权受控流程投送 Windows 测试宿主；设计者不直接操作宿主。原始日志及截图留 `/tmp`，本 change 记录最小摘要。无需 SQL、配置迁移或安装包格式变化；公开分发仍需单独授权与正式版本流程。

回滚仅撤回本轮 Core 初始化与错误元数据差异，并由原宿主操作者恢复同一测试候选前版本；不卸载已经成功安装的 IAM，不回滚其数据库，不撤销无关变更。回滚服务操作仍按既有授权执行。

## Independent Review

2026-10-04，独立审查者 `astramedium__reload_recovery_review`（未参与实现；当前会话脚本核实 gpt-6-astra / medium / openai）结论：**提供方必要性及上述最小设计通过，允许进入 Core 源码实现；不代表成品或发布验收通过。**

独立读取了 `windows-native-events-safe.json`、`windows-installed-state-safe.json` 并查看 `windows-native-500.png`：安装事务记录于 01:52:51Z complete、元数据 state=1/version=0.8.4；三个 Core GET 于 01:52:52.365–367Z 返回 500，52.557Z 导航 `/500`，截图有三条内部错误。已核对 Core 守卫先读取再注册路由的顺序、失败状态锁定、HTTP 错误元数据丢失以及 Package 的 Windows Timer/stopAll 分支。因此消费插件尚未挂载便已失败，插件 API 或菜单内重试无法覆盖该入口；Core 初始化责任边界和最小替代方案论证成立。

补读 `windows-vite-proxy-errors-tool-log.txt`，其中 Windows Vite 原会话 stdout 摘录在 09:52:52（UTC+08）对上述三个 GET 均记录 `read ECONNRESET`，与事件时间吻合。本次旧事件未捕获 500 响应体，空响应仍只是实际 Vite 7.1.7 代理 handler 行为的源码推断，不写成已抓包事实。候选复验须补充响应体及 content-type 证据，最终 Windows 门槛仍未完成。

已要求并核对纳入两项修正：只按已知 Axios 网络代码且无响应识别网络故障；任一不可恢复失败或耗尽后，共享终止状态停止兄弟请求继续重试。另根据主控补充审查，将原规划 10 秒统一调整为 **15 秒整个初始化共享期限**，与既有正常 GET 默认超时上限对齐，避免把正常慢请求的允许等待从 15 秒缩至 10 秒，也不引入首次失败后重设预算的并发状态。每请求最多 6 次、剩余期限限制单次 timeout、成功 GET 不重发、取消/超时/权限/业务错误不重试及 generation 检查均属于本次通过边界。实现验收须用行为回归验证这些边界，并核实菜单本地转换不进入恢复闭包；所有结果齐备后才可提交 Store 和注册路由。

兼容影响限可选错误分类与 GET 窄选项，默认调用和依赖方向不变；无需数据库、插件生命周期或安装协议变更。回滚沿上述 Migration Plan，原始诊断附件不入源码。本结论不授予宿主同步、服务管理、提交、推送或发布权限。

### 固定候选与 Windows 成品验收结论

2026-10-04 同一独立审查者续验，未参与提供方实现。**本次 Windows 安装后恢复切片通过；尚未提交或公开发布。** 四个权威源码文件 SHA-256 与冻结清单逐一匹配：beforeEach `b82ba6d78a261aa54591d3c1c598d5106e144b9a6bfe65caba76d15d1fa60177`、MenuProcessor `72c015b8ee9d06f2e81e4e04da88d0aef0d6d4d8c4beff16bf6d7254ca7f3af1`、auth `5db90af4c9d84e0df739042d2ad927312f5ff4a7628bb3da77d2f5cdeb6fc8e0`、error `ead69aefcd3a553be5b641b3feb6ccda41e25c799588f21a15b414392795b097`。独立重跑现有真实守卫与 Axios 行为测试 30/30 通过，约 0.699 秒。

由 runtime 执行者受控应用四文件并报告 Windows 摘要一致、正常卸载完成后交出独占窗口；独立审查者通过 SSH CLI 驱动原生 Windows Chrome 154，在真实插件市场点击公开 IAM 0.8.4 安装，全程未 mock 安装或后端。02:21:54.728–730Z 三个初始化 GET 实际返回 HTTP 500、text/plain、响应长度 0；55.739–745Z 第二轮相同失败；56.785–889Z 第三轮真实 API 200。页面自动继续 `/plugin`，58.337Z 显示已安装 0.8.4，250ms 轮询观察到的错误 toast 最大数为 0，全程无 `/500` 导航，无手动刷新。随后真实点击“管理总览”，进入 `/sand-iam/overview`，IAM application/index 返回 200。已独立查看安装后及总览截图。本轮实测补齐了先前空响应只能源码推断的缺口。

相同候选原生浏览器负例仅在该浏览器拦截菜单 GET，不改后端或授权数据：持续空 503 共 6 次后 `/500`（完整场景 7.753 秒，已成功 user/dict 各只请求一次）；403 仅一次后 `/403`；HTTP 200/业务 code 500 仅一次后 `/500`；401 仅一次后登录页，持久状态 isLogin=false。取消、超时、代际与兄弟重试终止以独立行为回归为证，不冒称全部原生界面逐项重演。前两次脚本会话准备因存储键及 hash 导航未重新挂载而停登录页，未触发安装；修正临时验收脚本后才进行上述真实安装，产品候选未变。

原始事件与截图保存在 `/tmp/windows-reload-independent/independent-{install,persistent,forbidden,business,unauthorized}/`；任务脚本 `accept.cjs` 不含凭据，读取既有正常认证身份。所有浏览器在 finally 关闭，窗口已交还 runtime；本验收未提交、推送或发布。

### Core 0.2.1 公开发行结果

2026-10-04 已完成提供方正式发行，历史验收段落的“尚未发布”描述保留其当时状态。PR #12 正常合并；版本 0.2.1 固定 `d054e79ba964ecc1770ff50b71d71384b35ec640`，system-update 547 文件独立摘要核验及公开回下载一致（SHA-256 `17ff6cb1070a6ce8eb4b7d6da52aa103ea9e460b079b6c30c524f9a839516ca7`），Packagist/Composer 已发现。具体链接及消费待验边界见 tasks 第 6 节；未部署生产。

## 2026-10-05 Follow-up Design

### Evidence and ownership

Windows DESKTOP-F1A13NL、Chrome 154、宿主 0.2.2、Core 0.2.3（1939ac2c8b796c0aa74680b9eefbc1aad2bc4728）、Package 0.2.3（971b0f405f97d6238a4859ca04f13ccfd0289e50）。IAM 0.8.4 安装后 129297ms 仍有全屏 art-loading-fix 遮罩，路由及已安装状态已完成，三个初始化 GET 经暂态恢复 200；手动刷新后消失。原始证据 /private/tmp/sandadmin-spinner-20261005-evidence/。独立复核确认 Core 收尾责任；不同 guard 模块版本与最后 pending=false 支持状态脱节排查，但不能证明注册回调的闭包状态或 loading 引用丢失。Package 仓库读取归本包页面责任，本轮不改 Core 全局 HTTP。

### Loading lifecycle

既有 loadingService 直接持有实例与导航 owner。beforeEach 绑定目标对象，afterEach 不以另一守卫模块的局部 pending 布尔为关闭前提，而按同一 owner 关闭。后续导航接管现有遮罩；旧失败、取消、延后 nextTick 只释放自己的 owner。会话 reset 同步释放当前遮罩；加载模块 HMR dispose 关闭本模块实例。只调用 Element Plus close，不删除 DOM、轮询、定时强关或刷新页面。现有行为测试运行真实服务和守卫，覆盖模块重新求值、重定向、并发和旧回调。

### Repository reads

Package installed 与 catalog 两个 GET 复用已有 isRecoverableInitializationError，仅明确 network 或空 HTTP 500/502/503 最多四次重试，15 秒内允许发起重试；catalog 每请求 65 秒正常超时不变，因此不声称总耗时严格 15 秒。401/403、业务非空失败、取消、超时和写请求不重试。AbortController 与请求代际在切 tab、deactivate/unmount、会话变更时失效，旧 success/error/finally 不覆盖新状态。

### Verification boundary

基线、最小补丁及摘要放 /tmp/core-spinner-owner-20261005；先运行旧版失败/新版通过行为回归及类型检查，再由 runtime 受控投送已授权 Windows TEMP 专属环境，独立验收原标准安装入口、遮罩结束及两个列表恢复。保留历史九项完成记录；本轮独立源码审查及受控投送已完成，Windows 本轮安装与边界验收已通过，最终临时资源清理已核对完成。无数据库迁移、提交、推送或发行。

### Follow-up independent source verification

2026-10-05：主控确认独立 Astra 已亲自重跑 Core 36/36、Package 权威源码及公开 0.2.3 基线精确补丁各 18/18，并核对冻结摘要，源码审查无阻断；runtime 已受控投送 Windows TEMP 专属宿主。Package 定向类型检查通过，CLI/Composer 类前端发布器和实际 Composer archive ZIP 均包含同摘要新增 helper；具体摘要及产物目录见 tasks 第 8 节。这些结论限源级行为、分发完整性和投送，不代表 Windows 安装验收全部通过，更不代表公开发行或生产部署。

Windows 本轮完整界面切片的独立证据已核对，结果见下节；8.5 已结合最终清理证据核对完成。

### Follow-up Windows acceptance

2026-10-05：独立原生 Windows Chrome 154.0.8037.58 验收通过。亲自读取 `/private/tmp/sandadmin-spinner-20261005-acceptance/acceptance-events.json` 的 435 条事件；该目录另有 12 张脱敏截图。目标为任务 TEMP 专属宿主，使用 Core/Package 0.2.3 公开基线加本轮冻结补丁，不代表用户原宿主已更新。

- SandIAM 0.8.4 从标准市场实际安装：列表与 catalog 在确认阶段约 26.5/27.1 秒已 HTTP 200/code 200；首次恢复采样明确显示已安装且无任何遮罩为 58226ms，后续样本至 170059ms 无残留。21032–58226ms 存在采样中断，因此只报告约 58 秒时首次确认恢复，不声称遮罩准确退出时刻。原页自动恢复、未手动刷新，恢复后观察超过 90 秒稳定。
- SandLicense 0.1.2 实际安装：已安装列表和 catalog 各连续两次真实空体 HTTP 500，第三次分别在 10138/10791ms 返回 HTTP 200/code 200；11301ms 样本已显示两插件已安装且无遮罩，阶段末 125010ms 仍稳定。此链路直接覆盖 Package 只读暂态恢复，不是 mock 安装。
- Windows 浏览器范围故障注入：403、非空业务 500 各只发一次；持续空 500 共五次后显示读取失败和重新加载入口，均无遗留遮罩；切 tab 取消只发一次，旧失败不覆盖新页；401 后旧列表请求失效，登录表单可见、旧安装数据不可见。
- 正常退出经 `/auth/login?redirect=/plugin`，真实登录 HTTP 200/code 200 后进入 `/dashboard/console`，连续采样无遮罩。故障注入仅验证边界，不等同真实服务故障；与两次真实安装分开记录。

清理最终结果已亲自核对：`/private/tmp/sandadmin-spinner-fix-cleanup-20261005/directory-removal.json` 确认临时目录移除；`cleanup-verified.json` 于 13:03:54Z 确认 status=cleaned、privateCredentialsRemoved、directoryAbsent、temporaryClusterRemoved、noOwnedRuntime、noListeners 均为 true，任务监听 25436/18787/33006/22207 已释放。8.5 据此完成。本轮未提交、推送或发行，未部署或修改用户既有宿主。

### 2026-10-05 Public release follow-up

本次恢复修复已按用户“发布”授权完成 Core / Package 0.2.4 与 SandAdmin 0.2.3 的公开发行和默认消费链。精确固定引用、契约摘要、独立候选审查及公开 clean clone 正常 Composer 安装证据见 tasks 第 9 节。六个运行文件与既有 Windows 独立验收摘要一致，本轮复用该平台证据；公开源码消费验证在 macOS 完成，不声称本轮 Windows 重跑或用户原宿主部署。Wiki 保持任务外最小待推送草稿。
