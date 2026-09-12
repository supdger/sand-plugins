# SandIAM 管理端接口交接（IAM-04 / IAM-T04，v0.3）

> 状态：**冻结，可供管理端消费**。v0.3 将身份源作用域明确为两种：`scope_type=application` 是应用私有实例；`scope_type=organization` 是组织持有实例，并通过 application mount 决定可用范围。旧身份绑定页面仍可提交 `provider_code`，但只会解析同组织且已挂载的既有身份源，绝不隐式创建记录或猜测作用域。Cursor 只修改 `sandadmin-artd/src/views/plugin/sand-iam/`，不得自行增改字段、路由、权限码或错误码。

> **版本口径：** 插件包候选版本为 `0.7.0`（根与插件 `info.ini`、运行配置的可安装发行版本）；OpenAPI 目录的 `0.13.0-candidate` 是管理 API 契约目录版本。两者独立演进，前者不能由后者推导，后者的 `candidate` 也不构成插件发布或 API 稳定性声明。`0.13.0-candidate` 新增 OIDC 后通道登出失败恢复的精确分页参数、请求/响应 DTO、稳定错误码和 404/503 响应声明；运行端点保持向后兼容。

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
| `identity-provider` | application scope：`organization_id, scope_type=application, application_id, code, name, status`；organization scope：`organization_id, scope_type=organization, code, name, status` | 创建事务固定 `organization_id,scope_type,provider_type=local` 并创建初始 application mount；`organization_id,application_id,scope_type,code` 之后不可改。应用私有实例只允许所属 application 挂载；组织实例必须显式挂载后才可使用。响应只给 `secret_configured`，**绝不返回** `encrypted_config` | `sand_iam:identity_provider:` |
| `identity-binding` | 新版：`identity_id, identity_provider_id, subject, status`；兼容版：`identity_id, provider_code, subject, status` | identity provider 必须属于相同 organization 且已挂载到 identity 的 application；应用私有实例还必须属于该 application。`subject` 仅在 `identity_provider_id + subject` 内唯一，禁止用 `provider_code + subject` 作全局键；不接收 token；`update` 只允许改 `status`，绑定主体不可篡改；响应同时返回 `identity_provider_id` 与 `provider_code` | `sand_iam:identity_binding:` |
| `user-type` | `application_id, code, name, status` | — | `sand_iam:user_type:` |
| `role` | `application_id, code, name, status` | — | `sand_iam:role:` |
| `resource` | `application_id, code, name, owner_field?, organization_field?, status` | — | `sand_iam:resource:` |
| `policy` | `application_id, resource_id, role_id xor identity_id, action, effect, condition, scope, priority, state?, status` | `POST /policy/publish`、`POST /policy/rollback`、`POST /policy/revoke`、`POST /policy/simulate` | 发布/回滚 `sand_iam:policy:publish`；模拟 `sand_iam:policy:read` |
| `admin-organization-grant` | `admin_user_id, organization_id, status` | 仅宿主超级管理员 | `sand_iam:admin_organization_grant:` |
| `audit` | 只读；筛选 `organization_id, application_id, actor_type, outcome` | `GET /audit/index`、`GET /audit/read?id=` | `sand_iam:audit:` |

## 受控验收数据清理

这两个接口只用于授权后的真实宿主验收，不是日常数据管理功能。部署侧必须先显式设置 `SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED=1`；默认关闭。调用者必须同时是平台超级管理员并拥有对应权限，普通客户主体管理员或应用管理员不能使用。

| 路由 | 用途 | 权限码 |
| --- | --- | --- |
| `POST /acceptance-fixture/webhook-event` | 仅为第 7 链发出一条本轮专用验收事件，并返回精确投递编号 | `sand_iam:acceptance_fixture:cleanup` |
| `POST /acceptance-fixture/cleanup` | 先停用，再按子对象到父对象的顺序清理本轮验收数据 | `sand_iam:acceptance_fixture:cleanup` |
| `GET /acceptance-fixture/status` | 按同一组精确编号检查是否还有残留 | `sand_iam:acceptance_fixture:read` |

当前支持七条受控清理链：`organization-application-environment`（客户主体、接入应用、应用环境）、`identity-group-role-policy`（身份、用户组及成员/角色关系）、`human-auth-session-mfa`（应用身份认证关系）、`workload-credential-invocation`（调用身份、服务授权、凭证）、`oauth-cas-api-governance`（OAuth、CAS、接口目录、路由绑定与策略）、`delegation-scope`（应用管理员委派）和 `event-webhook-delivery`（Webhook 端点与投递）。请求必须同时提交：

- 本轮唯一前缀：必须精确匹配 `^sand_iam_acceptance_[a-f0-9]{16}_$`；真实执行必须生成本轮唯一的 16 位小写十六进制标识，例如 `sand_iam_acceptance_20260829a1b2c3d4_`，并提交确认值 `I_CONFIRM_DELETE_ONLY_THIS_ACCEPTANCE_FIXTURE`；基础前缀、嵌套前缀和短/长标识均被拒绝；
- 请求内容中的 `request_id`，且必须与 `X-Request-Id` 完全一致，并以本轮前缀开头；每个对象的创建请求和清理请求使用不同编号；
- `organization_id`、`application_id`；
- 按对象类型列出的 `object_ids`；
- 与每个对象一一对应的 `object_request_ids`，它们必须命中该对象创建成功时的审计记录。

第 7 链在创建并启用端点后，只能调用 `POST /acceptance-fixture/webhook-event`。该接口与清理接口共用默认关闭、平台超级管理员和 `sand_iam:acceptance_fixture:cleanup` 边界；它要求固定链 `event-webhook-delivery`、本轮精确前缀、请求体与 `X-Request-Id` 相同的 `request_id`、客户主体/应用/端点编号，以及确认值 `I_CONFIRM_EMIT_ONLY_THIS_ACCEPTANCE_EVENT`。端点必须是本轮创建、已启用、归属该客户主体/应用，并命中 `webhook.create` 创建审计；接口只通过既有 Webhook outbox 写入一条 `acceptance.fixture.event`，其 `event_id` 精确等于 `request_id`，并返回该端点、应用和事件编号下唯一的 `delivery_ids`。它不触碰身份或任何其他业务对象。成功/失败审计仅保留链、前缀摘要、端点编号和投递数量，不记录确认值、原始前缀或端点密钥。

第 4 链额外要求 `environment_id`、`workload_client_id`、`service_id`、`service_action_id` 与 `invocation_request_ids`。接口逐项核验客户主体 → 应用 → 环境 → 调用身份，以及服务 → 服务动作关系；预置的客户主体、应用、环境、调用身份、服务和服务动作只作为边界输入，绝不在清理集合中。它先按 credential、grant 和完整层级查询同一固定前缀下的**全部** `service_invocation_operation`，再与提交的 `invocation_request_ids` 作无序精确集合比较：少一条、多一条或同一请求出现两条均拒绝；每条还必须命中 `service.invoke.authorize` 成功审计。历史或预置调用记录一律拒绝。该轮新建 grant 下的 quota bucket 是受限子对象，只在同一事务中处理。完整链先按正常语义撤销 credential、grant，再物理删除 **service_quota_bucket → service_invocation_operation → credential → service_grant**；若 credential 签发失败但 grant 已创建，可只提交 grant 的创建审计绑定作幂等回收。任何跨应用、跨环境、错调用身份、错服务/动作、少交对象、前缀或创建审计不匹配都会整批回滚。

第 2 链额外要求预置 `role_id`。接口按固定前缀与应用边界查询**全部**本轮 identity-group-member（同时限定本轮 group 和 identity）以及 identity-group-role（限定本轮 group）关系，再与提交编号无序精确比较；少交、多交、历史或同组额外关系均在任何停用或删除前拒绝。预置角色必须真实存在、已启用、属于当前应用且不带本轮前缀；关系中的 `role_id` 必须精确等于该预置角色。角色、资源、策略、客户主体、应用和环境均不属于清理对象。状态查询按实际关系范围统计残留，不能因提交编号较少而把额外关系误报为零残留。

第 7 链额外要求每条 `webhook_delivery` 的 `delivery_retry_request_ids`。端点必须属于提交的应用和客户主体；接口锁定该端点下该应用的**完整**投递集合，拒绝未提交、历史、额外、跨端点或跨应用的记录。任一投递已被 worker 领取（`status=2` 或锁未过期）即返回 `DRAIN_REQUIRED`，不执行任何写入；排空后才先停用端点，再物理删除 **delivery → endpoint**。每条投递还必须同时命中 `webhook.delivery_enqueue` 创建审计、`webhook.delivery` worker 审计及对应 `webhook.delivery_retry` 请求审计；审计只保留脱敏关联证据，不包含端点密钥。

接口会同时核对对象编号、固定前缀、客户主体/应用归属和创建审计。任一对象不属于本轮，整批操作都会拒绝并回滚；预置演示数据和历史数据不能被这个接口清理。成功响应包含 `matched`、`revoked`、`purged`、`residual` 和 `replayed`。重复提交同一个清理请求只返回上次结果，不会再次删除。成功审计使用 `acceptance_fixture.cleanup`；事务失败后的审计使用独立动作 `acceptance_fixture.cleanup_failed`，不会占用成功动作的同一请求键，因此同一清理请求可在故障消除后安全重试。两类审计只保留本轮请求编号、链、前缀摘要和数量，不保存确认值、凭证、`secret_hash`、token、原始前缀或密钥片段；客户主体和应用被清理后，审计正文仍保留。

该接口是验收闭环的一部分，不能降级为手工 SQL。运行前如果开关、权限、对象归属、创建审计或自动清理证据缺失，验收程序必须在第一个宿主写请求之前停止。

身份角色关系：

| 路由 | 请求字段 | 权限码 |
| --- | --- | --- |
| `GET /identity-role/index?identity_id=` | `identity_id` | `sand_iam:identity_role:index` |
| `POST /identity-role/grant` | `identity_id,role_id` | `sand_iam:identity_role:grant` |
| `POST /identity-role/revoke` | `id` | `sand_iam:identity_role:revoke` |

用户组角色关系：

| 路由 | 请求字段 | 权限码 |
| --- | --- | --- |
| `GET /identity-group-role/index?identity_group_id=` | `identity_group_id` | `sand_iam:identity_group_role:index` |
| `GET /identity-group-role/role-index?role_id=` | `role_id` | `sand_iam:identity_group_role:index` |
| `POST /identity-group-role/grant` | `identity_group_id,role_id` | `sand_iam:identity_group_role:grant` |
| `POST /identity-group-role/revoke` | `id` | `sand_iam:identity_group_role:revoke` |

用户组角色关系只允许同一接入应用内的用户组和角色建立关联；接入应用是所属客户主体的唯一授权边界。授予时两端都必须启用；撤销或停用用户组、成员关系或角色后，运行时立即不再从该关系取得角色。授予或撤销关系与对应审计记录在同一事务内提交，审计无法写入时关系变更会完整回滚。策略主体仍只能是 `role_id` 或 `identity_id`，不新增用户组策略。

身份用户类型关系：

| 路由 | 请求字段 | 权限码 |
| --- | --- | --- |
| `GET /identity-user-type/index?identity_id=` | `identity_id` | `sand_iam:identity_user_type:index` |
| `POST /identity-user-type/grant` | `identity_id,user_type_id` | `sand_iam:identity_user_type:grant` |
| `POST /identity-user-type/revoke` | `id` | `sand_iam:identity_user_type:revoke` |

## SCIM 管理令牌

SCIM provider 必须已经配置为 `provider_type=scim`，且挂载到目标 application。令牌明文只在签发响应中出现一次；列表绝不返回 token 或 token hash。

| 路由 | 字段 | 权限码 |
| --- | --- | --- |
| `POST /scim/token/issue` | `provider_id, application_id, name, expire_time?` | `sand_iam:scim:token_issue` |
| `GET /scim/token/index?provider_id=&application_id=` | — | `sand_iam:scim:token_index` |
| `POST /scim/token/revoke` | `provider_id, application_id, token_id` | `sand_iam:scim:token_revoke` |

`expire_time` 可省略；省略时后端固定为签发后 90 天。若提供，必须为未来的 `YYYY-MM-DD HH:MM:SS`，且不超过一年；SCIM token 永不保存为无到期时间。过期、停用、撤销、未挂载、跨 organization 或 application 的 token 均不能用于 SCIM；管理操作按实际 `application_id` 留 audit。SCIM 协议、ETag 和资源语义见 [SCIM API 契约](sand-iam-scim-api-v0.1.md)。

## 前端状态与稳定错误

- `condition`、`scope` 是 P0 受限 JSON：只允许 `equals` 与 `in`；字段与标量限制见 [授权与数据范围契约](sand-iam-authorization-contract.md#4-条件与范围-json-语法)。前端提交前校验，后端仍会拒绝无效值。
- 策略主记录仅以所属应用、`status=1` 与 `published_version_id` 定位运行版本；匹配的资源、动作、主体、条件、优先级、效果和数据范围全部取该不可变快照。草稿编辑不会改变运行版本。`POST /policy/rollback` 将指定历史快照复制为新版本，不修改历史。
- `POST /policy/simulate` 只读且响应 `Cache-Control: no-store`；请求需 `application_id,identity_id,resource_code,action,operation,attributes`，返回 request_id、命中 allow/deny 规则、优先级、条件、数据范围来源、缺失上下文和最终结论。它不产生业务放行、策略状态变更或审计事件；条件/范围中的秘密类字段默认脱敏。
- 显示统一错误语义：`SAND_IAM_VALIDATION_ERROR`（表单校验）、`SAND_IAM_RESOURCE_NOT_FOUND`（关联对象不存在）、`SAND_IAM_ORGANIZATION_ACCESS_DENIED`（越组织）、`SAND_IAM_POLICY_DENIED`（业务调用拒绝）、`SAND_IAM_RESOURCE_SCOPE_DENIED`（范围拒绝）、`SAND_IAM_FEDERATION_PROVIDER_CONFLICT`（身份源唯一边界冲突）、`SAND_IAM_SCIM_TOKEN_NOT_FOUND`（令牌已不存在/不可撤销）。
- 空列表、加载中、403 和后端 5xx 必须有诚实 UI 状态；不得模拟成功或将空范围显示为全量数据。
