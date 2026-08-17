# SandIAM 管理端接口交接（IAM-04，v0.1）

> 状态：**冻结，可供 Cursor U-03 消费**。后端路由已安装在 SandAdmin 验收宿主；Cursor 只修改 `sandadmin-artd/src/views/plugin/sand-iam/`，不得自行增改字段、路由、权限码或错误码。

所有管理路由前缀为 `/app/sand-iam/admin`，均需要宿主登录、权限与操作日志中间件。除非另有注明：

- `GET /{resource}/index`：分页参数 `page,limit`；可按关联 ID、`status` 与 `keywords` 筛选。
- `GET /{resource}/read?id=`：读取单条。
- `POST /{resource}/save`：创建。
- `POST /{resource}/update`：更新，必须传 `id`。
- `POST /{resource}/disable`：停用，必须传 `id`。
- 非超级管理员只能访问其 `sand_iam_admin_organization_grant` 已授予的 organization；越界统一是 `SAND_IAM_ORGANIZATION_ACCESS_DENIED`（403）。

## 资源与表单字段

| 前端资源段 | 字段 | 额外操作 | 权限码前缀 |
| --- | --- | --- | --- |
| `identity` | `application_id, code, display_name, status` | — | `sand_iam:identity:` |
| `identity-binding` | `identity_id, provider_code, subject, status` | `provider_code + subject` 全局唯一；不接收 token；`update` 只允许改 `status`，绑定主体不可篡改 | `sand_iam:identity_binding:` |
| `user-type` | `application_id, code, name, status` | — | `sand_iam:user_type:` |
| `role` | `application_id, code, name, status` | — | `sand_iam:role:` |
| `resource` | `application_id, code, name, owner_field?, organization_field?, status` | — | `sand_iam:resource:` |
| `policy` | `application_id, resource_id, role_id xor identity_id, action, effect, condition, scope, priority, state?, status` | `POST /policy/publish`、`POST /policy/revoke` | `sand_iam:policy:` |
| `admin-organization-grant` | `admin_user_id, organization_id, status` | 仅宿主超级管理员 | `sand_iam:admin_organization_grant:` |
| `audit` | 只读；筛选 `organization_id, application_id, actor_type, outcome` | `GET /audit/index`、`GET /audit/read?id=` | `sand_iam:audit:` |

身份角色关系：

| 路由 | 请求字段 | 权限码 |
| --- | --- | --- |
| `GET /identity-role/index?identity_id=` | `identity_id` | `sand_iam:identity_role:index` |
| `POST /identity-role/grant` | `identity_id,role_id` | `sand_iam:identity_role:grant` |
| `POST /identity-role/revoke` | `id` | `sand_iam:identity_role:revoke` |

身份用户类型关系：

| 路由 | 请求字段 | 权限码 |
| --- | --- | --- |
| `GET /identity-user-type/index?identity_id=` | `identity_id` | `sand_iam:identity_user_type:index` |
| `POST /identity-user-type/grant` | `identity_id,user_type_id` | `sand_iam:identity_user_type:grant` |
| `POST /identity-user-type/revoke` | `id` | `sand_iam:identity_user_type:revoke` |

## 前端状态与稳定错误

- `condition`、`scope` 是 P0 受限 JSON：只允许 `equals` 与 `in`；字段与标量限制见 [授权与数据范围契约](sand-iam-authorization-contract.md#4-条件与范围-json-语法)。前端提交前校验，后端仍会拒绝无效值。
- 策略仅 `state=published && status=1` 参与运行时判定；发布或撤销后刷新列表。
- 显示统一错误语义：`SAND_IAM_VALIDATION_ERROR`（表单校验）、`SAND_IAM_RESOURCE_NOT_FOUND`（关联对象不存在）、`SAND_IAM_ORGANIZATION_ACCESS_DENIED`（越组织）、`SAND_IAM_POLICY_DENIED`（业务调用拒绝）、`SAND_IAM_RESOURCE_SCOPE_DENIED`（范围拒绝）。
- 空列表、加载中、403 和后端 5xx 必须有诚实 UI 状态；不得模拟成功或将空范围显示为全量数据。
