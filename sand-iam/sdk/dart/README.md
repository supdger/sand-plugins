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

## 本地安装与发布前门禁

在独立 Dart 工程的 `pubspec.yaml` 用 `path: ../sand-iam/sdk/dart` 引用本包，并以 mock transport 验证 issue/verify。发布前应运行 `dart analyze` 和 `dart test`；当前环境没有 Dart runtime，因此本轮只保留 consumer 文件与源码契约，不能称为 Dart 运行时验收。

## 管理面客户端

`SandIamManagementClient` 是独立管理面 SDK：`baseUrl` 来自 SandIAM 宿主地址，`administratorToken` 来自 SandAdmin 管理员 session/Bearer token。它不接收工作负载凭证，并固定以 `Authorization` 头和 `Cache-Control: no-store` 发送请求。

它提供 onboarding preview/apply、作为 onboarding `route_manifest` 阶段的 route sync preview/apply、policy simulate/rollback、credential issue/rotate/revoke 与 IdP preset list/draft。apply、rotate、revoke 等写操作必须显式 `requestId`；onboarding manifest 还必须带 `operation_id`。一次性凭证通过 `SandIamCredentialResult` 表达，首次成功才 `secretAvailable=true`，调用 `revealSecretOnce()` 后失效，`toString()` 会拒绝日志输出；replay 不含秘密。

常见错误：`SAND_IAM_SDK_REQUEST_ID_REQUIRED` 为漏传 request ID；`SAND_IAM_ONBOARDING_PREVIEW_STALE` 为预检已过期，须重新预检；`SAND_IAM_IDP_PRESET_MANUAL_REQUIRED` 表示身份源需要专项接入。当前没有 Dart runtime，本说明仅证明源码与 path consumer 合同，不能替代实际 Dart 运行时验收。

## 诊断 CLI

```bash
dart run sand_iam:sand_iam doctor \
  --url https://iam.example.com \
  --organization your_organization \
  --application your_application

dart run sand_iam:sand_iam snippet --language flutter
```

CLI 不接收用户密码、访问令牌、客户端密钥或 RADIUS 共享密钥。
