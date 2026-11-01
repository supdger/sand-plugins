# plugin-install-lifecycle

## Purpose

插件上传/安装/升级/卸载/恢复链路的死局消除：所有一次性环境失败都留有收尾出口，任何失败都能被诊断到具体层与值，不允许出现"只能重装"的状态。

## ADDED Requirements

### Requirement: 兼容性与登记完整性预检前移

上传或仓库下载阶段在解包校验通过后 SHALL 校验：插件 `info.ini` 的 `support` 与当前宿主版本兼容、`config/app.php` version 与 `info.ini` 一致、依赖声明可解析。校验失败 SHALL 拒绝形成候选并给出携带实际值（插件声明 `support`、当前宿主版本、来源文件）的错误。

#### Scenario: 不兼容插件在上传阶段被拒

- **WHEN** 用户上传 `support = ">=0.2.3"` 的插件而宿主为 0.1.0
- **THEN** 请求被拒并返回形如 `插件要求宿主 >=0.2.3，当前宿主 0.1.0` 的错误，不产生 WAIT_INSTALL 候选

#### Scenario: config/app.php 版本不一致被拒

- **WHEN** 包内 `info.ini` 与 `config/app.php` 的 version 不一致
- **THEN** 上传被拒并指明两处版本值与文件，不产生候选

### Requirement: 首次安装候选可撤回

系统 SHALL 提供首次安装候选的撤回动作：删除候选目录与登记草稿，释放应用名，允许重新上传。该动作 MUST 不要求任何数据库或运行文件前置条件，且不得在候选存在时拒绝重新上传。

#### Scenario: 撤回后重传

- **WHEN** 候选处于 WAIT_INSTALL 或 install 前置失败状态，操作者请求撤回并确认
- **THEN** 候选被删除、应用名释放、可立即重新上传修正包

### Requirement: 所有升级都有生命周期日志与恢复入口

任何升级（含无 `host-payload.json` 的插件）SHALL 建立生命周期日志（运行目录文件变更原子记录+回滚信息）；升级失败后 SHALL 至少存在一条可检查的恢复路径，不得出现"登记 FAILED 且所有恢复入口拒绝"的状态。

#### Scenario: 无宿主载荷插件升级中途失败

- **WHEN** SandIAM 类无 host-payload 插件升级中 SQL 已提交、文件部署失败
- **THEN** 生命周期日志存在，`inspectHostUpgradeRecovery` 等入口能检查现场并给出可执行的恢复操作

### Requirement: 登记文件原子写与损坏自恢复提示

插件登记文件（`info.ini` 等）的写操作 SHALL 使用 临时文件+rename 原子写。若登记损坏，状态查询 SHALL 返回"登记损坏"并指向修复入口，不得让所有入口一律抛异常。

#### Scenario: 写入中途崩溃

- **WHEN** worker 在写登记文件时被终止
- **THEN** 读到的是上一个完整版本或一致的损坏检测，不阻断恢复入口

### Requirement: 全局守卫与孤儿记录可收尾

宿主级 `.json` 守卫记录阻塞其他插件操作时，拒绝消息 SHALL 携带记录所属插件与应走入口；记录所属插件已不存在时 SHALL 提供经确认的显式清理动作，不得形成全局死锁。

#### Scenario: 孤儿守卫阻塞

- **WHEN** A 插件依赖任务记录残留且 A 已被手工删除，B 插件上传被拒
- **THEN** 错误消息指明记录属 A 及清理入口；确认清理后 B 可正常操作

### Requirement: 上传暂存不进入插件命名空间

上传/解压临时目录 SHALL 建于存储根的保留子目录（如 `uploads/`），不被插件列表/管理记录当作插件；残留暂存可清理，不出现 state 5 假插件。

#### Scenario: 解压中途崩溃

- **WHEN** 上传解压期间进程被杀，留下 `upload-*` 目录
- **THEN** 它不出现在插件列表，且有入口可清理

### Requirement: 容量门槛统一口径且错误可诊断

上传/下载的大小限制 SHALL 由后端 `effectiveLimit` 统一下发，前端不再硬编码（如 5MB）；HTTP 层 `max_package_size` 校验 SHALL 在安装自检中核对并给出调整指引。任何超限错误 SHALL 说明命中的限制层、实际字节数与上限。

#### Scenario: 超限上传可诊断

- **WHEN** 用户上传超过有效上限的包
- **THEN** 错误明确写出 actual/max 与限制来源（前端校验/HTTP/后端归档），仓库下载与上传路径口径一致
