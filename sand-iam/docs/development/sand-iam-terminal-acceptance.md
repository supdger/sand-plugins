# SandIAM 终极能力差距与验收矩阵

> 当前复核日期：2026-09-12；2026-09-08 仅为原子定义基线。状态只认当前源码、当前候选、自动测试和可定位的运行证据；“有表/有页面/能构建”不等于业务闭环通过。48 个 FLOW 原子的唯一名称、完成定义、证据边界和七链映射见[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)；用户体验硬门槛见[产品体验与闭环交付契约](sand-iam-product-experience-contract.md)。

## 1. 当前结论

当前权威源码是未提交的 0.7.1 实现，Astra 已 ACCEPT：through037 精确 preflight→原038，迁移 `001–038` 不变、无039，normal 包不含旧 recovery descriptor。interim ZIP（文档审阅前）为 634 entries、SHA `38392c9a…`、source snapshot `07b932…`；文档修改会改变最终候选摘要。0.7.0 的 `fa344cd`/tree `6465…`、v70/v71 仅保留为历史证据；v71 与 v70 payload 相同但 B 因 rsync size+mtime 假阴性被独立 REJECT，`--checksum` 修复尚未重新 apply。

只读 demo 状态为 86 tables、迁移账本 38 rows、max revision 37、无038；C 因 runtime `state=1/stage=completed` 不适用。D/E/H–L 未执行，G 未授权。当前仍未完成宿主生命周期、浏览器、业务闭环、部署或线上验证。

此前宿主同步、worker、401/503 和前端 200 的记录仍是历史证据，不构成当前宿主验收；本轮未重新验证宿主数据库状态。登录后 HTTP、标准外部 IdP/LDAP/NAS、三角色浏览器、业务闭环、备份恢复和部署均不得判定通过。SandAI 已单独完成 4/4 隔离生命周期，不可据此替代 SandIAM 宿主、浏览器或业务验收。

本轮体验契约对应的源码已完成并通过静态检查；这不等于动态验收。直到以下条件在真实宿主逐项通过前，管理端不得称为“完成”：总览七个模块使用行业中立用途说明和五种用户状态；`/sand-iam/getting-started` 能按共同步骤和所选目标连续完成；所有可点击入口不出现 404；应用用户功能不伪装为后台配置；七条业务链均有允许、拒绝、审计、撤销或恢复与夹具清理证据。

七条业务链现在有统一的[可重复验收编排器](sand-iam-seven-chain-acceptance-runner.md)：默认源码预检和内存模拟均不触碰宿主或数据库，2026-08-28 的模拟结果为 **32/32** 且七条均完成内存夹具清理。它只补齐“静态检查/受控模拟”证据，真实宿主、浏览器、外部系统与数据库闭环仍为 **未核验**；真实执行必须使用本次授权的 live driver，并逐条附上页面、API、允许/拒绝、审计、撤销或恢复、零残留证据。

## 2. 能力矩阵

| 模块 | 当前证据 | 当前判定 | 终极验收 |
| --- | --- | --- | --- |
| 组织/应用/环境 | 管理 API、组织委派、历史宿主验收 | 已实现，仍需终极回归 | 跨组织全操作隔离；应用管理员只能管理被委派范围 |
| 应用身份目录 | identity/provider/binding/user type/role；0.2 升级与复合 FK 实证 | 基础完成 | 应用用户全生命周期、邀请/停用/删除、身份绑定冲突和审计 |
| 人类注册登录 | v0.3.0 公共 API、应用级认证策略、密码/验证/锁定/限流；隔离库服务级闭环 | 核心已实现，真实宿主 HTTP 待 T08 | 注册→验证→登录→刷新→登出→重置→禁用失效全链路 |
| 会话与设备 | 会话列表/撤销、刷新历史、重放整族撤销、密码重置全撤销 | 核心已实现，设备风险能力待扩展 | 会话列表、单会话撤销、全局登出、刷新重放检测、密码变更后撤销 |
| MFA/Passkey | v0.4.0 服务、API、004 迁移；真实 TOTP/恢复码/ES256 WebAuthn 隔离库验收 | 后端核心已实现，真实浏览器与宿主 HTTP 待 T07/T08 | TOTP/恢复码/WebAuthn 注册、挑战、验证、撤销、恢复和重放拒绝 |
| 外部身份源 | OIDC/OAuth2/SAML/LDAP、绑定/解绑、handoff 已进入生命周期并通过 PostgreSQL ORM 集成 | 本地服务级通过，真实 IdP/HTTP 未验收 | 外部 OIDC/OAuth/SAML 真实联调；属性映射、冲突、解绑和失败审计 |
| LDAP/SCIM | LDAP 同步与 SCIM Users/Groups/Token 已通过 PostgreSQL 创建、更新、停用、移除和原行恢复 | 本地服务级通过，真实目录/HTTP 未验收 | LDAP 只读/同步、SCIM create/update/disable、游标/重试和停用传播 |
| OAuth/OIDC Provider | v0.5.0：005 迁移、authorize interaction、token/JWKS/discovery/userinfo/revoke/logout；真实 ORM/服务/controller 与隔离生命周期通过 | 后端核心已实现，真实 HTTP/浏览器/标准客户端待 T07/T08 | Code+PKCE、client credentials、refresh rotation、userinfo、revoke、logout 的宿主网络协议、并发和密钥轮换验收 |
| 机器身份 | 凭证一次展示、短期 context、撤销、audience/action 与历史 HTTP 审计 | 部分通过 | 实际执行 quota/network/data_class，轮换过渡、限流和密钥治理 |
| 授权与数据范围 | PolicyAuthorizer/ScopeMatcher，七操作历史验收 | 内核完成，业务接入缺失 | 稳定 PDP API/SDK；真实业务 list/read/write/delete/export/batch 允许/拒绝 |
| API/路由治理 | service/action 语义目录、API 目录、route binding、OpenAPI 导入及停用关闭失败已通过 PostgreSQL 集成 | 本地服务级通过，真实业务中间件闭环未验收 | 真实业务路由允许/拒绝、数据范围、策略模拟和决策解释 |
| SDK/中间件 | PHP、TypeScript、Dart/Flutter SDK，Webman 中间件，CLI 与管理 OpenAPI 候选 | 自动测试通过，真实业务应用接入未验收 | 三套 SDK 与标准 OIDC 示例真实接入并完成允许/拒绝链路 |
| 审计/Webhook | 签名/重试/幂等/轮换、固定事件目录和投递状态已通过 PostgreSQL 集成 | 本地服务级通过，并发 worker/真实 HTTPS/告警出口未验收 | 统一事件、Webhook 事务生产者、保留/归档/恢复和告警出口 |
| 管理与自助体验 | 控制面页面在实施，自助 API 候选；UX-01 未验收 | 未闭环 | 平台管理员、应用管理员、终端用户三角色真实浏览器闭环；应用品牌、登录/注册/恢复编排完整 |
| SandAI/业务联调 | Adapter/fail-closed 契约；A-01 未开始 | 未闭环；L03 证据未来从 `sand_ai` 工作区回填，本轮不核验、不计分 | SandAI 真实 API 放行/拒绝/双侧审计；非 AI 应用 SDK 接入 |
| 发布一致性 | 当前 0.7.1 未提交实现：`001–038`（无039）、normal 包无旧 recovery descriptor；safe 114、PHP 507、package 24、hygiene 11/14；interim ZIP 634 entries（`38392c9a…`，文档审阅前） | 仍非最终候选；`--checksum` 修复未提交且未重新 apply，未完成迁移/数据库/registry/runtime recovery、浏览器与业务闭环 | 当前版本在空隔离宿主完成安装/连续升级/卸载、备份恢复、安全基线和发布包复核 |

完整 Casdoor 基线复核见[终极能力缺口](sand-iam-casdoor-baseline-gap.md)。T09–T12 未完成前，本表的终极验收不得整体判定通过。

## 3. 冻结模块对象

现有对象继续保留。新增对象在各实现任务开始前追加不可变迁移并冻结字段：

| 模块 | 计划对象 |
| --- | --- |
| 本地认证 | 已实现 `identity_auth`、`auth_verification`、`auth_rate_limit`、`auth_policy` |
| 会话 | 已实现 `auth_session`、`auth_refresh_token` |
| MFA | `mfa_factor`、`mfa_recovery_code`、`webauthn_credential`、`auth_challenge` |
| OAuth/OIDC | `oauth_client`、`authorization_code`、`oauth_grant`、`oauth_token`、`oauth_consent`、`signing_key` |
| 联合身份 | 扩展 `identity_provider` 配置，新增 `federation_state`、`directory_sync_job`、`directory_sync_cursor` |
| SCIM | `scim_token`、`provisioning_event` |
| API 治理 | `api_resource`、`api_route_binding`、`policy_version` |
| Webhook | `webhook`、`webhook_secret`、`webhook_delivery` |

每个对象必须以 `organization_id` 或 `application_id` 明确边界；秘密只保存哈希或加密信封；安全事件追加写，不能软删除事实。

## 4. API 路由权威来源

当前路径、HTTP 方法和管理面/身份面边界统一见 [API 路由权威表](sand-iam-api-route-registry.md)。运行时事实以 `plugin/sand-iam/config/route.php` 为准；本文不再维护第二套路由清单。

旧草案中的 `/api/sand-iam/v1/account/*`、`/api/sand-iam/v1/authorize`、`/api/sand-iam/v1/context/*` 和 `/api/sand-iam/v1/scim/v2/*` 已废止。当前分别使用 `/api/sand-iam/v1/me/*`、`/api/sand-iam/v1/authorization/decide`、`/app/sand-iam/runtime/context/*` 和 `/api/sand-iam/v1/scim/{provider}/*`。

## 5. 端到端主流程

### 5.1 应用用户

```text
应用配置注册规则
→ 用户注册并验证标识
→ 建立本地凭据或绑定外部身份
→ 登录风险检查
→ MFA/Passkey
→ 创建可撤销会话
→ 业务中间件调用 authorize
→ 业务系统执行最终状态检查
→ 登录和授权审计
```

异常必须覆盖：重复标识、跨应用错绑、验证码过期、密码错误锁定、MFA 重放、会话撤销、应用停用、策略拒绝、数据范围拒绝。

### 5.2 OIDC

```text
严格匹配 redirect_uri
→ state/nonce + PKCE S256
→ 用户登录与同意
→ 一次性 authorization code
→ token endpoint 校验 verifier/client
→ 短期 access/id token + 轮换 refresh token
→ resource server 校验 issuer/audience/scope/exp/kid
→ revoke 或 RP-initiated logout
```

### 5.3 接口治理

```text
应用登记语义资源与动作
→ 路由绑定语义动作
→ SDK/中间件提取身份上下文
→ SandIAM 决策角色/属性/数据范围
→ 业务代码补充业务状态校验
→ 允许或稳定拒绝并记录审计
```

## 6. FLOW 现状

> 原分母冻结于 2026-08-23；原子名称遗失后已于 2026-09-08 依据当前权威文件完成基线重建。重建没有改变分母或分子，也不伪称恢复了遗失清单原文。后续计分只认[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)的稳定 ID。

| 关口 | 通过项/总项 | 当前状态 |
| --- | ---: | --- |
| 需求/架构/票据 | **8/9** | R01–R08 通过；R08 的[规格冻结](sand-iam-t09-t12-terminal-spec-freeze.md)静态门禁 **9/9** 且独立复核 ACCEPT，只计需求冻结；R09 的双方同环境旅程计时未通过 |
| 模块实现 | **20/20** | [模块实现关口归位审计](sand-iam-module-implementation-gate-audit-2026-09-08.md)保持 P01–P20 名称和 20 项分母不变；P12–P20 的权威源码、migration、公开行为/契约和适用构建证据满足 P；P14、P19 已独立最终复核 ACCEPT |
| 正式 FLOW 验收 | **0/7** | F01–F07 是真实页面、宿主 API、允许/拒绝、审计、撤销/恢复、清理零残留、同候选可重复复核七个纵向维度；它们独立于升级票据七项和七条业务链，当前均未全量通过 |
| 本地业务闭环 | **0/4** | L01 管理员配置、L02 应用用户认证、L03 SandAI（证据未来从 `sand_ai` 工作区回填；本轮不核验、不计分）、L04 非 AI 业务应用均未形成当前完整证据 |
| 可上线部署 | **0/8** | D01–D08 的干净可重建源码、完整插件包、当前宿主生命周期、标准客户端/外部系统、三角色 UI、备份恢复、安全并发、回滚发布均未通过 |

因此完整目标为 **28/48（58.3%）**。本次仅对模块实现门槛归位：P14 的真实 service PostgreSQL 行为与 P19 的 `037` 迁移执行本轮均未在数据库重跑；真实供应商/目录、标准客户端、宿主 HTTP、浏览器、业务应用、恢复和部署仍分别留在 F/L/D，故 F **0/7**、L **0/4**、D **0/8** 不变。P20 已由自助模块自身的生产 service 公开行为测试计分，不借 P03/P04 认证/MFA 测试替代。三条可计时旅程及 Casdoor 对照规则见[开发者旅程验收](sand-iam-developer-journey-acceptance.md)。

### 6.1 当前阻断与独立待办

- **静态复验：** 当前 0.7.1 未提交实现已获 Astra ACCEPT；through037 精确 preflight→原038，`001–038` 不变、无039，normal 包不含旧 recovery descriptor；safe **114**、PHP lint **507**、包内 **24/24**、发布卫生 **11/14**。interim ZIP 的 `38392c9a…`/634 entries/`07b932…` 仅为文档审阅前摘要。
- **动态 PostgreSQL：** 当前只读状态为 86 tables、迁移账本 38 rows、max revision 37、无038；runtime `state=1/stage=completed` 使 C 不适用。尚未完成隔离安装、升级、卸载或正常 SandPackage 生命周期，不能声称当前生命周期通过。
- **产品端：** 当前 0.7.1 仅有静态包证据；演示宿主尚未重新 apply，旧实现记录和旧浏览器截图都不是当前宿主验收。这里不再把旧调度开关、监视进程或登录页观察写成当前状态。
- **宿主恢复：** runtime `state=1/stage=completed`，C 失败升级 recovery 不适用；只读 DB 为 86 tables、迁移账本 38 rows、max revision 37、无 038。D/E/H–L 未执行，候选替换、重试、数据库生命周期、浏览器、七链和部署均未通过，G 未授权。

卡密签发、商业许可和设备激活继续归独立 SandLicense，边界见[终极产品目标](../product/sand-iam-terminal-product-goal.md)。

## 7. 每个任务的证据要求

1. 模块契约和不可变迁移；
2. PHP lint、契约/单元/集成测试；
3. 隔离 PostgreSQL 安装、升级、重复执行、卸载；
4. HTTP 允许和拒绝路径，错误码与审计；
5. 真实管理端或应用端操作；
6. 夹具清理和残留查询；
7. 明确未验证项，不以构建成功替代运行或业务验收。
