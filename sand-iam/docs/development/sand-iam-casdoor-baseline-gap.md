# SandIAM 对照 Casdoor 的终极能力缺口（2026-08-21）

> 结论：当前 T01–T06 后端候选并未达到“除 SandAdmin 宿主能力外，完整度不低于 Casdoor”的目标。T08 只能在下列新增缺口完成后执行终极发布验收；否则只能发布明确标注能力范围的阶段版。

## 1. 对照依据

Casdoor 官方仓库把自己定义为 UI-first IAM，核心覆盖用户、组织、应用、Provider、OAuth/OIDC、SAML、CAS、LDAP、SCIM、WebAuthn、TOTP/MFA、REST API、SDK、Webhook 和可定制 UI：[官方仓库](https://github.com/casdoor/casdoor)。官方文档目录还列出 Guest、Kerberos/SPNEGO、动态客户端注册、邀请、Email/SMS/Captcha/Notification Provider、数据库/Azure AD/Active Directory/Google Workspace/Keycloak Syncer、CLI、桌面/移动 SDK、用户模拟、IP allowlist 等能力：[官方文档总览](https://casdoor.ai/docs/overview/)。

SandIAM 不照搬 Casdoor 的表结构、页面和 Casbin 文本，也不把支付、商城、订阅、AI/MCP 等商业扩展纳入 IAM 必选范围。但只要属于通用身份、协议、目录同步、用户自助、安全运营或开发者接入，就必须明确实现或写出有理由的替代方案。

## 2. 当前真实差距

| 能力 | 当前 SandIAM | 终极缺口 |
| --- | --- | --- |
| 组织/应用 | 客户主体、应用、环境、组织/应用委派 | 应用登录页配置、品牌/主题、登录方式排序、注册字段、独占登录、应用标签/访问条件 |
| 用户生命周期 | 注册、验证、登录、资料、停用基础、角色/用户类型 | 邀请、激活、软删除/恢复、组与层级、批量导入导出、访客升级、受控模拟登录 |
| 本地消息 | 验证码发送适配口 | 可管理的 Email/SMS/Captcha/Notification Provider、模板、测试发送、速率/失败审计、秘密只写不读 |
| MFA/Passkey | TOTP、恢复码、Passkey 后端 | 完整运行面 UI、恢复流程、策略管理和真实多浏览器/设备验证 |
| 联合/企业目录 | OIDC/OAuth/SAML、LDAP、SCIM 后端候选 | 通用 Syncer 模型；数据库、Azure AD/AD、Google Workspace、Keycloak 等连接器及游标/双向策略 |
| 协议 | OAuth/OIDC Provider、SAML SP/联合、SCIM | CAS Server、Kerberos/SPNEGO、RADIUS、动态客户端注册、Guest、标准 back-channel/front-channel logout 取舍与实现 |
| 授权/API | Sand 原生 resource/action/scope、接口目录、PHP/TS SDK | 策略模拟 UI、解释、IP allowlist 真正执行、完整 OpenAPI/Swagger、管理 API SDK/CLI |
| 客户端生态 | PHP、浏览器 TypeScript | 通用 Dart/Flutter SDK；桌面/移动 OIDC 示例；可生成配置与诊断命令 |
| Webhook | 端点、加密密钥、签名、队列、重试候选 | 注册/登录/退出/资料/目录/策略/凭证等事务事件生产者、事件版本目录、保留和告警 |
| 审计运营 | 审计查询/详情/CSV 候选 | 按客户主体保留期、归档/删除策略、告警出口、请求链路诊断和管理员可理解的事件字典 |
| 运行面 UI | 自助 API 候选 | 独立登录/注册/恢复/MFA/会话/资料/连接门户、应用品牌、多语言、无障碍与真实浏览器验收 |
| 初始化/迁移 | SQL lifecycle | 应用/身份源/角色/策略等可审查初始化包、导入预检、幂等应用、差异报告和回滚 |

## 3. 不照搬但必须替代

- Casdoor 的 URL/对象式权限不替代 SandIAM 的稳定 `resource + action + scope`；SandIAM 的 API 路由绑定是更适合业务演进的增强。
- Casdoor 的组织用户体系不升级为跨产品统一自然人；SandIAM 始终按应用隔离账号。
- 用户模拟登录默认不开放。若为客服排障实现，只能采用短时 break-glass、二次认证、用户可见通知、禁止高风险操作和不可关闭审计。
- Face ID/身份核验不是默认认证因子。只有产品有明确必要性与合规依据时，通过独立 Provider 接入，敏感原值不进入通用身份库。
- 支付、产品、套餐、订阅、订单和商业许可证归业务/SandLicense，不进入 SandIAM。

## 4. 新增执行任务

### IAM-T09：应用登录体验与消息 Provider

- 应用品牌、主题、登录/注册字段和方式编排；
- Email、SMS、Captcha、Notification Provider 管理、测试和秘密治理；
- 独立应用用户登录/注册/恢复/安全门户的后端配置和前端载荷。

### IAM-T10：完整用户生命周期、组、邀请与通用同步

- 邀请、激活、禁用、软删除、恢复、组/层级、批量导入导出、访客升级；
- Syncer 抽象和数据库/企业目录连接器；单向/双向、冲突、游标、重试、停用传播与审计。

### IAM-T11：协议补齐与会话互操作

- CAS、Kerberos/SPNEGO、RADIUS、动态客户端注册、Guest 的启用条件和安全实现；
- OIDC front-channel/back-channel logout、标准客户端互操作和协议负面测试。

### IAM-T12：开发者生态与安全运营

- Dart/Flutter SDK、CLI、完整管理 API/OpenAPI、接入诊断；
- Webhook 事务事件目录和生产者；审计保留、归档、告警；IP allowlist/网络策略执行；
- 初始化包、导入预检、差异报告与可回滚应用。

## 5. 完成判定

T09–T12 每项都必须具备契约、实现、自动测试、隔离 PostgreSQL 和真实宿主/标准客户端证据。T08 是这些任务之后的终极集成发布门，不得用当前 T01–T06 的模块候选完成度代替。
