## Purpose

Composer 钩子（`post-package-install` 等）触发的宿主载荷发布契约：sand-core / sand-package 把前端源码与后端插件文件发布到宿主目录，以清单（`.sand-*-source-manifest.json`）为唯一状态凭证，保证发布幂等、可重入、失败可降级、不损毁宿主已有文件。

## ADDED Requirements

### Requirement: 内容寻址的收养判定

前端发布器遇到目标目录非空且无有效清单时，SHALL 逐文件比对发布源的 sha256：目标中每个发布源文件内容相同则视为"已发布"并补写清单完成发布；仅当存在内容不同的同名文件或未受清单覆盖的新增冲突文件时，SHALL 拒绝并列出具体文件。判定 MUST NOT 仅以"文件是否存在"为依据。

#### Scenario: fresh clone 的已提交前端被收养

- **WHEN** 目标 `sandadmin-artd/` 包含与发布源逐字节一致的已提交文件且无清单
- **THEN** 发布器写入清单、执行 env 初始化并正常完成，不抛异常

#### Scenario: 真实修改仍被拒绝

- **WHEN** 目标目录无清单且存在与发布源同路径但内容不同的文件
- **THEN** 发布器拒绝，错误消息列出冲突文件路径并给出恢复命令

### Requirement: 后端载荷优先与前端失败降级

`Install::install()`/`update()` SHALL 先完成后端插件载荷拷贝（`installByRelation`），再执行前端发布。前端发布的任何失败（含 core 基线清单缺失、冲突拒绝、写盘失败）SHALL 降级为 STDERR 警告并携带可执行的恢复命令，MUST NOT 以异常中止 Composer，也 MUST NOT 回滚已安装的后端载荷。

#### Scenario: 前端冲突不拖死后端

- **WHEN** 后端载荷可正常拷贝但前端发布被冲突拒绝
- **THEN** 安装返回成功、`server/plugin/` 载荷完整，输出包含前端失败原因与 `composer run-script sand:publish` 恢复指引

### Requirement: 原子清单提交与中断重入

发布 SHALL 先把目标清单写入 `.pending` 临时文件，copy 完成后 `rename` 生效。启动发布时若发现 `.pending`，SHALL 按 pending 记录的目标哈希集判定"补写清单"或"重做 copy"，不得按"清单外修改"拒绝。

#### Scenario: 中断后续跑自愈

- **WHEN** 上一次发布在 copy 完成、清单生效前被中断
- **THEN** 下一次运行按 pending 清单完成或重做发布，不抛"已被修改"类异常

### Requirement: 可重入发布入口与逃生门

宿主 SHALL 提供幂等的 `composer run-script sand:publish` 入口（重复执行结果一致），并挂到 `post-install-cmd`/`post-update-cmd`，使钩子失败后重跑 `composer install` 可补齐载荷。`SAND_FRONTEND_PUBLISH=skip` SHALL 跳过前端发布、`force` SHALL 在清单校验外强制重发布并重建清单；两者默认值 MUST NOT 改变既有行为。

#### Scenario: 钩子失败后手动补齐

- **WHEN** `post-package-install` 曾因任何原因中止、`vendor/composer/installed.json` 已提交
- **THEN** 重跑 `composer install` 或 `composer run-script sand:publish` 可完成全部载荷发布

### Requirement: 清单驱动的删除、更新与卸载边界

升级时 SHALL 删除"旧清单登记而新版载荷不再提供"的文件，删除前按旧清单哈希校验该文件未被修改，已修改则保留并告警；`copyTree` 对清单内未修改文件执行覆盖、对已修改文件跳过并汇总报告（不得覆盖消费者修改）。`uninstall()` SHALL 仅移除清单登记的文件与因此变空的目录，未登记文件 MUST 保留；后端 `config/` 载荷仅首次安装写入，后续 `update` 不得覆盖宿主修改。

#### Scenario: 删除又加回的升级路径

- **WHEN** v1 含 `a.vue`、v2 删除、v3 重新加入，目标中 v1 残留 `a.vue`
- **THEN** v2 发布时按旧清单校验并移除该残留，v3 正常重新发布，不抛冲突

#### Scenario: 卸载不吞未知文件

- **WHEN** `server/plugin/sandadmin/` 内存在非清单登记的文件（运维或第三方放置）
- **THEN** `uninstall` 只删清单登记文件并保留未知文件，报告保留清单

### Requirement: 前端目标路径显式化与越界防护

前端目标 SHALL 优先读取宿主 `composer.json` 的 `extra.sandadmin.frontend-path`；"项目目录名为 server 则取同级"的启发式仅作缺省回退。发布前 SHALL 校验 `realpath(目标)` 位于宿主项目父级目录之内且目标路径各段非符号链接，违例 SHALL 拒绝并给出修复指引。

#### Scenario: 非标准布局不越界写

- **WHEN** 宿主项目目录名为 `server` 且位于多项目共享父目录，未配置 `extra.sandadmin.frontend-path`
- **THEN** 发布器在写入前校验目标路径边界，不满足时拒绝而不是在共享父目录创建 `sandadmin-artd/`

### Requirement: `.env` 初始化与演进

`initializeEnvironment` SHALL 按对应 `.env.*.example` 的键集补齐缺失键、不得修改或删除已有键值；`.env` 写入 MUST 对值做双引号包裹与必要转义（含安装器生成的数据库配置），使含空格、`#`、引号的值不破坏 dotenv 解析。

#### Scenario: 老实例缺新键

- **WHEN** 已存在的 `.env` 缺少新 example 引入的 `VITE_API_URL`
- **THEN** 发布后 `.env` 补齐 `VITE_API_URL = /api`，原有键值不变

#### Scenario: 密码含特殊字符

- **WHEN** 安装器收到含空格或 `#` 的数据库密码
- **THEN** 写入 `.env` 的值经转义后仍能被 dotenv 完整解析，服务可启动

### Requirement: 单一发布排除规则源

`FrontendPublisher::excluded()`、`SandSystemUpdateReleaseTools::excluded()`、`.gitattributes export-ignore` 与 `composer.json archive.exclude` SHALL 由同一份排除规则声明生成或对其做一致性断言；任意两条通道的文件集不一致时构建/检查 SHALL 失败。开发工具链文件（`.husky/`、commitlint/lint-staged 配置、`prepare`/`commit` scripts、编辑器目录、浏览器测试夹具）SHALL NOT 出现在发布载荷与清单中。

#### Scenario: 夹具不再随包发布

- **WHEN** 构建 sand-package 发布物（zipball / system-update 契约 / composer archive 任一通道）
- **THEN** `failed-upgrade-recovery.*` 测试夹具与 `.husky` 目录不出现在产物文件集，且三通道文件集一致

### Requirement: 可诊断的失败消息

发布、校验、卸载的错误消息 SHALL 包含：冲突/失败文件的实际路径、内容是否与发布源一致、命中的是哪条保护规则、可执行的恢复命令或开关名。MUST NOT 只给"拒绝覆盖/冲突"类无操作指引的文案。

#### Scenario: 冲突消息可操作

- **WHEN** 发布因用户本地修改被拒
- **THEN** 错误输出列出每个冲突文件、提示 `SAND_FRONTEND_PUBLISH=force` 与 `sand:publish` 恢复路径
