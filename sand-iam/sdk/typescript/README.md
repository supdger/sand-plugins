# SandIAM TypeScript SDK

接受邀请使用 `client.acceptInvitation({ token, username, password, displayName, requestId })`，返回 `id/display_name`，之后仍须登录。请求匿名发送，邀请令牌仅放在 POST body，不拼进 API URL；服务端依据邀请确定所属应用，SDK 不另传组织/应用。失败保留服务端错误码，不自动重试。

运行端和管理端请求均禁止自动重定向（Fetch `redirect: 'error'`），请配置最终 API 地址。重定向失败时不得转发原凭证重试到新地址；自定义 `fetch` 实现也必须遵守此选项。

登录返回 `mfa_required: true` 时尚未获得会话。读取 `methods` 展示可选验证方式、`expires_in` 提示有效期；`public_key` 保留服务端 Passkey 请求参数，其中 Base64URL 字段仍须由应用转换为浏览器所需的二进制类型。不要把挑战令牌当作访问令牌。

启用验证码时，应用完成挑战后将一次性令牌作为 `captchaToken` 传给 `login({...})` 或 `register({...})`。SDK 将其映射为请求体的 `captcha_token`，不生成或缓存令牌；省略参数不绕过服务端验证码策略。

运行端和管理端的基础地址必须使用 HTTPS；本机开发可使用 HTTP，主机须为 `localhost`、`127.0.0.1` 或 `[::1]`，可带端口及路径前缀。地址不能包含账号密码、查询参数或片段；`localhost.example.com` 等外部域名不属于本机。浏览器运行端另允许 `baseUrl: ''` 使用当前页面同源地址，管理端须提供完整地址。

使用强类型 `issueContext({ credential, serviceCode, audience, actions, subjectScope?, requestId? })` 和 `verifyContext({ context, serviceCode, audience, actions, sourceIp?, requestId? })`。凭证只会置于 `Authorization: Bearer`，不会出现在 URL、body 或日志；组织、应用、环境和 workload client ID 由 SandIAM 解析，SDK 不接收它们。

公开 HTTP verify 路由不接受调用方 `sourceIp`，因此该字段仅为服务端适配器保留且不会序列化。verify 会逐 action 校验，所有请求带 `Cache-Control: no-store`。`requestId` 对应一次 HTTP；SandAI 的 `operation_id` 是独立的业务幂等键。

网络、事实、数据分级、配额和幂等冲突均以稳定 `SAND_IAM_*` code 抛出：`SAND_IAM_SERVICE_NETWORK_FORBIDDEN`、`SAND_IAM_INVOCATION_FACTS_UNVERIFIED`、`SAND_IAM_DATA_CLASS_FORBIDDEN`、`SAND_IAM_SERVICE_QUOTA_EXCEEDED`、`SAND_IAM_IDEMPOTENCY_CONFLICT`。遇到它们必须拒绝，不得回退为匿名调用。

## 包外 consumer 安装与最小程序

在独立 Node.js consumer 中，从已检出的源码安装：

```sh
pnpm add ../sand-plugins/sand-iam/sdk/typescript
```

这只安装本地目录，不能推断 npm registry 已有发布包。安装后，服务端 consumer 可导入并完成最小的“签发 → 验证”闭环：

```ts
import { SandIamClient } from '@sand/iam-browser'

const iam = new SandIamClient({
  baseUrl: 'https://iam.example.com',
  organizationCode: 'your_organization',
  applicationCode: 'your_application',
  accessToken: () => process.env.SAND_IAM_ACCESS_TOKEN ?? '',
})
const issued = await iam.issueContext({
  credential: process.env.SAND_IAM_WORKLOAD_CREDENTIAL ?? '',
  serviceCode: 'document-service', audience: 'document-service', actions: ['document.read'], requestId: 'issue-001',
})
const claims = await iam.verifyContext({
  context: issued.context, serviceCode: 'document-service', audience: 'document-service', actions: ['document.read'], requestId: 'verify-001',
})
if (claims.context_id !== issued.context_id) throw new Error('SandIAM context verification failed')
```

只在可信服务端运行该程序；凭证和短期 context 不得进入浏览器、日志或前端构建物。请在自己的隔离环境运行并核对审计；本 README 不把示例视为真实调用已通过。

`dist/` 是受锁定的本地 `pnpm-lock.yaml` 与 TypeScript 工具链从 `src/` 生成的 ESM
消费载荷，不是手工维护的源码。修改 SDK 时只改 `src/`，执行 `pnpm run build`，并确认
两次构建的 `dist/index.js`、`dist/index.d.ts`、`dist/management.js`、`dist/management.d.ts`
字节一致。安装候选时只应消费 ZIP 内这四个构建物；不要从开发工作树、`node_modules` 或
自行编辑的 `dist` 回退。

可审查构建参数固定在 [`release-build-contract.json`](../../release-build-contract.json)：
pnpm `11.19.0`、Node `v24.11.1`、TypeScript `5.9.3`，并使用 `pnpm install --offline
--frozen-lockfile --ignore-scripts` 与 `pnpm exec tsc -p tsconfig.json`。构建前必须校验 lock 中的
`typescript@5.9.3` SHA-512 integrity；更新 lock、工具链或四个构建物必须先做两份隔离构建的逐字节比对，
再一并更新契约、产物和审核记录。

## 管理面客户端

从同一包导入独立的 `SandIamManagementClient`，但不要把它与 `SandIamClient` 或工作负载凭证混用。填写项及来源：`baseUrl` 是 SandIAM 宿主地址，`administratorToken` 是 SandAdmin 管理员登录态/Bearer token；token 只会进入 `Authorization` 头。每个管理请求均使用 `Cache-Control: no-store`。

```ts
const management = new SandIamManagementClient({
  baseUrl: 'https://iam.example.com',
  administratorToken: () => adminSessionToken,
})
const preview = await management.onboardingPreview(manifest, 'preview-20260822')
const applied = await management.onboardingApply({
  manifest, previewHash: String(preview.preview_hash), requestId: 'apply-20260822',
})
```

`routeSyncPreview` / `routeSyncApply` 复用正式 onboarding manifest 的 `route_manifest` 阶段，不会猜测独立同步地址。`policySimulate` / `policyRollback`、`credentialIssue` / `credentialRotate` / `credentialRevoke`、`presetList` / `presetDraft` 均有强类型入口；写操作必须显式传合格的 request ID。`SandIamCredentialResult` 仅首次返回 `secretAvailable=true`，只能 `revealSecretOnce()` 交给密钥存储，禁止 `String()`、日志或 JSON 明文输出；重放一律无秘密。

常见错误：`SAND_IAM_SDK_REQUEST_ID_REQUIRED` 是漏传写操作 request ID；`SAND_IAM_ONBOARDING_PREVIEW_STALE` 需重新预检；`SAND_IAM_IDP_PRESET_MANUAL_REQUIRED` 说明身份源只能人工专项接入；`SAND_IAM_SDK_INVALID_RESPONSE` 表示服务端返回了不安全的一次性秘密重放载荷。
# MFA 挑战提交

`login()` 返回 `mfa_required` 时，用 `verifyMfaChallenge({ challengeToken, method: 'totp', code, requestId })` 提交挑战；恢复码使用 `method: 'recovery_code'`。Passkey 使用 `method: 'passkey'`、base64url `rawId` 以及 `response: { clientDataJSON, authenticatorData, signature, userHandle? }`，字段来自浏览器认证响应并编码为 base64url。

请求固定绑定构造时的组织/应用，不附带会话 Authorization。请求失败后的同一次重试应保留 `requestId`。返回 `step_up: true` 与 `expires_in` 表示原会话升级成功，不是新登录；只有登录结果中的 `access_token` 才是新会话令牌。此方法不启动会话升级挑战。
# 密码恢复

先调用 `forgotPassword({ identifier, channel: 'email', requestId })`，再调用 `resetPassword({ identifier, channel: 'email', code, password, requestId })`。短信通道值为 `'phone'`，不是 `'sms'`；重置使用 `password`，不是已登录改密接口的 `new_password`。两方法返回 `Promise<void>`，请求固定绑定配置中的组织/应用且不附带会话凭证。发送成功不表示账号存在；统一提示“如账号存在，重置验证码已发送”。重置后需要重新登录，SDK 不自动登录。失败保留 SDK 错误，重试同一次请求保留原请求号。
# 注册联系方式验证

调用 `requestVerification({ identifier, channel: 'email', requestId })` 请求验证码，再调用 `confirmVerification({ identifier, channel: 'email', code, requestId })` 确认。手机通道为 `'phone'`。SDK 按通道推导 `email_verify` / `phone_verify`，不接受自定义 purpose 或密码恢复用途。两方法固定应用、匿名、返回 `Promise<void>`，不会自动登录；请求成功统一提示“如账号存在，验证码已发送”，不据此判断账号存在。错误继续抛出，同一次重试保留请求号。
# MFA 自助管理

幂等重放可能返回 `secret_available: false`，此时 `secret/otpauth_uri/recovery_codes` 不存在，仍保留 factor_id 或 enabled 等状态。不要将它当作可再次展示的秘密，也不要自动重新签发。

登录后可用 `mfaFactors(requestId)` 查看 TOTP/Passkey；`startTotp({ name, currentPassword, requestId })` 返回 `factor_id/secret/otpauth_uri`，再用 `confirmTotp({ factorId, code, requestId })` 启用并取得 `recovery_codes`。`renameMfaFactor({ factorId, type, name, requestId })` 改名，`revokeMfaFactor({ factorId, type, password, requestId })` 撤销；type 仅为 `totp` 或 `passkey`。`regenerateRecoveryCodes({ password, requestId })` 重新生成恢复码。

六项均要求会话 Authorization。开始使用 `current_password`，撤销与重生成使用 `password`，由服务验证真实当前密码，不能用已升级状态代替。SDK 不缓存 TOTP 秘密、恢复码，不自动保存或重试；调用方仅在受控内存中展示并完成安全交付后清除，不写日志或持久存储。错误按原 SDK 规则抛出。
# Passkey 调用

登录或注册前可调用 `captchaConfiguration('login' | 'register', requestId)`：`required:false` 表示无需验证码；`required:true, available:false` 表示当前不可用，不能绕过；可用时读取 `widget.kind/site_key/action/application_binding`，由平台展示组件并把令牌交给已有登录或注册方法。该 GET 不读取会话。

敏感操作需要近期验证时，由应用明确选择 `stepUpPassword(password, requestId)` 或 `startMfaStepUp(requestId)`；后者返回挑战，再通过 `verifyMfaChallenge` 提交。`step_up:true` 升级当前会话，不是新登录。`unlinkFederation(bindingId, requestId)` 返回 void，是否已完成升级、是否保留备用登录方式由服务端检查；SDK 不自动升级、解除绑定或重试。

已登录时先调用 `passkeyRegistrationOptions({ name, currentPassword })`，将返回的 `public_key` 交给平台完成 `credentials.create`，再调用 `passkeyRegistrationFinish({ challengeToken, rawId, response })`。注册完成返回 `void`，不会生成登录令牌。

登录时调用 `passkeyAuthenticationOptions()`，由平台完成 `credentials.get` 后调用 `passkeyAuthenticationFinish({ challengeToken, rawId, response, userAgent })`。认证请求匿名绑定 SDK 配置的组织和应用，返回既有认证结果。平台负责 WebAuthn 的二进制字段转换和 base64url 序列化；注册响应提供 `clientDataJSON/attestationObject`，认证响应提供 `clientDataJSON/authenticatorData/signature/userHandle`，其中 `userHandle` 必填。SDK 仅传输响应，不调用浏览器 API、不缓存凭据、不自动重试；各方法支持原有 `requestId`。
