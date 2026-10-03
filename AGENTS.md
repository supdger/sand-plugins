# AGENTS

先读 [README.md](README.md)。本仓是 SandAdmin 插件源码集合，不是宿主应用。

## 当前插件

- **SandIAM**：先读 [sand-iam/AGENTS.md](sand-iam/AGENTS.md)、[产品需求](sand-iam/docs/product/sand-iam-product-requirements.md)。
- **SandAI**：先读 [sand-ai/README.md](sand-ai/README.md)、[全量插件包契约](/Users/code/project/sand_ai/docs/development/sand-ai-plugin-package-contract.md)。`sand-ai/` 是可安装包的权威源码；`/Users/code/project/sand_plugins/sandadmin-demo-host/plugins/sand-ai/` 才是受控同步的演示发布副本。
- **SandWorkflow**：先读 [sandworkflow/README.md](sandworkflow/README.md)、[权威来源与宿主同步约定](sandworkflow/SOURCE_OF_TRUTH.md)。`sandworkflow/` 是完整插件包的权威源码；宿主副本仅用于受控同步与真实验收。
- 任务看板：[sand-iam/docs/development/sand-iam-task-board.md](sand-iam/docs/development/sand-iam-task-board.md)
- 协作边界：[sand-iam/docs/development/sand-iam-pg-collaboration.md](sand-iam/docs/development/sand-iam-pg-collaboration.md)

## 硬门槛

- 仅 PostgreSQL；禁止 MySQL 方言与新建 `sa_*` 业务表。
- 插件表 `sand_<domain>_*`；身份/应用/凭证/服务授权/审计归 SandIAM。
- 业务拒绝用 `plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`。
- 不跨改对方独占目录；未冻结字段不得猜测实现。

## 插件宿主兼容范围

插件元数据、发布与目录维护遵守 [SandAdmin 插件宿主兼容范围](../sandadmin/docs/repository-plugin-distribution.md#插件宿主兼容范围)。

- 默认 catalog 只写 `host_min`，两份源码 `info.ini` 的 `support` 对应 `>=host_min`，不得用 `0.1.x` 等通配符隐式添加上限。安装器支持历史通配符不构成新发布方缩窄范围的许可。
- 执行、审查与消费任务遇到源码、构包脚本、旧任务说明或 catalog 的相反声明时，报告兼容规则冲突并按权威规范修正，不静默沿用；这不证明插件业务在未来宿主上实际不兼容。
- 真实旧版本上限只沿用规范要求的新版最低版本提高、实际存在且仍兼容的旧宿主上限证据；没有这些证据不得推定例外。默认自动门槛不鉴别该历史例外，需先明确核对范围和证据。
- SandIAM 完整性检查、审查候选与独立发布构包入口复用 `tools/package-payload-policy.php` 的默认开放下限检查；独立发布读取已提交的两份 INI，在写出 ZIP 前拒绝冲突。提交前用 `bash scripts/install-pre-commit-checks.sh` 保留并接入既有 hook；暂存元数据检查只读实际 Git index 的变更插件及对应两份声明，不以无关 dirty 文件阻塞本次提交。

## 目录主责

- Codex：`sand-iam/plugin/sand-iam/`、迁移、API / Adapter 契约。
- Cursor：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`。
- Codex：`sand-ai/plugin/sand-ai/`、根生命周期 SQL、API、Adapter 与插件包契约。
- Cursor：`sand-ai/sandadmin-artd/src/views/plugin/sand-ai/`。
- Codex：`sandworkflow/` 完整插件包（后端、管理端载荷、生命周期 SQL、文档与发布元数据）。
- 所有 Sand 插件的演示与验收宿主：`/Users/code/project/sand_plugins/sandadmin-demo-host`（调用服务端时使用其 `server/` 子目录）。`/Users/code/project/sandadmin` 是纯净通用 SandAdmin 宿主，不用于插件演示。
- 宿主同步采用“SandAdmin 发布/通知，`sand_plugins` 主动拉取”；先读 [宿主消费与同步](docs/host-consumer-sync.md)，默认只运行 `scripts/sync-sandadmin-host.sh --dry-run`，明确执行时才使用 `--apply`。
- 禁止 SandAdmin 通过 post-commit hook 主动改写本工作区，禁止把 demo 宿主的修改反向同步到 SandAdmin。正式验收必须锁定 clean SandAdmin revision；`dirty-local` 只能用于本地探索。
- 插件候选只通过 `scripts/sync-plugin-to-demo.sh` 导出到 demo，禁止直接编辑 demo 副本。跨宿主/插件故障按 `docs/host-requests/` 冻结版本并单变量诊断；单插件问题优先在插件侧修，只有零插件、中立扩展或多个独立插件复现的通用缺陷才提交宿主，并要求宿主尽量贴近 SaiAdmin 上游。前两轮未收敛时，第 3 轮使用 `gpt-6-astra/high`；仍无可区分结论则停止并标记阻塞。

## 必读 skill

- 改插件：`saiadmin6`、`sand-platform-conventions`、`php-webman`
- 改管理端 Vue/TS：`typescript-vue-type-safety`

## 模型使用记录

- 每次最终回复前，运行 `/usr/bin/python3 /Users/supdger/.codex/hooks/current_model_record.py`，从当前 `CODEX_SESSION_ID` 的最新 `thread_settings_applied` 读取主 Agent 的实际模型与推理档位；不得以 `config.toml` 默认值代替。
- 最终回复必须追加“模型记录”：主 Agent、推理档位、子 Agent 模型与推理档位（未使用则写“子 Agent：未使用”）。脚本失败时先核查会话记录；仅在记录确实缺失时写“未暴露（不推测）”。
