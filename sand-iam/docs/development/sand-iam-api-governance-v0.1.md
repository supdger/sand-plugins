# SandIAM 接口治理与业务接入契约（IAM-T05，v0.1）

> 状态：后端候选实现。本文已冻结稳定语义和接入方式；PostgreSQL 生命周期以及应用/组织隔离、路由冲突、停用关闭失败、OpenAPI 语义保留和审计集成已通过，真实业务接入与发布验收尚未完成。

## 1. 一句话原则

权限判断使用“业务资源 + 语义动作”，不使用 HTTP 地址。

```text
HTTP 请求方法 + 路由模板
        ↓ 只负责定位
接口目录记录
        ↓ 固定映射
业务资源 + 语义动作 + 数据操作类型
        ↓
PolicyAuthorizer 允许/拒绝 + 数据范围 + 审计
```

例如 `/api/example/v1/records/{id}` 可以改为 `/api/example/v2/business-records/{id}`，只需修改路由绑定；长期策略仍然使用 `record + record.read`，不需要批量迁移权限。

## 2. 两类记录

### 接口目录

`sand_iam_api_resource` 说明一个应用向开发者开放的稳定接口能力。

| 中文名称 | 字段 | 填什么 | 用途 |
| --- | --- | --- | --- |
| 所属接入应用 | `application_id` | 接口属于哪个产品应用 | 保证应用隔离 |
| 业务资源 | `resource_id` | 已登记的业务资源 | 形成稳定权限对象 |
| 接口代码 | `code` | 如 `record.detail` | SDK 和文档使用的稳定代码 |
| 接口名称 | `name` | 如“查看业务详情” | 管理员和开发者识别 |
| 语义动作 | `action` | 如 `record.read` | 策略匹配使用，不能写 URL |
| 数据操作类型 | `operation` | `list/read/create/update/delete/export/batch` | 决定数据范围在哪个阶段执行 |
| 接口版本 | `api_version` | 如 `v1` | 支持并行升级 |
| 接口受众 | `audience` | OAuth access token 的目标服务 | 防止令牌跨服务错用 |
| OAuth Scope | `required_scope` | 可选，如 `record.read` | 使用 OAuth 时的最小范围 |
| 风险等级 | `risk_level` | 低、中、高、关键 | 审查、告警和后续 step-up 依据 |
| 接口说明 | `description` | 业务用途和调用结果 | 生成接入文档 |

接口代码不是数据库主键，也不是策略键；它是开发者接入层的稳定引用。真正参与策略判定的是接口记录指向的业务资源代码和语义动作。

### 路由绑定

`sand_iam_api_route_binding` 保存请求方法、路由模板和接口目录记录之间的关系。

- 路由必须使用 Webman 路由模板，如 `/api/example/v1/records/{id}`，不能写域名、查询参数或实际用户输入。
- 一个应用中的同一“请求方法 + 路由模板”只能有一个启用绑定；冲突时拒绝请求，不猜测使用哪条记录。
- `manual` 表示管理员手工登记，`openapi` 表示 OpenAPI 导入，`route_scan` 表示运行时路由扫描。
- 扫描器只能为已经存在的接口目录记录补充或刷新路由绑定，不能自动创建业务资源、动作、策略或授权。

### 应用业务动作声明（P1）

`sand_iam_application_business_action` 是应用自己的动作词典，和技术服务接入的 `sand_iam_service_action` 完全分开。先为每个业务动作声明稳定 `code`、中文 `name`、中文 `description`、发布状态和启停状态；例如 `record.read / 查看业务记录 / 读取一条业务记录的已授权字段`。一个代码只能在一个应用内出现一次，创建后代码不允许修改；发布只是把草稿固化为可审计声明。

接口目录的 `action`、策略的 `action`、路由清单最终定位到的接口目录动作，都必须引用同一个已启用声明。新建、修改、初始化包和路由同步默认严格校验，找不到声明会给出 `SAND_IAM_APPLICATION_ACTION_UNDECLARED`；停用声明后，运行时授权以 `SAND_IAM_APPLICATION_ACTION_DISABLED` 拒绝，不能继续放行已绑定路由。

管理后端入口为 `/app/sand-iam/admin/application-business-action/*`（列表、读取、保存、更新、停用），另有 `POST .../publish` 和 `GET .../pending-claims?application_id=...`。当前复用既有接口目录管理权限，直到独立管理端菜单和权限目录在后续版本一起发布。初始化包可写：

```json
"business_actions": [
  {"code":"record.read","name":"查看业务记录","description":"读取一条业务记录的已授权字段","state":"published","status":1}
]
```

初始化器可据此生成稳定 SDK 常量（例如 `RECORD_READ = 'record.read'`）；策略必须引用包内的 `business_actions.code`。历史 `api_resource.action` 和 `policy.action` 不会被迁移自动认定合法或自动写入新表：管理端的 `pending-claims` 报告会列出待人工认领的旧值。兼容期内旧记录运行时标记为 `pending_claim`，但任何新建、变更、初始化和路由同步均不接受它；认领、核对名称/说明后再建立正式声明。

## 3. 授权决策 API

`POST /api/sand-iam/v1/authorization/decide`

请求头：

```http
Authorization: Bearer <SandIAM 应用会话令牌或 OAuth access token>
X-Request-Id: <调用方生成的请求标识>
Content-Type: application/json
```

请求体：

```json
{
  "organization_code": "sand",
  "application_code": "customer-portal",
  "api_code": "record.detail",
  "api_version": "v1",
  "attributes": {
    "request_channel": "api",
    "record_state": "open"
  },
  "entity_attributes": {
    "organization_id": 42,
    "owner_identity_id": 101
  }
}
```

返回数据：

```json
{
  "allowed": true,
  "code": "allowed",
  "policy_ids": [12],
  "scope": {"equals": {"organization_id": 42}},
  "application_id": 3,
  "identity_id": 101,
  "api_code": "record.detail",
  "api_version": "v1",
  "resource_code": "record",
  "action": "record.read",
  "operation": "read",
  "risk_level": "medium",
  "scope_checked": true
}
```

- 身份、应用、接口、资源、策略任一停用都必须拒绝。
- 本地应用会话不能跨应用；OAuth 令牌同时校验 issuer、签名、过期时间、撤销状态、application、audience 和所需 scope。
- 没有策略或路由冲突一律拒绝；允许和拒绝都进入 SandIAM 审计。
- `attributes` 是策略选择上下文，`entity_attributes` 只能来自后端已加载的单个真实对象，二者不能用请求参数互相代替。
- 传入 `entity_attributes` 后，SandIAM 复核 `scope`、记录 `scope.*` 审计并返回 `scope_checked=true`；范围不匹配返回正常 deny 决定。业务后端仍须把 `scope` 当作限制，不能交给前端决定是否安全。

## 4. Webman 中间件

业务路由显式启用 SandIAM：

```php
use app\controller\RecordController;
use plugin\SandIam\app\middleware\ApplicationAuthorizationMiddleware;
use Webman\Route;

Route::get('/api/example/v1/records/{id}', [RecordController::class, 'read'])
    ->setParams([
        'sand_iam' => [
            'organization_code' => 'sand',
            'application_code' => 'customer-portal',
            'attributes' => ['request_channel' => 'api'],
        ],
    ])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
```

中间件从 Webman 当前路由读取路由模板，解析唯一接口绑定，完成粗粒度允许/拒绝，并把完整结果写入 `$request->sandIamAuthorization`。控制器在读取、更新或删除具体业务记录后，还必须使用 `ApplicationAuthorizationService::assertScope()` 对真实业务属性复核；禁止把请求头中的组织 ID 当作最终事实。

只要策略返回非空 `scope`，以及所有 `create/update/delete/export/batch` 路由，业务路由都必须声明 `entity_scope`；缺失时中间件以 `SAND_IAM_ENTITY_SCOPE_CONFIGURATION_REQUIRED` 或 `SAND_IAM_ENTITY_SCOPE_RESOLVER_REQUIRED` 拒绝。`resolver` 是必填项：它在 handler **之前**从数据库加载真实对象并完成 scope 校验；解析失败、对象越权或没有 resolver 时 handler 根本不会被调用。属性解析器只接收该真实对象，不能读取请求体伪造的 owner、组织或批量 ID。两阶段使用同一个规范化 `request_id`，路由级 `authorize.*` 与实体级 `scope.*` 审计可关联。

```php
Route::post('/api/example/v1/records/{id}/archive', [RecordController::class, 'archive'])
    ->setParams(['sand_iam' => [
        'organization_code' => 'sand',
        'application_code' => 'customer-portal',
        'entity_scope' => [
            'mode' => 'entity',
            'attributes' => static fn (Record $record): array => [
                'organization_id' => $record->organization_id,
                'owner_identity_id' => $record->owner_identity_id,
            ],
            'resolver' => static fn (Request $request): Record => Record::findOrFail((int) $request->route->param('id')),
        ],
    ]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);

// resolver 已在 handler 前拒绝越权对象；handler 只能执行归档业务。
```

导出、批量修改和批量删除使用 `mode: 'collection'`，并由 resolver 返回完整已加载对象集合；集合内任一对象越权即默认拒绝，不能只校验第一条或把请求体的 ID/owner 当作对象属性。`list/read/update/delete/export/batch` 都按接口目录的 `operation` 进入同一 scope 审计。`create` 没有待加载的新对象时，resolver 必须加载可信的服务器侧父资源（如所属组织、项目或业务记录）预检；禁止先写入新对象再补做范围校验。

非 Webman 的 PHP 业务端可使用 SDK 的 `authorizeEntity()` 或 `authorizeCollection()`。`authorizeEntity()` 先从已加载对象解析实体属性，把它与路由属性分字段提交给 SandIAM；SandIAM 完成粗粒度授权后复核 scope，并用同一 request ID 写入 `scope.allowed` 或 `scope.denied`。SDK 只接受带 `scope_checked=true` 的允许结果，并在本地再次匹配返回的 scope；旧服务端或未确认复核的结果按协议错误关闭。`authorizeCollection()` 仍先做粗粒度授权，再对完整已加载集合逐项本地匹配，任一对象不匹配即抛出 `SAND_IAM_RESOURCE_SCOPE_DENIED`；需要逐对象 SandIAM `scope.*` 审计的批量路由应使用插件的 `EntityScopeGuard`。

`application_code` 只在客户主体内唯一，因此远程 API、SDK 和中间件配置都使用 `organization_code + application_code`。只有本地应用会话可以从已验证会话反查应用；如果调用方同时提供代码，也必须与会话所属应用完全一致。

## 5. SDK

- PHP/Webman SDK：`sand-iam/sdk/php`。`authorize()` 在拒绝时抛出 `AuthorizationDenied`，网络、协议和认证错误抛出 `SandIamException`。
- 浏览器 TypeScript SDK：`sand-iam/sdk/typescript`。外部数据按 `unknown` 校验，`authorize()` 在拒绝时抛出 `SandIamDeniedError`。
- 浏览器判断只能控制按钮、入口和提示，不能替代业务后端鉴权与数据范围复核。
- PHP、TypeScript 与 Dart/Flutter SDK 都透传 `X-Request-Id`，业务日志应保存同一标识，便于从产品请求追到 SandIAM 审计。Dart SDK 与无密钥 CLI 见[开发者生态与安全运营契约](sand-iam-developer-security-operations-v0.1.md)。

为避免每个产品重复手写账号接入，三套 SDK 还统一提供：注册、登录、刷新、退出、个人资料、安全概况、外部账号连接、会话列表/撤销和修改密码。PHP 使用显式 access token 参数；TypeScript 与 Dart 使用调用方注入的 token 读取函数。SDK 不替产品决定持久化方式，浏览器端不得把长期刷新令牌写入可被任意脚本读取的 localStorage；优先由业务后端使用 HttpOnly、Secure、SameSite cookie 管理。

TypeScript 示例：

```ts
const iam = new SandIamClient({
  baseUrl: "https://iam.example.com",
  organizationCode: "sand",
  applicationCode: "customer-portal",
  accessToken: () => session.accessToken,
})

const result = await iam.login({ identifier: account, password })
const profile = await iam.profile()
await iam.authorize({ apiCode: "record.detail", attributes: { organization_id: 42 } })
```

登录、刷新和一次性安全材料响应应设置 `no-store`；SDK 不记录密码、令牌、验证码或响应正文。

## 6. OpenAPI 与路由扫描

### 6.0 一份清单接入（P1 预检格式）

`sand-iam.onboarding/v1` 把客户主体引用、初始化包、环境、应用业务动作、接口目录、路由、服务调用身份和服务授权放进一份开发者交接清单。`organization` 只能填已经存在的客户主体 `code`，不会因为清单自动创建主体。动作分两类：`initialization.business_actions` 是业务 API/策略动作；每条 `service_grants` 必须同时填写技术服务 `service_code` 和该服务内的 `action_code`，两者不能互相替代，也不能只按动作代码猜测服务。

先向 `POST /app/sand-iam/admin/developer/onboarding/preview` 提交清单，预检不会写入数据；结果会给出中文计划、当前状态、`preview_hash`、无秘密的 PHP/TypeScript/Dart/.env 交接物和 allow/deny/audit 验证清单。确认后以同一清单、`preview_hash`、显式 `apply: true` 调用 `/onboarding/apply`（需要专门的 `sand_iam:onboarding:apply` 权限；预检为 `sand_iam:onboarding:preview`）。应用在一个事务中重新读取受影响集合并校验哈希；任何对象被其他管理操作改变都会返回 `SAND_IAM_ONBOARDING_PREVIEW_STALE`，应重新预检。`operation_id` 是幂等键：同一操作者和同一清单只应用一次，重放只返回脱敏元数据。交接物可以由 `OnboardingManifest::handoff()` 生成；一次性 workload credential 仅首次返回，响应带 `Cache-Control: no-store`，重放不再展示明文。

`ApiGovernanceService::openApiOperation()` 输出标准 `operationId/summary/description` 和 `x-sand-iam` 扩展；其中 `x-sand-iam.action` 与应用业务动作声明使用同一稳定代码。未声明或已停用的动作会以稳定错误拒绝，不会进入可调用 OpenAPI；`pending_claim` 只出现在专门的历史待认领报告中。业务项目可把它合并到自己的 OpenAPI 文档。

`ApiGovernanceService::observeRoute()` 是受限适配入口：只接受已经登记的应用、接口代码和版本。扫描结果发生冲突时返回 `SAND_IAM_ROUTE_BINDING_CONFLICT`，不会覆盖原绑定。

### 6.1 Webman 路由清单同步（P0）

业务应用使用 `RouteBindingSynchronizer::synchronize($manifest, $apply, $disableMissing)` 同步路由绑定。它是插件权威源码中的正式开发者入口；应用可从自己的 Webman 路由定义导出同一份 JSON/PHP 数组清单，但 SandIAM **不会**自动遍历宿主的全部路由。

先在 SandIAM 管理台登记所属应用的业务资源和接口目录（接口代码、语义动作、版本、受众等），再在业务应用维护清单。`sand_iam` 块是唯一的纳管标记；没有该块的路由会被忽略，不会进入接口目录、路由绑定或 `sand_iam_service_action`。

```php
$manifest = [
    'format' => 'sand-iam.route-sync/v1',
    'organization_code' => 'sand',
    'application_code' => 'customer-portal',
    'environment_code' => 'production',
    'routes' => [
        // 未标记：健康检查不会被扫描或登记。
        ['method' => 'GET', 'path' => '/healthz'],
        [
            'method' => 'GET',
            'path' => '/api/example/v1/records/{id}',
            'sand_iam' => ['api_code' => 'record.detail', 'api_version' => 'v1'],
        ],
    ],
];

$sync = new \plugin\SandIam\app\runtime\RouteBindingSynchronizer();
$preview = $sync->synchronize($manifest, operationId: 'acceptance-run-20260822'); // 默认 dry-run，只显示差异
$applied = $sync->synchronize($manifest, apply: true, disableMissing: true, operationId: 'acceptance-run-20260822'); // 显式写入
```

预览和应用结果同时给出稳定机器字段 `operation/code`，以及中文汇总“新增、更新、停用、保留外部绑定、冲突、未绑定、忽略未标记路由”。`disableMissing` 只停用同一应用内来源为 `route_scan`、却已不在该**完整应用清单**中的启用绑定；不会删除记录，也不会刷新、改写或接管 `manual`、`openapi` 或其他来源。首次使用或分模块维护清单时应保持 `false`。`operationId`（也可传同值 `requestId`）用于串联一次验收/同步操作；每条实际写入由它派生唯一稳定子 request ID，避免审计或唯一约束误把多条操作合并。

常见错误：

- `SAND_IAM_ROUTE_SYNC_API_UNDECLARED`：清单的 `api_code + api_version` 尚未在该应用的接口目录中声明，或记录已停用；先登记接口目录，不能让同步器创建业务资源、动作或策略。
- `SAND_IAM_ROUTE_SYNC_ACTION_UNDECLARED`：接口目录引用的业务动作没有在同一应用中正式声明，或已停用；先在应用业务动作目录认领/声明，再同步。
- `SAND_IAM_ROUTE_SYNC_ACTION_DISABLED`：接口目录动作虽已声明但已停用；同步和后续授权均保持拒绝，需由应用负责人重新启用或改用已声明动作。
- `SAND_IAM_ROUTE_BINDING_CONFLICT`：同一“方法 + 路由模板”已绑定到另一接口；必须由负责人明确修改清单或既有绑定，系统不会猜测覆盖。
- `SAND_IAM_ROUTE_BINDING_OWNERSHIP_CONFLICT`：应用期间检测到绑定被 `manual`、`openapi` 或其他来源接管；重新预览并由原维护方处理，扫描器不会夺权。
- `SAND_IAM_ROUTE_SYNC_ENVIRONMENT_MISMATCH`：环境不存在、已停用或不属于清单的接入应用；检查客户主体、应用和环境三者的归属。
- `SAND_IAM_ROUTE_SYNC_APPLY_BLOCKED`：预览仍有冲突或未绑定项；修复后重新预览，再显式 `apply: true`。

## 7. 稳定错误码

| 错误码 | HTTP | 含义 |
| --- | ---: | --- |
| `SAND_IAM_API_NOT_REGISTERED` | 403 | 接口未登记、停用或版本不匹配 |
| `SAND_IAM_ROUTE_NOT_REGISTERED` | 403 | 路由没有启用绑定 |
| `SAND_IAM_ROUTE_BINDING_CONFLICT` | 403/409 | 路由映射不唯一或登记冲突 |
| `SAND_IAM_ROUTE_CONFIGURATION_REQUIRED` | 500 | 业务路由挂了中间件但没有声明接入配置 |
| `SAND_IAM_APPLICATION_MISMATCH` | 403 | 应用会话被用于另一个应用 |
| `SAND_IAM_POLICY_DENIED` | 403 | 没有命中允许策略或被拒绝策略命中 |
| `SAND_IAM_RESOURCE_SCOPE_DENIED` | 403 | 业务实体不满足数据范围 |
| `SAND_IAM_APPLICATION_ACTION_UNDECLARED` | 409 | 新建、变更、初始化或同步引用了未声明的应用业务动作 |
| `SAND_IAM_APPLICATION_ACTION_DISABLED` | 403 | 已声明动作被停用，相关接口授权按拒绝处理 |

## 8. T05 验收要求

1. 两个应用可以登记相同接口代码，授权和路由不能串用。
2. 更换 URL 但保持业务资源和语义动作时，策略无需迁移。
3. 未登记、重复绑定、停用接口、停用资源、错误版本全部 fail closed。
4. 本地会话与 OAuth access token 均完成允许、拒绝、audience/scope 错误和撤销测试。
5. Webman 中间件把决策传给控制器；详情和写操作使用真实业务属性复核数据范围。
6. PHP 和 TypeScript SDK 对成功、拒绝、401、403、超时和畸形响应提供稳定结果。
7. 真实接入 SandAI 一个运行接口和一个非 AI 业务接口，核对允许、拒绝和同一 `X-Request-Id` 审计。
8. 安装、重复升级、卸载和回滚在隔离 PostgreSQL 中通过，且无残留表。
