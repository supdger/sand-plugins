# 应用接入

## 接入边界与稳定契约

一个接入应用必须明确客户主体、应用、环境、用户来源、业务资源、语义动作和审计责任。
SandIAM 判定身份与权限，应用负责加载真实业务对象并只在允许后产生业务副作用。

公开 HTTP API 的稳定前缀为 `/api/sand-iam/v1`；机器运行上下文的稳定前缀为
`/app/sand-iam/runtime/context`。管理面 API 位于 `/app/sand-iam/admin`，仅供拥有 SandAdmin
管理员登录态和对应权限的部署方使用，不能被最终用户页面或工作负载凭证调用。

| 场景 | 入口 | 鉴权与调用方责任 |
| --- | --- | --- |
| 应用用户注册、登录、刷新、会话与自助安全 | `/api/sand-iam/v1/auth/*`、`/me/*` | 未登录入口仅限注册/登录/刷新/恢复；其他调用使用 `Authorization: Bearer <access token>`。应用必须按组织代码和应用代码隔离用户体验。 |
| 业务 API 授权决定 | `POST /api/sand-iam/v1/authorization/decide` | 带用户 access token；请求携带稳定 `api_code`、已登记的组织/应用代码和服务器加载的范围属性。 |
| 机器身份上下文 | `POST /app/sand-iam/runtime/context/issue`、`verify` | 签发端用工作负载凭证作为 `Authorization: Bearer`；验证端提交短期 context，比较服务代码、受众和每一个动作后才执行业务副作用。 |
| 协议互操作 | `/api/sand-iam/v1/oauth/*`、`.well-known/*`、`/scim/*`、`/cas/*` | 只在管理员明确配置并按相应协议校验时启用；不能因路由存在而假定外部互操作已经验收。 |

所有写请求都应带可追溯的 `X-Request-Id`。对同一次业务意图，超时重试复用相同 request ID 和相同请求内容；
需要新的业务意图时才生成新的 request ID。凭证、token、短期 context 和一次性秘密不得进入 URL、请求日志或客户端持久化。

## 最小运行请求契约

`authorization/decide` 必须在服务器拥有用户 Bearer token 后调用；必填 body 是
`organization_code`、`application_code`、`api_code`，`api_version` 默认 `v1`，`attributes` 必须是
JSON 对象且只能来自服务器已验证的事实。最小请求和成功形状如下，`data.allowed=false` 仍是一次正常的
授权决定，业务方必须拒绝副作用：

```http
POST /api/sand-iam/v1/authorization/decide
Authorization: Bearer <application-user-access-token>
X-Request-Id: business-read-001
Content-Type: application/json

{"organization_code":"acme","application_code":"workbench","api_code":"work_item.read","api_version":"v1","attributes":{"organization_id":42}}
```

<!-- sand-iam-doc-contract: decide.allow -->
```json
{"code":200,"data":{"allowed":true,"code":"allowed","policy_ids":[12],"scope":{"equals":{"organization_id":42}},"application_id":3,"identity_id":101,"api_code":"work_item.read","api_version":"v1","resource_code":"work_item","action":"work_item.read","operation":"read","risk_level":"medium","request_id":"business-read-001"}}
```

<!-- sand-iam-doc-contract: decide.deny -->
```json
{"code":200,"data":{"allowed":false,"code":"SAND_IAM_POLICY_DENIED","policy_ids":[],"scope":{},"application_id":3,"identity_id":101,"api_code":"work_item.read","api_version":"v1","resource_code":"work_item","action":"work_item.read","operation":"read","risk_level":"medium","request_id":"business-read-deny-001"}}
```

<!-- sand-iam-doc-contract: decide.error -->
```json
{"code":403,"msg":"SAND_IAM_RESOURCE_SCOPE_DENIED: real entity is outside the permitted scope"}
```

`data.allowed=false` 是可解析的 deny 决定，PHP SDK 的 `authorize()` 会以
`AuthorizationDenied` 失败关闭；HTTP `401`/`403` 等 error envelope 则以 `SandIamException` 失败关闭。
缺失/空 Bearer token 返回 `401 SAND_IAM_AUTHENTICATION_FAILED`；组织或应用与 token 不一致、未登记
API、范围不符或策略拒绝必须按返回的稳定 `SAND_IAM_*` 码拒绝。网络错误、非 JSON 或不能识别的
响应同样拒绝，不得把调用方提供的 `organization_id`、owner 或范围属性当作可信事实。

机器调用分成两次：`issue` 的必填 header 是 workload credential，body 必填
`service_code`、`audience`、非空 `actions`；`verify` 不接收 workload credential，body 必填短期
`context`、同一 `audience` 和**每一个**待执行 `action`。两端均带 `Cache-Control: no-store`，并用各自
request ID 关联审计。签发响应至少要求 `context`、`context_id`、`expire_time`；验证响应至少要求
`context_id`、`service_code`、`audience` 和包含当前 action 的 `actions`。缺失任一字段、受众或动作
不等、过期/重放、撤销凭证或错误 service 均拒绝，不能沿用旧 context。

## 业务中间件装配

业务 Webman 路由必须显式装配
`plugin\SandIam\app\middleware\ApplicationAuthorizationMiddleware`，并在 route params 的 `sand_iam`
中提供已有的 `organization_code`、`application_code` 和 handler 前可调用的 `attributes`。路由路径只会
解析到已登记的语义 API，不能把 HTTP 路径当策略 key。

写入、导出、删除和批量路由还必须提供 `entity_scope`：`mode` 为 `entity` 或 `collection`、一个把已加载
业务对象转换为范围属性的 `attributes` callable，以及在 handler 前从可信 ID 加载对象的 `resolver` callable。
中间件先作 route-level decide，再用该对象做第二次范围复核；resolver 缺失、返回错误类型或任何条目越权
都必须在 handler 前失败。完整的最小目录和 allow/deny 操作顺序见
[Webman 业务示例](../../examples/webman-business-app/README.md)；需要受控、可独立启动的**非 AI** consumer 时，使用其
[`standalone/`](../../examples/webman-business-app/standalone/README.md)，并遵循其中手工授权 schema、隔离启动和
allow/deny/revoke/audit 路径。

## 人员访问的接入步骤

1. 创建客户主体、应用和环境。
2. 配置身份源、注册/登录方式和应用用户入口。
3. 登记用户组、角色、资源和动作，发布最小允许策略。
4. 使用允许用户和无权用户各完成一次真实访问。
5. 撤销角色、策略或会话后再次访问，确认被拒绝并核对审计。

## API 与数据范围

在接口目录中登记稳定的 API code、版本、资源、动作、受众和风险，再绑定 HTTP 方法与路由模板。
写入、导出和批量接口必须在 handler 之前从业务数据库加载真实对象，使用可信的组织、所有者或其他范围属性判定；
不得相信请求体提供的 owner 或 organization。

授权成功只说明当前调用可继续；应用仍须验证业务状态、并发和自身输入。授权失败、身份失效或任何无法解析的响应
均必须 fail-closed，不得降级为匿名访问。应用应记录自己的业务结果，并以 request ID 与 SandIAM 审计关联。

常见可处理的机器错误码包括 `SAND_IAM_AUTHENTICATION_FAILED`（凭证不可用或已失效）、
`SAND_IAM_RESOURCE_SCOPE_DENIED`（真实对象不在数据范围）、
`SAND_IAM_ROUTE_NOT_REGISTERED`（路由未登记）、
`SAND_IAM_SERVICE_ACTION_FORBIDDEN`（服务动作或受众未授权）和
`SAND_IAM_CREDENTIAL_REVOKED`（工作负载凭证已撤销）。错误消息面向操作人员，应用分支必须使用稳定错误码和 HTTP 状态；
收到未知错误码、网络失败或无效响应时同样拒绝请求并保留脱敏诊断。

可复制的 Webman 实现见[业务应用示例](../../examples/webman-business-app/README.md)，其
[`standalone/`](../../examples/webman-business-app/standalone/README.md) 是非 AI 业务 consumer 的受控独立入口。
SDK 在
[PHP](../../sdk/php/README.md)、[TypeScript](../../sdk/typescript/README.md) 和
[Dart](../../sdk/dart/README.md)，凭证只从服务端运行环境或密钥管理系统读取。

## 机器调用

创建机器身份、服务授权和一次性凭证。调用方用凭证签发短期上下文，服务方用相同 audience 和 action 验证后
才执行业务副作用。必须验证错误受众、无权动作、过期、重放和撤销后的拒绝；受控机器接入源码见
[machine-service-client](../../examples/machine-service-client/README.md) 的
[`provider/` + `caller`](../../examples/machine-service-client/provider/README.md)：caller 在内存中取得短期 context，
只经 header 交给 provider，provider 在幂等查询和业务副作用前验证。两条 consumer 路径都只提供离线门禁说明，
并不声称已完成 live 启动或真实验收。

创建或轮换 OAuth 客户端密钥、调用凭证等一次性秘密时，每个业务意图使用唯一且稳定的
`X-Request-Id`。若响应超时或连接中断，必须用原请求内容和同一个 request id 重试；服务不会再次执行，
重放响应也不会再次返回明文，而会给出 `secret_available=false`。此时应先核对已接收的密钥库和审计，
确需产生新秘密时再以新的 request id 发起一次明确轮换。不要通过连续点击或自动换 request id 找回明文。

刷新会话也必须为一次刷新意图复用同一个 `X-Request-Id`。若刷新响应超时，在首次请求后 30 秒内使用
同一旧 refresh token、同一 request id 和同一来源网络重试，可取回首次轮换的响应且不会再次轮换。
不要为超时重试生成新 request id；超过恢复窗口、响应被拒绝或无法确认状态时，应停止自动刷新并要求用户重新认证。

## Webhook

接收端必须使用 HTTPS，验证签名、时间窗和重放标识，并以事件 ID 幂等处理。
制造一次受控失败，确认退避重试不会重复业务副作用；最终结果应能与 SandIAM 和接收端审计关联。
