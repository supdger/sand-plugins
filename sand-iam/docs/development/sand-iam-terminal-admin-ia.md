# SandIAM 终极管理端信息架构（U-T00）

> 状态：2026-08-28 体验契约已冻结，待 Cursor 实现、Codex 验收。本文是管理端页面责任清单和总览导航方案，不代表页面已全部实现，也不代表宿主/浏览器验收通过。产品语言、向导、入口和闭环标准以[产品体验与闭环交付契约](sand-iam-product-experience-contract.md)为准。
>
> 独占目录：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`。不改 PHP、SQL、路由或权限码。接口契约未就绪时，不生成面向用户的半成品入口，也不使用内部研发词汇解释页面状态。
>
> 运行平面（应用用户登录/自助）使用独立门户，**不得**进入 SandAdmin 左侧菜单。

## 1. 两个平面

| 平面 | 给谁 | 入口 | 会话 |
| --- | --- | --- | --- |
| SandAdmin 管理平面 | 平台管理员、客户主体管理员、应用管理员 | 本插件管理端总览 | 宿主 `check_admin` |
| SandIAM 运行平面 | 应用用户、标准协议客户端 | 独立门户 `/api/sand-iam/v1/auth/*`、`/api/sand-iam/v1/me/*` | 应用 access token，禁止 `check_admin` |

总览现有三条 P0 路径保留，并增加认证、接口治理、管理范围、事件通知四条任务入口。卡密只链接独立 SandLicense 边界，不在本插件做页面。

## 2. 总览导航

总览页 `index/index.vue` 按任务路径而不是数据表平铺。卡片只展示模块用途，不能重复“适用角色”：

| 模块 | 固定用途说明 |
| --- | --- |
| 客户与应用接入 | 登记谁在使用 SandIAM，以及哪些系统需要接入。 |
| 应用用户与权限 | 管理用户从哪里来、能进入哪些应用、可以做什么。 |
| 认证与会话 | 设置登录方式和安全要求，管理登录状态。 |
| 接口与访问控制 | 控制系统之间如何连接，以及可以调用哪些能力。 |
| 管理范围 | 把指定客户或应用的管理工作交给合适的管理员。 |
| 事件通知 | 把用户和权限变化通知给业务系统，并查看是否送达。 |
| 审计与排错 | 查询谁在什么时候做了什么，并处理异常。 |

独立应用用户门户在总览用说明卡片展示，不生成后台菜单项。

## 3. 默认列禁则

所有管理列表默认禁止：数据库 ID、内部外键、原始 JSON、完整 token、密钥、密码、来源 ID、审计 `actor_ref`、无操作价值的时间列。需要系统代码时作为次要可复制列。关联对象按名称选择，编辑时回显所属层级。

## 4. 页面责任矩阵

本文中的 `frozen`、`candidate`、`runtime` 仅是交付人员的契约标记，绝不直接显示给产品使用者。用户可见状态只允许为“已设置、尚未设置、进入页面确认、在接入应用中使用、暂时无法读取”。`runtime` 表示运行面，不进入 SandAdmin 菜单。

### 4.1 客户与应用接入

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 客户主体 | 平台管理员 | 登记接入边界 | 客户主体（组织） | 名称、状态、系统代码（次要可复制） | 名称、状态 | 新建/编辑/停用；停用后不可用于新授权 | `/organization/*` `sand_iam:organization:*` | frozen |
| 接入应用 | 平台/客户主体管理员 | 登记产品/项目 | 接入应用 | 名称、所属客户主体、状态、系统代码（次要） | 客户主体、状态 | 新建/编辑/停用 | `/application/*` `sand_iam:application:*` | frozen |
| 应用环境 | 应用管理员 | 开发/测试/生产隔离 | 应用环境 | 名称、所属接入应用、状态、系统代码（次要） | 客户主体→应用 | 新建/编辑/停用 | `/environment/*` `sand_iam:environment:*` | frozen |
| 服务调用身份 | 应用技术接入 | 机器身份，不是人 | 服务调用身份 | 名称、所属环境、服务受众、状态、系统代码（次要） | 客户主体→应用→环境 | 新建/编辑/停用 | `/client/*` `sand_iam:client:*` | frozen |
| 服务授权 | 应用技术接入 | 把服务动作授给调用身份 | 服务授权 | 调用身份、服务动作、受众、状态 | 调用身份 | 撤销授权；额度/网络为高级可选 | `/grant/*` `sand_iam:grant:*` | frozen |
| 调用凭证 | 应用技术接入 | 一次展示明文 | 调用凭证 | 名称、调用身份、过期、状态 | 调用身份 | 签发/轮换/撤销；明文只弹一次；`secret_available=false` 只引导轮换 | `/credential/*` `sand_iam:credential:*` | frozen |
| 平台服务 / 服务动作 | 平台管理员 | 平台服务目录 | 平台服务、服务动作 | 名称、状态、系统代码（次要） | 状态、所属服务 | 新建/停用；动作代码沿用语义契约 | `/service/*` `/action/*` | frozen |

### 4.2 应用用户与权限

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 身份源 | 应用管理员 | 登记本应用可用身份源 | 身份源 | 名称、所属应用、状态、系统代码（次要） | 应用 | 秘密只写不读，回显“已配置” | `/identity-provider/*` `sand_iam:identity_provider:*` | frozen |
| 应用身份 | 应用管理员 | 目录内身份 | 应用身份 | 应用用户名称、所属应用、账号状态（等待邀请/正常/已停用/访客/已删除）、用户组摘要 | 应用 | 停用/启用/删除/恢复须说明不可恢复项；登录标识脱敏值未出现在 identity 列表 DTO，不编造 | `/identity/*` `sand_iam:identity:*`；组摘要组合 `identity-group/members` | frozen（U-T06） |
| 身份绑定 | 应用管理员 | 外部账号绑定到应用身份 | 身份绑定 | 应用身份、身份源、外部标识、状态 | 身份 | 创建后主体不可改，只能停用 | `/identity-binding/*` `sand_iam:identity_binding:*` | frozen |
| 用户类型 / 角色 / 业务资源 | 应用管理员 | 权限对象 | 用户类型、角色、业务资源 | 名称、所属应用、状态 | 应用 | 新建/停用 | 对应 `sand_iam:user_type:*` `role:*` `resource:*` | frozen |
| 策略 | 应用管理员 | 角色或身份二选一授权 | 策略 | 资源、主体、动作、效果、发布状态 | 应用 | 发布/撤销；condition/scope 用 equals/in | `/policy/*` `sand_iam:policy:*` | frozen |
| 身份角色 / 用户类型关系 | 应用管理员 | 授予/撤销 | 身份角色、身份用户类型 | 身份、对象、状态 | 必须先选身份 | 授予/撤销 | `sand_iam:identity_role:*` `identity_user_type:*` | frozen |
| 应用用户生命周期 | 应用管理员 | 邀请/正常/停用/访客/删除 | 应用用户 | 名称、应用、账号状态、登录标识（诚实等待）、用户组摘要 | 应用、状态 | 停用/删除须说明不可恢复项 | 扩展 `identity`；组摘要组合 `identity-group` | frozen（U-T06；登录标识列未冻结） |
| 用户组 | 应用管理员 | 组层级与成员 | 用户组 | 名称、应用、上级组、成员数、状态 | 应用 | 循环/有成员冲突走后端原文 | `sand_iam:identity_group:*` `identity_group_member:*` | frozen（U-T06） |
| 邀请 | 应用管理员 | 按邮箱/手机邀请 | 用户邀请 | 脱敏接收目标、用户组、状态、到期 | 应用、状态 | 重发/撤销；不展示 token；门户接受后须登录 | `sand_iam:identity_invitation:*`；发送/接受走已实现 controller 字段 | frozen（U-T07） |
| CSV 导入导出 | 应用管理员 | 预检后确认 | 用户导入导出 | 预检摘要：总行/可执行/错误；行报告显示脱敏目标和用户组名称 | 应用 | 完整联系方式导出需独立权限+二次确认；有错误行禁止确认 | `sand_iam:identity_import:*` `identity_export:*` | frozen（U-T08） |
| 用户同步 | 应用管理员 | 目录同步连接 | 用户同步 | 名称、应用、方向、驱动、配置状态、最近同步 | 应用 | 秘密只写不读；不得写“已连接生产目录”直到真实驱动验收 | `sand_iam:sync_connector:*` `sync_run:*` | frozen（U-T09） |

### 4.3 认证与会话

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 认证设置 | 应用管理员 | 注册/密码/验证码开关/锁定 | 应用认证设置 | 所属应用、公开注册、密码最短位数、失败锁定次数、登录验证码、状态 | 客户主体→应用、状态 | 每应用一条；重复新建返回冲突；令牌 TTL/通行密钥进高级区 | `/auth-policy/*` `sand_iam:auth_policy:*` | frozen（U-T01） |
| 用户目录 | 应用管理员 | 创建/停用应用用户 | 应用用户 | 显示名称、所属应用、账号状态、用户组摘要 | 客户主体→应用、状态 | 停用后不能登录；恢复不恢复旧会话；ID/token 不进列表 | `/identity/*` `sand_iam:identity:*` | frozen（U-T01/U-T06） |
| 会话 | 应用管理员 / 当前应用用户 | 查看/撤销当前用户自己的会话 | 应用用户会话 | 当前/其他设备、最近活动、登录时间 | 无管理筛选 | 必须粘贴应用用户 access token；不使用 check_admin；编号和过期时间在详情 | `GET/POST /api/sand-iam/v1/auth/sessions` | runtime（U-T01） |
| MFA / Passkey | 应用管理员 / 当前应用用户 | 查看/添加/撤销当前用户认证器 | MFA 与通行密钥 | 名称、类型、状态、最近使用 | 无管理筛选 | 必须粘贴应用用户 access token；TOTP seed/恢复码只展示一次；通行密钥添加走门户 | `GET/POST /api/sand-iam/v1/auth/mfa/*` | runtime（U-T02） |
| 身份源 | 应用管理员 | 登记本地或企业身份源 | 身份源 | 名称、范围、协议、密钥状态、状态 | 客户主体、应用、范围 | 新建只写名称/范围/标识；密钥不回显 | `/identity-provider/*` `sand_iam:identity_provider:*` | frozen（U-T02） |
| 联合配置 | 应用管理员 | OIDC/OAuth/SAML/LDAP/SCIM/Kerberos 配置 | 联合身份源配置 | 不列表展示密钥、keytab 内容或完整 URL | 按名称选择身份源/应用 | 密钥只写不读；Kerberos 三项安全要求不可关闭；真实外部 IdP/GSSAPI 未验收不得写已连接 | `/federation/configure|mount|sync` `sand_iam:federation:*` | frozen / 协议未验收（U-T02/U-T12） |
| SCIM 令牌 | 应用管理员 | 签发/撤销目录令牌 | SCIM 供给令牌 | 用途名称、状态、到期、最近使用 | 身份源、应用 | 明文只弹一次；不展示 token hash | `/scim/token/*` `sand_iam:scim:token_*` | frozen（U-T02） |

### 4.4 接口与访问控制

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 应用业务动作 | 应用管理员 | 稳定“业务资源 + 语义动作”词典 | 应用业务动作 | 名称、代码、说明、发布状态、启停 | 应用 | 创建后代码不可改；发布固化声明；独立权限码未冻结，复用 `sand_iam:api_resource:*` | `/application-business-action/*` `sand_iam:api_resource:*` | frozen（U-T03，权限复用） |
| 接口目录 | 应用管理员 | 登记开发者接口 | 接口目录 | 接口名称、应用、业务资源、语义动作、操作类型、版本、受众、风险、状态 | 应用 | 创建后应用/资源/代码/动作/操作/版本不可改；权限依据不是 URL | `/api-resource/*` `sand_iam:api_resource:*` | frozen（U-T03） |
| 路由绑定 | 应用管理员 | 方法+模板 → 接口 | 路由绑定 | 方法、路由模板、绑定接口、来源、状态 | 应用 | 变更方法/模板需新建；扫描不自动建权限；不展示 fingerprint | `/api-route-binding/*` `sand_iam:api_route_binding:*` | frozen（U-T03） |
| 路由清单导入 | 应用管理员 | dry-run 后确认 apply | 路由清单 | 预检对象类型、标识、操作 | 无默认列表 URL | 只走 onboarding preview/apply；route-sync/v1 不是管理 HTTP；确认前不得显示已应用 | `/developer/onboarding/preview|apply` `sand_iam:onboarding:*` | frozen（U-T03） |
| 策略模拟 / 决策解释 | 应用管理员 / 当前应用用户 | 看后端 allow/deny 解释 | 策略模拟 | 主体、资源、动作、后端结果、原因摘要 | 按名称选择应用/身份 | **只展示后端字段**，前端不得推断；decide 用 Bearer，不用 check_admin | `/policy/simulate`、`/api/sand-iam/v1/authorization/decide` | frozen（U-T03） |
| OAuth/OIDC 客户端 | 应用管理员 | 标准客户端 | OAuth 客户端 | 名称、应用、类型、回调摘要、scope 摘要、前/后通道已配置、客户端密钥状态 | 应用 | 密钥只展示一次；签发密钥是 issuer 级摘要；登出地址进高级区 | `/oauth-client/*` `sand_iam:oauth_client:*` | frozen（U-T03/U-T10） |
| 动态注册令牌 | 应用管理员 | 签发 DCR 初始访问令牌 | 动态注册令牌 | 名称、应用、主机摘要、scope 摘要、剩余次数、到期、状态 | 应用 | 明文只弹一次；HMAC/IP 不进列表 | `/oauth-registration-token/*` `sand_iam:oauth_registration_token:*` | frozen（U-T10） |
| CAS 接入服务 | 应用管理员 | CAS 服务登记 | CAS 接入服务 | 名称、应用、服务地址摘要、属性释放、状态 | 应用 | 改地址需新建并停用旧记录 | `/cas-service/*` `sand_iam:cas_service:*` | frozen（U-T11） |
| Kerberos / RADIUS | 应用管理员 | 企业协议接入 | Kerberos 身份源、RADIUS 设备 | Kerberos 在联合配置页；RADIUS 显示名称、应用、CIDR、密钥已配置/未配置、状态 | 应用 | 不展示 keytab/共享密钥；RADIUS 仅 User-Name+Password | `/federation/configure` `/radius-nas/*` `sand_iam:federation:*` `sand_iam:radius_nas:*` | frozen（U-T12；真实 GSSAPI/NAS 未验收） |

### 4.5 管理范围

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 客户主体管理委派 | 平台管理员 | 把客户主体交给后台管理员 | 客户主体管理委派 | 管理员、客户主体、状态 | 客户主体 | 当前冻结接口仍可能要管理员编号；页面必须说明该限制 | `/admin-organization-grant/*` `sand_iam:admin_organization_grant:*` | frozen |
| 应用管理员委派 | 客户主体管理员 | 把接入应用交给后台管理员 | 应用管理员委派 | 后台管理员名称、接入应用、状态 | 应用 | 按名称搜索管理员，禁止手填 ID | `/admin-application-grant/*` `sand_iam:admin_application_grant:*` | frozen（U-T04） |

### 4.6 事件通知

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Webhook | 应用管理员 | 订阅事件 | 事件通知 | 名称、应用、地址摘要、事件摘要、密钥版本、状态 | 应用 | 密钥只弹一次；HTTPS only | `/webhook/*` `sand_iam:webhook:*` | frozen（U-T04） |
| 投递记录 | 应用管理员 | 失败重试 | 投递记录 | 事件、通知名称、结果、尝试次数、错误码 | 应用 | 仅等待/最终失败可重试；payload 不进列表 | `/webhook/delivery/*` `sand_iam:webhook_delivery:*` | frozen（U-T04） |

### 4.7 审计与排错

| 页面 | 给谁 | 任务 | 中文标题 | 默认列 | 筛选 | 详情/危险操作 | API / 权限 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 访问审计 | 平台/安全/接入人员 | 按对象追溯允许/拒绝 | 访问审计 | 发生时间、应用、操作者、操作、资源、结果、请求标识 | 客户主体、应用、主体类型、结果、请求标识 | 内部 ID 只在详情；context 中文映射。导出 ≤31 天，权限 `sand_iam:audit:export` 独立 | `/audit/*` `sand_iam:audit:*` | frozen（U-T04 含导出） |
| 应用网络规则 | 应用管理员 | 允许/拒绝网段 | 应用网络规则 | 应用、允许/拒绝网段数量、状态 | 应用 | 拒绝优先；完整 CIDR 在表单；每应用一条 | `/application-network-policy/*` `sand_iam:application_network_policy:*` | frozen（U-T13） |
| 安全告警 | 平台/安全人员 | 查看并标记处理 | 安全告警 | 客户主体、应用、等级、规则、次数、首次/最近时间、状态 | 客户主体、应用 | 指纹和原始 IP 不进列表 | `/security-alert/*` `sand_iam:security_alert:*` | frozen（U-T13） |
| 审计保留策略 | 平台管理员 | 归档/保留/告警阈值 | 审计保留策略 | 客户主体、归档天数、保留天数、清除策略、状态 | 客户主体 | 浏览器不能执行清除 | `/audit-retention-policy/*` `sand_iam:audit_retention_policy:*` | frozen（U-T13） |
| 初始化配置 | 平台管理员 | 预检/应用/回滚包 | 初始化配置 | 包代码、状态、应用时间、操作人 | 客户主体 | 先预检；禁止“强制覆盖” | `/initialization/*` `sand_iam:initialization:*` | frozen（U-T13） |

### 4.8 运行平面（不进后台菜单）

| 页面 | 给谁 | 任务 | 入口 | 默认展示 | 禁止 | 状态 |
| --- | --- | --- | --- | --- | --- | --- |
| 登录/注册/恢复/MFA | 应用用户 | 进入应用 | 独立门户 | 当前应用品牌名称、登录方式 | 不使用 SandAdmin 会话 | runtime（U-T05） |
| 资料与安全概况 | 应用用户 | 改显示名、看因子 | `/api/sand-iam/v1/me/*` | 显示名称、会话摘要、认证器状态 | 不展示内部 ID、密文、token | frozen（U-T04；登录页归 U-T05） |
| 会话自助 | 应用用户 | 撤销其他设备 | `/api/sand-iam/v1/auth/sessions` | 当前会话标记 | 管理端登录不能替代 | frozen（U-T04） |
| CAS 确认 | 应用用户 | 确认目标服务 | 运行面 `/cas/interaction` | 应用名称、服务名称、精确地址 | 管理端登录无效；Ticket 不展示 | runtime（U-T11） |

## 5. 空态与错误

每页必须区分：加载中、当前范围无数据、缺少上级对象、无权限、对象已停用、校验失败、冲突、网络失败、后端稳定错误码。Webhook/凭证在 `secret_available=false` 时不得重放 secret。

## 6. 本轮交付边界

- 已写入总览路径导航和等待态步骤，见 `api/taskPaths.ts`、`index/index.vue`。
- U-T01 已把认证设置、用户目录接到冻结管理 API，会话接到运行面 `/auth/sessions`。
- U-T02 已把身份源接到冻结管理 API，联合配置/挂载/LDAP 同步与 SCIM 令牌接到 `/federation/*`、`/scim/token/*`，MFA 接到运行面 `/auth/mfa/*`；Kerberos、未验收预置和独立验证码供应商目录仍为等待。
- U-T03 已把 OAuth 客户端、业务动作（权限复用）、接口目录、路由绑定、onboarding preview/apply、策略模拟和运行面 decide 接到冻结 API；动态注册令牌留给 U-T10。
- U-T04 已把应用委派、Webhook/投递、审计导出接到冻结管理 API；独立门户账户安全页只走 auth/me，不进后台菜单。登录/注册/experience 留给 U-T05。
- 未改 PHP/SQL/菜单/权限码。
- 未登录，故无 `1440×900` / `1280×720` / 三角色截图；不以构建代替宿主验收。
- U-T05 已把登录外观、消息服务接到冻结管理 API；门户增加公开 experience 与登录/注册/找回密码。供应商配置只写不读。
- U-T06 已把应用用户生命周期和用户组接到冻结管理 API；账号状态用 `lifecycle_state` 中文，不展示 `status=1/2`。登录标识脱敏值未出现在 identity 列表 DTO，列保持诚实等待；用户组摘要仅组合 `identity-group/members`。
- U-T07 已把邀请列表/发送/重发/撤销接到 `identity-invitation/*`；门户接受走 `/api/sand-iam/v1/invitations/accept`，成功后必须登录，不签发后台会话。
- U-T08 已把 CSV 预检/确认/行报告和脱敏/敏感导出接到冻结导入导出 API；有错误行禁止确认。
- U-T09 已把同步连接/配置/测试/运行记录接到 `sync-connector/*`；配置只写不读，不宣称已连接生产目录。
- U-T10 已把动态注册令牌和 OAuth 前/后通道登出配置接到冻结管理 API；令牌明文只展示一次。
- U-T11 已把 CAS 接入服务接到 `cas-service/*`；独立门户确认只走 `/cas/interaction` 与应用 Bearer。
- U-T12 已把 Kerberos 配置接到联合配置页，RADIUS 设备接到 `radius-nas/*`；不展示 keytab/共享密钥，不宣称已连通。
- U-T13 已把开发者下载、网络规则、安全告警、审计保留和初始化预检/应用/回滚接到冻结管理 API；初始化没有强制覆盖。
- 下一步源码任务：无。U-05B/UX-01B 仍等登录后「恢复启动 Autopilot」。
