# SandIAM 人类身份认证 API v0.1

状态：应用用户认证后端契约。匿名注册、登录、验证码和挑战接口以 `organization_code + application_code` 确定应用；已登录操作从 Bearer access token 对应会话确定应用与身份，刷新从 refresh token 确定会话。均不读取或复用 SandAdmin 后台账号。

公共前缀：`/api/sand-iam/v1/auth`。

| Endpoint | 用途 |
| --- | --- |
| `GET /captcha/config` | 查询指定应用、指定登录或注册用途的公开验证码配置，不要求登录。 |
| `POST /register` | 创建应用内 identity、认证资料；应用策略要求验证时只返回 `verification_required`，不签发会话。 |
| `POST /login` | 以 `identifier`（username/email/phone）及密码登录；30 秒内完全相同的成功请求可恢复原会话或 MFA challenge。 |
| `POST /refresh` | 轮换 refresh token；30 秒内以同一旧 token 和同一 `X-Request-Id` 重试可恢复原响应，其他已使用 token 重放撤销整条会话族。 |
| `POST /logout` | 撤销当前 Bearer access token 所属会话。 |
| `POST /password/forgot` | 请求密码重置验证码；账号不存在、通道未配置或发送失败时均返回相同的中性成功响应，具体原因只写内部审计。 |
| `POST /password/reset` | 校验验证码、更新密码并撤销该 identity 的全部会话。 |
| `POST /verification/request` / `confirm` | 邮箱/手机号验证；`email_verify` 只能用 email，`phone_verify` 只能用 phone。 |
| `GET /sessions` / `POST /sessions/revoke` | 查看或撤销当前应用身份的会话。 |
| `POST /password/change` | 已登录用户提交 `current_password`、`new_password`，成功后所有设备重新登录。 |
| `POST /step-up/password` | 当前会话提交 `password` 完成近期密码验证，返回 `step_up: true`、`expires_in: 300`。 |
| `POST /step-up/mfa/start` | 当前会话申请 MFA 二次验证挑战，随后调用 `/mfa/challenge/verify` 完成。 |
| `POST /federation/unlink` | 当前会话提交正整数 `binding_id`，解除本应用身份的联合身份绑定。 |

注册创建 identity、认证资料、已启用的身份事件和注册成功审计在同一事务提交，写入失败不会留下仅完成一部分的账户。
这不包含随后独立执行的会话签发；需要联系方式验证时返回 `verification_required`，客户端继续验证流程，不应重复注册。
要求邮箱或手机验证的认证策略，同时要求注册提供对应联系方式；缺失时返回
`SAND_IAM_AUTH_REGISTRATION_FIELD_REQUIRED`（400），不会创建账户。自定义注册字段若没有包含策略要求的
email/phone，则返回 `SAND_IAM_AUTH_REGISTRATION_CONFIGURATION_INVALID`（503）；管理员应先将相应字段加入
应用的注册字段配置。两种验证都开启时必须同时提供邮箱和手机号。

密码恢复的验证码消费、密码更新、该应用身份的会话与刷新令牌撤销，以及成功审计在同一事务提交。
其中任一步失败均回滚这些变更；请求准入的限流计数独立保留。成功后旧会话失效，已消费验证码不能再次使用。
连接中断或响应超时不代表事务一定失败：客户端应先尝试用新密码重新登录确认结果，不能把验证码重放被拒绝解释为密码没有改变。

### 登录与注册验证码

`GET /captcha/config` 的查询参数为 `organization_code`、`application_code` 和 `action`（仅 `login` 或 `register`），没有请求体。下列对象位于统一成功响应的 `data` 中，响应禁止缓存：

- `{"required":false}`：该用途无需验证码。
- `{"required":true,"available":false}`：需要验证码，但当前组件不可用；不能跳过验证继续提交。
- `{"required":true,"available":true,"widget":{...}}`：`widget` 包含 `kind: "turnstile"`、公开 `site_key`、对应的 `action` 和 `application_binding`。调用方用 `application_binding` 作为 Turnstile 的 `cData` 展示挑战，再将一次性结果作为 `captcha_token` 提交给同一应用的登录或注册接口。

配置接口仍检查应用、网络、登录方式和注册开关；例如注册未开启时返回 `SAND_IAM_AUTH_REGISTRATION_DISABLED`，不能将错误响应视为“不需要验证码”。验证码密钥不会通过此接口公开，客户端不得生成或缓存挑战令牌。

### 敏感操作与身份解绑

密码和 MFA 二次验证均需要当前应用用户的 Bearer access token。密码方式提交 `password`；MFA 方式先取得 `mfa_required`、`challenge_token`、`methods`、`expires_in` 及适用的 `public_key`，再匿名提交绑定该应用的 `/mfa/challenge/verify`。两种方式完成后都返回 `{"step_up":true,"expires_in":300}`，只提升原会话的近期验证状态，不签发新的 access/refresh token。挑战字段、通行密钥断言及响应恢复约束见 [MFA 与通行密钥 API](sand-iam-mfa-passkey-api-v0.1.md)。

`POST /federation/unlink` 要求近 300 秒内完成二次验证，且解绑后仍有可用的密码、通行密钥或其他身份源登录方式。缺少近期验证返回 `SAND_IAM_FEDERATION_STEP_UP_REQUIRED`；将失去最后一种登录方式时返回 `SAND_IAM_FEDERATION_LAST_LOGIN_METHOD`。成功响应的 `data` 为“身份源绑定已解除”；该绑定签发的会话及刷新令牌会被撤销，当前会话也可能失效，客户端随后应按认证失败处理。SDK 不自动发起二次验证或重试解绑。

认证策略为 `sand_iam_auth_policy` 的应用级记录，默认关闭公开注册；应用管理员显式启用后，策略可调整密码规则、15 分钟 access token、30 天 refresh token、5 次失败锁定 15 分钟和 DB 限流。策略可要求邮箱和/或手机号验证后才可签发会话。

敏感材料约束：密码用 Argon2id（运行时不支持时降级 bcrypt）保存；access token、refresh token、验证码、验证码目的地、IP 均只保存由 `SAND_IAM_AUTH_PEPPER` 导出的 HMAC。API 不回显验证码；验证码请求无论身份不存在、目标缺失、通道未配置还是传输失败，均返回同一中性成功响应，绝不伪造发送。内部审计分别记录 `identity_not_found`、`destination_unavailable`、`channel_unavailable` 或 `delivery_failed`，不向调用者泄露这些原因。

刷新响应恢复约束：客户端必须为一次刷新意图生成稳定的 `X-Request-Id`。连接中断或超时时，在首次请求后 30 秒内用同一旧 refresh token、同一 request id 和同一来源网络重试；服务只返回首次轮换生成的 token，不重复轮换或写审计。恢复材料以 XChaCha20-Poly1305 密文保存，密钥同时绑定部署 pepper、旧 token 和 request id，数据库中不保存可恢复的明文密钥。密文过期、被篡改、当前会话已变化，或改用其他 request id 时均拒绝并撤销会话族；调用方应停止自动重试并重新认证。

密码登录响应恢复约束：客户端应为一次登录意图固定 `X-Request-Id`。登录限流、captcha 验证和失败计数分别按同一请求指纹幂等提交；密码校验成功后，会话或 MFA challenge、登录状态、成功审计和认证密文在同一事务提交。首次响应丢失时，30 秒内以相同应用、身份、密码、验证码、来源网络、user agent 和 request id 重试可恢复原响应，不重复调用已成功的 captcha、占用登录限流、增加失败次数、创建会话/challenge 或写审计。任一请求要素变化返回幂等冲突，MFA challenge 已消费、密文过期/损坏或会话失效时返回 `SAND_IAM_AUTH_LOGIN_RETRY_UNAVAILABLE`，客户端必须以新 request id 重新开始认证。错误密码仍按每个不同请求意图计数和锁定。

`SAND_IAM_AUTH_PEPPER_VERSION` 当前为部署冻结版本。T01 不支持原地替换 pepper；修改 pepper 或版本前必须先执行经批准的凭据迁移/失效计划，否则旧密码、令牌和验证码将被 fail-closed 拒绝。

当前验收边界：已使用 SandAdmin 的真实 ORM 依赖在隔离 PostgreSQL 中完成服务层与生命周期验证；尚未在正式 SandAdmin 宿主启动 HTTP 服务、加载管理端页面或进行浏览器验收，这些证据归 IAM-T07/T08。
