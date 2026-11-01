# Proposal: harden-system-update-recovery（消除安装/升级/恢复/前端全链用户死局）

## Why

一次宿主升级任务的真实事故（`jobs/23410fc94f10267d08c7971b186852ed`）加上两轮独立代码审计证实：用户会遇到**无路可走的死局**——升级因一次网络抖动失败、恢复在文件已还原后挂收尾、指纹门禁把恢复按钮永久锁死、前端目录交换弄丢 vite 组件映射导致白屏且无任何错误边界。用户视角=崩溃，只剩卸载重装。同类死局还有 10+ 处（见审计），根因一致：每个失败路径都把一次性环境问题放大成无法收尾的状态。

## What Changes

**A. 升级/恢复链路（sand-package worker + 协调器）**
- 预检验活：composer/pnpm/php 实际执行 `--version`，坏工具链在升级前拦截。
- composer 阶段瞬时失败自动重试（≤3 次，dist 缓存收敛）。
- 恢复解耦：文件还原成功即锁定，pnpm/reload/health 收尾失败降级为警告，任务照标 `recovered`。
- 指纹不匹配的人工收尾出口：经精确确认后跳过覆盖、只跑收尾关闭任务（门禁保留）。
- 预检/任务失败消息透出 stderr 尾部。

**B. 插件安装生命周期死局（sand-package InstallLogic 链路）**
- 兼容性/登记完整性预检前移：上传/下载阶段即校验 `support`、`config/app.php` version 一致性、依赖声明；错误携带实际值（support、host、来源文件）。
- 首次安装候选可撤回：新 `discard` 动作，删候选即可重传。
- 无宿主载荷的插件升级也建生命周期日志+恢复入口（修 SandIAM/SandWorkflow 升级无日志）。
- `info.ini`/`setInfo` 改原子写；孤儿 `.json` 守卫可经确认清理且拒绝文案带插件名。
- 上传临时目录移出插件命名空间；容量门槛统一为后端下发 `effectiveLimit`（前端不再硬编码 5MB），413 前端预判。

**C. 前端白屏死局（sand-core artd）**
- 全局静态错误边界：包 `<RouterView>` + bootstrap catch + 白屏检测兜底，任何渲染/启动失败渲染可诊断错误页（非 `Art*` 组件）带重载按钮。
- 布局组件确定性解析：`Art*` 布局组件改为静态 import/全局注册，不再依赖 watcher 内存映射。
- 加载遮罩兜底：spinner 超时硬关并提示；`routeInitFailed` 可经用户重试清除，错误页"返回首页"不再死循环。
- 懒加载 chunk 失败（`vite:preloadError`/`router.onError`）给重载提示而非静默终止。
- 兜底重定向可见化（跳首页给提示）；持久化状态迁移做形状校验、腐坏检测接通。

## Capabilities

### New Capabilities
- `system-update`: 宿主系统更新的预检验活、下载重试、恢复解耦、指纹不匹配人工收尾、错误透出。
- `plugin-install-lifecycle`: 插件上传/安装/升级/卸载/恢复的死局消除——预检前移、候选撤回、全量生命周期日志、原子登记、守卫清理、统一容量口径。
- `frontend-resilience`: 管理前端不再白屏——全局错误边界、确定性组件解析、加载兜底、chunk 失败恢复、可重试初始化、可见兜底重定向。

### Modified Capabilities
（无既有 spec。）

## Impact

- **sand-package**：`SystemUpdate.php`、`system-update-worker.php`、`SystemUpdateEnvironment.php`、`SystemUpdateController.php`、`InstallLogic.php`/`LegacyInstallLogic.php`、`InstallController.php`、`PackageArchivePolicy.php`、`PluginDependencyPolicy.php`、`RepositoryLogic.php`/`GithubRepositoryClient.php`、compat `Server.php`、`route.php`、前端 `install-box.vue`/`failed-upgrade-recovery.ts`/`system-update` 页。
- **sand-core**：`sandadmin-artd` 的 `App.vue`、`main.ts`、`utils/sys/error-handle.ts`、`utils/ui/loading.ts`、`router/guards/*`、`router/core/ComponentLoader.ts`、`views/index/index.vue`、`views/index/exception/**`、`vite.config.ts`、`.env.example`、`storage-key-manager.ts`、`components/core/layouts/art-page-content/index.vue`。
- 不改变：发行契约格式、备份范围、指纹门禁安全语义、插件命名/表前缀规则、API 响应壳契约。
- 治理：属 `sand_plugins` 跨插件变更（sand-package 基础包 + sand-core 前端），论证记录随本 change 存档。
