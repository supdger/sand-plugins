# SandIAM API 路由权威表

> 口径日期：2026-08-22。本文是 SandIAM 唯一的人类可读路由分组表；运行时事实以
> `plugin/sand-iam/config/route.php` 为准。任何路由变更必须在同一改动中更新两处并通过路由契约检查。
> 旧文档中的 `/account/*`、`/authorize`、`/context/*` 和 `/scim/v2/*` 不是当前可调用路径。

## 1. 两个平面、三个路由分区

| 平面 | 路径 | 身份与门禁 | 用途 |
| --- | --- | --- | --- |
| SandAdmin 管理面 | `/app/sand-iam/admin/*` | SandAdmin 登录、权限码、委派范围；敏感配置接口另加脱敏中间件 | 平台管理员和应用管理员配置组织、应用、身份、策略、协议与安全运营 |
| SandIAM 运行面（人类/协议） | `/api/sand-iam/v1/*` | 应用会话、协议凭证或接口自己的安全中间件；不接受 SandAdmin 登录态代替应用身份 | 人类认证、自助、OAuth/OIDC、联合登录、SCIM、CAS 与授权决策 |
| SandIAM 运行面（机器） | `/app/sand-iam/runtime/context/*` | 工作负载凭证或短期上下文；不使用 SandAdmin 菜单权限 | 机器身份上下文签发和校验 |

后两行同属 SandIAM 运行平面。SandIAM 依托 SandAdmin/Webman 宿主进程运行，但应用用户和机器身份不复用 `sa_system_user`，也不依赖后台菜单权限。

## 2. 管理面分组

所有常规管理接口使用 `/app/sand-iam/admin` 前缀。当前分组如下：

| 分组 | 当前路径或模式 | 说明 |
| --- | --- | --- |
| 基础对象 | `/{organization,application,environment,client,service,action,grant,identity,auth-policy,application-experience,oauth-client,identity-provider,identity-binding,role,user-type,resource,policy,admin-organization-grant,admin-application-grant,api-resource,api-route-binding,cas-service,radius-nas,application-network-policy,audit-retention-policy}/{index,read,save,update,disable}` | 并非每个角色都能访问全部对象；实际权限由 controller 权限码和委派范围共同决定 |
| 角色和用户类型关系 | `/identity-role/*`、`/identity-user-type/*` | 查询、授予、撤销 |
| 审计与安全 | `/audit/*`、`/security-alert/*`、`/initialization/*` | 查询、导出、归档、告警处理和初始化包 |
| 凭证与协议客户端 | `/credential/*`、`/oauth-client/secret/rotate`、`/oauth-registration-token/*`、`/cas-service/*`、`/radius-nas/*` | 明文秘密只在签发或轮换时展示一次 |
| 联合身份与 SCIM 管理 | `/federation/*`、`/scim/token/*` | 身份源配置、挂载、同步和 SCIM Token 管理 |
| Webhook 与消息服务 | `/webhook/*`、`/message-provider/*` | 配置、密钥轮换、投递查询/重试和服务挂载 |
| 人员生命周期 | `/identity-group/*`、`/identity-invitation/*`、`/identity-import/*`、`/identity-export/*` | 组、邀请、导入导出和恢复 |
| 同步与开发者 | `/sync-connector/*`、`/developer/openapi`、`/developer/events` | 连接器、管理 API 目录和事件目录 |

联合身份、消息服务、邀请、导入、同步、注册令牌、RADIUS 和初始化中的秘密输入接口不经过会记录请求体的通用系统日志，改用对应的敏感输入中间件；它们仍要求 SandAdmin 登录和权限。

## 3. 身份运行面分组

| 能力 | 当前路径 | 主要方法 |
| --- | --- | --- |
| 认证、会话、MFA、Passkey | `/api/sand-iam/v1/auth/*` | `POST` 为主；会话和因子列表为 `GET` |
| 用户自助 | `/api/sand-iam/v1/me/profile`、`/me/connections`、`/me/security` | `GET`，资料修改为 `PATCH` |
| 授权决策 | `/api/sand-iam/v1/authorization/decide` | `POST` |
| 应用登录体验 | `/api/sand-iam/v1/experience` | `GET` |
| 邀请与访客 | `/api/sand-iam/v1/invitations/accept`、`/guests/upsert` | `POST` |
| OAuth/OIDC | `/api/sand-iam/v1/oauth/*` | authorize/interaction/token/userinfo/revoke/register/logout |
| OIDC 元数据 | `/api/sand-iam/v1/.well-known/openid-configuration`、`/.well-known/jwks.json` | `GET` |
| 外部联合身份 | `/api/sand-iam/v1/federation/{oidc,oauth2,saml,kerberos,handoff}/*` | 按协议使用 `GET` 或 `POST` |
| SCIM 2.0 | `/api/sand-iam/v1/scim/{provider}/...` | ServiceProviderConfig、Schemas、ResourceTypes、Users、Groups |
| CAS | `/api/sand-iam/v1/cas/*` | login、interaction、validate、serviceValidate、p3/serviceValidate |

SCIM 的 `{provider}` 是已登记身份源的稳定代码。当前没有 `/api/sand-iam/v1/scim/v2/*` 别名，客户端不得自行省略 provider。

## 4. 机器运行面

| 能力 | 当前路径 | 方法 |
| --- | --- | --- |
| 签发短期上下文 | `/app/sand-iam/runtime/context/issue` | `POST` |
| 校验短期上下文 | `/app/sand-iam/runtime/context/verify` | `POST` |

远程业务授权使用 `/api/sand-iam/v1/authorization/decide`，不是旧的 `/api/sand-iam/v1/authorize`。机器上下文使用 `/app/sand-iam/runtime/context/*`，不是旧的 `/api/sand-iam/v1/context/*`。应用环境引用校验仅通过包内 `EnvironmentReferenceVerifier` 供受信 Adapter 调用，不提供公网探测接口。

## 5. 维护和验收规则

1. `config/route.php` 是运行时路由事实；本文是唯一允许维护的文档路由分组表。
2. 其他契约文档只链接本文，不再复制一套“冻结路径”。
3. 每次改路由必须核对 HTTP 方法、controller、中间件、权限码、默认路由关闭和管理 OpenAPI。
4. 有源码或表不代表接口可用；正式验收必须留存允许、拒绝、错误码、审计 `request_id` 和清理证据。
5. 不为旧草案路径增加无期限兼容别名；如确需兼容，必须单独记录调用方、截止日期、告警与删除条件。
