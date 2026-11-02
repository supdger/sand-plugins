# sand_plugins

面向 **SandAdmin** 的 Sand 平台基础包与业务插件权威源码仓。本仓不包含可运行宿主；`sand-core`、`sand-package` 通过 Composer 交付，业务插件通过 SandPackage ZIP 安装到 PostgreSQL 宿主。

原 SaiAdmin-PG 宿主的更名影响、插件适配项与发布门槛见 [SandAdmin 更名通知](sand-iam/docs/development/sandadmin-rename-notice.md)。

当前源码单元：

| 单元 | 分发方式 | 说明 |
| --- | --- | --- |
| [sand-core](sand-core/README.md) | Composer | SandAdmin 核心后端与同版本 `sandadmin-artd` 前端源码 |
| [sand-package](sand-package/README.md) | Composer | PostgreSQL 插件安装器、恢复能力与管理端源码 |
| [sand-iam](sand-iam/README.md) | SandPackage ZIP | SandIAM：身份、组织、应用登记、服务授权、策略与审计 |
| [sand-ai](sand-ai/README.md) | SandPackage ZIP | SandAI：模型、能力路由、私有文件、解析、检索、任务、用量与来源追溯 |
| [sandworkflow](sandworkflow/README.md) | SandPackage ZIP | SandWorkflow：流程定义、发布、发起、审批、待办、抄送与流程数据管理 |

## 版本更新

升级前查看对应版本的功能变化、修复与升级影响。日志中的未发布候选与公开版本分别标注；源码日志不代表宿主已完成升级。

| 单元 | 版本更新与升级影响 | 完整日志 |
| --- | --- | --- |
| Sand Core | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](sand-core/CHANGELOG.md) |
| Sand Package | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](sand-package/CHANGELOG.md) |
| SandIAM | [版本更新（Wiki）](https://github.com/supdger/sand-iam/wiki/Changelog) | [完整更新日志](sand-iam/CHANGELOG.md) |
| SandAI | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](sand-ai/CHANGELOG.md) |
| SandWorkflow | [版本更新（Wiki）](https://github.com/supdger/sand-workflow/wiki/Changelog) | [完整更新日志](sandworkflow/CHANGELOG.md) |

## SandIAM 当前交付入口

按[完整开源成品交付计划](sand-iam/docs/development/sand-iam-terminal-release-runbook.md)推进；[任务看板](sand-iam/docs/development/sand-iam-task-board.md)记录当前业务动作，[验收账本](sand-iam/docs/development/sand-iam-terminal-acceptance-ledger.md)记录通过证据。需求实现、真实验收和最终发布分别调度，宿主条件只阻塞有直接依赖的动作。

## 演示与验收环境

SandIAM 与 SandWorkflow 的独立插件演示、受控同步和验收使用 `/Users/code/project/sand_demo`。SandIAM 作为 SandAI 依赖时，按标准包流程安装、配置到 `/Users/code/project/sand_ai` 测试宿主。SandAI 统一产品在该工作区隔离开发和测试，最终可安装源码由本仓 `sand-ai/` 管理。SandAI 新宿主验收仅接入现有 PostgreSQL `sandai` 数据库；安装器若回退到 `sandadmin`，须作为宿主安装缺陷处理，不得据此创建或使用另一个库。

`/Users/code/project/sandadmin` 保持纯净通用 SandAdmin 源码，不作为 SandAI 插件源码或测试宿主。

当前 SandIAM 0.6→0.7 failed-upgrade 宿主问题见[宿主恢复交接文档](docs/handoffs/sandadmin-sandiam-demo-host-recovery.md)；SandPackage 6.1.0 与恢复契约 v2 的双向交接见[恢复 v2 交接目录](docs/handoffs/sandpackage-recovery-v2/HOST_HANDOFF.md)；v19 接收、生命周期、七链、外部互操作和证据回传见[完整宿主验收交接单](docs/handoffs/sandadmin-sandiam-v19-acceptance-handoff.md)。`sand-package/` 现为安装器权威实现，SandAdmin 仅消费其锁定版本；这些交接均未改变既有验收状态，也不授权直接修改演示副本或数据库。

SandAdmin 版本由本工作区主动拉取，不接受 post-commit 主动覆盖。先运行
`scripts/sync-sandadmin-demo.sh --dry-run` 检查差异，取得本次写 demo 授权后再使用 `--apply`；
正式验收只接受 clean SandAdmin revision，并将结果写入 `sandadmin-demo.lock`。完整规则见
[SandAdmin demo](docs/sandadmin-demo.md)。

SandIAM 与 SandWorkflow 向 demo 导出候选时，先用 `scripts/sync-plugin-to-demo.sh <sand-iam|sandworkflow> --dry-run` 审查，再显式 `--apply` 到 demo 的 `plugins/` 发布副本。作为 SandAI 依赖的 SandIAM 和 SandAI 本身在上文指定的隔离测试场按标准包流程安装；正式升级和卸载仍走 SandPackage。

## SandAI 发布边界

`sand-ai/` 是 SandAI 可安装产品的唯一权威源码。一个 SandPackage ZIP 同时交付后台配置插件 `plugin/sand-ai/`、对外服务与业务运行代码 `app/`、受控运行配置 `config/sand_ai_*.php`、迁移和管理端资源。`host-payload.json` 固定应用载荷清单和摘要，由 SandPackage 检查目标文件归属、冲突、升级恢复与卸载。不得在插件目录维护第二套对外 API，也不得把 `/Users/code/project/sand_ai` 的测试宿主当作发布源码。真实数据库、HTTP 和页面验收另行留证。

## SandWorkflow 发布边界

`sandworkflow/` 是 SandWorkflow 的唯一权威源码单元，包含后端、管理端载荷、生命周期 SQL、文档与发布元数据。
演示宿主中可能存在的同名目录仅用于受控同步和真实验收，不得作为并行开发源或独立提交目标。同步和宿主验收要求见
[来源与同步说明](sandworkflow/SOURCE_OF_TRUTH.md)。
