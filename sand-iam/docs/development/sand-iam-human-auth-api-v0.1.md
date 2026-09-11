# SandIAM 人类身份认证 API v0.1

状态：IAM-T01 后端契约。所有端点都解析 `organization_code + application_code`，绝不读取或复用 SandAdmin 后台账号。

公共前缀：`/api/sand-iam/v1/auth`。

| Endpoint | 用途 |
| --- | --- |
| `POST /register` | 创建应用内 identity、认证资料；应用策略要求验证时只返回 `verification_required`，不签发会话。 |
| `POST /login` | 以 `identifier`（username/email/phone）及密码登录。 |
| `POST /refresh` | 轮换 refresh token；任意已使用 token 的重放撤销整条会话族。 |
| `POST /logout` | 撤销当前 Bearer access token 所属会话。 |
| `POST /password/forgot` | 请求密码重置验证码；账号不存在、通道未配置或发送失败时均返回相同的中性成功响应，具体原因只写内部审计。 |
| `POST /password/reset` | 校验验证码、更新密码并撤销该 identity 的全部会话。 |
| `POST /verification/request` / `confirm` | 邮箱/手机号验证；`email_verify` 只能用 email，`phone_verify` 只能用 phone。 |
| `GET /sessions` / `POST /sessions/revoke` | 查看或撤销当前应用身份的会话。 |

认证策略为 `sand_iam_auth_policy` 的应用级记录，默认关闭公开注册；应用管理员显式启用后，策略可调整密码规则、15 分钟 access token、30 天 refresh token、5 次失败锁定 15 分钟和 DB 限流。策略可要求邮箱和/或手机号验证后才可签发会话。

敏感材料约束：密码用 Argon2id（运行时不支持时降级 bcrypt）保存；access token、refresh token、验证码、验证码目的地、IP 均只保存由 `SAND_IAM_AUTH_PEPPER` 导出的 HMAC。API 不回显验证码；验证码请求无论身份不存在、目标缺失、通道未配置还是传输失败，均返回同一中性成功响应，绝不伪造发送。内部审计分别记录 `identity_not_found`、`destination_unavailable`、`channel_unavailable` 或 `delivery_failed`，不向调用者泄露这些原因。

`SAND_IAM_AUTH_PEPPER_VERSION` 当前为部署冻结版本。T01 不支持原地替换 pepper；修改 pepper 或版本前必须先执行经批准的凭据迁移/失效计划，否则旧密码、令牌和验证码将被 fail-closed 拒绝。

当前验收边界：已使用 SandAdmin 的真实 ORM 依赖在隔离 PostgreSQL 中完成服务层与生命周期验证；尚未在正式 SandAdmin 宿主启动 HTTP 服务、加载管理端页面或进行浏览器验收，这些证据归 IAM-T07/T08。
