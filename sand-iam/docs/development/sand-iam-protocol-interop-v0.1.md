# SandIAM 协议补齐与会话互操作（v0.1 草案）

> IAM-T11 契约。动态客户端注册、OIDC 前/后通道登出、CAS 1/2/3、Kerberos/SPNEGO 和 RADIUS 已有后端候选及非 PostgreSQL 证据；`016–018` 已进入根生命周期并通过 SandPackage 安装，真实 Realm/NAS、宿主 HTTP 和标准客户端互操作尚未完成。

## 1. 为什么不能只复制 Casdoor 的开关

Casdoor 当前公开能力包括 CAS 1/2/3、Guest、RADIUS，以及 RFC 7591 动态客户端注册；它的 DCR 文档说明可按组织开放匿名注册。SandIAM 是一个宿主承载多个独立产品，匿名请求无法可靠判断客户端属于哪一个接入应用，因此默认匿名开放会直接破坏应用隔离。

参考：

- [Casdoor 连接能力目录](https://casdoor.org/docs/category/how-to-connect-to-casdoor/)
- [Casdoor CAS Server](https://casdoor.org/vi/docs/how-to-connect/cas/)
- [Casdoor 动态客户端注册](https://casdoor.org/pl/docs/application/dynamic-client-registration/)
- [Casdoor RADIUS](https://casdoor.org/zh/docs/radius/overview/)
- [RFC 2865: RADIUS](https://www.rfc-editor.org/rfc/rfc2865)
- [RFC 2869: RADIUS Extensions / Message-Authenticator](https://www.rfc-editor.org/rfc/rfc2869)
- [RFC 5080: RADIUS Implementation Issues and Fixes](https://www.rfc-editor.org/rfc/rfc5080)
- [RFC 7591](https://www.rfc-editor.org/rfc/rfc7591)
- [OIDC Front-Channel Logout 1.0](https://openid.net/specs/openid-connect-frontchannel-1_0.html)
- [OIDC Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel_1_0.html)

## 2. 动态客户端注册

SandIAM 只提供 Initial Access Token 模式，不提供匿名开放模式：

1. 应用管理员为一个接入应用签发动态注册令牌；
2. 令牌限定回调主机、允许 scope、有效时间和最大使用次数；数据库只保存 HMAC，明文只展示一次；
3. 客户端以 Bearer Token 调用 `POST /api/sand-iam/v1/oauth/register`；
4. 服务返回 RFC 7591 的 `client_id`、一次性 `client_secret`（如适用）和已接受元数据；
5. 原生客户端只允许 `token_endpoint_auth_method=none`，并允许 `127.0.0.1`/`::1` 回环 HTTP；Web 客户端必须使用机密客户端和 HTTPS；
6. 动态注册不得申请 `client_credentials`，不能越过令牌允许 scope，也不能把回调地址换到未授权主机。

功能开关 `SAND_IAM_OAUTH_DYNAMIC_REGISTRATION_ENABLED` 默认关闭。关闭时 discovery 不公布 `registration_endpoint`，公开端点返回 RFC 7591 `access_denied`。

## 3. OIDC 单点登出

RP-Initiated Logout 仍以有效 `id_token_hint` 为入口，精确校验 `post_logout_redirect_uri`。查到 SandIAM 人类会话后，在一个数据库事务中：

- 锁定同一会话的全部 OAuth grant；
- 为登记 Front-Channel URI 的客户端生成带 `iss`、按配置带 `sid` 的 HTTPS iframe URL；
- 为登记 Back-Channel URI 的客户端签发 `typ=logout+jwt` 的 JWT，包含 `iss/aud/iat/jti/sid/events`，明确不含 `nonce`；
- 加密保存 Back-Channel logout token 到持久化投递队列；
- 撤销全部 grant/token、SandIAM 会话和 refresh token；
- 一起提交或一起回滚。

Front-Channel 页面对 URL 做 HTML 转义并设置 CSP、no-store、no-referrer。Back-Channel worker 使用表单编码 POST、只访问公网 HTTPS、验证 TLS、固定本次 DNS 解析结果、禁止重定向和私网/保留地址，失败最多重试 5 次并写审计。

达到五次失败的投递保持 `dead`，原 logout token 不会被重新排队。管理员可在同一 OAuth 客户端范围内查询脱敏投递，并对 dead 记录执行重新签发；系统要求客户端、应用、主体仍启用且原会话已经撤销，使用原事件确定性派生唯一后继 `jti`，签发新的有效期并创建 pending 记录。原 dead 行不修改，同一来源的并发或重复恢复由事件唯一键收敛到一个后继；后继若再次死亡，可继续形成下一段恢复链。列表、响应和审计都不包含 token 或密文。

功能开关和 worker 均默认关闭：

- `SAND_IAM_OIDC_FRONTCHANNEL_LOGOUT_ENABLED`
- `SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED`
- `SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED`

## 4. CAS

CAS 不复用 SandAdmin 后台登录。`017` 候选把每个 CAS 服务登记到具体接入应用，并要求全局唯一、不可修改、无通配符的精确 HTTPS `service` URL。服务 URL 最长 512 字节，可以保留自己的查询参数，但不得预置 `ticket`。

当前候选流程：

1. `/cas/login` 精确解析已登记服务，生成只存 HMAC、5 分钟有效的一次性登录请求；`renew` 和 `gateway` 当前显式拒绝，不伪装已支持；
2. 独立应用用户交互页读取应用/服务名称，使用该应用 Bearer 会话确认；SandAdmin 后台登录态不能完成确认；
3. 同一应用身份确认后签发只存 HMAC、5 分钟有效的 `ST-*`，并把请求标记为已消费；
4. CAS 1.0 `/validate`、CAS 2.0 `/serviceValidate`、CAS 3.0 `/p3/serviceValidate` 在事务锁内消费 Ticket；跨应用、跨服务、过期和重放均返回协议失败；
5. CAS 3.0 只按服务白名单释放“显示名称/邮箱”，用户名始终来自当前应用身份，不提供跨产品账号信息。

功能开关 `SAND_IAM_CAS_ENABLED` 默认关闭。当前没有宣称 CAS 单点登出、代理票据、`renew` 或 `gateway` 可用。

## 5. Kerberos/SPNEGO

Kerberos 不是“接收一个用户名请求头”。正式启用必须同时具备：

- 独立 SPN/keytab，文件权限和轮换流程；
- 支持 channel binding、时钟偏差和 replay cache 的 GSSAPI 实现或受控 sidecar；
- 反向代理不得把客户端可伪造的身份头转发为认证结果；
- Kerberos principal 到 SandIAM 应用身份的显式映射；
- 无 GSSAPI/keytab/replay cache 时 fail closed，不降级为密码成功。

当前候选已冻结并接入：

- 部署侧 `SpnegoVerifier`，验证结果必须同时证明目标 SPN、mutual auth、channel binding 和 replay cache；默认实现只会返回“不可用”；
- 部署侧 `SpnegoContextResolver`，只能从受信 TLS 终结器或服务器 API 取得通道绑定和远端地址，明确禁止读取客户端可伪造的身份/绑定请求头；
- `kerberos` 身份源配置要求固定 `HTTP/...@REALM`、部署侧 `keytab_ref`、允许 Realm，以及三个强制安全开关；
- principal 只查找身份源实例 + 接入应用内显式有效的 `IdentityBinding`，不按用户名猜测、不自动创建账号；
- 登录成功沿用 federation session，使停用绑定后已签发会话也会在后续访问时失效。

`SAND_IAM_KERBEROS_ENABLED` 默认关闭；验证器或传输上下文解析器缺失时 fail closed。真实正向验收仍必须使用临时 Realm、标准浏览器/curl `--negotiate` 和错 SPN/过期票据/重放负例。

## 6. RADIUS

RADIUS Server 属于网络接入面，没有塞进普通 HTTP controller。`018` Access-Request 候选已包含：

- opt-in 独立 UDP worker，每批有界读取，日志只记录异常类型/文件/行号，不记录报文、用户名、密码或共享密钥；
- 每个 NAS 绑定一个接入应用和不重叠的规范 IPv4/IPv6 CIDR，共享密钥使用 RADIUS 独立版本化密钥加密；
- 所有 Access-Request 强制且只允许一个 Message-Authenticator，HMAC-MD5、User-Password 解封装和 Response Authenticator 严格按 RADIUS 既有协议计算；这里使用 MD5/HMAC-MD5 是协议互操作要求，不作为 SandIAM 其他密码或签名算法；
- 通过真实 UDP 源地址选择唯一 NAS，不读取 HTTP 头；报文指纹进入 5 分钟唯一重放缓存；篡改、来源不明、重放和服务异常静默丢弃；
- 仅支持 User-Name + User-Password。认证复用应用身份的密码锁定、限流、验证状态和审计，但不创建 Bearer 会话；已启用 MFA 的账号明确拒绝，不能绕过 MFA；
- Access-Accept/Reject 始终带 Response Authenticator 和 Message-Authenticator。
- 独立 1813 Accounting worker 校验 Accounting-Request Authenticator，支持 Start、Interim-Update、Stop；会话 ID 和用户名只保存带密钥指纹，计数器必须单调，缺失 Start 或倒退事件分别记为 `orphaned`/`conflict`，不伪造正常会话；相同已落库报文可幂等返回 Accounting-Response。

该阶段尚未实现 CHAP/EAP、Access-Challenge 和 RadSec/RADIUS 1.1，真实 FreeRADIUS/radclient 互操作也未执行，因此不能把当前候选称为完整 RADIUS Server。

RADIUS MFA Client 是另一能力：SandIAM 把第二因子交给外部 RADIUS 服务验证。两者配置、权限和审计必须分开，不能因为实现了 MFA Client 就声称具备 RADIUS Server。

## 7. Guest

Guest 已归入 IAM-T10：业务服务使用受控运行上下文按应用幂等创建访客，外部访客 ID 只保存 HMAC；邀请升级沿用原 Identity ID。它不是匿名全局账号，也不提供跨产品统一登录。

## 8. 当前证据边界

当前可以证明：DCR 输入/方向/回调安全门禁；注册令牌 HMAC 边界；Front-Channel URL 与安全页面生成；Back-Channel token 加密轮换、标准 claims、SSRF 拒绝和持久化 worker 源码契约；CAS 精确服务 URL、请求/Ticket HMAC、5 分钟时限、一次消费、CAS 1/2/3 响应和 XML 转义源码契约；Kerberos verifier/传输上下文/显式绑定 fail-closed 边界；RADIUS Access-Request 报文认证、密码解封装、CIDR、密钥轮换、重放缓存和响应签名非 PG 契约。

`016–018` 已进入根与插件生命周期，并有 SandPackage 隔离安装记录；这只证明包内迁移可执行，不证明协议互操作可用。

当前不能证明：DCR 被真实 OAuth/MCP 客户端消费；两个 RP 同时收到登出；故障重试和事务回滚在 PostgreSQL 生效；真实 CAS 客户端完成登录及重放负例；真实 Kerberos Realm/GSSAPI 和 RADIUS NAS/radclient 可用；RADIUS MFA Client 可用；恢复后的宿主 HTTP 和管理页面可用。因此 IAM-T11 尚未完成。

## 验收字段与错误码对账

| 原子 ID | 字段 | 错误码/恢复 |
| --- | --- | --- |
| R08-T11-01 | `scope`、`redirect_uri`、`registration_token` | `SAND_IAM_DCR_TOKEN_POLICY_INVALID`、`SAND_IAM_DCR_ACCESS_DENIED`：拒绝注册或授权。 |
| R08-T11-02 | `service_url`、`ticket`、`renew`、`gateway` | `SAND_IAM_CAS_REQUEST_REJECTED`：拒绝签发并保留可审计失败事实。 |
| R08-T11-03 | `SPN`、`keytab_reference`、`CIDR` | `SAND_IAM_KERBEROS_AUTHENTICATION_FAILED`：拒绝认证，不返回协商细节。 |
