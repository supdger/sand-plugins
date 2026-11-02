# Tasks

## 1. sand-core：FrontendPublisher 核心修正

- [x] 1.1 `server/FrontendPublisher.php`：无清单非空目标改为逐文件 sha256 比对——全同则写清单收养，内容不同的同名文件才拒绝；冲突错误列出文件清单与恢复命令。（已实现：收养/拒绝语义在 FrontendPublisher::publish+assertNoConflict）
- [x] 1.2 两阶段清单：`begin` 写 `.pending`（含目标哈希集）→ copy → `rename` 生效；启动检测 pending 并按其哈希判定补写/重做。（已实现：writePending/commitManifest 两阶段）
- [x] 1.3 升级删除集：旧清单文件 − 新载荷文件，按旧哈希校验未修改才 `unlink`，已修改保留并告警；`copyTree` 对已修改的清单内文件跳过并汇总报告而非覆盖。（已实现：removeObsolete 哈希校验+copyTree 跳过已修改）
- [x] 1.4 空目录判定排除 `.DS_Store` 等易失文件；copy 阶段逐段 `is_link` 检查（对齐 worker `safePath` 语义）。（已实现：transient 易失文件+assertNoLinkedSegment 逐段链接检查）
- [x] 1.5 `initializeEnvironment` 改为按 example 键集补齐缺失键、不改已有值。（已实现：initializeEnvironment 按键补齐不改已有值）
- [x] 1.6 读取 `SAND_FRONTEND_PUBLISH`（`skip`/`force`），`force` 跳过修改校验并重建清单。（已实现：mode() 读 SAND_FRONTEND_PUBLISH skip/force）
- [x] 1.7 `tools/publish-frontend.php`：同步上述语义（或改为调用 `FrontendPublisher` 消除第三套实现）；修复自覆盖守卫的 `realpath` 缺陷。（已做：tools/publish-frontend.php 改为委托 FrontendPublisher 薄壳（验证：fresh 397 文件/幂等 0/adopted 收养）；realpath 守卫缺陷随第三套实现删除消解）

## 2. sand-core：Install 顺序、卸载边界与目标路径

- [x] 2.1 `Install::install()/update()` 调序：`installByRelation()` → `FrontendPublisher::publish()`，前端发布包 try/catch 降级为 STDERR 警告 + `composer run-script sand:publish` 指引。（已实现：installByRelation 先行+publishFrontend try/catch 降级 STDERR+恢复命令）
- [x] 2.2 `frontendTarget()` 优先读 `extra.sandadmin.frontend-path`，启发式仅作回退；`realpath` 边界 + 符号链接校验。（已实现：configuredFrontendPath 读 extra.sandadmin.frontend-path+assertWithinProject）
- [x] 2.3 `uninstall()` 改清单驱动：仅删登记文件、保留未知文件并报告保留清单；后端 `config/` 载荷仅首次写入、update 不覆盖。（已实现：清单驱动卸载+FIRST_WRITE_ONLY config/）
- [x] 2.4 `server/plugin/sandadmin/app/controller/InstallController.php`：`.env` 值统一双引号包裹 + 转义（空格、`#`、引号、反斜杠）。（已实现：InstallController renderEnv 双引号包裹+转义）
- [x] 2.5 `server/plugin/sandadmin/config/app.php`：移除 `'debug' => true` 默认。（已实现：config/app.php 无 debug 默认）

## 3. sand-package：同步语义

- [x] 3.1 `server/Install.php`：同 2.1 调序与降级；"core 基线清单缺失"降级为跳过前端 + 告警。（已实现：sand-package Install 同调序+reportFrontend 降级）
- [x] 3.2 删除 `retireLegacyTest` 硬编码哈希逻辑（并入清单驱动卸载）；载荷中的 `plugin/sandpackage/tests/` 移出发布面。（已实现：retireLegacyTest 不存在；tests/ 由 DIRECTORIES 排除）
- [x] 3.3 `server/FrontendPublisher.php`：同步 1.1–1.6 全部修正（收养、pending、删除集、易失文件、符号链接、env 演进、SAND_FRONTEND_PUBLISH）。（已实现：sand-package FrontendPublisher 同语义全套）

## 4. 重入入口与宿主接入

- [x] 4.1 sand-core 提供幂等 `sand:publish` CLI 入口（重复执行 no-op），可被 `composer run-script` 调用。（已做：幂等入口 tools/publish-frontend.php+sand-core composer.json scripts.sand:publish（no-op 已验证））
- [x] 4.2 宿主 `sandadmin/server/composer.json`：`scripts` 增加 `sand:publish` 并挂 `post-install-cmd`/`post-update-cmd`；`extra.sandadmin.frontend-path` 显式声明 `../sandadmin-artd`。（已做：宿主 server/composer.json extra.sandadmin.frontend-path+sand:publish+post-install/update 挂点（宿主仓改动待提交））
- [ ] `[impl]` 4.3 宿主 `composer.lock` 升至新 sand-core/sand-package 版本，回归 fresh clone 全路径（install → `/core/install` 200）。

## 5. 发布面收敛与载荷瘦身

- [x] 5.1 `PublishExclusions` 单一规则源；写一致性断言（publisher / `SandSystemUpdateReleaseTools` / `.gitattributes` / `archive.exclude` 四口径比对，不一致即失败）。（已实现：PublishExclusions 单一源+assert-publish-exclusions 四口径断言 consistent）
- [ ] `[impl]` 5.2 sand-core 载荷剥离：`.husky/`、`commitlint.config.cjs`、lint-staged、`"prepare": "husky"`、`"commit": "git-cz"`、`.vscode/`、`scripts/clean-dev.ts`、空 `src/mock/`、死 `fastEnter` 配置与 `artd.pro` 外链（`links.ts` 换 SandAdmin 自有地址）。
- [ ] `[impl]` 5.3 sand-package：`failed-upgrade-recovery.*` 10 个夹具移出 `sandadmin-artd/`（入包根 `tests/`）；core `tsconfig.json` 删跨包夹具引用。
- [x] 5.4 `.env.example` 的 `VITE_LOCK_ENCRYPT_KEY = change-me` 改为发布/安装时随机生成；`VITE_VERSION` 从包版本注入。（已实现：environmentLine VITE_LOCK_ENCRYPT_KEY 随机生成+VITE_VERSION 包版本注入）
- [ ] `[impl]` 5.5 宿主仓清理残留：`sandadmin-artd/` 内不受清单管理的 10 个夹具文件删除（宿主侧单独提交）。

## 6. 门禁与回归

- [ ] `[impl]` 6.1 `tools/assert-frontend-parity.php`：包载荷 ↔ 宿主仓前端清单比对（sand-package 覆盖层白名单），接 pre-commit 与 release 步骤 0。
- [ ] `[impl]` 6.2 publisher 测试补齐：非空无清单收养/拒绝、中断重入（pending）、跨版本增删、前端失败不阻塞后端、`extra` 目标路径与越界拒绝、`SAND_FRONTEND_PUBLISH` 两档。
- [ ] `[impl]` 6.3 验收矩阵：干净 clone 安装、已提交前端 clone 安装、消费者改文件后 update、钩子失败后重跑 install、卸载保留未知文件，逐项留证据。

## 7. 发版与交付

- [ ] `[auth-gate]` 7.1 sand-core、sand-package CHANGELOG + 次版本发版（注意先发 core 再发 package）。
- [ ] `[impl]` 7.2 宿主仓升 lock + `docs/getting-started.md` 增补"钩子失败后 `composer run-script sand:publish` 恢复"与 `SAND_FRONTEND_PUBLISH` 说明。
- [ ] `[device-gate]` 7.3 `test/sandadmin` 隔离宿主按新版 lock 重验一次（复用现有验证脚本）。
