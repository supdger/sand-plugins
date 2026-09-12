# 机器调用服务示例

该示例用于后端服务进程，不适用于浏览器或普通用户令牌。先在 onboarding manifest 的
`service_grants` 中合入 `onboarding.service-grant.json` 的 `service_code + action_code`；
二元组必须与目标服务已登记的服务目录完全一致。

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
