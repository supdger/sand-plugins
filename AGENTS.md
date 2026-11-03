# AGENTS

## SandIAM 持续授权

用户已授予[本地开发与验收持续授权](docs/sandiam-local-authorization.md)。
执行其中列出的提交、候选签名、demo 生命周期、协议验收、24 小时稳定性及临时
Docker 恢复建库等操作时直接沿用，不因阶段、版本、候选或失败复测整批重新申请。
这份最新授权优先于此前对应批次的授权限制；用户后续限制及系统强制审批仍有效。

## 本地定向测试

已授权的实现或修复包含必要的本地回归，不逐次申请业务授权。SandIAM helper 测试使用
`bash scripts/test-local.sh iam-helpers`，Dart management client 测试使用
`bash scripts/test-local.sh iam-dart`。这两个入口复用已安装依赖，分别避免 tsx CLI
附带的 IPC 服务与 Dart 遥测写入；运行前应已完成依赖安装。可用 `NODE_BIN` /
`DART_BIN` 指定已安装的运行时。其他测试仍按实际依赖与既有任务授权执行；
此入口不改变数据库、服务或系统沙箱权限。

涉及 HOST-003 恢复检查及重复授权时，先读[本批授权澄清](docs/host-requests/HOST-202609-003-authorization.md)，按原任务已批准范围执行，不把 inspect 批准扩展为数据库服务管理或恢复写入。

先读 [README.md](README.md)。本仓是 SandAdmin 插件源码集合，不是宿主应用。

## 当前插件

- **Sand Core**：先读 [sand-core/README.md](sand-core/README.md)。`sand-core/` 是 SandAdmin 核心后端和匹配管理端前端源码的 Composer 包权威来源。
- **Sand Package**：先读 [sand-package/README.md](sand-package/README.md)。`sand-package/` 是 PostgreSQL 插件安装器、恢复能力和管理端页面源码的 Composer 包权威来源。
- **SandIAM**：先读 [sand-iam/AGENTS.md](sand-iam/AGENTS.md)、[产品需求](sand-iam/docs/product/sand-iam-product-requirements.md)。
- **SandAI**：先读 [sand-ai/README.md](sand-ai/README.md)、[统一安装包契约](/Users/code/project/sand_ai/docs/development/sand-ai-plugin-package-contract.md)。开发和隔离测试在 `/Users/code/project/sand_ai`，最终可安装源码交付 `sand_plugins/sand-ai/`；宿主安装副本不是源码来源。`sand-ai/plugin/` 是交付产物，只能由 `scripts/deliver-sand-ai.sh` 从源码单向同步，禁止手改；先读 [SandAI 交付同步](docs/sand-ai-delivery.md)。
- **SandWorkflow**：先读 [sandworkflow/README.md](sandworkflow/README.md)、[权威来源与宿主同步约定](sandworkflow/SOURCE_OF_TRUTH.md)。`sandworkflow/` 是完整插件包的权威源码；宿主副本仅用于受控同步与真实验收。
- 任务看板：[sand-iam/docs/development/sand-iam-task-board.md](sand-iam/docs/development/sand-iam-task-board.md)
- 协作边界：[sand-iam/docs/development/sand-iam-pg-collaboration.md](sand-iam/docs/development/sand-iam-pg-collaboration.md)

## 硬门槛

- 仅 PostgreSQL；禁止 MySQL 方言与新建 `sa_*` 业务表。
- 插件表 `sand_<domain>_*`；身份/应用/凭证/服务授权/审计归 SandIAM。
- 业务拒绝用 `plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`。
- 不跨改对方独占目录；未冻结字段不得猜测实现。
- 依赖插件工件一律构建期外部注入，不提交进源码仓；`config.json` 的 `plugin_dependencies` 只声明工件身份（version/sha256）。

## 插件宿主兼容范围

插件元数据、发布与目录维护遵守 [SandAdmin 插件宿主兼容范围](../sandadmin/docs/repository-plugin-distribution.md#插件宿主兼容范围)。

- 默认 catalog 只写 `host_min`，两份源码 `info.ini` 的 `support` 对应 `>=host_min`，不得用 `0.1.x` 等通配符隐式添加上限。安装器支持历史通配符不构成新发布方缩窄范围的许可。
- 执行、审查与消费任务遇到源码、构包脚本、旧任务说明或 catalog 的相反声明时，报告兼容规则冲突并按权威规范修正，不静默沿用；这不证明插件业务在未来宿主上实际不兼容。
- 真实旧版本上限只沿用规范要求的新版最低版本提高、实际存在且仍兼容的旧宿主上限证据；没有这些证据不得推定例外。默认自动门槛不鉴别该历史例外，需先明确核对范围和证据。
- SandIAM 完整性检查、审查候选与独立发布构包入口复用 `tools/package-payload-policy.php` 的默认开放下限检查；独立发布读取已提交的两份 INI，在写出 ZIP 前拒绝冲突。提交前用 `bash scripts/install-pre-commit-checks.sh` 保留并接入既有 hook；暂存元数据检查只读实际 Git index 的变更插件及对应两份声明，不以无关 dirty 文件阻塞本次提交。

## 跨插件变更

插件开发需要修改其他插件或基础包前，必须按[跨插件变更论证](docs/cross-plugin-changes.md)，由本仓记录通用性或必要性证据并完成审查后实施。先消费公开 API、扩展点和配置；不得把调用方业务实现移入通用提供方。论证复用当前 OpenSpec，不另建审批台账或口令。

## 目录主责

- Codex：`sand-iam/plugin/sand-iam/`、迁移、API / Adapter 契约。
- Codex：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`。
- Codex：`sand-ai/` 统一安装包；`plugin/sand-ai/` 承担后台配置和管理，安装到宿主 `app/**` 的载荷承担对外服务及共享业务实现，并包含运行配置、PostgreSQL 生命周期 SQL、Adapter 与安装契约。
- Codex：`sand-ai/sandadmin-artd/src/views/plugin/sand-ai/`。
- Codex：`sandworkflow/` 完整插件包（后端、管理端载荷、生命周期 SQL、文档与发布元数据）。
- 当前覆盖（2026-09-13）：`sand_plugins` Web 前端由 Codex 接管，Astra 负责前端设计与实现，并由不同上下文的 Astra 独立验收；该当前覆盖优先于历史 Cursor 分工记录，仍仅限权威源码前端目录，不扩大数据库、服务、同步、提交、推送或部署权限。
- SandIAM、SandWorkflow 的独立插件演示与验收环境为 `/Users/code/project/sand_demo`（调用服务端时使用其 `server/` 子目录）。SandIAM 作为 SandAI 依赖时，按标准包流程安装、配置到 `/Users/code/project/sand_ai` 测试宿主；这不改变 SandIAM 权威源码和独立验收环境。SandAI 当前也在该工作区测试，新的安装宿主只接入现有 PostgreSQL `sandai`；不得隐式建库或改用其他库。`/Users/code/project/sandadmin` 是纯净通用 SandAdmin，不用于插件演示。
- `sand-core/` 与 `sand-package/` 是 SandAdmin 基础 Composer 包的权威源码；SandAdmin 宿主通过锁定版本消费，不得把宿主安装副本反向同步为权威来源。SandIAM、SandAI、SandWorkflow 仍是 SandPackage 管理的 ZIP 业务插件。
- SandAdmin 同步采用“SandAdmin 发布/通知，`sand_plugins` 主动拉取”；先读 [SandAdmin demo](docs/sandadmin-demo.md)，默认只运行 `scripts/sync-sandadmin-demo.sh --dry-run`，明确执行时才使用 `--apply`。
- 禁止 SandAdmin 通过 post-commit hook 主动改写本工作区，禁止把 demo 宿主的修改反向同步到 SandAdmin。正式验收必须锁定 clean SandAdmin revision；`dirty-local` 只能用于本地探索。
- SandIAM、SandWorkflow 候选向 demo 导出时只通过 `scripts/sync-plugin-to-demo.sh`，禁止直接编辑 demo 副本。SandIAM 作为 SandAI 依赖时与 SandAI 一样按标准包安装流程进入当前测试宿主，不为旧同步脚本复制第二套 SandAI API。跨宿主/插件故障按 `docs/host-requests/` 冻结版本并单变量诊断；单插件问题优先在插件侧修，只有零插件、中立扩展或多个独立插件复现的通用缺陷才提交宿主，并要求宿主尽量贴近 SaiAdmin 上游。前两轮未收敛时，第 3 轮使用 `gpt-6-astra/high`；仍无可区分结论则停止并标记阻塞。

## 必读 skill

- 改插件：`saiadmin6`、`sand-platform-conventions`、`php-webman`
- 改管理端 Vue/TS：`typescript-vue-type-safety`

## 模型使用记录

- 每次最终回复前，运行 `/usr/bin/python3 /Users/supdger/.codex/hooks/current_model_record.py`，从当前 `CODEX_SESSION_ID` 的最新 `thread_settings_applied` 读取主 Agent 的实际模型与推理档位；不得以 `config.toml` 默认值代替。
- 最终回复必须追加“模型记录”：主 Agent、推理档位、子 Agent 模型与推理档位（未使用则写“子 Agent：未使用”）。脚本失败时先核查会话记录；仅在记录确实缺失时写“未暴露（不推测）”。
