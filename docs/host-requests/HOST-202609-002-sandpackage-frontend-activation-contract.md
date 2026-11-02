# HOST-202609-002：SandPackage 管理端前端激活契约缺失

- 类型：`HOST`
- 状态：`已发送至 SandAdmin 任务 01a09082-723f-7990-8e41-a3c418800f34，并已告知协同任务 01a09843-6204-7b12-8762-319aeba177df；待宿主接收处理（不等于已接单或已修复）`
- 范围：通用 SandPackage/SandAdmin 宿主安装与管理端载荷激活契约；SandIAM 仅作为冻结消费方，不声称已有第二插件复现
- 冻结宿主来源 revision：`558d92959947230ee562f29e015c62566be58c8e`（`sandadmin-host.lock`，`source_state=clean`）
- 冻结插件来源 revision：`fa344cdfadfc7adb6306d2507809e4a493a0e319`（`demo-plugin-locks/sand-iam.lock`，`source_state=clean`）
- 插件版本：`sand-iam 0.7.1`，宿主声明 `6.x`，数据库 PostgreSQL
- 当前 demo 工作树：存在未提交的 SandIAM 前端及其他插件变更；本请求不把 dirty demo 当作正式验收基线
- 候选包 SHA-256：未形成最终签名/发布候选；本请求不上传、不安装、不构建、不部署
- 授权边界：本证据未写入 SandAdmin、demo、数据库、registry、服务、锁文件或部署；发送请求仅用于宿主接收处理，不授权同步、提交、推送或其他宿主变更，也不代表已接单或已修复

## 问题摘要

SandIAM 公开安装文档已经定义完整 ZIP 的插件市场入口和 SandPackage 生命周期，但没有定义普通开发者在 ZIP 安装后如何执行管理端前端生产构建并使页面生效。宿主存在 `web-build` 配置，但当前管理端插件安装页只暴露前端依赖安装 `web-install`，没有公开、可追溯的构建入口或成功状态。

这使“ZIP 已安装”与“管理端页面已发布”之间存在未定义的宿主契约。插件侧不能安全猜测宿主的构建命令、Node/pnpm 版本、产物目录、缓存/重载方式或失败回滚规则。

## 冻结的源码与文档证据

1. SandIAM 文档要求从插件市场 `/plugin/sandpackage/install/index` 上传完整 ZIP；不得上传解压子目录。[`sand-iam/docs/user-guide/installation-and-upgrade.md:25-31`](../../sand-iam/docs/user-guide/installation-and-upgrade.md:25)
2. SandIAM 管理端载荷目录冻结为 `sandadmin-artd/src/views/plugin/sand-iam/`；文档明确写着本仓没有宿主管理端构建/发布命令，要求运维方确认宿主既定流程。[`sand-iam/docs/user-guide/installation-and-upgrade.md:35-46`](../../sand-iam/docs/user-guide/installation-and-upgrade.md:35)
3. SandAdmin 本地前端开发文档只定义宿主级命令 `corepack pnpm install`、`corepack pnpm dev` 和 `corepack pnpm build`，不是插件安装后的公开激活流程。[`sandadmin-demo-host/docs/getting-started.md:20-26`](../../sandadmin-demo-host/docs/getting-started.md:20)
4. 宿主 `sandadmin-artd/package.json` 的 `build` 为 `vue-tsc --noEmit && vite build`。[`sandadmin-demo-host/sandadmin-artd/package.json:9-13`](../../sandadmin-demo-host/sandadmin-artd/package.json:9)
5. SandPackage `InstallLogic` 的允许部署路径包含后端 `server/plugin/<app>` 和前端 `sandadmin-artd/src/views/plugin/<app>`；安装生命周期会部署文件、登记服务、重载服务并标记已安装，但没有在该生命周期中显示执行前端构建。[`sandadmin-demo-host/server/plugin/sandpackage/app/logic/InstallLogic.php:117-124`](../../sandadmin-demo-host/server/plugin/sandpackage/app/logic/InstallLogic.php:117) [`sandadmin-demo-host/server/plugin/sandpackage/app/logic/InstallLogic.php:247-282`](../../sandadmin-demo-host/server/plugin/sandpackage/app/logic/InstallLogic.php:247)
6. 宿主配置确实声明了 `web-build`（npm/yarn/pnpm 的 `* run build`），但它只是配置能力；当前终端组件的 `frontInstall()` 调用的是 `web-install`，未调用 `web-build`。[`sandadmin-demo-host/server/plugin/sandpackage/config/terminal.php:28-56`](../../sandadmin-demo-host/server/plugin/sandpackage/config/terminal.php:28) [`sandadmin-demo-host/sandadmin-artd/src/views/plugin/sandpackage/install/terminal.vue:96-104`](../../sandadmin-demo-host/sandadmin-artd/src/views/plugin/sandpackage/install/terminal.vue:96)
7. 当前插件安装页的成功判断仍由插件文档补充：SandPackage 明确安装成功、SandIAM 菜单可见、总览可打开；源码目录存在不等于管理端已发布。[`sand-iam/docs/user-guide/installation-and-upgrade.md:42-48`](../../sand-iam/docs/user-guide/installation-and-upgrade.md:42)
8. 宿主公共架构要求插件与宿主之间的前端载荷路径、环境变量和命令写入兼容性矩阵，但当前没有提供本问题所需的完整激活契约。[`sandadmin-demo-host/docs/architecture.md:19-23`](../../sandadmin-demo-host/docs/architecture.md:19)

## 最小复现（只读、无数据库/服务）

1. 准备含 `sandadmin-artd/src/views/plugin/sand-iam/` 的完整 ZIP。
2. 按公开文档上传至 `/plugin/sandpackage/install/index` 并完成 SandPackage 安装。
3. 检查 SandPackage 是否只完成文件部署/生命周期，而没有可追溯的前端 build、产物摘要、缓存失效或重载结果。
4. 查找普通开发者可调用的唯一 `web-build` UI/CLI/API 入口；当前仓库证据只能找到宿主配置项，找不到安装页公开调用链。

预期：安装成功后存在一个公开、固定、可复核的前端激活动作和机器可读成功状态。

实际：ZIP/源码载荷路径与安装入口明确；前端依赖安装入口存在；前端生产构建和生效确认没有完整公开契约。不要以静态载荷存在、SandPackage `state=installed` 或后端服务重载单独宣称管理端已发布。

## 请求的宿主契约

请 SandAdmin/SandPackage 提供并公开记录以下通用契约，适用于所有携带管理端源码载荷的插件：

### 1. 源码/静态产物模式

- 明确 ZIP 中管理端载荷是宿主构建输入（源码）还是已构建静态产物。
- 若为源码，明确插件源码如何进入宿主前端工程、是否允许插件携带独立 lockfile/依赖。
- 若为静态产物，明确产物目录、入口清单、资源寻址、版本/摘要绑定和宿主加载方式。
- 同一 ZIP 不得出现“源码已部署但静态产物未激活”的未定义中间成功态。

### 2. 唯一构建入口

- 提供普通开发者可执行的唯一 UI、CLI 或 API 入口；说明上传 ZIP 后是否自动触发。
- 固定工作目录（当前候选是 `sandadmin-artd/`）、命令、参数、环境变量和权限要求。
- 若沿用 SandPackage terminal，明确 `web-build.<npm|yarn|pnpm>` 的公开调用路径；不能只保留配置项。
- 明确 `web-install` 与 `web-build` 的先后关系、是否使用 `corepack`，以及依赖安装失败时的状态。

### 3. Toolchain 与 lockfile

- 固定 Node、包管理器、corepack/pnpm 版本范围及校验方式。
- 明确使用哪个 lockfile、是否 `--frozen-lockfile`、是否允许网络访问、缓存位置和离线失败语义。
- 构建必须绑定宿主 revision、插件候选 SHA、依赖 lockfile 摘要及构建工具链摘要。

### 4. 失败、超时与可恢复状态

- 定义依赖安装失败、构建失败、进程退出、超时、输出截断、服务重载失败的稳定错误码和状态。
- 定义超时上限、终止子进程、保留日志/请求 ID/候选摘要和下一步动作。
- 失败时不得把插件标为“管理端已发布”；应保留可重试或明确回滚状态。

### 5. 产物、缓存与重载成功状态

- 定义构建产物目录、文件清单/摘要、静态资源版本号或 manifest。
- 定义前端静态服务如何加载新产物、浏览器/CDN/宿主缓存如何失效。
- 明确后端重载是否足够；若需前端静态服务重启或独立发布，给出唯一入口。
- 提供机器可读的最终成功条件，至少包含：候选身份、源码/产物摘要、build 状态、静态服务生效状态、缓存失效/重载状态。

### 6. 原子回滚

- 构建或激活失败时，原前端源码、产物、manifest、菜单/路由和 SandPackage 状态必须保持一致。
- 升级时定义旧产物备份、替换顺序、失败恢复和重复重试行为。
- 不得出现后端已标记 installed、前端目录已替换但页面仍加载旧/半成品资源的不可判定状态。

### 7. 普通开发者公开步骤

请在 SandAdmin/SandPackage 公共文档给出可直接执行的步骤：

1. 校验 ZIP、签名和 manifest。
2. 进入插件市场并上传完整 ZIP。
3. 选择安装/升级并等待生命周期结果。
4. 若不自动构建，调用唯一公开的前端 build/activate 入口。
5. 查看依赖、构建、产物、重载和缓存状态。
6. 重新登录，确认插件菜单、总览和一个页面入口。
7. 失败时按错误码和恢复入口处理，不手工复制目录、执行 SQL 或猜测 shell 命令。

## 验收条件

- 在干净 SandAdmin 宿主和一个独立插件 ZIP 上，普通开发者仅依照公开文档完成安装、前端激活和重新登录。
- 记录宿主 revision、SandPackage 版本、插件 ZIP SHA-256、Node/包管理器/lockfile 摘要、build 命令、产物摘要、缓存/重载状态和最终页面 URL。
- 人为注入依赖失败、构建失败、超时和重载失败；每种情况均保持状态可解释、可重试或可回滚，且不残留半成品。
- 连续安装、升级、重复升级和卸载后，前端源码/产物、菜单、路由、后端插件状态与数据库生命周期保持一致。
- 宿主提供的成功状态必须能区分：`ZIP accepted`、`lifecycle installed`、`frontend built`、`frontend activated`、`frontend verified`；不能以单一 `installed` 替代全部阶段。

本请求只冻结契约缺口和公开验收要求；在 SandAdmin 接受并提供契约前，SandIAM 不修改宿主、不在插件侧猜测构建/部署命令，也不把静态 ZIP 检查或源码目录复制计为管理端已发布。
