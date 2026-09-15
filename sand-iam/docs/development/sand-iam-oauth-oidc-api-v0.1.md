# SandIAM OAuth 2.0 / OpenID Connect Provider API v0.1

## 归属与边界

SandIAM 是 OAuth 2.0 / OIDC Provider；一个接入应用可登记多个 OAuth 客户端，共用该应用内 SandIAM 用户会话。应用之间不共享登录、主体或 OAuth client。此协议不管理业务用户类型和业务资源。

`client_id` 是 issuer 内全局唯一的 OAuth 客户端系统代码；数据库仍以 `application_id + client_id` 复合外键隔离 client、identity、session、grant、code 与 token。OIDC `sub` 是 issuer + application + identity 由部署专用稳定密钥派生的值，绝不暴露内部 identity ID。

## 部署配置

必须配置：

- `SAND_IAM_OIDC_ISSUER`：精确 HTTPS issuer，例如 `https://iam.example.com/api/sand-iam/v1`；
- `SAND_IAM_OIDC_PRIVATE_KEY_BASE64` 与 `SAND_IAM_OIDC_KID`：首次托管轮换前的 legacy RSA 私钥与 key ID；
- `SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY`、`SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION` 与 `SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS`：既有版本化 OIDC envelope 及仅用于解密历史记录的 keyring；轮换后活跃私钥复用此 envelope；
- `SAND_IAM_OIDC_SUBJECT_KEY`：至少 32 字符的稳定部署专用 subject HMAC key；
- `SAND_IAM_AUTH_PEPPER`：代码、令牌、CSRF 与 nonce 哈希的既有部署密钥。

私钥绝不写入日志、审计、响应或版本库。首次托管轮换后，私钥以版本化 secretbox envelope 保存在 `sand_iam_oidc_signing_key.encrypted_private_key`，绝不由读取接口或 JWKS 返回。OIDC 当前只有一个 issuer-wide JWKS，OAuth client/token 也没有 environment 选择器，因此签名密钥同样是 issuer-wide：任何传入 `application_id`/`environment_id` 的轮换或退役请求都会失败，而不是伪装成分区生效。

轮换原子地将旧 `active` 置为 `verify_only`（管理 API 显示为 `retiring`），新 RS256 key 成为唯一 `active`。旧 JWK 至少保留 `max signed-token TTL (900s) + clock skew`，由 `SAND_IAM_OIDC_SIGNING_KEY_CLOCK_SKEW_SECONDS` 决定；宽限期未结束时不能退役，退役后不会再签发或发布。密钥不提供删除 API。

## 客户端登记

管理 API：`/app/sand-iam/admin/oauth-client/*`。公开客户端只能走授权码 + PKCE；机密客户端可使用授权码或 client credentials。创建或轮换机密客户端时，`client_secret` 只在当次响应出现；数据库只保存 Argon2id(HMAC(secret))。

`redirect_uris` 与 `post_logout_redirect_uris` 只允许逐字精确的 HTTPS 地址，不允许 HTTP、native loopback、通配符、账号信息和 URL fragment。可配置 `allowed_scopes`、`allowed_audiences`、`default_audience`；机器令牌仅使用被登记的 API scope 与 audience。

## 授权交互

`GET /oauth/authorize` 只校验 client、精确 redirect、`response_type=code`、PKCE `S256`、scope、nonce 与 state，然后创建一个 10 分钟、一次性的服务器授权请求，并仅 302 到固定同源 `/oauth/interaction?request=...`。无合法 client 或 redirect 时绝不向传入地址跳转。

同源交互端点：

1. `GET /oauth/interaction?request=` 返回安全显示 DTO，不返回 state、nonce、code 或 verifier。
2. `POST /oauth/interaction/session` 只接受 `authorization_request` 与现有 SandIAM Bearer 会话，绑定同应用 identity/session 后轮换并返回 CSRF token。
3. `POST /oauth/interaction/confirm` 只接受 request、CSRF 和 `decision=approve|deny`，再次锁定和核验 TTL、session、identity 与 application，返回 `no-store` JSON 的已验证 redirect，供同源 UI 顶层导航。

GET 参数 `consent=approve` 不存在也不起作用。`prompt=consent` 永远要求确认；已存在 consent 时，新增 scope 仍须确认；`prompt=login` 和 `max_age` 重新核验 session auth time；`prompt=none` 不使用 header 桥接，直接向已验证 redirect 返回 `login_required`。

## Token 与 OIDC

`POST /oauth/token` 支持 `authorization_code`、`refresh_token`、`client_credentials`；拒绝 implicit、password grant、PKCE plain。client authentication 只能是 Basic 或 form body 之一。Basic 使用表单 URL 解码。错误是 OAuth JSON；`invalid_client` 返回 401 与 `WWW-Authenticate`。

- 授权码绑定 client、应用、identity、live session、redirect、S256 verifier、auth_time 与 nonce；单次消费与 token 签发在同一事务。
- access token 为 RS256 JWT，header `typ=at+jwt`，包含 `iss/sub/aud/exp/iat/jti/client_id/scope/application_id/grant_id/token_use=access_token`。用户 access audience 固定为 userinfo resource；机器 access audience 必须由客户端白名单选择。
- ID token header `typ=JWT`，`aud=client_id`，包含 nonce、auth_time 和 sid。refresh token 为 opaque，仅 HMAC 哈希保存；只有明确同意 `offline_access` 才签发。重放 refresh 会撤销整条 grant family。
- 资源服务须校验 RS256、kid、issuer、exp、iat、jti、token_use、typ、精确 audience、scope，且通过 `verifyAccessTokenForAudience()` 复核 token / grant / client 的数据库状态。用户令牌还检查原应用内身份及 grant 绑定会话：身份停用、会话停用或撤销、记录缺失或归属不符均拒绝；机器令牌不依赖用户会话。

刷新成功时，旧 refresh token 的消费、新令牌记录与成功审计在同一事务提交；已确认发生事务回滚时，旧令牌保持未消费，可以重新发起刷新。当前没有按 `request_id` 恢复已提交刷新响应的能力：如果服务端已提交而客户端未收到响应，再提交旧 refresh token 会触发重放检测并撤销整条授权，包括第一次刷新生成的新令牌。因此网络超时不能当作“事务已回滚”，客户端不得无限自动重试同一旧令牌；无法确认结果时应重新发起授权登录。

机器 `client_credentials` 请求先验证 scope 与 audience，再创建授权。授权、访问令牌记录和成功审计一起提交，失败回滚；不签发 refresh token 或 ID token。该事务保证不等于成功请求幂等：响应丢失后再次签发可能产生另一组有效授权和令牌，接入方不能按相同 `request_id` 假定返回上一次令牌。

`GET /userinfo` 只接受 `aud=userinfo`、`openid` access token，按 profile/email 最小返回。`POST /oauth/revoke` 对未知 token 也返回成功。`/oauth/logout` 校验 id_token_hint 和精确 logout redirect，撤销同一 SandIAM session 的全部 OAuth grants/tokens 后撤销底层 session，并在 logout redirect 回传已校验 `state`。`state` 最长 1024 字节且不能含控制字符，非法值在撤销前拒绝；会话撤销和成功审计同事务提交，写入失败回滚后可以重试。

启用后通道登出时，待投递通知与会话撤销一起提交，入队成功不等于客户端已收到。会话已撤销后重复登出不会重复生成通知，也不会重新返回前通道地址；因此重复请求不能补回首次响应丢失的前通道通知。客户端应同时清除自身登录状态，并按后通道协议处理收到的通知。

已知且属于当前客户端的令牌撤销会同时撤销所在 grant 及其关联令牌，并将成功审计纳入同一事务；不是仅删除本次提交的单个 token。未知、空值或不属于当前客户端的 token 不修改其他授权，仍保持成功响应，避免暴露令牌存在性。

## 审计与验收

审计只保存 client、application、结果、scope、协议动作与不敏感原因；不得保存 secret、code、access/refresh/id token、nonce、verifier、CSRF 或 state。验收需覆盖跨应用复合 FK、全局 client_id、错误 redirect 不跳转、交互 CSRF/TTL/会话绑定、PKCE、code 重放、JWT 篡改/issuer/aud/scope、client credentials、offline_access、refresh 重放、userinfo、revoke、logout、JWK 历史验签与卸载清理。

参考：[RFC 9700](https://www.rfc-editor.org/rfc/rfc9700)、[RFC 7636](https://www.rfc-editor.org/rfc/rfc7636)、[RFC 8414](https://www.rfc-editor.org/rfc/rfc8414)、[RFC 7009](https://www.rfc-editor.org/rfc/rfc7009)、[RFC 9068](https://www.rfc-editor.org/rfc/rfc9068)、[OIDC Core](https://openid.net/specs/openid-connect-core-1_0.html)、[OIDC Discovery](https://openid.net/specs/openid-connect-discovery-1_0.html)、[RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)。
