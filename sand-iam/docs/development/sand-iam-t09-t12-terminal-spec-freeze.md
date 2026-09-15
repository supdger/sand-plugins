# SandIAM T09–T12 末端验收规格冻结

> 冻结日期：2026-09-08。本文只冻结“要交付什么、怎样证明、失败后怎样恢复和清理”的规格；它不把候选源码、静态检查、隔离 PostgreSQL 记录或页面文件当成真实宿主验收。唯一 FLOW 计分仍以[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)为准。

## 1. 权威来源和路由边界

四张票据的能力边界分别由 [T09](sand-iam-application-experience-message-provider-v0.1.md)、[T10](sand-iam-user-lifecycle-group-sync-v0.1.md)、[T11](sand-iam-protocol-interop-v0.1.md) 和 [T12](sand-iam-developer-security-operations-v0.1.md)定义；管理页责任以[终极管理端信息架构](sand-iam-terminal-admin-ia.md)为准，实际 HTTP 路径以[API 路由权威表](sand-iam-api-route-registry.md)和 `plugin/sand-iam/config/route.php` 为准。体验入口、用户可见状态和七链证据层级以[产品体验与闭环交付契约](sand-iam-product-experience-contract.md)为准。

以下旧草案路径没有入口，也不得被页面、SDK 或验收计划重新采用：`/account`、`/authorize`、`/context`、`/scim/v2`。当前运行面固定使用 `/api/sand-iam/v1/auth/*`、`/api/sand-iam/v1/me/*`、`/api/sand-iam/v1/oauth/*`、`/api/sand-iam/v1/cas/*`、`/api/sand-iam/v1/federation/*` 和 `/api/sand-iam/v1/scim/{provider}/*`；管理面固定使用 `/app/sand-iam/admin/*`。同一能力若同时有管理配置和应用用户操作，前者只使用 SandAdmin 管理会话，后者只使用应用用户会话，不能相互替代。

状态词：`源码候选`表示当前权威源码已有相应文件或路由；`待真实验收`表示尚未在同一受控宿主完成页面、HTTP、允许/拒绝、审计、撤销或恢复和清理。它们不能合并为“已完成”。

## 2. 原子差异矩阵

每行都已经把“入口、字段、错误/恢复、外部依赖和清理”落为可验收项。`页面/入口`的仓库相对路径必须存在；运行面或协议入口以路由为准而非虚构一个后台页面。`后端接口`必须在当前路由文件中存在；若以后新增页面或路由，必须先新增本表原子或在对应原子中显式登记为 planned，不能静默扩展。

| 原子 ID | 票据 | 页面/入口与会话 | 关键字段与稳定错误/恢复 | 前端路由或源码入口 | 后端接口 | 外部依赖 | 允许/拒绝与审计 | 撤销/恢复与清理 | 当前实现状态与完成证据 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| R08-T09-01 | T09 | 应用管理员配置登录外观；应用用户读取公开体验 | `application_id`、品牌、方式、注册字段；`SAND_IAM_APPLICATION_EXPERIENCE_UNAVAILABLE` 时提示稍后重试，不展示内部配置 | `sandadmin-artd/src/views/plugin/sand-iam/application-experience/index.vue`；运行面 `GET /api/sand-iam/v1/experience` | `GET /api/sand-iam/v1/experience`；管理 `application-experience/*` | 无；启用开关和 HTTPS 品牌链接 | 只返回公开品牌；未启用、跨应用和未编排登录方式必须拒绝；配置变更写审计 | 停用配置后公开读取不可用；清理测试应用的体验配置和审计范围 | 源码候选、待真实管理页/HTTP/浏览器；T09 第 2、5、6 节 |
| R08-T09-02 | T09 | 客户主体管理员管理消息服务，应用管理员仅挂载 | `provider_type`、`driver`、`config_version`、用途、模板、优先级；`SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID` 时拒绝保存或测试，Captcha 必填/无效、跨主体挂载拒绝 | `sandadmin-artd/src/views/plugin/sand-iam/message-provider/index.vue` | `message-provider/index|read|save|update|disable|configure|test|options|mounts|mount|unmount` | 真实邮件、短信、Captcha 或站外通知供应商；部署侧驱动和密钥 | 正常发送/验证；秘密不回显，停用与投递失败关闭，管理操作和认证结果审计 | 停用连带关闭挂载；卸载测试服务、模板、挂载和可清理审计夹具 | 源码候选、待真实供应商/HTTP/页面；T09 第 3–6 节 |
| R08-T09-03 | T09 | 独立登录、注册、恢复和安全门户；应用用户会话，非后台菜单 | `login_methods`、`session_id`、`mfa_state`；`SAND_IAM_AUTHENTICATION_FAILED` 时保留可重试输入，不泄露认证细节 | 运行面 `GET /app/sand-iam/account/` 与 `/api/sand-iam/v1/auth/*`、`/me/*` | `/api/sand-iam/v1/auth/*`、`/api/sand-iam/v1/me/profile|connections|security` | 真实浏览器和应用用户会话 | 合法应用用户可操作；后台会话、过期/撤销会话和未列入方式必须拒绝；安全操作审计 | 撤销会话/因子/通行密钥后旧凭证失效；用业务接口清理测试身份及关系 | 源码候选、待独立门户和多视口真实验收；T09 第 1、6 节及体验契约第 5 节 |
| R08-T10-01 | T10 | 应用身份和用户组；应用管理员会话 | `lifecycle_state`、`group_code`、`member_id`；`SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE` 时拒绝变更并说明恢复限制 | `sandadmin-artd/src/views/plugin/sand-iam/identity/index.vue`；`identity-group/index.vue` | `identity/*`；`identity-group/*`；`identity-group/member/*` | 无；若来自目录则由 T10-03 驱动 | 正常状态允许；停用、删除、跨应用和循环后端拒绝；状态与组变更审计 | 停用撤销会话；恢复不恢复旧会话；清理身份、组、成员和精确审计夹具 | 源码候选、待真实管理页/HTTP；T10 第 1、2、4、7 节 |
| R08-T10-02 | T10 | 邀请、访客升级、CSV 预检/确认；应用管理员或应用用户会话 | `invitation_token`、`external_guest_id`、`import_row`；`SAND_IAM_INVITATION_GROUPS_INVALID`、`SAND_IAM_IMPORT_FILE_INVALID` 时拒绝确认并可修正后重试 | `identity-invitation/index.vue`；`identity-import/index.vue`；运行面 `POST /api/sand-iam/v1/invitations/accept`、`guests/upsert` | `identity-invitation/*`、`identity-import/*`、`identity-export/*`、`/api/sand-iam/v1/invitations/accept`、`/api/sand-iam/v1/guests/upsert` | T09 已挂载的真实消息服务；业务服务身份 | 正常邀请/确认/访客原 ID 升级；过期、撤销、重放、跨应用、未预检确认拒绝并审计 | 撤销邀请、停用或删除身份；清理邀请、导入批次、访客和关联审计，不用人工 SQL | 源码候选、待真实 HTTP/消息服务/页面；T10 第 3、5、7 节 |
| R08-T10-03 | T10 | 同步连接、运行记录和脱敏出站记录；应用管理员会话 | `sync_connector_id`、`cursor`、`conflict_policy`、`outbox_id`；`SAND_IAM_SYNC_CONFIGURATION_INVALID` 保留失败页，`SAND_IAM_SYNC_OUTBOX_NOT_RETRYABLE` 拒绝不符合条件的显式重试，出站拒绝达到上限进入 failed | `sandadmin-artd/src/views/plugin/sand-iam/sync-connector/index.vue` | `sync-connector/index|read|save|update|disable|configure|test|run|runs|outbox|outbox-retry` | 真实 PostgreSQL/企业目录或其他受控目录驱动；调度器 | 已映射的同应用组允许同步；未映射、跨主体、游标失败、异常停用比例和跨应用 outbox 重试拒绝/暂停并审计 | 入站从保留游标重跑；出站仅对同应用 failed 事件显式重新排队，不与 running 任务并发；停用连接并清理测试投影 | 后端候选已补有界出站终态与恢复；管理端需 Cursor 补 failed 列表/重试交互；真实目录/调度/HTTP/页面待验收 |
| R08-T11-01 | T11 | 动态注册令牌管理和 OAuth/OIDC 运行面；管理员与标准客户端分别会话 | `scope`、`redirect_uri`、`registration_token`；`SAND_IAM_DCR_TOKEN_POLICY_INVALID`、`SAND_IAM_DCR_ACCESS_DENIED` 时拒绝注册或授权 | `sandadmin-artd/src/views/plugin/sand-iam/oauth-registration-token/index.vue`；运行面 `/oauth/*` | `oauth-registration-token/index|issue|revoke`；`/api/sand-iam/v1/oauth/register|authorize|token|logout` | 标准 OAuth/OIDC 客户端及两个 RP | 合法令牌注册和正确登出允许；匿名、超额、错误回调、越权 scope 拒绝；注册/注销审计 | 撤销初始令牌、grant/token/session；前/后通道登出任务失败重试，清理 client、token、投递和审计夹具 | 源码候选、待标准客户端/RP/宿主 HTTP；T11 第 2、3、8 节 |
| R08-T11-02 | T11 | CAS 服务登记与应用用户确认页；应用用户会话 | `service_url`、`ticket`、`renew`、`gateway`；`SAND_IAM_CAS_REQUEST_REJECTED` 时拒绝签发并保留可审计失败事实 | `sandadmin-artd/src/views/plugin/sand-iam/cas-service/index.vue`；运行面 `/api/sand-iam/v1/cas/*` | `cas-service/*`；`/api/sand-iam/v1/cas/login|interaction|serviceValidate|p3/serviceValidate` | 标准 CAS 客户端和已登记 HTTPS 服务 | 精确服务和同应用身份允许；后台会话、未知服务、票据重放拒绝并审计 | ticket 一次消费；停用服务后不得签发；清理服务、请求、票据及审计夹具 | 源码候选、待标准 CAS 客户端/宿主 HTTP/页面；T11 第 4、8 节 |
| R08-T11-03 | T11 | Kerberos 配置与 RADIUS NAS 管理；协议客户端运行面 | `SPN`、`keytab_reference`、`CIDR`；`SAND_IAM_KERBEROS_AUTHENTICATION_FAILED` 时拒绝认证，不返回协商细节 | `radius-nas/index.vue`；Kerberos 使用 `federation/configure` 管理页；运行面 `POST /api/sand-iam/v1/federation/kerberos/negotiate` 和 UDP RADIUS worker | `federation/configure`、`radius-nas/*`、`/api/sand-iam/v1/federation/kerberos/negotiate` | 临时 Kerberos Realm/GSSAPI/keytab、标准浏览器或 curl negotiate、真实 NAS/radclient | 仅可信传输/精确绑定/合法 NAS 允许；无 verifier、伪造头、MFA、篡改或重放拒绝并审计 | 停用身份源/NAS 后访问立即拒绝；清理 keytab 引用、NAS、RADIUS 会话和审计夹具 | 源码候选、待 Realm/GSSAPI/NAS 真实互操作；T11 第 5、6、8 节 |
| R08-T12-01 | T12 | 开发者 OpenAPI/事件目录、SDK、CLI；管理员与真实业务应用 | `application_code`、`discovery_url`、`https_endpoint`；`SAND_IAM_ONBOARDING_MANIFEST_INVALID` 时拒绝生成接入材料 | 管理入口在开发者页面；SDK/CLI 入口 `sdk/dart` 与 `bin/sand-iam` | `GET /app/sand-iam/admin/developer/openapi|events` | 真实 Flutter/Dart、PHP 或 TypeScript 业务应用与 HTTPS 端点 | 合法管理范围读取目录；无权限拒绝；SDK 不记录敏感输入，访问与诊断审计 | 撤销示例会话/凭证；清理独立业务应用夹具、诊断请求和审计 | 源码候选、待真实业务应用/宿主 HTTP；T12 第 1、7 节 |
| R08-T12-02 | T12 | 事件通知、网络规则、安全告警和审计保留；管理员会话 | `event_type`、`CIDR`、`retention_days`；`SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID`、`SAND_IAM_AUDIT_RETENTION_INVALID` 时失败关闭或可修正后重试 | `webhook/index.vue`、`application-network-policy/index.vue`、`security-alert/index.vue`、`audit-retention-policy/index.vue` | `webhook/*`、`application-network-policy/*`、`security-alert/*`、`audit-retention-policy/*` | 真实 HTTPS 接收器、可信代理边界、worker、备份/归档存储 | allow/deny CIDR 与事件投递均要正负例和审计关联；拒绝优先，不能由前端判断 | Webhook 失败重试；归档失败保留原审计；清除需单独授权；清理端点、投递、告警和测试规则 | 源码候选、待 worker/代理/接收器/备份恢复/页面；T12 第 2–4、6、7 节 |
| R08-T12-03 | T12 | 初始化配置预检、应用、回滚；平台管理员会话 | `preview_hash`、`initialization_run_id`、`rollback_scope`；`SAND_IAM_INITIALIZATION_PREVIEW_STALE`、`SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT` | `sandadmin-artd/src/views/plugin/sand-iam/initialization/index.vue` | 现有 `initialization/index|read|export|preview|apply|rollback|save|update|disable`；显式兼容入口 `GET /app/sand-iam/admin/initialization/draft-index|draft-read`；冻结请求/响应见[初始化草稿 API v0.1](sand-iam-initialization-draft-api-v0.1.md) | 无；但真实 PostgreSQL 事务和备份恢复 | 仅通过严格预检及确认摘要的操作允许；过期预检、漂移、秘密或外部身份源材料拒绝并审计 | 只按逆序回滚仍匹配的本次修改；清理测试运行、绑定和可撤销配置，不覆盖后续人工修改 | 源码候选、待真实 PostgreSQL 全流程/宿主页面；T12 第 5–7 节 |

## 3. 外部系统的真实验收门槛

每个原子均在下表登记真实系统、动作、可复核记录和 mock 边界。`N/A` 表示该原子没有跨系统调用；其理由仍需写明，不能借此跳过页面、HTTP、审计或清理验收。

| 原子 ID | 真实系统或 N/A（业务理由） | 动作与可复核证据 | mock 边界 |
| --- | --- | --- | --- |
| R08-T09-01 | N/A：公开体验读取只消费本插件配置，HTTPS 品牌链接不是被调用的外部服务 | 受控宿主记录应用启用/停用、公开读取允许/拒绝和对应审计 | 不适用；仍须以真实宿主 HTTP 记录证明，不能以页面内存数据替代。 |
| R08-T09-02 | 邮件、短信、Captcha 或站外通知供应商 | 受控供应商账号完成发送或挑战、失败关闭、密钥轮换和脱敏审计；保留投递记录与夹具撤销记录 | mock 不能证明供应商凭据、网络、模板、限流、投递回执或失败关闭。 |
| R08-T09-03 | N/A：应用用户会话由 SandIAM 运行面签发，不依赖第三方身份服务 | 受控宿主记录登录、撤销后拒绝、恢复输入和安全审计 | 不适用；仍须在真实浏览器自动化环境保留会话与撤销记录，不能以界面桩替代。 |
| R08-T10-01 | N/A：身份和用户组在 SandIAM 权威域内维护 | 受控宿主记录跨应用/组环拒绝、停用撤销会话和精确审计清理 | 不适用；仍须由真实 HTTP 与审计记录证明，不能以模型对象替代。 |
| R08-T10-02 | 已挂载消息供应商与受控业务服务身份 | 完成邀请投递、过期/撤销/重放拒绝、访客原 ID 升级；保留消息投递、HTTP 和审计清理记录 | mock 不能证明供应商投递、业务服务身份边界或跨服务升级副作用。 |
| R08-T10-03 | 受控 PostgreSQL 或企业目录驱动与调度器 | 完成分页、游标、映射、冲突、失败页重试和熔断；保留连接运行记录、来源记录与审计 | mock 不能证明实际驱动、TLS、查询权限、来源字段质量或调度恢复。 |
| R08-T11-01 | 标准 OAuth/OIDC 客户端与两个 RP | 完成重定向、PKCE、注册、登出、重放负例和清理；保留客户端 HTTP/cookie 与审计记录 | mock 不能证明协议编码、重定向、cookie、客户端回调和跨服务互操作。 |
| R08-T11-02 | 标准 CAS 客户端与已登记 HTTPS 服务 | 完成登录、ticket 一次消费、未知服务和重放负例；保留服务 HTTP、CAS 响应和审计清理记录 | mock 不能证明 CAS 编码、客户端回调、服务 URL 校验和跨服务互操作。 |
| R08-T11-03 | 临时 Kerberos Realm/GSSAPI/keytab 与真实 NAS/radclient | 完成正负认证、错 SPN、篡改、重放和 NAS 来源拒绝；保留协议报文、审计和清理记录 | mock 不能证明 Kerberos 协商、时钟/重放缓存、UDP 报文认证、源地址与共享密钥处理。 |
| R08-T12-01 | 真实 Flutter/Dart、PHP 或 TypeScript 业务应用与 HTTPS 端点 | 业务应用消费 SDK/CLI 并完成发现、权限拒绝和诊断；保留调用、审计和夹具清理记录 | mock 不能证明真实 SDK 消费、TLS、运行时兼容性或业务调用副作用。 |
| R08-T12-02 | HTTPS 接收器、可信代理、worker 与备份/归档存储 | 完成投递失败重试、来源地址判定、告警、归档和恢复；保留接收器、worker、备份恢复和审计记录 | mock 不能证明进程并发、网络拓扑、TLS、实际消费方副作用或备份恢复。 |
| R08-T12-03 | N/A：初始化包不调用外部身份或供应商系统 | 受控宿主记录 PostgreSQL 事务预检、应用、逆序回滚和备份恢复；保留运行、绑定与审计清理记录 | 不适用；数据库事务和备份恢复必须在真实宿主证明，不能以事务桩替代。 |

| 外部系统 | 真实验收最低门槛 | 为什么 mock 不能替代 |
| --- | --- | --- |
| 邮件、短信、Captcha、站外通知供应商 | 受控真实账号完成发送或挑战、供应商失败、秘密轮换、审计脱敏和测试夹具撤销 | mock 不会证明供应商凭据、网络、模板、限流、投递回执或失败关闭路径。 |
| 目录与同步来源 | 真实受控目录完成分页、游标、映射、冲突、重试、阈值熔断和来源缺失保护 | mock 不会证明实际驱动、TLS、查询权限、来源字段质量或调度恢复。 |
| OAuth/OIDC RP、CAS 客户端 | 标准客户端完整执行重定向、PKCE 或 ticket 消费、登出、重放负例与清理 | mock 无法证明协议编码、cookie/重定向、客户端回调和跨服务互操作。 |
| Kerberos Realm 与 RADIUS NAS | 临时 Realm/GSSAPI/keytab 和真实 NAS/radclient 完成正负认证、错 SPN、篡改和重放 | mock 不会证明 Kerberos 协商、时钟/重放缓存、UDP 报文认证、源地址与共享密钥处理。 |
| 业务应用、HTTPS 接收器、代理和备份 | 真实业务应用消费 SDK；HTTPS 接收器处理失败重试；可信代理来源地址、worker、归档与恢复均留记录 | mock 不会证明进程并发、网络拓扑、TLS、实际消费方副作用或备份恢复。 |

## 4. 冻结结论与剩余边界

本矩阵覆盖 T09–T12 的 12 个原子入口，且每一项都明确了字段、错误或恢复、页面或运行面、实际接口、外部依赖、正负行为、审计、撤销或恢复和清理。不存在待决定的产品入口、旧路径或重复归属：T09 的认证事务消息不等同 T12 的通用业务/安全事件；T10 的目录同步不等同 P05 的 LDAP/SCIM 端点；T11 的 Guest 已归 T10；T12 的初始化包不搬运秘密或外部身份源密文。初始化的 `save|update|disable` 已在当前路由实现；旧 `index|read` 继续只返回运行记录，草稿改由显式 `draft-index|draft-read` 兼容入口提供，字段与权限以[初始化草稿 API v0.1](sand-iam-initialization-draft-api-v0.1.md)冻结。

R08 的规格矩阵已完成登记：静态门禁 **9/9**，矩阵、唯一 ID、四票据分布、契约字段/错误、当前 API、外部门槛和旧路径均通过，并经独立复核 ACCEPT。因此 R08 状态为**通过**，但它只表示规格冻结，绝不构成运行验收，也不自动使任何 P/F/L/D 原子通过。P 的当前计分只认[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)：P01–P20 已按权威源码、schema、独立公开行为与适用静态/构建证据计入模块实现，其中 P14、P19 已独立最终复核 ACCEPT；F01–F07、L01–L04、D01–D08 仍未通过。
