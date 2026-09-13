# 机器调用服务示例

该示例用于后端服务进程，不适用于浏览器或普通用户令牌。它不是独立服务：没有服务端业务 handler、密钥管理、
目标服务或部署配置。请把两个脚本和 `MachineServiceHttpClient.php` 装配到已有的调用方/目标服务中，并在目标
服务的验证成功分支之后才接入业务副作用。先在 onboarding manifest 的
`service_grants` 中合入 `onboarding.service-grant.json` 的 `service_code + action_code`；
二元组必须与目标服务已登记的服务目录完全一致。

## 取得凭证并交接上下文

1. 使用拥有 onboarding apply 权限的 SandAdmin 管理员登录态应用服务授权；保留不含秘密的 handoff 和 request ID。
2. 首次签发响应可能显示一次性 `SAND_IAM_WORKLOAD_CREDENTIAL`。立即存入密钥管理系统；不要把它放入 `.env`、终端历史、日志或 issue。
3. 调用方进程从运行环境读取凭证，执行 `issue_context.php`。脚本只输出 `context_id` 和过期时间；它不会输出短期 context。
4. 将短期 context 仅通过调用方与目标服务之间的可信内部通道交接给目标服务。目标服务把它注入自己的短生命周期运行环境，执行 `verify_context.php`，并比较 service、audience、action 和 context ID。
5. 只有验证脚本以零退出且目标服务完成自身业务状态检查时才允许副作用；失败、超时或无效响应均拒绝。该示例不含目标服务，下一步是由目标服务维护者实现这一交接和 allow/deny 审计。

部署时填写 `.env.example` 中的地址、service code、audience 和 action。
`SAND_IAM_WORKLOAD_CREDENTIAL` 是首次签发时一次性展示的秘密：立即写入密钥管理系统，
只通过运行环境注入，禁止提交 `.env`、日志或命令历史。

`issue_context.php` 用工作负载凭证请求短期运行上下文，只输出 context id 和过期时间。
将返回的 context 经内部可信通道交给目标服务；目标服务运行 `verify_context.php`，
以同一 service、audience 和 action 校验后才执行业务副作用。每次 issue/verify 使用独立
`X-Request-Id`，便于在 SandIAM 和业务服务两侧关联审计。

两个脚本只接受 HTTPS 地址（本机开发可用 loopback HTTP），对非 2xx、非法 JSON、缺少
`data` 或返回的 service/audience/action 不一致均以非零退出关闭失败。`verify_context.php`
只输出不含短期 context 的允许摘要；业务进程必须在脚本成功结束后才执行副作用。

`SAND_IAM_SERVICE_ACTION_FORBIDDEN` 表示未授予动作或受众不匹配；
`SAND_IAM_AUTHENTICATION_FAILED` 表示凭证无效；`SAND_IAM_CREDENTIAL_REVOKED`
表示凭证已撤销。三者都必须关闭失败，不能降级为匿名调用或旧 API Key。

最小验收顺序是：保存 issue 与 verify 的 request ID（不保存 context）、确认同一 service/audience/action 的
签发和验证均成功；用未授予 action 或错误 audience 重试并确认拒绝；再由有权限的管理员撤销此 workload
credential，使用新的 request ID 重新签发并确认 `SAND_IAM_CREDENTIAL_REVOKED` 或其他拒绝结果。按这些
request ID 在 SandIAM 与调用方/目标服务两侧查询审计，确认没有副作用。

## Provider B 与 caller 受控源码入口

本仓现有的 [`provider/`](provider/README.md) 及其
[`provider/caller/invoke.php`](provider/caller/invoke.php) 提供完整的调用方内存 context 交接入口：
caller 用 PHP SDK 签发短期 context，只在 `X-Sand-Iam-Context` 传给 provider；provider 在每次请求（包括幂等重放）
先用同一 SDK 验证 context，随后才加载和处理业务对象。固定的 service、audience、action 是
`provider-b-document`、`provider-b`、`document.process`，请求不能覆盖它们。

受控步骤如下：

1. 在 `provider/` 执行 `composer install`，其 SDK 只解析为本仓 `../../../sdk/php`。
2. `provider/schema.pgsql` 只能在数据库负责人明确授权后，手工应用到已创建的隔离 PostgreSQL consumer 数据库；
   它不创建数据库、账号、`sand_iam_*` 或 `sa_*` 表。
3. 以 `.env.example` 的空键为准，经密钥管理系统注入配置；不得提交真实 `.env` 或记录 credential/context。
4. 由隔离环境操作者使用内部 PHP 进程管理器部署 `public/index.php`，并以稳定
   `PROVIDER_B_IDEMPOTENCY_KEY` 执行 `php caller/invoke.php <document-id>`。
5. 再分别验证 allow、未授权/错误 audience 的 deny、撤销后旧 context 与幂等 key 不能绕过验证、审计关联和夹具清理。

`composer test` 只覆盖离线配置门禁、重放/冲突和 deny 无持久化，不会连接 SandIAM、HTTP 或 PostgreSQL。provider、
caller、数据库、allow、deny、revoke、audit 与清理均**未在本仓实跑**，不能把离线门禁当成 live 验收。
