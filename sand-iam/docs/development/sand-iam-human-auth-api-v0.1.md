# SandIAM 人类身份认证 API v0.1

状态：IAM-T01 后端契约。所有端点都解析 `organization_code + application_code`，绝不读取或复用 SandAdmin 后台账号。

公共前缀：`/api/sand-iam/v1/auth`。

| Endpoint | 用途 |
| --- | --- |
| `POST /register` | 创建应用内 identity、认证资料；应用策略要求验证时只返回 `verification_required`，不签发会话。 |
| `POST /login` | 以 `identifier`（username/email/phone）及密码登录；30 秒内完全相同的成功请求可恢复原会话或 MFA challenge。 |
| `POST /refresh` | 轮换 refresh token；30 秒内以同一旧 token 和同一 `X-Request-Id` 重试可恢复原响应，其他已使用 token 重放撤销整条会话族。 |
| `POST /logout` | 撤销当前 Bearer access token 所属会话。 |
| `POST /password/forgot` | 请求密码重置验证码；账号不存在、通道未配置或发送失败时均返回相同的中性成功响应，具体原因只写内部审计。 |
| `POST /password/reset` | 校验验证码、更新密码并撤销该 identity 的全部会话。 |
| `POST /verification/request` / `confirm` | 邮箱/手机号验证；`email_verify` 只能用 email，`phone_verify` 只能用 phone。 |
| `GET /sessions` / `POST /sessions/revoke` | 查看或撤销当前应用身份的会话。 |

认证策略为 `sand_iam_auth_policy` 的应用级记录，默认关闭公开注册；应用管理员显式启用后，策略可调整密码规则、15 分钟 access token、30 天 refresh token、5 次失败锁定 15 分钟和 DB 限流。策略可要求邮箱和/或手机号验证后才可签发会话。

敏感材料约束：密码用 Argon2id（运行时不支持时降级 bcrypt）保存；access token、refresh token、验证码、验证码目的地、IP 均只保存由 `SAND_IAM_AUTH_PEPPER` 导出的 HMAC。API 不回显验证码；验证码请求无论身份不存在、目标缺失、通道未配置还是传输失败，均返回同一中性成功响应，绝不伪造发送。内部审计分别记录 `identity_not_found`、`destination_unavailable`、`channel_unavailable` 或 `delivery_failed`，不向调用者泄露这些原因。

刷新响应恢复约束：客户端必须为一次刷新意图生成稳定的 `X-Request-Id`。连接中断或超时时，在首次请求后 30 秒内用同一旧 refresh token、同一 request id 和同一来源网络重试；服务只返回首次轮换生成的 token，不重复轮换或写审计。恢复材料以 XChaCha20-Poly1305 密文保存，密钥同时绑定部署 pepper、旧 token 和 request id，数据库中不保存可恢复的明文密钥。密文过期、被篡改、当前会话已变化，或改用其他 request id 时均拒绝并撤销会话族；调用方应停止自动重试并重新认证。

密码登录响应恢复约束：客户端应为一次登录意图固定 `X-Request-Id`。登录限流、captcha 验证和失败计数分别按同一请求指纹幂等提交；密码校验成功后，会话或 MFA challenge、登录状态、成功审计和认证密文在同一事务提交。首次响应丢失时，30 秒内以相同应用、身份、密码、验证码、来源网络、user agent 和 request id 重试可恢复原响应，不重复调用已成功的 captcha、占用登录限流、增加失败次数、创建会话/challenge 或写审计。任一请求要素变化返回幂等冲突，MFA challenge 已消费、密文过期/损坏或会话失效时返回 `SAND_IAM_AUTH_LOGIN_RETRY_UNAVAILABLE`，客户端必须以新 request id 重新开始认证。错误密码仍按每个不同请求意图计数和锁定。

`SAND_IAM_AUTH_PEPPER_VERSION` 当前为部署冻结版本。T01 不支持原地替换 pepper；修改 pepper 或版本前必须先执行经批准的凭据迁移/失效计划，否则旧密码、令牌和验证码将被 fail-closed 拒绝。

当前验收边界：已使用 SandAdmin 的真实 ORM 依赖在隔离 PostgreSQL 中完成服务层与生命周期验证；尚未在正式 SandAdmin 宿主启动 HTTP 服务、加载管理端页面或进行浏览器验收，这些证据归 IAM-T07/T08。
