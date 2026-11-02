## 1. 契约与提供方边界
- [x] 1.1 定位权威来源、现有 API/发布器和授权边界
- [x] 1.2 记录提供方通用性论证并由未参与实现主 Agent 审查通过
- [x] 1.3 实现受信发布契约生成工具及消费校验
## 2. 后端执行链路
- [x] 2.1 超级管理员状态、版本、预检、启动、任务及恢复 API
- [x] 2.2 独立原生执行器、持久任务、全局锁、固定参数与日志
- [x] 2.3 受管理文件冲突保护、备份、安装、前端构建/静态发布、配置重载/健康及恢复
- [x] 2.4 行为测试覆盖成功、自更新依赖独立、失败恢复、锁和篡改拒绝
## 3. 管理入口与交付
- [x] 3.1 后台系统更新页面，预检/版本/阶段/日志/持久任务/恢复
- [x] 3.2 相关文档和完整配置/发行示例
- [x] 3.3 独立源码安全审查与必要修复回归
- [x] 3.4 隔离公开来源宿主默认安装、正式独立工具升级、实际已安装协调器自更新/失败恢复与真实 HTTP/静态健康验收
- [ ] `[device-gate]` 3.5 既有部署宿主上线、真实登录/数据库业务及目标平台页面验收（未执行，不以隔离验收代替）

## 4. 本地版本声明
- [x] 4.1 用户要求的 minor 同时覆盖 Core 与 Package：两包公开 0.2.0；同版本候选入口缺陷另发 Package 0.2.1，保留已发 0.2.0 不变

## 5. Windows 系统更新修复（2026-10-04 当前授权）
- [x] 5.1 替换 Unix 专属路径判定，统一盘符路径、大小写重叠、目录联接及静态基线保护
- [x] 5.2 实现固定 argv Windows Job 命令、实时日志与超时/owner 中断子孙收尾，原生后台 worker 独立启动
- [x] 5.3 最终当前源码 macOS 原有完整更新/失败恢复/手工救援/并发恢复回归及新增路径/argv/原子记录检查通过 43/43；发行工具通过 35/35
- [x] 5.4 独立 Astra 审查当前源码及本次 Windows 10 fixture 证据，返工修复并复测；不扩为公开发行或既有宿主验收
- [x] 5.5 Windows 真机运行完整回归及 Job/硬中断/重解析点专属检查；记录真实平台与不足
- [ ] `[auth-gate]` 5.6 当前源码候选在公开发行、安装消费与既有用户宿主系统更新中验收；不以本地或临时夹具代替

## 验收证据与交付边界

### 2026-10-04 Windows 修复候选
- 真实 Windows 10 19045、Windows PowerShell 5.1、PHP 8.2.12（`D:\xampp\php\php.exe`、`proc_open` 可用）在本任务独立临时目录运行，未替换既有 SandAdmin 宿主、未访问业务数据库或重启服务。最终原生 `SystemUpdateRuntimeTest.php` 54/54、退出 0；日志 `/tmp/sand-update-windows-runtime-final.log`。覆盖后台 API start、任务/status 刷新、自更新、健康失败与恢复、手工现场救援、外部修改拒绝、并发恢复只执行一次及 Windows 路径/原子记录/Job 超时/owner 硬中断/PID 复用/嵌套 junction/batch 拒绝。Composer/pnpm/reload/health 为隔离 fixture，不能解读为既有宿主的真实依赖更新与业务验收。
- 最小真实 Windows 额外测试 5/5：literal argv 的原始输出无需过滤可直接解码、原生退出码 7 保留、正常父命令返回后长活孙进程按 PID＋开始时间核查消失且心跳停止；日志 `/tmp/sand-update-windows-minimal-fixed-rerun.log`。
- 首轮真实 Windows 发现 PowerShell progress CLIXML 污染与 .NET 子进程 stdout 未继承问题，修为禁止启动器 progress、Win32 显式标准句柄继承及独立身份握手文件后通过最小复测；不以输出过滤掩盖缺失。第二处为测试手工计划未规范化 `PHP_BINARY`，仅修测试按生产 inspect 的六路径规范化，保留产品配置变化的严格拒绝。
- 上述完整 54/54 对应技术 SHA-256：worker `be117a2247bcfa3b3ac63432efd9d84a6616c3a97c4be2d2701e78308a886b30`；SystemUpdate `f2954c83047d62a881d7039724812430344b2884df2691a7f83c7732ac857190`；ReleaseTools `1199a21c07da5c0de96c9ddc8490a9635ed11e21cda07bf06ad2d2cb4e9cc3bb`；prepare `617f6b4af3ee9844afcef87f3462c4915a0529a6bf1263bd807e30539a836d54`；RuntimeTest `e36643a4dd490063dbfe837486d3b023d9520db66cbcce4bf8556ccd6a7b256c`。独立审查随后发现部分负例断言可捕获断言自身异常，已修为调用与外部断言分离，并新增重复分隔符别名拒绝；不能把旧 54/54 自动计入当前最终候选。
- 最终候选 worker `ba2a967ea455b856d292d17657d5abb835922de3712b5d65ed2ff8186849f489`、RuntimeTest `043ca13c1a67395c15ff14147af9a0748a6148a7e94c11ca2dd93dd0cfea68fb`，其他技术文件不变。真实 Windows 正式入口完整回归 59/59、退出 0，日志 `/tmp/sand-update-windows-runtime-59.log`；原后台 API 保持每 100 毫秒读取任务，detached 更新成功、并发恢复只 dispatch/restore 一次，路径负例断言没有捕获断言自身异常。公开发行、标准安装消费与既有 Windows 用户宿主升级仍分开验收。
- 同一最终 worker/test 在 macOS 使用 `TMPDIR=/private/tmp php sand-package/server/tests/SandPackage/SystemUpdateRuntimeTest.php`，原生完整流水线及当前负例检查 43/43、退出 0；Windows-only 压力与 Job 专属检查不伪装为 macOS 执行。此前发行工具测试 35/35 的受影响文件未再修改，复用其结果。全程临时夹具按本任务范围清理，未操作既有宿主或数据库。
- 不同上下文 Astra 最终独立验收亲读 59 项正式 Windows 回归及 atomic-pressure 原生日志，并核对上述最终 worker/test hash；本次源码及 Windows 10 隔离 fixture 范围无必须返工项。该验收不覆盖新的公开发行、宿主安装、副本同步或既有数据库/登录业务。
- 最终回归前定位并修复了 Windows 文件句柄竞争：后台健康已通过，但 API reader 暂不共享删除使原子 task.json 替换收到拒绝访问（code 5）；不靠减慢轮询或延长测试 deadline 掩盖。增加 Windows-only 有界重试，维护测试覆盖 250 毫秒持有 reader 后成功、持续持有超过 2 秒拒绝且原 JSON/hash 不变/自身临时文件清理、100 版本 writer 与 1 毫秒读取得到完整记录。独立最小真机压力验证 350 毫秒 reader 实测 0.345 秒成功；持续锁定实测 2.008 秒明确失败并保留原 JSON、只清自身临时文件，日志 `/tmp/sand-update-windows-atomic-pressure.log`。

- 实现期原生执行器行为测试 26/26，发行工具测试 35/35 通过；0.2.1 动态过滤同版/旧版候选增量回归后行为测试 27/27。实现期 Composer/pnpm/重载/健康为可控 fixture 的证据与下列真实公开消费证据分开。
- 两包按用户 minor 要求发布：Core 0.2.0 对应公开 main/tag `177a4566b6b6a68717bb63a353bdb5fc15b945e5`、547 文件契约；Package 0.2.0 对应 `d1c37dcd5e6169811f3fed913cb6b5b8c6c43646`。真实已安装入口发现同版本仍列为可选目标，限定修复另发 Package 0.2.1，对应公开 main/tag `39b9ee61dd7cbba11295c1b8ee6337feabecb0f9`、82 文件契约。既有 0.2.0 tag/资产未改写。此前 Core 0.1.10 与本地 731/421 候选证据保留为历史，不代表最终公开输入。
- 正常公共 Packagist 的 source/dist references 与正式 tags 一致。公开宿主 PR #35 已合并到 main `64661e4d62aaa994b159f71376239b4dc6238f37`，最低 Core `^0.2`、Package `^0.2.1`，只改 README、两依赖约束/精确锁及前端 manifest 两处 README 所有权摘要。旧 3 个已被 Core 正式删除且无引用的资产不纳入宿主；其他 dirty 文件未混入。固定四文件 index 经独立 Astra 审查和实际 pre-commit 索引审查后提交。仓库没有配置 CI status checks，不冒称 CI 通过。
- 最终公开 main 全新克隆 `/private/tmp/sandadmin-021-merged-public`，不施本地补丁、不加 VCS/path/package 覆盖，实际默认 `composer install` 保留所有 scripts/platform 检查，102 个依赖成功，原生日志 `/private/tmp/sandadmin-021-merged-default-install.log`，退出 0。全部官方 Core 547 / Package 82 文件摘要匹配，后端完整树 152 / 60 文件与 vendor 一致，前端发布 manifest 396 / 9 文件全部匹配，自动加载来自正式 vendor。观察记录 `/private/tmp/sandadmin-021-merged-observation.json`。
- 另一隔离公开 Webman 宿主从正常安装的 Core/Package 0.1.9 起点，使用正式发布 0.2.0 的独立原生 worker 工具升级到双 0.2.0。工具版本与旧宿主版本明确分开，未复制 0.2 runtime 到旧后台冒充入口。实际 Composer 求解/下载、发行校验、备份、publish、pnpm frozen install、生产构建及 vue-tsc、HTTP 服务重载/静态健康成功，任务 `ccd820ca905917724e34ec271c0ccd79` 用时 48.22 秒。正式旧包运行元数据 Core 0.1.2 / Package 0.1.8 如实记录，升级后安装/运行均为 0.2.0。
- 真实已安装的公开 0.2.0 协调器调用 inspect/start，自更新到公开 Package 0.2.1，仅一个包变化；任务 `3a8266345b1335d0931d69c8903f01fb` 在本任务外部健康故障注入后失败且可恢复。去除故障后由实际当前协调器 recover，备份/摘要、依赖、前端与静态恢复，实际 HTTP 重载/健康通过，5.04 秒终态 recovered。重新 inspect/start 任务 `2b3a0d5c76fa335d1a6520b61ce5ee66` 用时 40.32 秒 succeeded，最终 Core 0.2.0 / Package 0.2.1 refs/运行元数据正确。原生日志/plan/task 位于 `/private/tmp/sand-020-public-worker/server/runtime/system-update/jobs/`。
- 真实升级构建 2996 模块及类型检查通过；最终实际 coordinator 状态 supported=true、installed=Core0.2.0/Package0.2.1、releases=[]、latest task=succeeded，严格 backend/frontend/static managed 检查及 HTTP/静态健康通过。最终数据 `/private/tmp/sand-020-public-worker/public-final-coordinator-status.json`。实现期浏览器 UI fixture 验收基于模拟后台契约，不能冒充部署宿主登录/业务页面验收。
- README 与相关 Wiki getting-started/plugin-updates 已按技能同步、独立审查；Wiki 两页提交 `49eebd1`，原生默认分支发布。local Wiki checker 22 页、0 errors/0 warnings；外链及锚点的未验证数量未冒称全测。实际公开 getting-started/plugin-updates HTML 已返回新增 0.2.1/后台系统更新内容，远端 Wiki master 精确 `49eebd16c306c4b91c66ee11f0c7f3b616771a07`；此为公共内容回读，不冒称所有外链/锚点自动全测。
- 用户已明确“批准发布”，覆盖本目标的 provider 公开发行、宿主 feature PR 合并及对应 Wiki；临时隔离 HTTP 操作仅属于本任务验收。既有部署宿主、共享服务及数据库未改，未建库，未运行迁移或登录业务验收。3.5 保留未完成，不归档，不冒称线上部署。测试 HTTP 所有自有历史 PID 9511/11070/12528/12599/12878 均已停止，端口 43120 无监听。
- 支持 Linux/macOS CLI 无数据库/宿主骨架变更发行；Windows、数据库迁移或骨架变更拒绝。硬中断走独立 worker manual-inspect/manual-recover 保全现场，普通恢复仍拒绝外部编辑。公开 worker SHA-256 `781a20f84b1f356d0d76d44c88a4ebad2fcb8a90c79f7824b136eb6cfc9aad99`；Package 0.2.1 service SHA-256 `9b7d7f1cde48b876d54326e78e0ad823b7ad948f13de6302c094b0f1d707e401`。
- 公共消费子任务 solhigh__public_consumption_020 请求 gpt-6.1-sol/high；结束前模型脚本证实实际 gpt-6.1-sol/high/openai，证据 thread_settings_applied；本子任务未再派发。整轮其他模型由主 Agent 汇总。

### 2026-10-04 SandPackage 0.2.2 公开发行
- 用户“那你发布你的”承接本次 0.2.2 的 feature PR、合并、tag/Release、Packagist 与 Wiki/消费范围。Provider 从公开 0.2.1 `39b9ee61dd7cbba11295c1b8ee6337feabecb0f9` 建独立 checkout，只迁 9 文件：已验收 Windows 技术 5 文件、配置示例、README/CHANGELOG 及 `config/app.php` 版本声明；未带入集合仓其他 Unreleased 功能。五技术 SHA-256 与上述 Windows 59/macOS 43 一致。
- 独立 Astra 审查 index tree `20506a1f7a36ce7b50994bcecb3df1a9a0fe3c20` 通过；实际 provider Git pre-commit 已接入并检查与该索引绑定的 repository-review。Commit `c6c15fd874df7f165742572fb295d3d4b2d2579e` 经正式 [PR #17](https://github.com/supdger/sand-package/pull/17) 正常 merge；公开 main、0.2.2 peeled tag 与 Release target 均为 `4d4d2607602b5b63b3aa06bfe7a0aa4d58af3847`，merge tree 与独立验收一致。历史 0.2.1 tag 未改。
- 同一独立 provider 的发行/静态工具回归 35/35、PHP 语法与暂存空白检查通过。从 clean merge Git archive 生成 82 文件 `system-update.json`，`package=supdger/sand-package`、`version=0.2.2`、`host_min=0.2.0` 无上限、`schema_changes=false`、`skeleton_changes=false`。每一载荷摘要与 committed tree 一致。
- [0.2.2 Release](https://github.com/supdger/sand-package/releases/tag/0.2.2) 为公开稳定版；公开重新下载资产 SHA-256 `b288c82a39b5cf0e503b2ac91f2c9631ee196ad02e85a64b21db76967d4c8ebb` 与本地产物、GitHub asset digest 一致。正常 Packagist p2 已索引 0.2.2，source/dist references 同为上述 merge commit，PHP ≥8.2、Core ^0.2 依赖未变。证据 `/private/tmp/sand-package-022-packagist.json` 与 `/private/tmp/sand-package-022-public-download/system-update.json`。
- 本段仅确认 provider 公开发行链；正常安装消费、Wiki 同步和既有用户宿主系统更新仍由各自证据确认，5.6 不因 Release 自动勾选。Provider 子任务请求/实际模型均 gpt-6.1-sol/high/openai（current_model_record.py / thread_settings_applied），未再派发子任务。

### 2026-10-04 宿主消费与 Wiki 发布进度
- 主控确认 SandAdmin [PR #41](https://github.com/supdger/sandadmin/pull/41) 已正常合并，公开消费 revision `642651f61322eab11cc4b223756396752356236c`：Core 保留 0.2.1，Package 锁为 0.2.2 / `4d4d2607602b5b63b3aa06bfe7a0aa4d58af3847`。本段只记录公开依赖约束/锁及合并事实，独立 macOS/Windows fresh clone 的默认 Composer 安装验收尚在执行。
- 本次 Wiki 仅同步受影响两页，公开 Wiki revision `cca71de28e548b7a5e001f1662c4abd5cc565d6a` 已由主控确认；不扩为其他文档重建或所有链接/部署方式的验证。
- 5.6 待最终 fresh 官方消费证据后核对；3.5 既有部署宿主上线、真实登录/数据库业务及目标平台页面验收仍未执行，不因依赖合并或 Wiki 发布自动完成。

### 2026-10-04 0.2.2 正常公开消费 fresh 验收
- 消费子任务最终核对公开 SandAdmin revision `642651f61322eab11cc4b223756396752356236c` 的独立 macOS / Windows fresh clone，正常默认 `composer install` 各完成 102 installed / 0 updates，安装 scripts 与 autoload 正常，退出 0；不使用本地源码替代公开发行。Core 保留公开 0.2.1，Package 为公开 0.2.2 / `4d4d2607602b5b63b3aa06bfe7a0aa4d58af3847`。
- macOS 实际安装 payload 摘要、依赖 refs 与真实 vendor `SystemUpdate` status 核对通过。Windows Core 后端 152 / 前端 396 文件、Package 后端 60 / 前端 9 文件全部匹配，实际依赖 refs 正确，真实 vendor `SystemUpdate` 与原生 Windows 平台检查通过；这是正常 Composer 安装、源码发布及已安装入口证据。
- fresh 宿主尚未配置真实 reload / health / static，相关检查如实失败；本轮未执行真实 HTTP、完整后台更新、既有用户宿主升级、登录或数据库业务。Windows 默认 autocrlf 导致 git status 提示，但实际 `git diff --exit-code` 为 0；不将其表述为 literal clean 工作树。
- 本次 Provider Release、Packagist、公开消费 revision 和 fresh 默认安装交付均已确认；5.6 仍包含既有用户宿主系统更新，3.5 仍包含既有部署/登录/数据库/页面验收，两项保持未勾选。隔离资源清理由消费子任务按明确所有权收尾，不扩大到既有宿主或共享资源。


## 6. 用户只点升级的框架自动准备（0.2.3，2026-10-05 已公开发行）
- [x] 6.1 复用现有 OpenSpec 与提供方边界；冻结标准宿主实际监听、公共目录、主进程和命令来源，不要求用户编写准备脚本
- [x] 6.2 status 只读识别，inspect 锁内核对受管理源码并准备静态基线；默认静态 base 与前端构建匹配
- [x] 6.3 内置主进程归属与身份保护、loopback 随机挑战、运行版本及入口 JS/CSS HTTP 摘要健康校验；原空静态基线恢复不误判失败
- [x] 6.4 本机自动环境 26 项、原更新/自更新/恢复 43 项、真实 Workerman 隔离重载 6 项通过；独立上下文复验 43/26/9 通过
- [x] 6.5 受影响 README、CHANGELOG、配置示例与本地 Wiki 候选同步，不发布或改写公开 0.2.2 版本说明
- [x] 6.6 Windows 新增自动命令解析和标准监督器祖先识别/触发链路的真实平台隔离验收：运行时 61 项、标准监督器 9 项及实际原生命令 2 项通过
- [ ] `[auth-gate]` 6.7 正式发行、Packagist 0.2.3 索引、公开 SandAdmin 默认约束/锁更新及公开合并提交的 fresh 正常 Composer 安装已完成；截图宿主身份、用户既有宿主升级及登录业务验收未完成

### 自动准备候选证据
生产 SHA 清单 `/tmp/sand-auto-readiness-production-sha.txt` 冻结 6 个后端生产文件。源码范围为 SandPackage，未修改其他插件、宿主安装副本或数据库。`SystemUpdateEnvironmentTest.php` 实跑 26/26（需要本地 socket 权限）：只读探测、静态自动接管/本地冲突保护、随机端口 HTTP、挑战拒绝与清理、错误资产 base 拒绝、原空静态恢复、Windows CLI 入口解析和错误 PID 拒绝。`SystemUpdateWorkermanTest.php` 用现有 Workerman 依赖，在 `/tmp` 独立随机端口运行真实 master/HTTP child，6/6：精确宿主启动身份、SIGUSR1 替换 worker、新运行版本、master 身份不变、挑战清理。隔离 master 已停止、临时运行目录已删除。

独立验收先复现 index 正常而 `/assets` 404、错误 PID readiness 误通过及默认 Windows batch argv 问题，候选返工后重新冻结。独立复验 43/26/9 通过：原有完整 runtime、自动准备行为与真实 Workerman 正向/身份变化拒绝。Windows CLI 解析的本机文件夹具只证明解析行为，不代表 Windows 原生进程实际运行。公开 0.2.2、正式用户 Windows 宿主及线上登录不在本次验收证据中。


本轮同时修正系统更新体验与插件仓库卡片两个用户目标：升级由框架准备，普通管理员只选择并确认；仓库卡片的版本紧随标题，简介保留独立空间，作者与操作固定底部。卡片源码与真实组件界面验收由本轮管理端子任务及不同上下文独立验收完成，具体截图、组件输入与测量记录由主控核对。两目标已纳入 0.2.3 正式发行，不表示截图所示既有宿主已更新。

### 2026-10-05 自动准备 Windows 实机验收
- 冻结 worker SHA-256 `35c6249ed643eab0ea85512e94081af3f0f2ebd97ff179d17493997924ad4434`，RuntimeTest `8cb240cf99780aa105b6014fa4184e8268430b32342b56510d818d8a28ed8999`、WindowsHostTest `3039d20c03db8bc337a603e03faa304687f59ef1d054a2004290b4f42a53e742`；远端文件与权威候选同字节。真实 Windows 10 / PHP 8.2.12 / Windows PowerShell 5.1 的运行时 61/61、退出 0，包含长 argv 私有载荷传递及清理、Unicode/空格/引号/反斜杠、Job 超时与 owner 中断，完整原生日志 `/tmp/sand-ready-windows-finish-original-evidence.log`。
- 标准 `windows.php` 在本任务随机端口、临时宿主运行 9/9、退出 0，真实 HTTP worker 识别所属祖先监督器、mtime 触发 worker 替换、新运行版本和静态资源健康通过；恢复真实加载原版本，原空静态部署不伪造 index。日志 `/tmp/sand-ready-windows-finish-supervisor9-cleanup.log`；独立 Astra 亲读上述 61/9 原始日志并核对候选摘要后通过。
- 从真实冻结 worker 解析并执行原生 Composer PHAR / pnpm 的 `--version`，2/2 通过。Composer 2.8.12 是本任务临时下载、核对官方 SHA-256 `f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2` 的工具；pnpm 11.19.0 是已有安装，使用已有缓存 Node 24.19.0，不改全局 PATH，不表示既有宿主 Composer 已安装或依赖已升级。
- 本任务所有临时进程为 0，监督器 fixture 和 Windows 任务目录已删除；原始日志保留在本机 `/tmp`。未修改既有宿主、访问数据库或运行其服务重载；6.7 的截图宿主身份与部署、真实登录业务仍按各自证据确认，保持未勾选。
- 主控公开回读确认 [0.2.3 稳定 Release](https://github.com/supdger/sand-package/releases/tag/0.2.3)，main/tag peeled commit `971b0f405f97d6238a4859ca04f13ccfd0289e50`、tree `3067b1b115afbaff0cad5551cd9ef74289b2bee7`；`system-update.json` SHA-256 `33ba4404e58f62d84183c76b4acdca76200019251bdf691124244b4b493a25ce` 与公开重新下载及 GitHub asset digest 一致。正常 Packagist p2 已索引 0.2.3，source/dist refs 同为上述 commit。正常 Composer 精确安装在隔离 fixture 通过、退出 0，保留标准 Webman `post-package-install` hooks，核对发行契约 84 个载荷文件、后端版本 0.2.3、前端 manifest 9 个文件及版本 0.2.3、实际 InstalledVersions refs 为上述 commit；Core manifest 为 0.2.2，记录 `/tmp/sand-package-023-public-download/consumer-verification.json`。未执行数据库或服务操作，公开宿主默认锁仍为旧版，不能据此宣称截图宿主已升级。

### 2026-10-05 0.2.3 公开宿主默认消费
- 用户另行授权公开 `supdger/sandadmin` 的两文件提交、推送和 PR 合并；[PR #44](https://github.com/supdger/sandadmin/pull/44) 已正常合并到公开 commit `fc52ba7651d1056141870e85409a7bec98f6150f`，tree `9f110a91cd144cbb336c79cd3fa7c2bf8e4d3e4b`。只修改 `server/composer.json` 的 Package 最低版本 `^0.2.3` 与 `server/composer.lock` 中 Package 0.2.3 / `971b0f405f97d6238a4859ca04f13ccfd0289e50`；Core 0.2.2 和其余 101 个锁定包不变。实际索引经不同上下文独立审查和 Git pre-commit 内容检查后提交，没有带入安装生成的前端差异。
- 在上述公开合并提交的新干净 checkout 按 README 默认入口执行正常 `composer install --no-interaction --prefer-dist`，标准 Webman scripts 保留，102 installed / 0 updates / 0 removals、退出 0，`composer validate --strict` 通过。实际 InstalledVersions 为 Package 0.2.3 / `971b0f405f97d6238a4859ca04f13ccfd0289e50`；核对官方契约 84 个 vendor 载荷摘要、62 个后端物理文件和 9 个前端 manifest 文件全部一致，Core 0.2.2 保留，Composer manifest/lock 安装后与公开提交同字节。
- 原生安装日志 `/private/tmp/sandadmin-package-023-fresh-public-install-raw.log`，实物核对记录 `/private/tmp/sandadmin-package-023-fresh-public-install-verification.json`。不同上下文 Astra 实时核对公开 main、fresh 默认安装原生日志、上述实物摘要与其余锁定包，最终公共默认消费独立验收通过，记录 `/tmp/sandadmin-package-023-public-default-independent-review.json`。此阶段补齐前段尚未完成的公共默认消费；未操作数据库、迁移、既有服务或截图宿主。截图宿主地址与真实部署/登录验收仍缺证据，6.7 保持未勾选。
