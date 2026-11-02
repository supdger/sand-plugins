## Why

全新 clone 的 SandAdmin 宿主执行 `composer install` 在 `post-package-install` 钩子里必然失败：发布状态（`.sand-*-source-manifest.json`）被 `.gitignore` 排除，而发布产物（`sandadmin-artd/`）被 git 提交，`FrontendPublisher` 把"目标目录非空且无清单"一律判为冲突。失败还会中止 autoload 生成与后端插件载荷拷贝；且 Composer 事务已提交使失败不可重入——重跑 install 零操作"成功"返回，宿主却全站 404。

独立源码审计同时确认：发布非原子（copy 与写清单之间中断即永久砖化）、`uninstall`/`update` 无差别删改宿主 `server/plugin/`、消费者改动任一前端文件即永久锁死升级、`frontendTarget()` 按目录名启发式可能越界写到项目外、`.env` 只初始化不演进（已缺 `VITE_API_URL`）、安装器写 `.env` 不加引号导致含空格/`#` 的密码装完即崩。

## What Changes

- **内容寻址收养**：目标目录非空且无清单时，逐文件比对发布源 sha256；全部相同视为"已发布"并补写清单；仅内容不同的既有文件才拒绝。（行为变更：由"存在即拒绝"改为"内容不同才拒绝"。）
- **安装顺序与失败降级**：`Install::install()/update()` 先发布后端插件载荷再发布前端；前端发布失败降级为携带恢复命令的警告，不再中止 Composer（含 sand-package 的"core 基线缺失"硬错误）。
- **原子发布**：清单先写 `.pending` 再 `rename` 生效；启动时发现 pending 按其目标哈希集判定"补写/重做"，不当作被篡改。
- **可重入入口与逃生门**：提供幂等的 `composer run-script sand:publish`，由宿主 `composer.json` 挂到 `post-install-cmd`/`post-update-cmd`；新增 `SAND_FRONTEND_PUBLISH=skip|force` 环境变量。
- **清单驱动删除与卸载**：升级时删除"旧清单有、新载荷没有"的文件（按旧哈希校验后移除）；`uninstall()` 只移除清单登记的文件、未知文件保留；`config/` 仅首次安装写入；删除 `retireLegacyTest` 硬编码哈希逻辑。
- **前端目标路径显式化**：`frontendTarget()` 优先读宿主 `composer.json` 的 `extra.sandadmin.frontend-path`，目录名启发式仅作缺省回退；`realpath` 校验目标必须位于宿主项目父级之内且非符号链接。
- **`.env` 演进与写入转义**：`initializeEnvironment` 按 example 键集补齐缺失键、不改已有值；安装器写 `.env` 的值统一加双引号并转义。
- **判定细节修正**：空目录判定排除 `.DS_Store` 等易失文件；copy 阶段逐段符号链接检查；错误消息携带冲突文件、是否内容不同与恢复命令。
- **发布面收敛**：单一"发布排除规则"常量驱动 `FrontendPublisher::excluded()`、`SandSystemUpdateReleaseTools::excluded()`、`.gitattributes export-ignore`、`composer.json archive.exclude` 的一致性断言；发布载荷剥离 `.husky`/commitlint/lint-staged/`prepare` 脚本/测试夹具等开发物。
- **测试补齐**：publisher 测试覆盖"非空无清单收养/拒绝""中断重入""跨版本增删文件""降级不中止安装"等分支。

## Capabilities

### New Capabilities

- `composer-publish-lifecycle`: Composer 钩子触发的宿主载荷发布契约——前端源码发布、后端插件载荷拷贝、清单生命周期、失败降级与重入、卸载边界。

### Modified Capabilities

（无。现有 `plugin-install-lifecycle` 覆盖管理端 ZIP 插件生命周期；本变更新增 Composer 包级发布契约，不改其既有要求。）

## Impact

- 代码：`sand-core/server/Install.php`、`server/FrontendPublisher.php`、`tools/publish-frontend.php`、`server/plugin/sandadmin/app/controller/InstallController.php`；`sand-package/server/Install.php`、`server/FrontendPublisher.php`；`sand-core/tools/SystemUpdateReleaseTools.php`（排除规则收敛）；宿主 `sandadmin/server/composer.json`（scripts、`extra`）。
- 发布：sand-core、sand-package 各发一个次版本；宿主升 `composer.lock` 并回归 fresh clone 全路径。
- 兼容：清单格式向后兼容（既有清单照常读取）；`assertUnmodified` 等保护语义保留，仅放宽误杀分支；`SAND_FRONTEND_PUBLISH` 默认行为不变。
