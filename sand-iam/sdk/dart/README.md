# SandIAM Dart / Flutter SDK

接受邀请使用 `client.acceptInvitation(token: token, username: username, password: password, displayName: displayName, requestId: requestId)`，返回 `SandIamInvitationIdentity`（`id/displayName`），之后仍须登录。请求匿名发送，邀请令牌仅放在 POST body，不拼进 API URL；服务端依据邀请确定所属应用，SDK 不另传组织/应用。失败保留服务端错误码，不自动重试。

登录返回 `mfaRequired == true` 时尚未获得会话。读取 `methods` 展示可选验证方式、`expiresIn` 提示有效期；`publicKey` 保留服务端 Passkey 请求参数，由应用交给目标平台的认证组件处理。不要把挑战令牌当作访问令牌。

启用验证码时，应用完成挑战后将一次性令牌通过 `captchaToken: token` 传给 `login(...)` 或 `register(...)`。SDK 将其映射为请求体的 `captcha_token`，不生成或缓存令牌；省略参数不绕过服务端验证码策略。

这是一个纯 Dart 包，可直接用于 Flutter、Dart 服务和命令行工具。它覆盖：

- 应用用户注册、登录、刷新、退出；
- 个人资料、安全概况、外部账号连接和会话管理；
- 按稳定接口代码执行授权决策；
- OIDC 发现文档与应用登录配置诊断；
- 生成不含密钥和令牌的 PHP、TypeScript、Flutter 最小接入示例。

## 最小用法

```dart
final iam = SandIamClient(
  baseUrl: 'https://iam.example.com',
  organizationCode: 'your_organization',
  applicationCode: 'your_application',
  accessToken: tokenStore.read,
);

final result = await iam.login(
  identifier: username,
  password: password,
);

final decision = await iam.authorize(apiCode: 'case.document.export');
```

生产地址必须使用 HTTPS；只有 `localhost`、`127.0.0.1` 和 `::1` 可用 HTTP。SDK 不负责持久化令牌，Flutter 应用应把令牌保存到平台安全存储，不要写入普通偏好设置、日志或错误上报。

## 工作负载运行上下文

服务端可用 `issueContext` 与 `verifyContext` 对接 `/app/sand-iam/runtime/context/*`。只填写已声明的 `serviceCode`、`audience`、`actions`；组织、应用、环境和 workload client 由 SandIAM 从凭证解析，SDK 不接受这些 ID。`credential` 只进 `Authorization: Bearer`，请求带 `Cache-Control: no-store`。`verifyContext` 会对每个动作单独验证；`sourceIp` 仅为服务端适配器保留，公开 HTTP 请求不会传它。每个 HTTP 请求有独立 `requestId`，业务 `operation_id` 应另行保存。

网络、事实、数据分级、配额和幂等冲突均保留稳定的 `SAND_IAM_*` 错误码，例如 `SAND_IAM_SERVICE_NETWORK_FORBIDDEN`、`SAND_IAM_INVOCATION_FACTS_UNVERIFIED`、`SAND_IAM_DATA_CLASS_FORBIDDEN`、`SAND_IAM_SERVICE_QUOTA_EXCEEDED` 与 `SAND_IAM_IDEMPOTENCY_CONFLICT`；调用方必须拒绝而非降级。

## 包外 consumer 安装与最小程序

在独立 Dart/Flutter 项目的 `pubspec.yaml` 中引用已检出的 SDK：

```yaml
dependencies:
  sand_iam:
    path: ../sand-plugins/sand-iam/sdk/dart
```

然后执行 `dart pub get`，并在可信服务端完成“签发 → 验证”最小程序：

```dart
import 'dart:io';

import 'package:sand_iam/sand_iam.dart';

Future<void> main() async {
  String required(String key) {
    final value = Platform.environment[key]?.trim() ?? '';
    if (value.isEmpty) throw StateError('$key must be injected by the runtime');
    return value;
  }

  try {
    final iam = SandIamClient(
      baseUrl: required('SAND_IAM_BASE_URL'),
      organizationCode: required('SAND_IAM_ORGANIZATION_CODE'),
      applicationCode: required('SAND_IAM_APPLICATION_CODE'),
      accessToken: () => '', // This service flow never accepts a user token.
    );
    final credential = required('SAND_IAM_WORKLOAD_CREDENTIAL');
    final serviceCode = required('SAND_IAM_SERVICE_CODE');
    final audience = required('SAND_IAM_AUDIENCE');
    final action = required('SAND_IAM_SERVICE_ACTION');
    final issued = await iam.issueContext(
      credential: credential,
      serviceCode: serviceCode,
      audience: audience,
      actions: [action],
      requestId: 'context-issue-001',
    );
    final context = issued.context;
    if (context == null || context.isEmpty) {
      throw StateError('SandIAM did not return a workload context');
    }
    final claims = await iam.verifyContext(
      context: context,
      serviceCode: serviceCode,
      audience: audience,
      actions: [action],
      requestId: 'context-verify-001',
    );
    if (claims.contextId != issued.contextId ||
        claims.serviceCode != serviceCode ||
        claims.audience != audience ||
        claims.actions == null ||
        !claims.actions!.contains(action)) {
      throw StateError('SandIAM context verification failed');
    }
    stdout.writeln('context verified: ${claims.contextId}'); // Never print context.
  } on SandIamException catch (error) {
    stderr.writeln('SandIAM rejected the request: ${error.code}');
    exitCode = 1;
  } on StateError catch (error) {
    stderr.writeln(error.message);
    exitCode = 1;
  }
}
```

这只使用本地 path dependency，不能推断 pub.dev 已有发布包。不要把工作负载凭证或短期 context 放进 Flutter
客户端；应由服务端签发、经可信内部通道交给目标服务并在目标服务验证。请在自己的隔离环境运行并核对审计；
本 README 不把示例视为真实调用已通过。

示例对缺失环境变量、SDK 例外、空 context 或 service/audience/action/context ID 不匹配均以失败结束；
不得改为匿名调用、缓存旧 context 或继续产生业务副作用。使用前应在目标 consumer 的受控 Dart/Flutter
工具链中完成 `dart pub get`、静态分析和该 consumer 自己的隔离运行验证；本 README 不把示例视为网络调用已通过。

## 管理面客户端

`SandIamManagementClient` 是独立管理面 SDK：`baseUrl` 来自 SandIAM 宿主地址，`administratorToken` 来自 SandAdmin 管理员 session/Bearer token。它不接收工作负载凭证，并固定以 `Authorization` 头和 `Cache-Control: no-store` 发送请求。

它提供 onboarding preview/apply、作为 onboarding `route_manifest` 阶段的 route sync preview/apply、policy simulate/rollback、credential issue/rotate/revoke 与 IdP preset list/draft。apply、rotate、revoke 等写操作必须显式 `requestId`；onboarding manifest 还必须带 `operation_id`。一次性凭证通过 `SandIamCredentialResult` 表达，首次成功才 `secretAvailable=true`，调用 `revealSecretOnce()` 后失效，`toString()` 会拒绝日志输出；replay 不含秘密。

常见错误：`SAND_IAM_SDK_REQUEST_ID_REQUIRED` 为漏传 request ID；`SAND_IAM_ONBOARDING_PREVIEW_STALE` 为预检已过期，须重新预检；`SAND_IAM_IDP_PRESET_MANUAL_REQUIRED` 表示身份源需要专项接入。源码或 path consumer 合同不能替代实际 Dart 运行时验收。

## 诊断 CLI

```bash
dart run sand_iam:sand_iam doctor \
  --url https://iam.example.com \
  --organization your_organization \
  --application your_application

dart run sand_iam:sand_iam snippet --language flutter
```

CLI 不接收用户密码、访问令牌、客户端密钥或 RADIUS 共享密钥。
# MFA 挑战提交

`login()` 返回 `mfaRequired` 时，调用 `verifyMfaChallenge(challengeToken: token, method: 'totp', code: code, requestId: requestId)`；恢复码使用 `method: 'recovery_code'`。Passkey 使用 `method: 'passkey'`、base64url `rawId` 和 `response`，包含 base64url `clientDataJSON`、`authenticatorData`、`signature` 及可选 `userHandle`，由平台认证响应提供。

请求固定绑定构造时的组织/应用，不读取或附带会话令牌。重试同一次请求保留 `requestId`。结果 `stepUp == true` 和 `expiresIn` 表示原会话升级成功，不是新登录；不要将它当作含 `accessToken` 的登录结果。本方法不启动会话升级挑战。
# 密码恢复

先调用 `forgotPassword(identifier: identifier, channel: 'email', requestId: requestId)`，再调用 `resetPassword(identifier: identifier, channel: 'email', code: code, password: password, requestId: requestId)`。短信通道值为 `'phone'`，不是 `'sms'`；重置使用 `password`。两方法返回 `Future<void>`，固定组织/应用且不读取会话凭证。发送成功不证明账号存在，请统一提示“如账号存在，重置验证码已发送”。重置后重新登录，SDK 不自动登录；错误继续抛出，重试同一次请求保留原请求号。
# 注册联系方式验证

调用 `requestVerification(identifier: identifier, channel: 'email', requestId: requestId)` 请求验证码，再调用 `confirmVerification(identifier: identifier, channel: 'email', code: code, requestId: requestId)`。手机通道为 `'phone'`。SDK 固定推导 `email_verify` / `phone_verify`，不开放密码恢复 purpose。两方法固定应用、匿名、返回 `Future<void>`，不会自动登录。请求成功统一提示“如账号存在，验证码已发送”，不判断账号是否存在；错误继续抛出，同一次重试保留请求号。
# 默认传输重定向

运行与管理客户端的默认 HTTP 传输均禁用自动重定向，3xx 按请求失败处理，避免把会话、机器凭证或管理员令牌转发到重定向地址。请配置最终服务地址；自定义 transport 也必须保持此边界。
# MFA 自助管理

幂等重放可能返回 `secretAvailable == false`，此时 `secret/otpauthUri/recoveryCodes` 为 null，仍保留 factorId 或 enabled 等状态。不要据此自动重新签发，也不要尝试重新显示秘密。

`mfaFactors()` 返回 TOTP/Passkey 列表；`startTotp(currentPassword: password, name: name)` 返回 `factorId/secret/otpauthUri`，`confirmTotp(factorId: id, code: code)` 返回启用状态和 `recoveryCodes`。`renameMfaFactor(factorId: id, type: 'totp', name: name)` 改名；`revokeMfaFactor(factorId: id, type: 'passkey', password: password)` 撤销；`regenerateRecoveryCodes(password: password)` 重生成恢复码。所有方法支持 `requestId`。

六项均要求当前会话。服务校验真实当前密码，已有升级状态不能代替密码。SDK 不自动缓存、保存或重试一次性 TOTP 秘密与恢复码；调用方只在受控内存展示，安全交付后清除，勿写日志或持久存储。返回数据经过类型校验，服务错误继续抛出。
# Passkey 调用

CLI `snippet` 在输出前使用 SDK 构造器校验地址、客户主体和应用代码，不发起网络请求。示例使用规范化 URL，并按 PHP、TypeScript、Dart 的字符串规则转义；Dart 的 `$` 不会被当作生成代码中的插值。

登录或注册前可调用 `captchaConfiguration('login', requestId: requestId)`（也支持 `register`）。`required == false` 无需验证码；`required == true && available == false` 表示不可用，不能绕过；可用时读取 `widget` 的 `kind/siteKey/action/applicationBinding`，由平台展示并将令牌交给已有登录或注册方法。该 GET 不读取会话。

敏感操作由应用明确选择 `stepUpPassword(password, requestId: requestId)` 或 `startMfaStepUp(requestId: requestId)`，后者挑战通过 `verifyMfaChallenge` 提交。`stepUp == true` 升级当前会话，不是新登录。`unlinkFederation(bindingId, requestId: requestId)` 返回 `Future<void>`；近期验证和备用登录保护仍由服务端检查，SDK 不自动升级、解除绑定或重试。

已登录时先调用 `passkeyRegistrationOptions(name: name, currentPassword: password)`，将返回的 `publicKey` 交给平台完成注册，再调用 `passkeyRegistrationFinish(challengeToken: options.challengeToken, rawId: rawId, response: response)`。注册完成返回 `Future<void>`，不会生成登录令牌。

登录时调用 `passkeyAuthenticationOptions()`，平台取得凭据后调用 `passkeyAuthenticationFinish(challengeToken: options.challengeToken, rawId: rawId, response: response, userAgent: userAgent)`。认证请求匿名绑定 SDK 配置的组织和应用，返回既有认证结果。平台负责 WebAuthn ceremony（浏览器为 `credentials.create/get`）、二进制转换与 base64url 序列化；注册响应提供 `clientDataJSON/attestationObject`，认证响应提供 `clientDataJSON/authenticatorData/signature/userHandle`，其中 `userHandle` 必填。SDK 仅传输响应，不调用平台 API、不缓存凭据、不自动重试；各方法支持 `requestId`。
