# sand_plugins

面向 **SandAdmin** 的 Sand 平台插件源码仓。插件安装到 SandAdmin PostgreSQL 宿主，本仓不包含宿主应用。

原 SaiAdmin-PG 宿主的更名影响、插件适配项与发布门槛见 [SandAdmin 更名通知](sand-iam/docs/development/sandadmin-rename-notice.md)。

当前插件：

| 插件 | 说明 |
| --- | --- |
| [sand-iam](sand-iam/README.md) | SandIAM：身份、组织、应用登记、服务授权、策略与审计 |
| [sand-ai](sand-ai/README.md) | SandAI：模型、能力路由、私有文件、解析、检索、任务、用量与来源追溯 |
| [sandworkflow](sandworkflow/README.md) | SandWorkflow：流程定义、发布、发起、审批、待办、抄送与流程数据管理 |

## 演示与验收宿主契约

本仓三个插件（SandIAM、SandAI、SandWorkflow）的日常安装演示、受控同步和真实功能验收统一使用：

```text
/Users/code/project/sand_plugins/sandadmin-demo-host
```

调用服务端时使用该宿主的 `server/` 子目录。`/Users/code/project/sandadmin` 是纯净通用
SandAdmin 宿主，只用于零插件基线，不是默认发布副本、演示或验收目标。仅在明确指定其他隔离宿主时，才可
通过相应工具的 `SANDADMIN_ROOT` 覆盖默认目标。

当前 SandIAM 0.6→0.7 failed-upgrade 宿主问题见[宿主恢复交接文档](docs/handoffs/sandadmin-sandiam-demo-host-recovery.md)；SandPackage 6.1.0 与恢复契约 v2 的双向交接见[恢复 v2 交接目录](docs/handoffs/sandpackage-recovery-v2/HOST_HANDOFF.md)；v19 接收、生命周期、七链、外部互操作和证据回传见[完整宿主验收交接单](docs/handoffs/sandadmin-sandiam-v19-acceptance-handoff.md)。这些交接均未改变 FLOW 计数，也不授权直接修改宿主、演示副本或数据库。

宿主版本由本工作区主动从 SandAdmin 拉取，不接受 SandAdmin post-commit 主动覆盖。先运行
`scripts/sync-sandadmin-host.sh --dry-run` 检查差异，取得本次写宿主授权后再使用 `--apply`；
正式验收只接受 clean SandAdmin revision，并将结果写入 `sandadmin-host.lock`。完整规则见
[宿主消费与同步](docs/host-consumer-sync.md)。

插件修改完成后先用 `scripts/sync-plugin-to-demo.sh <sand-ai|sand-iam|sandworkflow> --dry-run`
审查候选导出，再显式 `--apply` 到 demo 的 `plugins/` 发布副本。不得直接编辑 demo；正式安装、升级和卸载仍走 SandPackage。

## SandAI 发布边界

`sand-ai/` 是 SandAI 可安装插件的唯一权威源码。演示宿主中的
`/Users/code/project/sand_plugins/sandadmin-demo-host/plugins/sand-ai/` 是从该目录单向同步的发布副本，
不得直接作为开发源或独立提交目标。每次同步后，执行 `sand-ai/tools/check-sandadmin-export.sh` 确认两个
目录一致；真实 PostgreSQL 安装和 API/管理端验收仍必须在演示宿主另行完成。

## SandWorkflow 发布边界

`sandworkflow/` 是 SandWorkflow 的唯一权威源码单元，包含后端、管理端载荷、生命周期 SQL、文档与发布元数据。
演示宿主中可能存在的同名目录仅用于受控同步和真实验收，不得作为并行开发源或独立提交目标。同步和宿主验收要求见
[来源与同步说明](sandworkflow/SOURCE_OF_TRUTH.md)。
