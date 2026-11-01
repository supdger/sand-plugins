# Tasks: harden-system-update-recovery（消除全链用户死局）

权威源码：`/Users/code/project/sand_plugins`（`sand-package`、`sand-core`）。跨插件变更论证随本 change 存档。

## A. 升级/恢复链路（worker + 协调器）— 已完成（组 6c768f5e，路由已归拢）

- [x] `SystemUpdateEnvironment`：工具验活（resolved argv 执行 `--version` 探针，限时）；Windows `nativeWindowsCommand` 遍扫 PATH 优先真实 pnpm（非 corepack shim）并记录所选
- [x] `SystemUpdate::environmentChecks`：composer/pnpm/php 接入验活；`inspect()` `preflight` 失败消息带 stderr 尾部
- [x] `SystemUpdate::task()`：`mutated=false` 的静默任务标 `failed`（不升 `recovery_required`）；去掉 `start()` 里的重复 fingerprint
- [x] `SystemUpdate::recover`/新 `finalize` 动作：指纹不匹配时提供人工收尾（精确确认、跳过覆盖、仅收尾关闭）；错误文本带 `manual-inspect` CLI 指引
- [x] `scopes()`/`tree()`：exclude 支持嵌套路径，补全生成集（`dist`、`src/types/import/*.d.ts`、`.auto-import.json`、`*.local`、`.husky/_`、OS 垃圾文件）；门禁改为只比受管文件，非受管差异降级为确认警告
- [x] worker composer phase：命令失败自动重试（≤3 次）
- [x] worker `restore()`：`files_restored`/`frontend_started` checkpoint；失败前未到 frontend 阶段跳过 pnpm；从失败步续跑；工具重新解析
- [x] worker 恢复收尾降级：restore 完成后 pnpm/reload/health 失败 → `recovered`+警告而非 `recovery_required`
- [x] worker 在线目录改 staged rename 交换（`plugin/*`、`static`）
- [x] worker 终态写入兜底：emit('failed') 失败时写 sidecar `failed.json`，API 合并读取
- [x] inspect 期预检健康路径：`config('static.enable')`、probe 可达、无 `monitor.lock`、无未来 mtime（⚠ live HTTP probe 未做，见报告）

## B. 插件安装生命周期 — 已完成（组 57622ac5，路由已归拢）

- [x] `uploadFromPath()`/`RepositoryLogic::download()`：解包后即校验 `support` 兼容、`config/app.php` version 一致、依赖声明可解析；错误带实际值与来源
- [x] 新 `discard` 动作：撤回首次安装候选（删目录+草稿），API+CLI+前端入口
- [x] `install()`：`$runtimeChange`/`$hostJournal` 创建条件放宽到 `$isUpdate`；`inspectHostUpgradeRecovery` 接受无 host 文件仅有 runtime 日志的现场
- [x] `Server::setInfo` → `writeDependencyRecord` 原子写
- [x] `.json` 守卫拒绝文案带插件名/入口；孤儿记录确认清理
- [x] `upload-*` 暂存移到 `uploads/`；`directories()`/`managedRecords()` 忽略
- [x] `install-box.vue`/`failed-upgrade-recovery.ts` 上限改读后端 `effectiveLimit`；`InstallController::index()` 自检加 `max_package_size` 余量检查；超限错误统一带 actual/max+层名
- [x] `install()` 不存在插件先报"目录不存在"而非兼容错（:292 顺序）

## C. 前端白屏（sand-core artd）— 已完成（组 996dc094）

- [x] 静态错误边界组件（非 `Art*`）：`App.vue` 包 RouterView；`main.ts` mount try/catch 写兜底；`scriptErrorHandler` 停止吞栈；资源错误 handler 给重载提示
- [x] `views/index/index.vue` 布局组件改静态 import；`vite.config.ts` `Components()` 显式 `dirs`+`allowOverrides`
- [x] `loading.ts` 绝对超时看门狗+提示；守卫早退必经 `hideLoading`
- [x] `router.onError`+`vite:preloadError` → 可见提示+一键 reload
- [x] `routeInitFailed` 用户可重试清除；`ArtException` 按钮先重置初始化状态
- [x] `continueWithDynamicRoutes`/`handleRootPathRedirect` 加可见提示；`findDefaultMenuPath` 验证目标
- [x] `storage-key-manager` 迁移形状校验；`App.vue` 传真实登录态接通腐坏检测；提供"重置本地状态"出口
- [x] `ArtPageContent` `isRefresh`/`showTransitionMask` 改 finally/看门狗释放
- [x] `.env.example` 补全必需变量；vite 启动缺关键 env 即报错

## 验证（证据见各组报告；⚠ 标注项待真实宿主/dev 环境复核）

- [x] 注入坏 pnpm → 预检 fail、任务不创建（harness：ERR_PNPM_UNSUPPORTED_ENGINE 尾部透出，capabilities.supported=false）
- [x] composer 模拟断流 → 自动重试收敛/耗尽走备份（harness：恰 3 次尝试成功、2 条 composer-retry；耗尽 → failed+recovery_available）
- [x] restore 成功+pnpm 坏 → recovered+警告（harness 复现本次事故根路径：files_restored=true、错误带 pnpm 输出尾部）
- [x] 指纹不匹配 → 未确认拒绝 → 确认后只收尾关闭（真实 detached worker 端到端验证 finalize/abandon）
- [x] `pnpm dev` 中目录交换 → 布局仍解析/错误边界兜底不白屏（结构性：静态 import+全局注册已实现；⚠ 运行时需装依赖 dev 复测）
- [x] 不兼容插件上传即拒（带 support/host）；候选可撤回重传（代码路径静态验证；⚠ 未在真实宿主复现）
- [x] 无载荷插件升级失败 → 有恢复入口（inspectHostUpgradeRecovery host_payload_absent 现场；⚠ 需真实升级复现）
- [x] 正常升级/安装全路径回归（官方回归套件 SystemUpdateRuntimeTest 43 项 + EnvironmentTest 26 项 + ReleaseTools 35 项全过）

## 治理

- [x] 跨插件论证：`sand-package` 基础包 + `sand-core` 前端，记录随 change
- [x] 影响面声明：发行契约、备份范围、指纹门禁语义、API 响应壳、命名/表前缀不变（A 组声明：finalize/abandon 不新增任何覆盖外部改动的路径）
