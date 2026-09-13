# SandIAM PHP SDK

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

## 管理面客户端

`SandIamManagementClient` 与 `SandIamClient` 分离：前者只接受 SandAdmin 管理员 session/Bearer token，绝不接受工作负载凭证。每次请求自动带 `X-Request-Id` 和 `Cache-Control: no-store`，管理员 token 只进 `Authorization` 头。

- `onboardingPreview($manifest)` / `onboardingApply(new SandIamOnboardingOperation(...))`：预检或应用一份接入清单；`manifest.operation_id` 与写入用 `requestId` 均必填。
- `routeSyncPreview` / `routeSyncApply`：复用 onboarding manifest 的 `route_manifest` 阶段，不猜测不存在的独立路由同步 API。
- `policySimulate`、`policyRollback`：模拟或发布一个新的策略版本。
- `credentialIssue`、`credentialRotate`、`credentialRevoke`：写入均要求调用方显式提供 8–96 位 `requestId`。
- `providerPresetList`、`providerPresetDraft`：读取外部 IdP 预设或生成不保存的草稿；草稿不得提交 `client_secret`。

签发/轮换返回 `SandIamCredentialResult`：只有首次成功响应才会 `secretAvailable=true`，用 `revealSecretOnce()` 交给密钥存储；对象禁止转字符串和 JSON 明文输出。重放响应不含明文，若服务端异常返回明文会按 `SAND_IAM_SDK_INVALID_RESPONSE` 拒绝。

常见错误：`SAND_IAM_SDK_REQUEST_ID_REQUIRED` 表示写操作漏传 request ID；`SAND_IAM_ONBOARDING_APPLY_REQUIRED` 表示 apply 未显式确认；`SAND_IAM_ONBOARDING_PREVIEW_STALE` 表示预检后状态已变，需重新 preview；`SAND_IAM_IDP_PRESET_MANUAL_REQUIRED` 表示该身份源尚不能生成通用草稿。
