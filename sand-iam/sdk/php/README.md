# SandIAM PHP SDK

接受邀请使用 `$client->acceptInvitation($invitationToken, $username, $password, $displayName, $requestId)`，返回 `id/display_name`，之后仍须登录。请求匿名发送，邀请令牌仅放在 POST body，不拼进 API URL；服务端依据邀请确定所属应用，SDK 不另传组织/应用。失败保留服务端错误码，不自动重试。

注册返回 `verification_required` 后，可调用 `requestVerification($identifier, 'email', $requestId)` 申请验证码，再调用 `confirmVerification($identifier, 'email', $code, $requestId)` 完成验证；手机渠道为 `phone`。SDK 自动选择匹配的验证用途，密码重置验证码须走专用恢复方法。申请成功不证明账号存在；验证成功后仍须登录，不自动签发会话。

密码找回调用 `forgotPassword($identifier, 'email', $requestId)`，收到验证码后调用 `resetPassword($identifier, 'email', $code, $newPassword, $requestId)`。手机渠道使用 `phone`。两者无需登录；找回成功不代表账号存在，重置成功后须重新登录，SDK 不自动创建会话。验证码与新密码只进入请求体，不放入 URL。

MFA 登录挑战使用 `verifyMfaChallenge(['challenge_token' => $challenge, 'method' => 'totp', 'code' => $code], $requestId)` 提交；恢复码使用 `recovery_code`，Passkey 使用 `passkey` 并传序列化的 `rawId`/`id` 与 `response`。应用绑定来自客户端配置。登录验证成功返回会话；会话升级挑战返回 `step_up: true`，不会签发新会话。失败保留服务端错误码；同一请求重试应保留请求号和原始载荷。

启用验证码时，由应用完成挑战后传入一次性令牌：`login($identifier, $password, captchaToken: $token)`；注册使用 `register(['username' => $username, 'password' => $password, 'captcha_token' => $token])`。SDK 不生成或缓存挑战令牌，省略参数不绕过服务端验证码策略。

运行端和管理端的基础地址必须使用 HTTPS；本机开发可使用 HTTP，主机须为 `localhost`、`127.0.0.1` 或 `[::1]`，可带端口及路径前缀。地址不能包含账号密码、查询参数或片段；`localhost.example.com` 等外部域名不属于本机。

`issueContext($credential, $serviceCode, $audience, $actions, $subjectScope, $requestId)` 只将工作负载凭证置于 `Authorization: Bearer`，不会写入 URL、JSON body 或日志。SandIAM 根据凭证确定客户主体、应用、环境和 workload client，调用方不能传这些 ID。

`verifyContext($context, $serviceCode, $audience, $actions, $sourceIp, $requestId)` 会对每个 action 调用一次公开 verify 路由并校验返回的 service code；公共 HTTP 路由从对端读取可信 IP，所以 `sourceIp` 不会进入请求。每个 HTTP 请求使用独立 request_id；业务 operation_id（例如 SandAI 推理幂等键）必须单独管理。两类请求都带 `Cache-Control: no-store`。

网络、事实、数据分级、配额和幂等错误保持稳定机器码：`SAND_IAM_SERVICE_NETWORK_FORBIDDEN`、`SAND_IAM_INVOCATION_FACTS_UNVERIFIED`、`SAND_IAM_DATA_CLASS_FORBIDDEN`、`SAND_IAM_SERVICE_QUOTA_EXCEEDED`、`SAND_IAM_IDEMPOTENCY_CONFLICT`。它们都应 fail-closed。

## 包外 consumer 安装与最小程序

从源码目录使用本地 path repository 的 consumer，可执行：

```sh
composer config repositories.sand-iam path ../sand-plugins/sand-iam/sdk/php
composer require sand/iam-sdk:*
```

这只安装已检出的 SDK，不能推断 Composer Registry 已有发布包。安装后，服务端 consumer 可导入并完成最小的“签发 → 验证”闭环（示例中的值必须替换为已登记的服务、受众、动作和由密钥管理系统注入的凭证）：

```php
<?php
require 'vendor/autoload.php';

use Sand\Iam\Sdk\SandIamClient;

$iam = new SandIamClient('https://iam.example.com', 'your_organization', 'your_application');
$issued = $iam->issueContext(getenv('SAND_IAM_WORKLOAD_CREDENTIAL'), 'document-service', 'document-service', ['document.read'], null, 'issue-001');
$claims = $iam->verifyContext($issued['context'], 'document-service', 'document-service', ['document.read'], null, 'verify-001');
if (($claims['context_id'] ?? '') !== $issued['context_id']) throw new RuntimeException('SandIAM context verification failed');
```

`issueContext` 仅能在可信服务端使用；把短期 context 经内部可信通道交给目标服务，目标服务验证成功后才执行副作用。请在自己的隔离环境运行该程序并核对审计；本 README 不把示例视为真实调用已通过。

## 应用用户的 MFA 管理

以下方法属于 `SandIamClient`，使用应用用户的访问令牌：

- `mfaFactors($accessToken, $requestId)`：列出已启用的 TOTP 与 Passkey，操作时同时保留 `id` 和 `type`。
- `startTotp($accessToken, $name, $currentPassword, $requestId)`：返回 `factor_id`、`secret`、`otpauth_uri`；扫码后调用 `confirmTotp($accessToken, $factorId, $code, $requestId)`，确认结果包含 `enabled` 和 `recovery_codes`。
- `renameMfaFactor($accessToken, $factorId, $name, $type, $requestId)`、`revokeMfaFactor($accessToken, $factorId, $password, $type, $requestId)`：类型为 `totp` 或 `passkey`，不能仅凭编号区分两类设备。
- `regenerateRecoveryCodes($accessToken, $password, $requestId)`：返回替换后的 `recovery_codes`，旧恢复码失效。

绑定、撤销和更新恢复码需要当前密码，由服务端验证；已有会话或 MFA 升级状态不能代替该密码。绑定秘密与恢复码仅用于当前交付界面，不写日志、分析事件或普通缓存；用户保存后清理调用方内存引用。SDK 不缓存这些结果，也不自动重试。结果不确定时保留原请求号与原参数，按服务端重放结果处理，不能自动创建新的绑定或再次轮换恢复码。重放可能仅返回 `secret_available=false` 及非秘密状态，不再包含 `secret`、`otpauth_uri` 或 `recovery_codes`；调用方须显示秘密不可再次取得，不能当作首次签发成功展示空码。

## 应用用户的 Passkey 注册与登录

注册使用当前应用用户会话：`passkeyRegistrationOptions($accessToken, $name, $currentPassword, $requestId)` 返回 `challenge_token` 和 `public_key`，调用方交给目标平台创建凭据后，以 `passkeyRegistrationFinish($accessToken, $challengeToken, $rawId, $response, $requestId)` 提交。`response` 包含 `clientDataJSON`、`attestationObject`；完成方法成功返回空值，不签发新会话。

登录无需已有会话：`passkeyAuthenticationOptions($requestId)` 固定使用客户端配置的应用，取得挑战；平台完成认证后调用 `passkeyAuthenticationFinish($challengeToken, $rawId, $response, $userAgent, $requestId)`。`response` 包含 `clientDataJSON`、`authenticatorData`、`signature`、`userHandle`，本服务的无账号选择登录要求返回用户句柄。成功结果为应用用户认证结果。

SDK 只传输序列化参数，不模拟认证器。浏览器或原生客户端负责调用平台 Passkey API，将选项中的二进制字段解码，并把返回的二进制凭据编码为无填充 Base64URL；PHP 后端不能代替用户设备完成凭据操作。取消平台操作时不提交 finish；不要记录挑战或凭据响应。SDK 不自动重试；finish 结果不确定时保留原请求号和原响应，不自动重新调用 options 创建挑战。

## 验证码配置与敏感操作

`captchaConfiguration('login', $requestId)` 或 `captchaConfiguration('register', $requestId)` 匿名读取当前客户端应用的公开挑战配置。`required=false` 时无需挑战；`required=true, available=false` 时应提示暂不可用，不能跳过验证；可用时把 `widget` 交给实际客户端渲染，取得令牌后传给登录或注册。该方法不返回供应商密钥，也不代替用户完成挑战。

解绑前先对当前会话完成升级验证：

- 密码方式：`stepUpPassword($accessToken, $password, $requestId)` 返回 `step_up` 与 `expires_in`，不返回新的登录令牌。
- MFA 方式：`startMfaStepUp($accessToken, $requestId)` 返回挑战、可用验证方式及适用的 Passkey 参数，再调用 `verifyMfaChallenge` 完成挑战；不要把“取得挑战”当作验证成功。
- 验证成功后调用 `unlinkFederation($accessToken, $bindingId, $requestId)`。服务端继续检查升级有效期、绑定归属及剩余可用登录方式；SDK 不自动升级、不重试解绑，也不把拒绝当成成功。

## 管理面客户端

`SandIamManagementClient` 与 `SandIamClient` 分离：前者只接受 SandAdmin 管理员 session/Bearer token，绝不接受工作负载凭证。每次请求自动带 `X-Request-Id` 和 `Cache-Control: no-store`，管理员 token 只进 `Authorization` 头。

- `onboardingPreview($manifest)` / `onboardingApply(new SandIamOnboardingOperation(...))`：预检或应用一份接入清单；`manifest.operation_id` 与写入用 `requestId` 均必填。
- `routeSyncPreview` / `routeSyncApply`：复用 onboarding manifest 的 `route_manifest` 阶段，不猜测不存在的独立路由同步 API。
- `policySimulate`、`policyRollback`：模拟或发布一个新的策略版本。
- `credentialIssue`、`credentialRotate`、`credentialRevoke`：写入均要求调用方显式提供 8–96 位 `requestId`。
- `providerPresetList`、`providerPresetDraft`：读取外部 IdP 预设或生成不保存的草稿；草稿不得提交 `client_secret`。

签发/轮换返回 `SandIamCredentialResult`：只有首次成功响应才会 `secretAvailable=true`，用 `revealSecretOnce()` 交给密钥存储；对象禁止转字符串和 JSON 明文输出。重放响应不含明文，若服务端异常返回明文会按 `SAND_IAM_SDK_INVALID_RESPONSE` 拒绝。

常见错误：`SAND_IAM_SDK_REQUEST_ID_REQUIRED` 表示写操作漏传 request ID；`SAND_IAM_ONBOARDING_APPLY_REQUIRED` 表示 apply 未显式确认；`SAND_IAM_ONBOARDING_PREVIEW_STALE` 表示预检后状态已变，需重新 preview；`SAND_IAM_IDP_PRESET_MANUAL_REQUIRED` 表示该身份源尚不能生成通用草稿。
