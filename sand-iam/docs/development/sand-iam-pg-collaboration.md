# SandIAM PostgreSQL 协作约定

> **实时任务状态、负责人、依赖和交接包见 [SandIAM 任务看板](sand-iam-task-board.md)。** 本文保留责任边界与决策记录；不作为实时状态来源。
>
> 状态：执行中
> 目标：在不重复劳动、不覆盖彼此改动的前提下，并行推进 SandIAM 后端与管理前端；并作为 SandAI `IdentityContextProvider` / `EnvironmentReferenceVerifier` 的唯一权威来源。
>
> 当前覆盖（2026-09-13）：Web 前端由 Codex 接管，Astra 负责前端设计与实现，并由不同上下文的 Astra 独立验收；本文历史 Cursor 主责记录保留追溯，不代表当前写者。前端切片与独立验收可并行，互不争用全局 write gate。

## 1. 责任边界

| 交付物 | 主责 | 协作方 | 完成标准 |
| --- | --- | --- | --- |
| P0 领域模型、PostgreSQL 迁移、安装/升级/卸载 | Codex | 按当前编排安排独立验收 | 空 PG 库可安装；仅 `sand_iam_*`；无 MySQL 方言 |
| 管理 API、运行时身份上下文、策略/数据范围、审计 | Codex | Codex 前端按契约消费 | 路由、DTO、权限码、错误码已冻结并可验证 |
| SandAI Adapter 契约（token/context、audience、grant、environment 引用） | Codex | SandAI Codex 按契约实现宿主适配器 | 不跨库读 `sand_iam_*`；无上下文 / 无授权的拒绝语义稳定 |
| `sandadmin-artd/src/views/plugin/sand-iam/` 页面 | Codex 接管；Astra 设计与实现 | 不同上下文 Astra 独立验收 | 只消费已冻结、已标「可消费」的契约字段 |
| 契约、联调、回归与宿主验收 | Codex | 不同上下文 Astra 独立验收，Codex 修正问题 | 在受控演示宿主 PostgreSQL 实例上的真实路径通过 |

前后端均由 Codex 负责，不再向 Cursor 派发或等待其交付。Codex 子任务按文件范围分工，避免同时修改同一文件；共享变更在看板说明原因和影响文件。

## 2. 不可同时编辑的区域

- Codex 独占：`sand-iam/plugin/sand-iam/` 的 PHP、SQL、迁移、`config/menu.php`、`config/route.php`，以及 API / Adapter 契约文档。
- 当前 Web 前端：Codex 接管；Astra 负责设计与实现；不同上下文 Astra 独立验收；范围为 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/` 的 Vue / TypeScript / 样式。历史 Cursor 独占记录仅作追溯。
- 共享前先冻结：路由、Request/Response DTO、错误码、权限标识、页面字段字典。
- 不允许：新增 MySQL 兼容分支；`sa_*` 业务表；前端根据猜测的字段反向定义后端；把任何接入应用的用户类型或业务规则写进 SandIAM。

## 3. 并行规则（对齐 SandAI）

1. **写者互斥。** Codex 主控协调前后端子任务的文件范围；共享文件串行修改，并在看板写明影响。
2. **契约是交接物。** 后端先发布增量版本并标注「可消费」；前端只消费已冻结版本。向后兼容的新增字段不要求前端同步等待。
3. **每个任务只依赖可验证物。** U-01 不依赖 IAM-01；U-03 依赖已冻结管理 API，而不是「Codex 做完宿主验收」。IAM-01 的 Adapter 契约一旦冻结，SandAI `SAND-113C` 即可继续，不必等管理页。
4. **交接不靠口头提醒。** 完成者补四项：变更路径、契约版本、验证命令/真实路径、已解锁任务。
5. **合并只在验收点发生。** 日常开发互不等待；真实联调在 `/Users/code/project/sand_plugins/sandadmin-demo-host`。`/Users/code/project/sandadmin` 保持纯净通用宿主，不用于插件演示。

未冻结的接口不得被视为前端阻塞。页面壳、加载/空/失败状态可以先做；禁止发明未冻结 DTO 字段或假 CRUD。

## 4. 源码与宿主路径

| 角色 | 路径 |
| --- | --- |
| SandIAM 插件源码（本仓库） | `/Users/code/project/sand_plugins/sand-iam` |
| 插件集合仓（Codex 当前任务工作区） | `/Users/code/project/sand_plugins` |
| 安装、演示与功能验收宿主 | `/Users/code/project/sand_plugins/sandadmin-demo-host`（服务端为其 `server/` 子目录） |
| 纯净通用 SandAdmin 宿主 | `/Users/code/project/sandadmin`（不用于插件演示） |
| SandAI 消费方（Adapter 实现落点） | `/Users/code/project/sand_ai` |

Codex `SAND-113C` 此前在 `/Users/code/project` 与 `/Users/supdger/Documents/plugins` 未找到 SandIAM。以上路径即为权威源码位置；Adapter 协议仍须由本仓 IAM-01 冻结，不得凭路径猜测关闭 SandAI 的 fail-closed。

## 5. 进度反馈规则

- 只在任务状态变化时反馈：**任务 ID、完成项、证据、风险、已解锁的下一任务**。
- Codex 前端子任务完成一个页面或遇到接口阻塞时，应给出：任务 ID、页面路径、使用的接口/字段、构建证据、阻塞所需的最小后端变更。
- 用户无需充当中转站：只在产品取舍、凭证/环境、或不可逆数据库操作需要授权时请求决定。

## 6. 历史交接记录（不作为当前指令）

| 日期 | 阶段 | 交接内容 | 状态 | 证据 |
| --- | --- | --- | --- | --- |
| 2026-08-13 | 协作启动 | 确认 PostgreSQL-only、目录独占、与 SandAI 相同的并行规则；向 Codex 交付源码路径与 Cursor 开工输入 | 已冻结 | 本文档、任务看板 |
| 2026-08-14 | SandAdmin 宿主更名 | 用户授权 Cursor 执行 Codex 变更通知：源码 `plugin\\saiadmin` → `plugin\\sandadmin`，前端载荷目录 `saiadmin-artd` → `sandadmin-artd`；过渡期保留双向类别名。跨界原因：通知覆盖 PHP 引用且用户指定本 Agent 执行。 | 已执行 | [更名通知](sandadmin-rename-notice.md)、`plugin/sand-iam/`、`sandadmin-artd/` |
| 2026-08-21 | UX-01 第二轮 | 用户明确要求不等待宿主验证、继续真人体验改造；Codex 主代理委派 Terra/high 实现代理修改管理端，并由主代理复核前端、菜单和后端契约。未修改验收宿主、数据库或运行服务。 | 权威源码和隔离自动检查已完成；正式验收未完成 | [三角色验收契约](sand-iam-human-usability-acceptance.md)、[UX-01 执行记录](../../../.codex/autopilot/executions/UX-01.md) |
| 2026-08-28 | Autopilot 恢复 | 用户授权 Cursor 执行 `start_goal.sh`；清除 HARD_STOP、启用 `state.json`、恢复 hooks `loop_limit=40`、启动 DETECT watcher；`cursor agent` 已登录。 | Autopilot 运行中；浏览器验收仍待宿主登录 | `.cursor/autopilot/executions/AUTOPILOT-RESUME-20260828.md`、`detect-status.md` |
| 2026-08-30 | 协作通道恢复 | 用户要求恢复与 Codex 通信；重跑 `start_goal.sh`、拉起已退出的 DETECT watcher；向 Codex 交付 U-05B/UX-01B/UX-02 Cursor 证据与权威包回同步缺口。 | 交互 Agent 与 Autopilot 通道在线；FLOW 计数不上调 | `.cursor/autopilot/executions/CURSOR-PING-20260830.md`、任务看板 |
| 2026-08-31 | 协作通道开启 | 用户确认 Codex 已恢复并要求开启协作；重拉 DETECT watcher（pid 14997）；向 Codex 重投 U-05B/UX-01B/UX-02 证据与权威包回同步缺口。 | 交互 Agent 与 Autopilot 通道在线；FLOW 计数不上调 | `.cursor/autopilot/executions/CURSOR-PING-20260831.md`、任务看板 |
| 2026-08-31 | DETECT 去重 | 用户授权清除重复 watcher：`SIGTERM` 仍存活的 14997，保留值班 99262。未关 Autopilot、未改 backoff、未重拉、未改 Codex 队列。 | 通道仍在线；仅单实例值班 | `.cursor/autopilot/executions/DETECT-01.md`、任务看板 |
| 2026-09-12 | Codex Goal 对接 | Codex Goal `01a091cd`（完整开源成品交付）已 active，并关闭旧 Autopilot 循环。Cursor 交互 Agent 在线对齐该 Goal；不重开 DETECT watcher；无新冻结前端面。 | 交互通道在线；旧循环保持停止；FLOW 不上调 | `.cursor/autopilot/executions/CURSOR-PING-20260912.md`、任务看板 |
