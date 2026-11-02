# SandIAM v19 宿主验收交接单

状态：**SandIAM 产品源码已交付；SandAdmin / SandPackage 宿主验收待接手。**

本文是 `/Users/code/project/sand_plugins/sandadmin-demo-host` 的执行交接，不授权直接修改宿主、数据库、服务、registry 或发布状态。执行方必须在各自任务中取得对应授权，并把结果按本文约定回传到 SandIAM FLOW 账本。

## 1. 交付物身份

- 审阅候选：`.artifacts/sand-iam-0.7.0-v19-20260908T073318Z/`
- ZIP：`sand-iam-0.7.0-v19-20260908T073318Z-candidate-dirty-not-release.zip`
- ZIP SHA-256：`4fcba790272b902ae7e58435a78ae0c595c5cb02ffd976f43b905a8cabe7043c`
- 条目：608
- descriptor 排除后的载荷：606 个文件
- 载荷摘要：`5280fbd24a2dc1c1fde4720aad71dbc736427d916ef76d22d86cc56246133adf`
- descriptor 摘要：`5b5c70674a4ebf173e98fa923bae9ebbddbe429ae05e8805d16751ca81be1cea`
- `update.sql` 摘要：`aff487972130f1bf0d9b2e20fc6651b9f7738bf6a30a78c8fa642bad6d3f8457`
- 迁移范围：`001–037`，37 个修订号、38 个迁移文件
- 目标结构：86 张 `sand_iam_*` 表
- 构建结论：同一快照重复构建字节一致
- 发布边界：`candidate/dirty-not-release`

执行前必须先核对 ZIP SHA、`manifest.json`、`source-snapshot.json` 和 `recovery/failed-upgrade.v1.json`；任一项不一致即停止。不得使用 v17 或更早候选替代。

## 2. 当前已完成与未完成

SandIAM 当前 FLOW 为 **28/48（58.3%）**：

| 关口 | 当前结果 | 责任说明 |
| --- | ---: | --- |
| 需求/架构/票据 | 8/9 | R09 同环境对照旅程仍需产品与验收方共同执行 |
| 模块实现 | 20/20 | SandIAM 已完成，P19 前后端独立复核 P0/P1/P2=0 |
| 正式 FLOW | 0/7 | 需要当前宿主、数据库、页面、API、审计和清理证据 |
| 本地业务闭环 | 0/4 | 需要管理员、应用用户、SandAI 与非 AI 应用真实运行 |
| 可上线部署 | 0/8 | 需要干净来源、发布包、生命周期、外部互操作、安全与回滚 |

源码预检 17/17、受控内存模拟 38/38、package checker 23/23、前端隔离构建和 ZIP SDK 回环消费均已通过；这些证据不替代本交接中的真实宿主验收。

### 剩余 20 项逐项责任

| ID | 主责 | 必须交付的当前证据 |
| --- | --- | --- |
| R09 | 产品验收 + SandAdmin | SandIAM 与 Casdoor 在同硬件、网络、浏览器和数据量下完成三条旅程的原始计时、步骤、失败和恢复记录 |
| F01 | SandAdmin 前端验收 | v19 的全部声明入口按角色、状态和目标视口逐项可达，无 404、裁切或滚动陷阱 |
| F02 | SandAdmin 后端验收 | 页面背后的管理、应用用户、协议和机器 API 在真实 Webman 宿主返回契约内结果 |
| F03 | SandAdmin + SandIAM 验收 | 同一夹具的合法请求允许，跨组织、应用、角色、范围、停用和撤销请求稳定拒绝 |
| F04 | SandAdmin + SandIAM 验收 | 允许、拒绝和管理变更均能按 request ID 对账到脱敏审计与事件 |
| F05 | SandAdmin + SandIAM 验收 | 会话、因子、凭证、授权、委派、协议会话和失败升级的撤销/恢复前后证据 |
| F06 | SandAdmin + 数据库验收 | 七链本轮前缀夹具精确清理、重复清理安全、数据库/文件/外部接收器零残留 |
| F07 | 独立验收者 | 同一 v19 从空环境重复执行，结果与清理稳定，证据包可由第三方复核 |
| L01 | SandAdmin 管理端验收 | 平台管理员与被委派管理员完成配置、越权拒绝、撤权、审计和清理 |
| L02 | SandAdmin 应用用户端验收 | 注册/邀请、登录、会话、MFA/Passkey、自助、撤销后拒绝和清理 |
| L03 | SandAI + SandAdmin | 真实 SandAI 副作用、无权/错 audience/action/撤销后失败及双侧审计 |
| L04 | 业务应用 + SandAdmin | 一个非 AI 应用通过 SDK/中间件验证真实资源 allow/deny/scope、隔离、审计和清理 |
| D01 | 发布负责人 | 从干净可追溯 revision 重建相同候选，记录 revision、环境、命令、许可和 bit-identical SHA |
| D02 | 发布负责人 + 独立审查 | 最终 ZIP、完整载荷、许可、外部可信 manifest、签名和来源审查 |
| D03 | SandAdmin / SandPackage | v19 受控同步、全新安装、连续/重复升级、卸载、服务重载和零残留 |
| D04 | 协议集成验收 | 标准 OIDC/SAML/LDAP/SCIM/CAS/Kerberos/RADIUS 客户端和真实消息/目录服务报告 |
| D05 | SandAdmin UI 验收 | 平台管理员、应用管理员、应用用户三角色多视口任务、错误恢复、撤权和无 404 |
| D06 | SandAdmin + DBA | PostgreSQL 备份恢复到隔离目标，授权、撤销、审计和验签前后一致且过程可回滚 |
| D07 | 安全验收 + SandAdmin | 当前候选的秘密扫描、限流/重放、并发消费/撤权/刷新、轮换和故障注入报告 |
| D08 | 发布负责人 + SandAdmin | 失败停止、候选/数据库/运行文件回滚、残留清理、审批和线上验证计划 |

每一行只认该行要求的证据；其他行通过不能代替，也不能按局部比例提前计分。

## 3. SandAdmin 必须先关闭的宿主阻断

在接入 v19 前，先按[失败升级恢复交接](sandadmin-sandiam-demo-host-recovery.md)关闭以下问题：

1. 当前 registry 仍记录 `state=8`、`stage=failed`、`failed_stage=database_update`。
2. 现场 PostgreSQL 最新保留证据是 83 张表、无 migration ledger，属于 `prefix_033_034`，不是 v19 的 86 表目标。
3. 真实备份建立后存在一个管理端运行文件漂移，严格恢复校验因此拒绝继续。
4. Gate B 最近证据为 28/32 REJECT；必须关闭 retry 状态、归档漂移重算、审计字段和中断矩阵缺口。
5. 必须提供不依赖浏览器的本地诊断/恢复 CLI；浏览器只负责随后的人机交互验收。

以上问题属于 SandAdmin / SandPackage。SandIAM 不修改宿主恢复代码，不手工改 registry、ledger 或历史清单。

## 4. 执行顺序

### 阶段 A：只读接收与恢复判定

目标：证明宿主当前状态可追溯，并判断是否允许进入恢复。

必须输出：

- 候选、备份、运行目录、quarantine、journal 和 registry 的固定字段摘要；
- PostgreSQL `READ ONLY`、`REPEATABLE READ` 下的 profile、断言通过数和非空 fingerprint；
- backup 自洽性与 current runtime 漂移分别报告；
- Gate A、Gate B 的逐项结果和失败原因。

禁止输出秘密、环境变量、完整 SQL 行、绝对运行路径或文件正文。阶段 A 未通过时不得替换候选或执行 SQL。

### 阶段 B：受控恢复与 v19 生命周期

目标：在取得数据库、文件和服务操作授权后，完成失败恢复和当前候选生命周期。

顺序固定为：

1. 留存当前文件、registry、数据库结构与服务状态摘要；
2. 在应用锁下隔离运行文件漂移并重新核验完整清单；
3. 重新执行 Gate A，必须得到非空 fingerprint 的 `retry_safe/prefix_033_034`；
4. 以官方 SandPackage 流程核验并替换为 v19；
5. 执行 `0.6.0 → 0.7.0` 更新，只允许 `033–037`；
6. 核验 migration ledger、86 张表、约束、索引和三项初始化草稿权限只入目录未自动授予角色；
7. 完成重复升级、全新安装和卸载零残留；
8. 任一步失败时证明 registry、数据库和运行目录收敛到可再次核验的完整状态。

不得创建未获授权的数据库，不得手工补 ledger，不得直接解除 failed state，不得把 HTTP 200 当作升级成功。

### 阶段 C：当前候选页面、API 与七条业务链

目标：以同一 v19、同一宿主和同一批带前缀夹具完成 F01–F07。

唯一编排入口：

```bash
php sand-iam/tools/run-terminal-acceptance.php --mode=live
```

执行方先按 `sand-iam/docs/development/sand-iam-seven-chain-acceptance-runner.md` 审阅并填写独立 live plan。每条链必须同时证明：

- 页面入口可达，无 404、无底部裁切和滚动陷阱；
- 保存后刷新读取一致，真实后端生效；
- 合法操作允许，越权、错范围、停用和撤销后稳定拒绝；
- 页面、API、业务副作用和审计以 request ID 对账；
- 错误可恢复，不靠改库或强制覆盖；
- 清理只删除本轮固定前缀夹具，重复清理安全，零残留查询通过。

七条链分别为：

1. 客户主体 → 接入应用 → 应用环境；
2. 用户/用户组 → 角色 → 资源 → 策略 → 允许与拒绝；
3. 登录 → 会话 → MFA/Passkey → 撤销后拒绝；
4. 调用身份 → 授权 → 凭证 → 调用 → 拒绝 → 撤销；
5. OAuth/OIDC/CAS → 接口目录 → 路由绑定 → 策略模拟 → 实际调用；
6. 管理委派 → 范围内操作 → 越权拒绝 → 撤权；
7. 事件订阅 → 投递 → 失败重试 → 审计 → 排错。

浏览器截图只作视觉证据，不能替代 API、数据库、业务副作用或审计记录。

### 阶段 D：外部互操作、安全与发布

目标：关闭 R09、L03/L04 和 D01–D08。

- 用同硬件、同网络、同浏览器、同数据量分别完成 SandIAM 与 Casdoor 三条计时旅程，保留开始/结束条件和原始记录；
- 用标准 OIDC/SAML/LDAP/SCIM/CAS/Kerberos/RADIUS 客户端及受控消息、目录服务完成正负互操作；
- SandAI 和一个非 AI 业务应用分别验证真实副作用、错 audience/action、撤销后拒绝和双侧审计；
- 从干净、可追溯 revision 构建并复现相同 SHA；完成许可、来源、签名和独立发布审查；
- 完成秘密扫描、限流/重放、并发消费/撤权/刷新、密钥轮换和故障注入；
- 完成 PostgreSQL 备份恢复、候选/数据库/运行文件回滚及发布审批。

任何外部系统未实际运行时必须写“未验证”，不得用 fake transport 或源码测试计分。

## 5. 回传证据格式

每个阶段单独提交证据目录，不覆盖历史记录。至少包含：

- `summary.md`：候选 SHA、宿主 revision、时间、环境和结论；
- `commands.log`：脱敏命令、退出码和开始/结束时间；
- `assertions.json`：稳定检查 ID、expected、actual、passed；
- `artifacts.sha256`：本阶段全部证据文件摘要；
- `cleanup.md`：本轮夹具、清理顺序、重复清理和零残留结果；
- 页面验收表：URL、角色、视口、动作、结果、request ID、截图编号；
- API/审计对账表：请求、响应、业务后置状态和审计记录的关联编号。

阶段结论只允许 `ACCEPT`、`REJECT` 或 `BLOCKED`。`BLOCKED` 必须写出已完成项、唯一阻断、恢复条件和未执行动作。

## 6. FLOW 回写规则

- 阶段 A/B 的局部恢复结果只更新 REC01–REC08，不自动增加 48 项分子。
- F01–F07 必须覆盖全部适用业务链后才能逐项计分；一条链通过不能折算为某个 F 原子通过。
- L01–L04 必须有真实业务状态和清理证据。
- D01–D08 必须使用同一最终候选、干净来源和可追溯发行物。
- 只有证据满足 `sand-iam/docs/development/sand-iam-terminal-acceptance-ledger.md` 的完整定义后，才更新该稳定 ID。

SandAdmin 接手后，应把宿主问题和执行结果留在本交接链路中；不要要求 SandIAM 修改产品源码来绕过宿主生命周期失败。
