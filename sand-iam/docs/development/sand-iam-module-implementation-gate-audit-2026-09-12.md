# SandIAM 模块实现关口复核（2026-09-12）

> 本记录只复核 R01–R09、P01–P20。它不证明正式 FLOW、真实业务闭环、安装升级、
> 页面体验、外部协议互操作、可部署、已部署或线上验证。

## 1. 候选与执行边界

- 变更前 SandIAM tree：`24af41dd869e2ac64fcc7b71ea64e255dcda86a4`。
- 当前工作树包含本轮 README/账本、恢复描述器和 Dart SDK 修复，仓库整体 dirty。
- 未创建或连接数据库，未执行 migration，未同步宿主，未启停服务，未提交、推送或部署。
- 模块门槛沿用原账本定义：权威源码闭合、所需 migration 在根/插件镜像存在、至少一个当前测试验证公开契约或可观察行为，并有适用静态证据。

## 2. 当前验证

| 验证 | 结果 |
| --- | --- |
| `check-package-integrity.php` | **24/24 PASS** |
| PHP lint（app/config/tests/PHP SDK/tools/examples） | **474/474 PASS** |
| non-PG + contract 测试 | **99/99 PASS** |
| R08 末端规格门禁 | **9/9 PASS** |
| 测试质量扫描 | `tests=123`、`SOURCE_MATCH=0`、`heuristic_leads=1`、exit 0 |
| PHP SDK | PASS |
| portal TypeScript + contract | typecheck PASS；contract PASS |
| TypeScript SDK | build/test PASS |
| Dart SDK | 初次 analyze 3 errors；修复后 analyze 0 issues，测试 **11/11 PASS** |
| demo 导出 dry-run | 管理端目录无差异；未 apply |

质量扫描唯一 heuristic 是 `route_binding_synchronizer_non_pg_test.php`。人工复核确认该测试
直接执行生产 `RouteBindingSynchronizer`，只以最小内存模型代替持久层；断言 preview/apply、
外部绑定保留、停用、唯一审计 request id、跨环境、未登记动作、冲突和停用动作拒绝。
它没有复制生产规划算法，因此保留为行为证据。

## 3. R01–R09

- R01–R07：当前产品目标、边界、PostgreSQL/对象、路由/动作、末端任务、七链和发布授权文档逐项存在并互相引用。
- R08：当前工具实际返回 9/9，四票据 3/3/3/3、12 个原子、66 个当前 API 引用均闭合。
- R09：已有关闭失败的结构化证据校验器，但没有 SandIAM/Casdoor 同环境三旅程各两轮原始记录。

结论：需求/架构/票据 **8/9**。

## 4. P01–P20

| 原子 | 当前代表性行为/契约证据 | 结论 |
| --- | --- | --- |
| P01–P02 | 双平面/范围守卫、environment lifecycle、管理范围行为 | 通过 |
| P03–P04 | auth/MFA/Passkey 契约、challenge/finish/重放边界 | 通过 |
| P05–P06 | SCIM/federation、OAuth/OIDC、consent/logout 正负契约 | 通过 |
| P07–P08 | group-role 授权、PolicyAuthorizer、版本/优先级/deny/scope | 通过 |
| P09 | 生产 RouteBindingSynchronizer + API governance 行为 | 通过 |
| P10–P11 | context 撤销、service grant quota/network/data-class 行为 | 通过 |
| P12–P15 | federation、消息体验、目录 worker、公有协议 fail-closed | 通过 |
| P16–P17 | 管理委派、撤权、Webhook/outbox/签名/重试/审计契约 | 通过 |
| P18 | PHP/TS SDK 通过；Dart SDK 当前缺陷修复后 analyze + 11/11 | 通过 |
| P19 | initialization preview/draft/apply/replay/conflict/rollback 行为 | 通过 |
| P20 | 生产 SelfServiceService 的 profile/session/MFA/Passkey 边界 + portal contract | 通过 |

结论：模块实现 **20/20**。此结论不复用旧勾选；它来自当前源码和本轮重新执行结果。

## 5. 不计分事实

- 宿主全量 `vue-tsc --noEmit` 被 SandPackage 的
  `failed-upgrade-recovery.vite.config.mts` 在当前 `moduleResolution=node` 下阻断；这不是
  SandIAM 文件错误，但本轮也没有据此宣称管理端完整构建通过。
- 没有当前 PostgreSQL、真实 HTTP、浏览器、标准客户端、真实外部 IdP/目录/Realm/NAS、
  非 AI 业务应用、机器调用服务、备份恢复、并发、24 小时稳定性或 Casdoor 对照证据。
- 因此 F **0/7**、L **0/4**、D **0/8**、发布门槛 **0/10** 保持不变。
