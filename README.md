# sand_plugins

面向 **SandAdmin** 的 Sand 平台插件源码仓。插件安装到 SandAdmin PostgreSQL 宿主，本仓不包含宿主应用。

原 SaiAdmin-PG 宿主的更名影响、插件适配项与发布门槛见 [SandAdmin 更名通知](sand-iam/docs/development/sandadmin-rename-notice.md)。

当前插件：

| 插件 | 说明 |
| --- | --- |
| [sand-iam](sand-iam/README.md) | SandIAM：身份、组织、应用登记、服务授权、策略与审计 |
| [sand-ai](sand-ai/README.md) | SandAI：模型、能力路由、私有文件、解析、检索、任务、用量与来源追溯 |
| [sandworkflow](sandworkflow/README.md) | SandWorkflow：流程定义、发布、发起、审批、待办、抄送与流程数据管理 |

## SandAI 发布边界

`sand-ai/` 是 SandAI 可安装插件的唯一权威源码。SandAdmin 宿主中的
`/Users/code/project/sandadmin/plugins/sand-ai/` 是从该目录同步的发布副本，不得直接作为开发源
或独立提交目标。每次同步后，执行 `sand-ai/tools/check-sandadmin-export.sh` 确认两个目录一致；真实
PostgreSQL 安装和 API/管理端验收仍必须在 SandAdmin 宿主另行完成。

## SandWorkflow 发布边界

`sandworkflow/` 是 SandWorkflow 的唯一权威源码单元，包含后端、管理端载荷、生命周期 SQL、文档与发布元数据。
SandAdmin 宿主中可能存在的同名目录仅用于受控同步和真实验收，不得作为并行开发源或独立提交目标。同步和宿主验收要求见
[来源与同步说明](sandworkflow/SOURCE_OF_TRUTH.md)。
