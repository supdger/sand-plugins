# SandIAM SCIM 2.0 API 契约 v0.1

状态：IAM-T04 后端契约。路由前缀为 `/api/sand-iam/v1/scim/{provider}`；`provider` 是 identity provider 的 public code。请求使用 `Authorization: Bearer <SCIM token>`；带实体的 SCIM 请求发送 `Content-Type: application/scim+json`，客户端声明 `Accept: application/scim+json`。响应均为 `application/scim+json`，错误响应带 `Cache-Control: no-store`；401 另带 `WWW-Authenticate: Bearer`。

令牌只属于一个已挂载的 application。管理端省略到期时间时固定签发 90 天；也可设未来一年内的 `YYYY-MM-DD HH:MM:SS`，但绝不创建永久 token。provider、organization、mount、application、令牌状态和过期时间会在认证时共同校验；历史 `expire_time=NULL` 记录同样拒绝认证，因此不同 application 的同一 organization provider 也不能混用令牌。

## 资源

Group PATCH 清空 `externalId` 可用 `{"op":"remove","path":"externalId"}`、`{"op":"replace","path":"externalId","value":null}` 或 `{"op":"replace","value":{"externalId":null}}`。成功后响应与重新读取均不再含该属性，资源 ID 和成员不变，版本递增。PUT 省略 `externalId` 保留原值，显式传 `null` 才清空。

- `GET /ServiceProviderConfig`、`GET /Schemas`、`GET /ResourceTypes`
- `GET|POST /Users`，`GET|PATCH|PUT|DELETE /Users/{id}`
- `GET|POST /Groups`，`GET|PATCH|PUT|DELETE /Groups/{id}`

创建返回 201、`Location` 和 `ETag`；读取、PUT、PATCH 返回资源 `meta.location`、弱 ETag `meta.version` 与同值 `ETag` header。更新和删除必须发送精确的 `If-Match`，缺失为 428，版本不匹配为 412。列表中的每个资源也带 `meta.location`。

`User.externalId` 与 `Group.externalId` 是 RFC 核心的可选 `readWrite` 属性：可在 PUT/PATCH 更新或移除，不能承担 SandIAM 的机器主键。创建时必须提供冻结扩展 `urn:sand:params:scim:schemas:extension:source:1.0` 的 `sourceKey`；它是 UTF-8、无控制字符、无首尾空白的稳定来源键，创建后不可变。内部 identity code 固定由 provider 和该 stable source key 的摘要导出。`userName` 是来源显示属性，不是 SandIAM 的机器 code；`displayName` 会清理控制/格式字符、归并空白并截断到 128 字符。`active=false` 或 DELETE 只停用该 provider/application binding 并撤销其 federation session 和 refresh token，不会删除可能被其他来源共享的 Identity。

Group PATCH 支持完整 `members` 替换/增加/清空、无 `path` 的对象值（`displayName`、`members`，成员可为单对象或数组），也支持 RFC 7644 形式的精确移除：`members[value eq "<scim user id>"]`。成员必须是该 provider 和 application 下仍归属该来源的 SCIM User；成员关系始终带 application_id，采用差量软删/恢复（含 `delete_time`），不能以新插入绕过唯一约束或跨应用复用成员。

## 稳定错误

| 代码 | HTTP / SCIM type | 含义 |
| --- | --- | --- |
| `SAND_IAM_SCIM_UNAUTHORIZED` | 401 | bearer token、状态、过期、provider 或 mount 不可用 |
| `SAND_IAM_SCIM_INVALID_RESOURCE` / `SAND_IAM_SCIM_INVALID_PATCH` | 400 / `invalidSyntax` | 字段、UTF-8、来源 subject 或 PATCH 格式不符合契约 |
| `SAND_IAM_SCIM_SOURCE_KEY_INVALID` | 400 / `invalidSyntax` | 创建资源未提供合格的冻结来源扩展 `sourceKey` |
| `SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY` / `SAND_IAM_SCIM_TOKEN_EXPIRE_INVALID` | 400 / `invalidSyntax` | PUT/PATCH 试图修改来源扩展 `sourceKey`，或管理签发传入无效到期时间 |
| `SAND_IAM_SCIM_CONFLICT` | 409 / `uniqueness` | external subject 或唯一身份冲突 |
| `SAND_IAM_SCIM_NOT_FOUND` | 404 | 资源不属于当前 token application/provider，或已删除 |
| `SAND_IAM_SCIM_PRECONDITION_REQUIRED` | 428 | 缺失 `If-Match` |
| `SAND_IAM_SCIM_PRECONDITION_FAILED` | 412 / `invalidVers` | ETag 不匹配 |

每个成功的写操作以 token 的实际 application 留审计。`ProvisioningEvent` 的落库字段仍待 006 架构在受控 PostgreSQL 生命周期中读取确认；在确认前不得把该模型当作已完成的事件证据。

Group 创建、替换、PATCH 和删除的成功审计与组及成员变更同事务提交。审计写入失败时，组状态、成员和版本号一起回滚，更新请求可使用原 `If-Match` 重试；成功更新后再使用旧版本返回 412，删除后的组返回 404，不提供响应丢失后的自动重放恢复。

User 创建、替换、PATCH 和删除同样将成功审计纳入事务；审计失败时，身份属性、来源绑定、版本及该来源会话的撤销一起回滚。成功停用或删除后，仅该来源绑定及其会话失效，共享 Identity 保留启用状态；旧版本重试和已删除资源分别按 412、404 处理。
