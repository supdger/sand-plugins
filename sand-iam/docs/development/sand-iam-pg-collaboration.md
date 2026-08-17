# SandIAM PostgreSQL 协作约定

> **实时任务状态、负责人、依赖和交接包见 [SandIAM 任务看板](sand-iam-task-board.md)。** 本文保留责任边界与决策记录；不作为实时状态来源。
>
> 状态：执行中
> 目标：在不重复劳动、不覆盖彼此改动的前提下，并行推进 SandIAM 后端与管理前端；并作为 SandAI `IdentityContextProvider` / `EnvironmentReferenceVerifier` 的唯一权威来源。

## 1. 责任边界

| 交付物 | 主责 | 协作方 | 完成标准 |
| --- | --- | --- | --- |
| P0 领域模型、PostgreSQL 迁移、安装/升级/卸载 | Codex | Cursor 只读 | 空 PG 库可安装；仅 `sand_iam_*`；无 MySQL 方言 |
| 管理 API、运行时身份上下文、策略/数据范围、审计 | Codex | Cursor 按契约消费 | 路由、DTO、权限码、错误码已冻结并可验证 |
| SandAI Adapter 契约（token/context、audience、grant、environment 引用） | Codex | SandAI Codex 按契约实现宿主适配器 | 不跨库读 `sand_iam_*`；无上下文 / 无授权的拒绝语义稳定 |
| `sandadmin-artd/src/views/plugin/sand-iam/` 页面 | Cursor | Codex 提供接口支撑 | 只消费已冻结、已标「可消费」的契约字段 |
| 契约、联调、回归与宿主验收 | Codex | Cursor 修正前端问题 | 在 `sandadmin` PostgreSQL 实例上的真实路径通过 |

Codex 与 Cursor 都可以改前后端，但默认不跨越上述主责边界；跨界修复必须在看板交接记录中说明原因和影响文件。

## 2. 不可同时编辑的区域

- Codex 独占：`sand-iam/plugin/sand-iam/` 的 PHP、SQL、迁移、`config/menu.php`、`config/route.php`，以及 API / Adapter 契约文档。
- Cursor 独占：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/` 的 Vue / TypeScript / 样式。
- 共享前先冻结：路由、Request/Response DTO、错误码、权限标识、页面字段字典。
- 不允许：新增 MySQL 兼容分支；`sa_*` 业务表；前端根据猜测的字段反向定义后端；把律序律师/客户规则写进 SandIAM。

## 3. 并行规则（对齐 SandAI）

1. **目录独占。** 不跨改对方目录；必须跨界时先在看板写明文件、原因和回滚方式。
2. **契约是交接物。** Codex 先发布增量版本并标注「可消费」；Cursor 只消费已冻结版本。向后兼容的新增字段不要求 Cursor 同步等待。
3. **每个任务只依赖可验证物。** U-01 不依赖 IAM-01；U-03 依赖已冻结管理 API，而不是「Codex 做完宿主验收」。IAM-01 的 Adapter 契约一旦冻结，SandAI `SAND-113C` 即可继续，不必等管理页。
4. **交接不靠口头提醒。** 完成者补四项：变更路径、契约版本、验证命令/真实路径、已解锁任务。
5. **合并只在验收点发生。** 日常开发互不等待；真实联调在 `/Users/code/project/sandadmin`。

未冻结的接口不得被视为前端阻塞。页面壳、加载/空/失败状态可以先做；禁止发明未冻结 DTO 字段或假 CRUD。

## 4. 源码与宿主路径

| 角色 | 路径 |
| --- | --- |
| SandIAM 插件源码（本仓库） | `/Users/code/project/sand_plugins/sand-iam` |
| 插件集合仓（Cursor / Codex Autopilot 根） | `/Users/code/project/sand_plugins` |
| 安装、演示与功能验收宿主 | `/Users/code/project/sandadmin` |
| SandAI 消费方（Adapter 实现落点） | `/Users/code/project/sand_ai` |

Codex `SAND-113C` 此前在 `/Users/code/project` 与 `/Users/supdger/Documents/plugins` 未找到 SandIAM。以上路径即为权威源码位置；Adapter 协议仍须由本仓 IAM-01 冻结，不得凭路径猜测关闭 SandAI 的 fail-closed。

## 5. 进度反馈规则

- 只在任务状态变化时反馈：**任务 ID、完成项、证据、风险、已解锁的下一任务**。
- Cursor 完成一个页面或遇到接口阻塞时，应给出：任务 ID、页面路径、使用的接口/字段、构建证据、阻塞所需的最小后端变更。
- 用户无需充当中转站：只在产品取舍、凭证/环境、或不可逆数据库操作需要授权时请求决定。

## 6. 交接记录

| 日期 | 阶段 | 交接内容 | 状态 | 证据 |
| --- | --- | --- | --- | --- |
| 2026-08-13 | 协作启动 | 确认 PostgreSQL-only、目录独占、与 SandAI 相同的并行规则；向 Codex 交付源码路径与 Cursor 开工输入 | 已冻结 | 本文档、任务看板 |
| 2026-08-14 | SandAdmin 宿主更名 | 用户授权 Cursor 执行 Codex 变更通知：源码 `plugin\\saiadmin` → `plugin\\sandadmin`，前端载荷目录 `saiadmin-artd` → `sandadmin-artd`；过渡期保留双向类别名。跨界原因：通知覆盖 PHP 引用且用户指定本 Agent 执行。 | 已执行 | [更名通知](sandadmin-rename-notice.md)、`plugin/sand-iam/`、`sandadmin-artd/` |
