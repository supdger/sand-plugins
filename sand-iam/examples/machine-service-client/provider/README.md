# 机器服务 Provider B 与调用方示例

这是独立的轻量 PHP 业务服务及调用方示例。服务提供 `GET /health` 和
`POST /provider/v1/documents/{id}/process`。调用方使用本仓 PHP SDK 签发短期上下文，
仅通过 `X-Sand-Iam-Context` 请求头交接；服务端每次请求都调用 SDK 的
`verifyContext()`，包括幂等重放。

服务、受众和动作由部署环境固定，默认分别为 `provider-b-document`、`provider-b`、
`document.process`，调用请求不能覆盖。调用方和 Provider 必须注入相同值，真实验收可使用
隔离前缀而不占用生产协议名。验证成功后，服务锁定 PostgreSQL 中已有的 `provider_b_document`，
确认其 `organization_id` 与验证结果一致，再保存文档的 SHA-256 和字节数。
业务处理记录与成功审计在同一事务中提交；失败则回滚。

## 配置与运行

1. 经数据库负责人明确授权后，将 `schema.pgsql` 应用到已存在的隔离 PostgreSQL
   业务数据库，并准备业务文档。示例不创建数据库、账号、`sand_iam_*` 或 `sa_*` 表。
2. 在本目录执行 `composer install`；锁文件固定本次可安装依赖，`sand/iam-sdk` 来自本仓
   `../../../sdk/php`。生成的 `vendor/` 是可再生目录，不进入源码或候选包。
3. 按 `.env.example` 的配置项，通过部署环境和密钥管理系统注入配置，不提交真实 `.env`。
   缺少必填配置或 DSN 不是 `pgsql:` 时，服务拒绝运行。
4. 使用内部 PHP 进程管理器部署 `public/index.php`。调用方执行
   `php caller/invoke.php <document-id>`，并提供稳定的 `PROVIDER_B_IDEMPOTENCY_KEY`。

以上是接入步骤，不表示本仓已经执行数据库初始化、服务启动或真实联调。

## 真实调用与撤销复查

先按[管理员配置单](../../../docs/user-guide/sand-iam-operator-guide.md#第四条调用身份服务授权凭证和调用)登记服务动作、调用身份、授权和凭证。这里的受众是 `provider-b`，不是 `PROVIDER_B_BASE_URL`。对照 `.env.example` 将实际地址、公司/应用代码、数据库连接、凭证及一次业务操作的稳定幂等键注入受控运行环境；数据库连接和凭证都不打印。

服务提供方准备本公司实际测试文档，并把其 ID 交给调用工程师。下列命令在 `provider/` 目录执行，`PROVIDER_B_DOCUMENT_ID` 由该 ID 设置，不代表示例已有文档：

```sh
php caller/invoke.php "$PROVIDER_B_DOCUMENT_ID"
```

caller 在内存中取得短期上下文，再经 `X-Sand-Iam-Context` 交给 Provider；成功输出包含业务结果及请求/上下文编号，不输出凭证或上下文秘密。接收方应新增处理记录并保存文档摘要及字节数，工程师核对实际业务结果后再认定调用成功。

使用同一获准测试环境执行负向检查，原身份不应拥有下列错误受众或动作授权：

```sh
PROVIDER_B_AUDIENCE=wrong-provider php caller/invoke.php "$PROVIDER_B_DOCUMENT_ID"
PROVIDER_B_ACTION=document.ungranted php caller/invoke.php "$PROVIDER_B_DOCUMENT_ID"
```

两次应非零退出且不新增处理结果。另由管理员撤销原凭证或服务授权，再用原配置运行 caller，应拒绝；已有幂等结果不能代替撤销后的验权。要测试独立新业务操作，技术负责人注入新的稳定 `PROVIDER_B_IDEMPOTENCY_KEY`，不要复用另一业务对象的键。

恢复调用时由管理员重新签发凭证或按需要新建服务授权，技术负责人更新运行配置，重新验证正确调用。旧凭证不能恢复；不要把新凭证更新到其他应用或生产环境。按运行输出的请求/上下文编号核对 SandIAM 与 Provider 审计，清理只处理获准的本轮测试数据。

这些命令的参数来源与失败处理已按 caller 核对；本说明未执行目标部署、数据库初始化或实际 HTTP 调用。

## 需要在真实环境验证的行为

- **允许处理：** 授予固定服务、受众和动作，处理调用方所属客户主体的文档，保留脱敏的请求与上下文编号用于关联审计。
- **拒绝处理：** 未授权动作、错误受众、无效上下文、网络或协议错误、文档不存在、跨客户主体文档均应拒绝，且不产生处理记录。
- **撤销生效：** 撤销凭证或服务授权后，使用此前签发的上下文及幂等键重试。服务先验权再查询重放记录，旧上下文不能绕过撤销。
- **幂等与冲突：** `(workload_client_id,idempotency_key)` 唯一。同一文档及内容重复请求返回原处理结果；同一键改用其他文档或内容时返回 409，不新增处理记录。
- **审计与清理：** `provider_b_audit_log` 保存请求编号、上下文编号、业务对象编号、结果及重放标记；幂等键仅保存 SHA-256，不保存上下文令牌、凭证、文档正文或密码。验收后清理隔离夹具与测试数据。

命中业务路由的 400/401/403/409/503 失败会尝试独立写入脱敏失败审计，
上下文令牌及幂等键仅保存摘要。审计写入失败不会允许业务处理，也不会改变原始拒绝结果。
通过请求编号、上下文编号及摘要证据关联 SandIAM 签发/验证审计与业务服务审计；运行器日志不能代替审计记录。

## 离线检查

在本目录执行 `composer test`，检查 PostgreSQL 配置限制、幂等重放与冲突，以及拒绝请求不调用持久化。
这些测试不连接 SandIAM、HTTP 或 PostgreSQL，不能证明真实撤销、并发或双侧审计已通过。
发布前仍需完成真实服务、数据库与调用方联调。
