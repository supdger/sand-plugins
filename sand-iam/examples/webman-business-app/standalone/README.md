# 受控独立非 AI 业务 Consumer

这是一个轻量 PHP consumer，用真实 PostgreSQL 业务对象调用 SandIAM PHP SDK 的 `authorizeEntity`。它不是
SandAdmin 插件、不会创建数据库或表，也没有默认账号、token 或可直接运行的业务数据。

## 完整填写与账号准备

此 consumer 有 HTTP 工作项接口，没有员工可点击的列表页面。先按[管理员工作项例子](../../../docs/user-guide/sand-iam-operator-guide.md#给两名员工开通账号并分配工作项权限)完成账号和权限，再由开发者联调。

| 配置 | 演示填写值 | 输入来源 |
| --- | --- | --- |
| 公司代码 / 应用代码 / 环境 | `example-company` / `work-items` / `test` | 管理台已登记记录，与部署环境一致 |
| 两名员工 | 小林 `xiaolin`、小周 `xiaozhou` | 本人接受邀请或注册设密，开发者使用获准测试账号登录 |
| 角色 | `operator`（工作项操作员） | 授给小林，小周不授予 |
| 资源代码 / 名称 | `standalone_work_item` / 工作项 | 本示例既有业务表契约 |
| 所有者 / 组织字段 | `owner_identity_id` / `organization_id` | 本示例从 PostgreSQL 对象加载的整数，不从请求传入 |
| 查看 API / 动作 | `standalone_work_item.read` / `work_item.read` | 下方登记表，版本 `v1` |
| 关闭 API / 动作 | `standalone_work_item.close` / `work_item.close` | 下方登记表，版本 `v1` |
| 接口受众 | `work-items-api` | 应用开发者约定的登记值，按实际应用保持一致 |
| 两条策略 | 角色 `operator`、上述资源、分别 read/close 动作、允许并发布 | 管理员按应用负责人确认配置 |

若限制为小林自己的工作项，两条策略设置相同静态数值范围：`equals` 的 `organization_id` 为实际公司编号，`owner_identity_id` 为小林实际身份编号。小林编号从其 SDK 登录响应的 `identity.id` 获取，公司编号由技术负责人核对实际客户主体记录；不要复制其他宿主的编号。表单为这两个值选择“数字”类型。本例不提供部门字段、动态当前用户替换或列表自动过滤。

业务数据由环境/业务负责人在已有隔离库准备两条 `state=open, version=1` 的工作项：一条属小林，另一条属不同所有者，公司编号均为该公司实际编号。取得两条记录的实际 ID；本目录不填默认数据、不执行 SQL。下面的操作只有表和数据已获授权准备后才运行。

从[登录程序](../../../sdk/php/README.md#人员登录与首次鉴权)取得小林和小周当前用户令牌，通过受控运行环境分别注入请求，不要求员工复制令牌。调用步骤：

1. 小林 `GET /items/<自己的记录ID>` 应返回 `200` 和 `id/state/version`；小林读取另一所有者的记录应 `403`。
2. 小周读同一条小林记录应 `403`。若出现 `401`，先检查账号会话，不把认证失败当成无权策略验证。
3. 小林 `POST /items/<自己的记录ID>/close`，空对象 body，使用新的 8–96 位 `X-Request-Id`；应 `200`，`state=closed`，版本增长。其他拒绝请求不得关闭记录。
4. 撤销小林角色/策略后再读取，应 `403` 且不产生业务修改；撤销会话时保留撤销前的令牌重试，应 `401/403`，不能重新登录后用新令牌检查旧会话。恢复权限需重新授权；账号仍可用时可重新登录建立新会话，旧令牌不会恢复。
5. 用各次请求编号关联 SandIAM 授权/范围审计及 `standalone_business_audit`。本程序不自动验证审计完整；结束后按环境授权清理本轮业务测试数据，保留必要审计。

程序的有权返回和实际数据库状态都应核对。尚未运行目标宿主、数据库、HTTP 或业务撤销，离线测试不证明上述步骤已成功。

## 配置与启动

1. 在已创建的**非生产** PostgreSQL 数据库中，经环境负责人授权后执行 `schema.pgsql`；它只创建
   `standalone_*` consumer 表，绝不创建 `sand_iam_*` 或 `sa_*` 表。
2. 复制 `.env.example` 到受控环境变量注入方式，填写现有 PostgreSQL DSN、数据库账号、SandIAM 地址及已登记的
   组织/应用代码及两个已登记的 API code。DSN 不是 `pgsql:`，缺少配置，API code 重复，或任一 consumer 表不存在时，
   应用会拒绝请求；不会自动建库、建表或补数据。验收环境可为 API code 使用受控验收前缀，业务路由不因此变化。
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

关闭操作在一个事务中对真实对象 `SELECT … FOR UPDATE`，用 SDK `authorizeEntity` 根据该对象的数据库组织/所有者字段授权；
实体属性与路由属性分字段提交，SandIAM 用同一 request ID 记录粗粒度 `authorize.*` 和实体级 `scope.*` 审计，SDK 还会本地复核返回 scope。
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
