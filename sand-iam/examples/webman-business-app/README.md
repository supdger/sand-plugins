# Webman 非 AI 业务接入示例

这是一个可复制到业务 Webman 项目的最小案例：先用一份 onboarding manifest 声明业务资源、动作、接口和路由，再在写操作前由 SandIAM 中间件加载真实数据库对象并校验数据范围。它不创建客户主体、不写数据库，也没有任何秘密。

## 装配边界与凭证来源

这不是可独立启动的 Webman 应用：它没有 `composer.json`、业务数据库迁移、宿主配置或可用的用户夹具。请将
`config/route.php`、`app/`、`generated/` 和所需 SDK 文件复制到**已有**的业务 Webman 项目，并替换示例命名空间、
路由、表模型和稳定代码。下一步是由该项目的维护者在隔离宿主中完成路由装配、数据库对象加载和 allow/deny 验收。

开始前取得以下内容：

1. SandIAM 管理台中已有的客户主体代码与应用代码；
2. 具有 `sand_iam:onboarding:preview` 和 `sand_iam:onboarding:apply` 权限的 SandAdmin 管理员登录态；
3. 应用用户 access token，用于业务路由的 allow/deny 验证；
4. 如需机器调用，由 onboarding 首次成功响应一次性显示的 workload credential。立即存入密钥管理系统，后续只由运行环境注入。

`SAND_ADMIN_COOKIE` 只用于管理 onboarding；`SAND_IAM_ACCESS_TOKEN` 只用于业务用户授权；`SAND_IAM_WORKLOAD_CREDENTIAL`
只用于服务端机器上下文。三者不能相互替代，不能提交到 `.env`、日志或命令历史。

## 先填什么

1. 在 `onboarding.manifest.json` 填已经存在的 `organization.code`。来源是 SandIAM 管理台的客户主体代码；它决定应用隔离边界。
2. 填 `application.code`、业务资源/动作和 API/路由。动作必须同时出现在 `initialization.business_actions`、策略和 `api_resources.action`；不要把业务动作填入 `sand_iam_service_action`。
3. 把 `.env.example` 的 `SAND_IAM_BASE_URL`、业务数据库连接信息配置到部署环境。`SAND_IAM_ACCESS_TOKEN` 和 `SAND_IAM_WORKLOAD_CREDENTIAL` 保持空白示例，只能由密钥管理系统或运行环境注入。

## 预览与应用

管理 API 使用 SandAdmin 管理员登录态，预检需要 `sand_iam:onboarding:preview`，应用需要单独的 `sand_iam:onboarding:apply`。预检默认不写入：

```sh
curl -sS -X POST "$SAND_IAM_BASE_URL/app/sand-iam/admin/developer/onboarding/preview" \
  -H "Cookie: $SAND_ADMIN_COOKIE" -H 'Content-Type: application/json' \
  --data "$(jq -n --slurpfile manifest onboarding.manifest.json '{manifest: $manifest[0]}')"
```

保存返回的 `preview_hash`，确认中文计划中的新增、更新、冲突和当前状态后再应用：

```sh
curl -sS -X POST "$SAND_IAM_BASE_URL/app/sand-iam/admin/developer/onboarding/apply" \
  -H "Cookie: $SAND_ADMIN_COOKIE" -H 'Content-Type: application/json' -H 'X-Request-Id: work-item-onboarding-001' \
  --data "$(jq -n --slurpfile manifest onboarding.manifest.json --arg hash "$PREVIEW_HASH" '{manifest: $manifest[0], preview_hash: $hash, apply: true}')"
```

首次成功结果才可能带一次性 workload credential，响应是 `Cache-Control: no-store`。立即存入密钥管理系统；同一 `operation_id` 重放只会返回脱敏元数据。若收到 `SAND_IAM_ONBOARDING_PREVIEW_STALE`，表示预检后已有管理变更，必须重新预览，不能沿用旧 hash。

## 业务路由与真实对象

将 `config/route.php`、`app/WorkItem.php`、`app/WorkItemRepository.php`、`app/WorkItemController.php` 放入业务项目并改成自己的命名空间/表名。`WorkItemRepository::findOrFail()` 是唯一读取 `organization_id` 和 `owner_identity_id` 的位置；路由 resolver 在 handler 前完成此加载和 scope 复核。控制器只消费 `$request->resolvedWorkItem`，不得用请求体里的 owner 或 organization 替代它。

批量关闭使用 `collection` resolver，先把每个 ID 加载为对象，任意一条越权就拒绝，handler 不会执行。创建接口没有新对象可加载时，应让 resolver 读取可信的父组织或父级业务对象后再允许写入。

## allow、deny 与审计

下面两次请求必须使用不同 `X-Request-Id`；在管理审计中按对应 request id 查询 `authorize.*`、`scope.*` 和业务结果。

```sh
# allow：令牌所属身份须有 work_item.read/work_item.close 及目标工作项的范围权限
curl -i -X POST "$BUSINESS_BASE_URL/api/work-items/v1/items/101/close" \
  -H "Authorization: Bearer $SAND_IAM_ACCESS_TOKEN" -H 'X-Request-Id: work-item-allow-001'

# deny：换成无权限身份或跨组织工作项；应为 403，业务记录不能关闭
curl -i -X POST "$BUSINESS_BASE_URL/api/work-items/v1/items/202/close" \
  -H "Authorization: Bearer $SAND_IAM_ACCESS_TOKEN_DENY" -H 'X-Request-Id: work-item-deny-001'

# 管理员登录态下核对审计（也可在 SandIAM“审计”页面按 request_id 筛选）
curl -sS "$SAND_IAM_BASE_URL/app/sand-iam/admin/audit/index?request_id=work-item-allow-001" \
  -H "Cookie: $SAND_ADMIN_COOKIE"
```

常见错误：`SAND_IAM_ROUTE_NOT_REGISTERED` 表示 manifest 的 route 尚未应用；`SAND_IAM_APPLICATION_ACTION_UNDECLARED` 表示动作没有在初始化包中声明；`SAND_IAM_ENTITY_SCOPE_RESOLVER_REQUIRED` 表示写/导出/批量路由漏了 handler 前 resolver；`SAND_IAM_RESOURCE_SCOPE_DENIED` 表示加载出的真实对象不在授权范围。

在完成 allow/deny 后，由有权限的管理员撤销该身份的角色、策略或会话，再使用新的
`X-Request-Id` 重放同一业务请求；结果必须为拒绝且 handler 不能改变业务记录。用 allow、deny、revoke
三个 request ID 同时查询 SandIAM 审计和业务应用审计。示例只说明应执行的验收步骤，未附带令牌、用户夹具、
管理端或业务数据库，因此不声称已真实运行。

## SDK 配置产物

`generated/sand_iam.php`、`generated/sand_iam.ts`、`generated/sand_iam.dart` 与 onboarding 返回的 handoff 格式相同，不含秘密。三个 `sdk/consume.*` 都实际导入该产物和对应 SDK；凭证仅从 `SAND_IAM_ACCESS_TOKEN` 环境变量读取。PHP 可直接运行，TypeScript 可用仓库 SDK 的 TypeScript 编译器做无输出检查；Dart 文件可在已安装 Dart SDK 的项目中分析或运行。示例未提供可运行业务数据库和令牌，不能单独证明业务路由已经接通。

## 受控 standalone consumer 源码入口

本仓现有的 [`standalone/`](standalone/README.md) 是可独立启动的受控**非 AI** consumer 源码入口，不是
SandAdmin 插件，也不替代前文复制到既有 Webman 项目的装配示例。它的受控步骤是：

1. 在 `standalone/` 执行 `composer install`；依赖仅通过本仓 PHP SDK 的 local-path repository 解析。
2. 依照 `standalone/.env.example` 将现有非生产 PostgreSQL DSN、数据库账号、SandIAM 地址及已登记的组织/应用代码
   注入受控运行环境；缺失、无效或未就绪的配置必须拒绝，不能补建数据库、表或测试数据。
3. `standalone/schema.pgsql` 只能由环境负责人在已创建的隔离数据库中**手工、显式授权**执行；它只创建
   `standalone_*` consumer 表，不创建 `sand_iam_*`、`sa_*`、数据库或账号。
4. 仅在隔离环境由操作者选择启动，例如
   `php -S 127.0.0.1:8088 -t public public/router.php`；随后按 `GET /health`、allow close、deny close、撤销后重试、
   审计关联和授权清理的顺序验收。

`POST /items/{id}/close` 只接受应用用户 Bearer token、合规的 `X-Request-Id` 与空 JSON 对象；consumer 从数据库锁定并
加载真实组织/所有者字段后才调用授权，不能由客户端提供范围。离线门禁可运行
`php standalone/tests/offline_test.php`，但它不启动 HTTP、不访问 PostgreSQL 或 SandIAM。以上真实启动、数据库、allow、
deny、revoke、audit 与清理路径均**未在本仓实跑**。
