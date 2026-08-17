# SandIAM 任务看板

> **任务状态唯一来源。** 每次状态变化都更新本文件：负责人、状态、完成证据、解锁项与最小阻塞条件。它不是排期表，不按时间推进。
>
> Codex 原子队列：`/Users/code/project/sand_plugins/.codex/autopilot/tasks.md`
> Cursor 原子队列：`/Users/code/project/sand_plugins/.cursor/autopilot/tasks.md`
> 协作边界：[sand-iam-pg-collaboration.md](sand-iam-pg-collaboration.md)

## 状态说明

- `✅ 已完成`：交付条件和证据已满足。
- `▶ 进行中`：负责人当前只执行这一项。
- `🟢 可领取`：无未解决依赖，负责人可直接开始。
- `⏸ 等待决策`：仅等待明确的外部权限或产品选择；不阻塞无关任务。
- `◻ 未开始`：仍有明确前置条件。

## 当前快照

| 已完成 | 进行中 | 可领取 | 等待决策 | 未开始 |
| --- | --- | --- | --- | --- |
| 协作启动、U-01～U-04、IAM-01～IAM-05 | DETECT-01 监视 | — | — | A-01、SandAI SAND-113F |

## 正在执行与可并行任务

| ID | 负责人 | 状态 | 交付条件 | 已冻结输入 / 交接物 | 任务完成时必须写入的证据 |
| --- | --- | --- | --- | --- | --- |
| IAM-01 | Codex | ✅ 已完成 | 冻结 P0 领域、PostgreSQL 模型、管理/运行时 API、错误码、SandAI Adapter 与 Cursor 开工输入 | [P0 契约 v0.1（可消费）](sand-iam-p0-contract.md) | 文档覆盖五项门槛、表归属/主键/边界、DTO/权限/错误码、Adapter 与验收责任；解锁 IAM-02、U-02 与 SandAI `SAND-113C` |
| U-01 | Cursor | ✅ 已完成 | 管理端页面目录与诚实占位页；不依赖真实后端字段 | 产品对象已确认：organization / application / environment / workload_client / service_grant / audit | 见 `.cursor/autopilot/executions/U-01.md` |

**并行规则：** Codex/Cursor 互不阻塞。Cursor 在已冻结契约上可自领并行项；U-01 不因 IAM-01 停工。不得因 SandAI 演示站或 OCR 决策停工。

## 后续任务

| ID | 负责人 | 状态 | 前置 | 交付/验收条件 |
| --- | --- | --- | --- | --- |
| IAM-02 | Codex | ✅ 已完成 | IAM-01 | SaiPackage 只执行包根 lifecycle SQL；当前 `0.1.1` 在隔离库回归安装后为 18 表 / 213 约束 / 53 索引、无非 SandIAM 表；包根 update 成功；uninstall 后 0 表。解锁 IAM-03。 |
| IAM-03 | Codex | ✅ 已完成 | IAM-02 | 后端、路由与 17 表已安装；临时宿主服务的后台入口真实返回 401、无 signer 返回 503；回滚事务证明签发→校验→撤销拒绝→审计，且 0 残留。真实部署 signer 与已登录后台会话移交 IAM-05 端到端验收。解锁 IAM-04。 |
| IAM-04 | Codex | ✅ 已完成 | IAM-03 | [授权与数据范围契约 v0.1](sand-iam-authorization-contract.md) 已冻结；`0.1.1` 迁移、策略/范围/委派/审计控制面实现及真实宿主回滚验收完成。七操作均放行，跨组织、条件不匹配、同优先级 deny、无策略及范围不匹配均稳定拒绝；审计存在且测试无残留。宿主的 `saiadmin → sandadmin` 迁移通过 SandIAM 的按需类别名适配，不修改宿主核心目录。 |
| IAM-05 | Codex | ✅ 已完成 | IAM-04 | 已在真实 `sandadmin` 宿主同步插件并完整重启。Codex 已生成部署期 signer 并仅写入宿主 `.env`；运行时真实 HTTP 使用专用 `saiadmin` 验收库的临时服务凭证夹具，完成 `issue=200`、`verify=200`、错误 audience `403`、凭证撤销后 `401` 和审计存在验证。夹具及关联审计已精确清理，9 项残留检查均为 0；不输出 signer、凭证或 context。管理端接口交接已完成，见 [管理端接口交接 v0.1](sand-iam-management-api-v0.1.md)。 |
| U-02 | Cursor | ✅ 已完成 | IAM-01（页面信息架构/字段字典已标可消费） | 六个列表已按 P0 契约接入加载、空、失败、分页和冻结字段；无伪造 CRUD，验收宿主 `vue-tsc --noEmit` 与 Vite 构建通过。见 `.cursor/autopilot/executions/U-02.md`。 |
| U-03 | Cursor | ✅ 已完成 | U-02，且管理 API 已标可消费 | 列表筛选、权限码展示、401/403/503/`SAND_IAM_*` 诚实失败；已接入 identity / binding / user-type / role / resource / policy / 委派 / 身份关系只读列表。`vue-tsc` 与宿主 Vite 构建通过。见 `.cursor/autopilot/executions/U-03.md`。 |
| U-04 | Cursor | ✅ 已完成 | 管理写入 API 已冻结（不依赖 IAM-05） | save/update/disable、policy publish/revoke、grant revoke、关系 grant/revoke、service/action/credential；凭证明文只显示一次。见 `.cursor/autopilot/executions/U-04.md`。 |
| A-01 | Codex + Cursor | ◻ 未开始 | IAM-05、U-03 | 后台配置 → 身份上下文 → SandAI 拒绝/放行 → 审计的真实路径通过 |

## 给 Codex 的开工输入（IAM-01）

- 源码包：`/Users/code/project/sand_plugins/sand-iam`
- 验收宿主：`/Users/code/project/sandadmin`
- 产品/边界已确认：`docs/product/sand-iam-product-requirements.md`、`docs/architecture/sand-iam-access-boundary.md`
- 开发门槛：`docs/development/sand-iam-development-entry.md`
- Cursor 已占用：`sandadmin-artd/src/views/plugin/sand-iam/` 页面壳；请勿改该目录
- SandAI 等待：冻结 Adapter 后即可继续 `SAND-113C`（token/context 验证、audience、service grant、environment 引用）；缺省保持 fail-closed
- 业务拒绝统一：`plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`

## 给 Cursor 的消费规则

- 独占目录：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`
- IAM-01 未标「可消费」前：只做壳与诚实空态，禁止假 CRUD
- 契约文件名由 IAM-01 冻结后写入本看板「当前交接包」；DETECT 监视看板、契约与 `.codex/autopilot/tasks.md`

## 当前交接包

### 给 Codex：IAM-03 可立即继续

- 协作约定、P0 schema 与 lifecycle 验收已完成；Cursor 不改 PHP/SQL。
- [P0 契约 v0.1](sand-iam-p0-contract.md) 已冻结并可消费；实现控制面、运行时身份上下文和 SandAI Adapter。SandAI `SAND-113C` 可据第 5 节实现 Adapter，仍必须保持 fail-closed。

### 给 Cursor：U-04 已完成；DETECT 监视中

- 冻结管理写入面已接入，IAM-05 signer 成功路径已经真实验收完成。
- IAM-05 已完成；A-01 是后续 SandAI Adapter 的双插件联调项，不把它计入 SandIAM P0 运行时验收。
- SAND-113F 是空白宿主双插件安装，不是 Cursor 前端项。
