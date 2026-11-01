# Design: harden-system-update-recovery

基于真实事故 `jobs/23410fc94f10267d08c7971b186852ed`（composer TLS 断流 → 恢复死于 corepack → vite 再生成 d.ts 改变指纹 → 自动恢复被永久拒绝）。

## 架构改动点

### 1. 工具链验活（SystemUpdate::environmentChecks / SystemUpdateEnvironment）

- `environmentChecks` 中 `composer`/`pnpm`/`php` 三项在"命令已配置"之外，新增对 resolved argv 执行 `['--version']`（或等价无写探针），用 `\SandSystemUpdateRuntime::command()`（限时 10–15s，输出进日志但不失败即丢弃）。
- Windows 上 `nativeWindowsCommand` 只保证可执行文件被解析；验活把"corepack shim 崩/`node:sqlite` 缺失/命令不存在"统一收敛为预检 fail。
- 成本：每个工具一次子进程，<1s；不通过则 `can_start=false`。

### 2. composer 阶段自动重试（worker `run()` composer phase）

- 在 `run()` 的 `composer` phase 内，对 `composerArguments($plan, false)` 包装最多 3 次执行：捕获 `\SandSystemUpdateRuntime::command` 抛出的 RuntimeException → emit `composer-retry` 日志 → 重跑同一 argv。
- composer 幂等（同一 require 重跑安全）+ dist 缓存保证收敛；仅对退出码非零重试，不做错误分类（简单可靠）。
- 重试耗尽才走既有 catch（mutated=true → fingerprint + recovery_available）。

### 3. 恢复收尾解耦（worker `restore()`）

- 现状：restore → frontend-restore(pnpm) → reloadAndHealth，任一步失败 → catch → `recovery_required`，掩盖"文件已还原"事实。
- 改法：restore phase 完成后即标记 `files_restored=true`；`frontend-restore` 与 `reloadAndHealth` 的失败降为 **收尾警告**：`$this->job['state']='recovered'`，`error` 记录收尾警告（含 stderr 尾部与手工指引），`recovery_available=false`。
- reload/health 失败同样降级为警告但任务仍记 recovered——文件层恢复事实不变；健康检查失败的原因写进 error（现状也是把失败暴露给用户，只是状态语义错误）。
- 例外：restore phase 本身（校验/拷贝）失败仍走原失败路径。

### 4. 指纹不匹配人工收尾（SystemUpdate + API + 前端）

- 现状：`recover()` 指纹不一致 → `ApiException('失败后宿主文件发生外部修改，拒绝覆盖')`，死锁。
- 新动作 `finalize`（或 `recover` 增 `manual=true` + confirmation 文本参数）：
  - 前置：任务处 `failed|recovery_required` 且 `backup.json` 存在；要求 `confirmation === 'accept-current-files'`（或任务 id）精确确认。
  - 行为：跳过指纹比对与文件还原，仅执行收尾——`pnpm install --frozen-lockfile`（失败按第 3 点降级）→ `reloadAndHealth` → 状态 `recovered`，日志记录人工确认。
  - 指纹门禁保持强制：不提供此确认时原拒绝行为不变；此出口绝不执行文件覆盖。
- 路由：`POST /app/sandpackage/system-update/finalize`（或复用 recover 端点加参数）；前端在"恢复被拒"提示旁提供"我已人工核对，执行收尾"按钮 + 确认输入。

### 5. 错误详情透出

- `inspect()` 的 `preflight` check：catch 到错误时把 `\SandSystemUpdateRuntime::redact()` 后的 stderr/输出尾部（末 3–5 行、≤2KB）拼进 `check.message`。
- worker `emit('failed', …)` 的 `job['error']` 已存完整错误；任务 logs 保留各命令行——确保 `publicTask()` 返回的错误含末次命令输出尾部而非仅"退出码 N"（command() 抛错时附带捕获的 stderr 尾部）。

### 6. 完成提示

- `emit('succeeded'/'recovered', …)` 消息与前端任务卡提示：开发模式部署（frontend 存在 pnpm dev 场景）提示重启 dev server 使组件重新扫描；生产部署由 static manifest health 覆盖。

## 安全边界（不变）

- backup/restore 范围、manifest 比对、`failure_fingerprint` 门禁语义不动。
- 人工收尾出口只做"跳过覆盖的收尾"，永不覆盖外部修改的文件。
- 脱敏（`redact`）贯穿所有新日志/消息。

## 审计增补（三份独立审计的代码行级机制）

### worker 死局类（57b48cb1）

- **指纹门禁只盖受管文件**：`scopes()` 前端 exclude 仅 `['node_modules','.git']`，把 `dist/`、`src/types/import/*.d.ts`、`.auto-import.json`、`*.local`、`.husky/_`、`Thumbs.db`/`desktop.ini`/`.DS_Store` 全部纳入指纹 → `pnpm dev` 一生成即永久破坏门禁。修法：`tree()` 的 exclude 支持嵌套路径，补全项目自身 gitignored 生成集；更进一步门禁只比对**受管文件**（composer 两文件、vendor、plugin 目录、manifest 清单内前端文件、static），非受管差异降级为"需确认警告"而非硬拒。
- **卡死状态必有出口**：`task()` 把 >30s 静默任务一律升 `recovery_required`——即使 `mutated=false`（什么都没改）。修法：`mutated=false` 直接标 `failed`；新增 `abandon` 终态转移（确认已人工核对 backup/ 后释放 `assertNoPending`/`latest` 阻塞）；错误文本给出 `manual-inspect` CLI 路径。
- **restore 幂等断点**：当前每次恢复都 remove+copy 全量且必跑 plan 冻结的 pnpm。修法：`task.json` 记 `files_restored`/`frontend_started` checkpoint；失败前未到 frontend 阶段则跳过 `pnpm install`；从失败步续跑；恢复时**重新解析** pnpm/composer（不把冻结 plan 的坏 shim 带回来）。
- **Windows 工具解析**：`nativeWindowsCommand` 按 PATH 序取首个命中，系统 PATH 的 corepack shim 优先于真实 `pnpm.exe`/`pnpm.cjs`。修法：遍扫 PATH 优先选真实 pnpm（`pnpm.exe`、含 `node_modules/pnpm/bin/pnpm.cjs`），命中前记录所选；验活（spec 第 1 条）兜底。
- **预检健康路径**：`static.enable=false` 时健康检查仍强求 `index.html` → 90s 超时 → 升级+恢复双失败循环；`runtime/monitor.lock` 残留/未来 mtime 会让 touch 触发哑火。修法：inspect 期核对 `config('static.enable')` 与 probe 可达、monitor.lock 缺席、mtime 不超前。
- **在线目录原子交换**：`remove()+copy()` 对运行中的 `plugin/*`、`static` 有时间窗（Windows 文件占用 unlink 失败留半空树）。修法：staged `dir.new`→`dir`→`dir.old` rename 交换。
- **终态写入兜底**：`emit('failed')` 写 task.json 失败则记录永远停在 running → 死局。修法：sidecar `failed.json`（最小 `{state,error,failure_fingerprint}`），API 读 task 时合并。
- **成本**：`start()` 里 fingerprint 算两遍（`SU:103/108`）去一；Windows `tree()` 每文件 `realpath` 可用 installed.json+包树优化。

### 安装生命周期死局类（c026d036）

- **预检前移**：`uploadFromPath()` 解包校验后、`rename` 进候选前调 `assertHostCompatibility` + `config/app.php` version 一致性 + 依赖声明解析；错误携带 `support=… host=… source=…`。
- **候选撤回**：新 `discard` 动作——删候选目录+登记草稿，无 SQL/运行文件前置，候选存在不再阻塞重传（修 1716 死局+F1）。
- **无载荷升级补日志**：`install()` 中 `$hostJournal`/`$runtimeChange` 创建条件从 `$hostChange !== null` 放宽到 `$isUpdate`；`inspectHostUpgradeRecovery` 接受"无 host 文件、有 runtime 日志"现场（修 SandIAM/SandWorkflow F2）。
- **原子登记**：`Server::setInfo` 改用既有 `writeDependencyRecord()`（tmp+fsync+rename）。
- **守卫可收尾**：`.json` 守卫拒绝文案带插件名/入口；孤儿记录（所属插件已删）给显式确认清理。
- **暂存隔离**：`upload-*` 临时目录移到 `uploads/` 保留区；`PluginStorage::directories()`/`managedRecords()` 忽略。
- **容量口径**：前端 `install-box.vue`/`failed-upgrade-recovery.ts` 的 5MB 改读后端下发 `effectiveLimit`；`InstallController::index()` 自检加 `max_package_size ≥ effectiveLimit + multipart 余量` 检查；所有超限错误带 actual/max+层名。

### 前端白屏类（e2c84d81）

- **错误边界**：`App.vue` 包 `onErrorCaptured` 静态边界组件（纯 HTML，不用 `Art*`）；`main.ts` `app.mount` try/catch 写静态兜底；`error-handle.ts` `scriptErrorHandler` 停止 return true（恢复原生堆栈），`registerResourceErrorHandler` 对 SCRIPT/LINK 失败弹重载提示。
- **确定性布局**：`views/index/index.vue` 把 5 个布局组件改静态 import（或 `app.component` 全局注册）；`vite.config.ts` `Components()` 显式 `dirs` + 决策 `allowOverrides`。
- **遮罩看门狗**：`loading.ts` 绝对超时硬关+可见提示；`beforeEach` 任何早退路径必经 `hideLoading`（修 HOST 回退版 pendingLoading 泄漏）。
- **chunk 失败**：`router.onError` + `vite:preloadError` 监听 → 提示+一键 reload。
- **初始化可重试**：`routeInitFailed` 由用户重试清；`ArtException` 的"返回首页"先 `resetRouteInitState()`。
- **兜底可见化**：`continueWithDynamicRoutes`/`handleRootPathRedirect` 静默重定向加 toast；`findDefaultMenuPath` 先验证目标可解析。
- **存储校验**：`storage-key-manager.migrateData` 加形状校验；`App.vue` 调 `checkStorageCompatibility` 传真实登录态（接通现在被 requireAuth=false 短路掉的检测）。
- **env 校验**：`.env.example` 补 `VITE_API_URL`/`VITE_API_PROXY_URL`；vite 启动缺关键 env 即报错（不代入 undefined）。
- **`ArtPageContent` 闩锁**：`isRefresh`/`showTransitionMask` 改 finally 释放或看门狗，防止 nextTick 抛错永久空内容区。

## 验证要点

- 单测/脚本：验活失败注入（坏 pnpm → 预检 fail）；composer 假失败→重试计数；restore 后 pnpm 失败 → recovered+警告。
- 人工收尾：制造指纹不匹配 → 无确认拒绝 → 带确认只跑收尾关闭任务；孤儿守卫清理路径。
- 目录交换：前端 watcher 丢失场景下布局静态 import 仍可用；错误边界捕获渲染异常不白屏。
- 回归：正常完整升级/安装路径不受影响。
