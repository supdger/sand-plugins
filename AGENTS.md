# AGENTS

先读 [README.md](README.md)。本仓是 SandAdmin 插件源码集合，不是宿主应用。

## 当前插件

- **SandIAM**：先读 [sand-iam/AGENTS.md](sand-iam/AGENTS.md)、[产品需求](sand-iam/docs/product/sand-iam-product-requirements.md)。
- **SandAI**：先读 [sand-ai/README.md](sand-ai/README.md)、[全量插件包契约](/Users/code/project/sand_ai/docs/development/sand-ai-plugin-package-contract.md)。`sand-ai/` 是可安装包的权威源码；`/Users/code/project/sandadmin/plugins/sand-ai/` 仅是受控同步的发布副本。
- **SandWorkflow**：先读 [sandworkflow/README.md](sandworkflow/README.md)、[权威来源与宿主同步约定](sandworkflow/SOURCE_OF_TRUTH.md)。`sandworkflow/` 是完整插件包的权威源码；宿主副本仅用于受控同步与真实验收。
- 任务看板：[sand-iam/docs/development/sand-iam-task-board.md](sand-iam/docs/development/sand-iam-task-board.md)
- 协作边界：[sand-iam/docs/development/sand-iam-pg-collaboration.md](sand-iam/docs/development/sand-iam-pg-collaboration.md)

## 硬门槛

- 仅 PostgreSQL；禁止 MySQL 方言与新建 `sa_*` 业务表。
- 插件表 `sand_<domain>_*`；身份/应用/凭证/服务授权/审计归 SandIAM。
- 业务拒绝用 `plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`。
- 不跨改对方独占目录；未冻结字段不得猜测实现。

## 目录主责

- Codex：`sand-iam/plugin/sand-iam/`、迁移、API / Adapter 契约。
- Cursor：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`。
- Codex：`sand-ai/plugin/sand-ai/`、根生命周期 SQL、API、Adapter 与插件包契约。
- Cursor：`sand-ai/sandadmin-artd/src/views/plugin/sand-ai/`。
- Codex：`sandworkflow/` 完整插件包（后端、管理端载荷、生命周期 SQL、文档与发布元数据）。
- 验收宿主：`/Users/code/project/sandadmin`（SandAdmin）。

## 必读 skill

- 改插件：`saiadmin6`、`sand-platform-conventions`、`php-webman`
- 改管理端 Vue/TS：`typescript-vue-type-safety`
