# SandIAM 联邦浏览器交接 API v0.1

状态：IAM-T04 后端契约。该协议只用于外部 OIDC、OAuth 2.0 与 SAML 登录后，把浏览器安全地交回已登记的接入应用；它不是 SandIAM OAuth Provider 授权码端点。

## 配置与共同约束

每个可用于浏览器登录的 identity provider 配置必须包含 `handoff_return_uris`：1 至 32 条精确的 HTTPS URI。没有通配符、前缀匹配、片段、userinfo、控制字符或运行时动态登记；预注册 URI 也不得自带 `code` 或 `state` query key，避免 callback 重复参数。应用在 start 请求给出的 `return_uri` 必须和其中一条逐字一致。

`state` 是接入应用自己生成的原始关联值，必须为 1 至 2048 字节且不含控制字符。SandIAM 原样保存并只在最终回跳时返回。`code_challenge` 必须是应用对其 verifier 计算的 PKCE S256 base64url 值（43 字符）。

start 端点为三种协议各自的既有 GET 路由，新增以下必填 query 参数：

| 参数 | 说明 |
| --- | --- |
| `provider` | 已公开的身份源代码 |
| `application` | 接入应用代码 |
| `return_uri` | provider 配置中已登记的应用回跳地址 |
| `state` | 应用原始 state |
| `code_challenge` | 应用 PKCE S256 challenge |

SandIAM 生成自己的上游 state、nonce 和上游 PKCE verifier；它们与浏览器绑定 cookie、应用回跳 URI、应用 state 和 handoff challenge 一起记录在 federation transaction。上游 callback 中从不返回 access token 或 refresh token。

## Callback 结果

OIDC callback、OAuth2 callback 与 SAML ACS 成功时都返回 HTTP `303`。Location 只能是已登记的 `return_uri`，query 只能包含：

```
?code=fh_<64 lowercase hex>&state=<application original state>
```

code 有效期 120 秒、只能消费一次。数据库只保存带部署 pepper 的 HMAC-SHA-256 `code_hash`，绝不保存明文 code。回跳响应和 exchange 响应均为 `Cache-Control: no-store`，不使用 refresh token URL、cookie 或审计上下文。

## Exchange

`POST /api/sand-iam/v1/federation/handoff/exchange`

请求体：

| 字段 | 说明 |
| --- | --- |
| `provider` | 发起 start 的 provider public code |
| `application` | 发起 start 的 application code |
| `code` | callback 返回的单次 handoff code |
| `redirect_uri` | 与 start 的 `return_uri` 逐字相同 |
| `verifier` | 43 至 128 字符 RFC 7636 verifier |

交换会在一个锁定事务内校验并消费 code：HMAC、有效期、未消费状态、provider、provider mount、application、organization、identity binding、provider config version、return URI 和 S256 verifier 都必须仍有效。任一失败稳定返回 `SAND_IAM_FEDERATION_HANDOFF_INVALID`（401）；能关联到 provider 的拒绝会审计为 `identity_provider.handoff_exchange/failed`。成功后才调用既有应用会话签发；若身份已有 MFA 因子，复用既有 MFA challenge，不绕过 MFA。

## 绑定与解绑

已登录的应用身份可以在任一 federation start 请求中携带 `purpose=link` 和 Bearer session。SandIAM 只接受该 session 属于目标 application 且最近五分钟完成了 step-up；它把 session 和 identity id 固定在 transaction 中。step-up 端点为：

- `POST /api/sand-iam/v1/auth/step-up/password`：Bearer session 加当前密码；
- `POST /api/sand-iam/v1/auth/step-up/mfa/start`：Bearer session 创建 MFA challenge，再由既有 `/mfa/challenge/verify` 完成。

两种方式只把 `step_up_time` 和 `step_up_method` 写入当前 session。callback 在同一原子事务中重验该 session 未撤销、access token 未过期、pepper version 仍为当前版本、同应用且仍在五分钟窗口内；外部 subject 只能绑定到该既有 identity。subject 已属于其他 identity 时稳定拒绝，绝不按 email、phone 或 display name 自动合并。失败审计在业务事务回滚后以脱敏原因单独写入，不记录 token、subject 或上游响应。

`POST /api/sand-iam/v1/auth/federation/unlink` 接受 Bearer session 和 `binding_id`。它也要求最近 step-up，并以锁定事务确认 session、application、organization、provider、mount 和 binding 仍匹配；本地 password、当前 WebAuthn policy 下实际可用的 passkey 或除目标外的 active federation binding 至少保留一项。解除绑定会停用该 binding、撤销其关联会话和 refresh token，并审计 `identity_provider.account_unlink`。跨应用、已撤销或过期 session、过期 step-up 与最后登录方式都会拒绝。

## 非目标和验收边界

本契约不把 provider secret、上游 access token、refresh token、handoff code 或 verifier 写入审计。真实 SandAdmin HTTP 浏览器跳转、第三方 IdP 互操作、并发请求和 PostgreSQL 生命周期需要在 IAM-T07/T08 的受控宿主验收中单独证明。

## 稳定错误码

| 代码 | HTTP | 触发条件 |
| --- | --- | --- |
| `SAND_IAM_FEDERATION_HANDOFF_REQUEST_INVALID` | 400 | start 的 return URI、state 或 S256 challenge 不符合契约/白名单 |
| `SAND_IAM_FEDERATION_HANDOFF_INVALID` | 401 | exchange code、provider、application、redirect URI、verifier、状态、版本或生命周期任一不匹配，包括重放和过期 |
| `SAND_IAM_FEDERATION_STEP_UP_REQUIRED` | 401 | link 或 unlink 的 session 不属于目标应用、已撤销或不在五分钟 step-up 窗口 |
| `SAND_IAM_FEDERATION_LINK_CONFLICT` | 409 | 外部 subject 已绑定到另一应用 identity；不会自动合并 |
| `SAND_IAM_FEDERATION_LAST_LOGIN_METHOD` | 409 | unlink 会移除 identity 的最后一个当前可用登录方式 |
