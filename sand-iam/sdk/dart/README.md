# SandIAM Dart / Flutter SDK

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
