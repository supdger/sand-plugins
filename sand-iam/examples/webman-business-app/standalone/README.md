# 受控独立非 AI 业务 Consumer

这是一个轻量 PHP consumer，用真实 PostgreSQL 业务对象调用 SandIAM PHP SDK 的 `authorizeEntity`。它不是
SandAdmin 插件、不会创建数据库或表，也没有默认账号、token 或可直接运行的业务数据。

## 配置与启动

1. 在已创建的**非生产** PostgreSQL 数据库中，经环境负责人授权后执行 `schema.pgsql`；它只创建
   `standalone_*` consumer 表，绝不创建 `sand_iam_*` 或 `sa_*` 表。
2. 复制 `.env.example` 到受控环境变量注入方式，填写现有 PostgreSQL DSN、数据库账号、SandIAM 地址及已登记的
   组织/应用代码。DSN 不是 `pgsql:`，缺少配置，或任一 consumer 表不存在时，应用会拒绝请求；不会自动建库、建表或补数据。
3. 在本目录执行 `composer install`。该命令会生成本地 `vendor/`；它是可再生依赖目录，已被 `.gitignore`
   排除，不能纳入源码或发布包。`composer.json` 使用本仓 PHP SDK 的本地 path repository；这不表示
   Composer Registry 已发布该 SDK。
4. 仅在隔离环境由操作者选择 HTTP 启动方式，例如 `php -S 127.0.0.1:8088 -t public public/router.php`。
   示例不包含服务启动或数据库连接验收；发布前须在隔离真实环境完成该验收。

## 路由与受控验证

- `GET /health`：检查现有数据库连接和两张 consumer 表。
- `GET /items/{id}`：要求 Bearer token 与 request ID，从 PostgreSQL 读取业务对象后按真实组织/所有者范围授权；不接受客户端提供的组织、所有者或范围。
- `POST /items/{id}/close`：要求 `Authorization: Bearer <应用用户 token>` 与 8–96 位 `X-Request-Id`。
  请求体只能为空对象，若含 `organization_id`、`owner_identity_id`、`scope` 或 `attributes` 会被拒绝。

关闭操作在一个事务中对真实对象 `SELECT … FOR UPDATE`，用 SDK `authorizeEntity` 根据该对象的数据库组织/所有者字段授权，
再用 `state='open' AND version=:version` 完成 `open → closed`。deny、网络故障、协议无效、并发变化和审计写入失败均回滚并拒绝；
业务审计只存 SHA-256 截断引用，不存 access token、组织/所有者原值或秘密。read allow/deny、close deny、认证/撤销拒绝和
授权异常均由独立 audit connection 写入，不会随 close 事务回滚；close allow 审计仍在业务事务中。

## 接入登记契约

在调用本 consumer 前，应用管理员须通过既有 SandIAM 管理流程登记下列稳定契约；本目录不调用管理 API：

| API code | resource_code | action | operation | api_version | route | 数据范围 |
| --- | --- | --- | --- | --- | --- | --- |
| `standalone_work_item.read` | `standalone_work_item` | `work_item.read` | `read` | `v1` | `GET /items/{id}` | 仅服务端加载的 `organization_id`、`owner_identity_id` |
| `standalone_work_item.close` | `standalone_work_item` | `work_item.close` | `update` | `v1` | `POST /items/{id}/close` | 同上；状态和版本仅来自 PostgreSQL |

登记时分别绑定 API code、resource_code、策略 action、operation、api_version、HTTP 方法/路由和 scope resolver；这些字段按上表契约对应，不要求使用同一个字符串。请求 body 不能提供或覆盖范围字段。

隔离验收时，应分别记录 allow（关闭一次）、deny（403 且状态仍为 `open`）、撤销后再试（拒绝且无副作用）和审计
request ID；结束后按隔离环境授权流程删除测试数据。真实 HTTP、SandIAM、数据库、撤销和清理路径须由部署方在隔离真实环境验收；
离线检查不构成真实环境验收。

## 离线测试

`php tests/offline_test.php` 使用内存 fake repository/authorizer 验证 deny、授权网络/协议失败和 body 范围篡改均不产生
关闭副作用。它不启动服务、不访问 PostgreSQL、不调用 SandIAM HTTP，不能计入真实验收。
