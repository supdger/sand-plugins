# SandAI 机器身份示例

该示例是给 SandAI 部署进程用的，不是浏览器或普通用户 token 示例。先在同一 onboarding manifest 的 `service_grants` 中合入 `onboarding.service-grant.json` 的 `service_code + action_code`；二元组必须与 SandAI 已注册的服务目录完全一致。

部署时填写 `.env.example` 中的地址、`service_code`、audience 和 action。`SAND_IAM_WORKLOAD_CREDENTIAL` 是 onboarding 首次应用时一次性展示的秘密：立刻写入密钥管理系统，只通过运行环境注入，禁止提交 `.env`、日志或命令历史。

```sh
SAND_IAM_WORKLOAD_CREDENTIAL="$(secret-manager read sandai/matter-demo)" php issue_context.php
```

`issue_context.php` 用工作负载凭证请求五分钟运行上下文，只输出 `context_id` 和过期时间。将返回的短期 context 经内部可信通道交给 SandAI；SandAI 可运行 `verify_context.php`，以同一 service/audience/action 校验后才执行推理。每次 issue/verify 使用独立 `X-Request-Id`，可在 SandIAM 审计关联；SandAI 的 `operation_id` 是业务幂等键，不能替代 `request_id`。

`SAND_IAM_SERVICE_ACTION_FORBIDDEN` 表示未授予对应 service/action 或 audience 不匹配；`SAND_IAM_AUTHENTICATION_FAILED` 表示凭证无效；`SAND_IAM_CREDENTIAL_REVOKED` 表示凭证已撤销。三者都必须 fail-closed，不能降级为匿名调用或旧 API Key。
