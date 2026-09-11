# SandIAM MFA 与通行密钥 API v0.1

## 模块边界

SandIAM 的 MFA 只服务于某个应用内的人类身份认证。它不读取或复用 SandAdmin 后台账号。

| 对象 | 作用 | 保护方式 |
| --- | --- | --- |
| `sand_iam_mfa_factor` | TOTP 认证器 | 160-bit secret 仅以部署独立 XChaCha20-Poly1305 信封保存 |
| `sand_iam_mfa_recovery_code` | 恢复码 | 只保存 application + identity + factor 上下文 HMAC；明文仅显示一次 |
| `sand_iam_webauthn_credential` | 每把通行密钥 | 应用内唯一 credential ID、ES256 公钥、user handle、signCount |
| `sand_iam_auth_challenge` | 短期一次性认证挑战 | 令牌 HMAC，WebAuthn challenge 加密，过期/消费/失败次数可审计 |

应用身份是隔离边界。数据库复合外键强制 identity、factor、recovery code、credential、challenge 的 `application_id` 一致；跨应用令牌或 credential 均被拒绝。

部署以 `SAND_IAM_MFA_ENCRYPTION_KEY_VERSION` 和 `SAND_IAM_MFA_ENCRYPTION_KEY` 指定当前写入密钥。轮换时先通过 `SAND_IAM_MFA_ENCRYPTION_KEYS` JSON key ring 保留旧版本解密密钥，完成存量重包与覆盖验收后才能下线旧密钥；直接替换唯一密钥会被视为配置错误并失败关闭。

## 路由与中文字段

所有路由以 `/api/sand-iam/v1/auth` 为前缀。含密钥、恢复码、挑战令牌或会话令牌的响应统一 `Cache-Control: no-store`。

| 路由 | 用途 | 关键入参/输出 |
| --- | --- | --- |
| `GET /mfa/factors` | 我的认证方式 | 只显示名称、类型、状态、最近使用时间 |
| `POST /mfa/totp/start` | 添加验证器第一步 | `name`、`current_password`；仅一次返回 `secret`、`otpauth_uri`、`factor_id` |
| `POST /mfa/totp/confirm` | 确认验证器 | `factor_id`、6 位 `code`；返回一次性 `recovery_codes` |
| `POST /mfa/factors/rename` | 重命名 | `factor_id`、`type`、`name` |
| `POST /mfa/factors/revoke` | 撤销 | `factor_id`、`type`、当前 `password` |
| `POST /mfa/recovery/regenerate` | 重新生成恢复码 | 当前 `password`；原恢复码全部作废 |
| `POST /mfa/challenge/verify` | 密码后完成 MFA | 应用代码、`challenge_token`、`method` 和验证码/断言 |
| `POST /passkeys/registration/options` | 添加通行密钥选项 | `name`、`current_password`；返回 WebAuthn creation options |
| `POST /passkeys/registration/finish` | 完成添加 | `challenge_token`、浏览器 Credential 响应 |
| `POST /passkeys/authentication/options` | 无密码登录选项 | 应用代码；返回 discoverable-credential request options |
| `POST /passkeys/authentication/finish` | 完成无密码登录 | `challenge_token`、浏览器 assertion；返回会话令牌 |

## 端到端流程

### TOTP 与恢复码

1. 已登录用户提交当前密码调用 `totp/start`。服务器先执行独立限流的二次认证，再生成 20-byte secret；明文只在本次响应出现。仅持有被盗 access token 不能绑定新验证器。
2. 用户提交 `totp/confirm`；RFC 6238 SHA-1 / 30 秒 / 6 位在前后一个时间窗内验证。成功时间步写入 `last_used_counter`，同一步再次使用返回 `SAND_IAM_MFA_TOTP_REPLAYED`。
3. 成功后返回 10 个恢复码，数据库只保存 HMAC。重新生成或撤销 TOTP 会失效旧码。
4. 密码登录成功且存在启用 TOTP/Passkey 时，不签发会话，而返回 5 分钟一次性 `challenge_token`；通过 TOTP 或恢复码后才签发正式会话。

### Passkey

1. 管理员配置认证策略中的 RP ID、允许来源和用户验证级别；缺配置返回 `SAND_IAM_PASSKEY_CONFIGURATION_UNAVAILABLE`。
2. 注册 options 同样要求当前密码二次认证，并要求 `residentKey=required`、`attestation=none`。finish 校验 `webauthn.create`、challenge、origin、`crossOrigin=false`、RP ID hash、UP/UV、BE/BS 合法关系、空 attStmt、credentialId 对应和 ES256 COSE key。
3. 认证 options 生成新 challenge。finish 校验 `webauthn.get`、origin、RP ID hash、UP/UV、ES256 `authenticatorData || SHA-256(clientDataJSON)` 签名、passwordless 流程必需且与保存值相同的 userHandle，以及 signCount 单调递增。
4. 任一挑战仅能成功消费一次；跨应用、过期、错误或超过五次失败均拒绝并审计。MFA 验证还按应用、身份和来源执行独立数据库限流，不因不断申请新 challenge 而重置。

## 错误与状态

常见稳定错误码：`SAND_IAM_MFA_CONFIGURATION_UNAVAILABLE`、`SAND_IAM_MFA_TOTP_INVALID`、`SAND_IAM_MFA_TOTP_REPLAYED`、`SAND_IAM_MFA_RECOVERY_CODE_INVALID`、`SAND_IAM_MFA_CHALLENGE_INVALID`、`SAND_IAM_PASSKEY_CLIENT_DATA_INVALID`、`SAND_IAM_PASSKEY_RP_ID_MISMATCH`、`SAND_IAM_PASSKEY_SIGNATURE_INVALID`、`SAND_IAM_PASSKEY_SIGN_COUNT_REPLAYED`。

审计记录开始、成功、失败、重放、跨应用拒绝和撤销，但不记录 secret、OTP、恢复码、challenge 原文、私钥、公钥二进制内容或原始 IP。

## 验收边界

隔离 PostgreSQL 服务级验收覆盖凭证绑定二次认证、TOTP 注册、同时间步重放、密码后 MFA、恢复码单次消费、跨应用挑战、真实 ES256 `fmt=none` 注册和 passwordless assertion，以及错误 origin、RP ID、签名、userHandle、BE/BS、signCount 与 credential 跨应用拒绝。它不代替真实浏览器/Authenticator、已安装宿主 HTTP 和正式部署验收；这些属于 IAM-T07/T08。
