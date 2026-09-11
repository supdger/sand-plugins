# SandIAM PHP SDK

`issueContext($credential, $serviceCode, $audience, $actions, $subjectScope, $requestId)` 只将工作负载凭证置于 `Authorization: Bearer`，不会写入 URL、JSON body 或日志。SandIAM 根据凭证确定客户主体、应用、环境和 workload client，调用方不能传这些 ID。

`verifyContext($context, $serviceCode, $audience, $actions, $sourceIp, $requestId)` 会对每个 action 调用一次公开 verify 路由并校验返回的 service code；公共 HTTP 路由从对端读取可信 IP，所以 `sourceIp` 不会进入请求。每个 HTTP 请求使用独立 request_id；业务 operation_id（例如 SandAI 推理幂等键）必须单独管理。两类请求都带 `Cache-Control: no-store`。

网络、事实、数据分级、配额和幂等错误保持稳定机器码：`SAND_IAM_SERVICE_NETWORK_FORBIDDEN`、`SAND_IAM_INVOCATION_FACTS_UNVERIFIED`、`SAND_IAM_DATA_CLASS_FORBIDDEN`、`SAND_IAM_SERVICE_QUOTA_EXCEEDED`、`SAND_IAM_IDEMPOTENCY_CONFLICT`。它们都应 fail-closed。

## 本地安装与发布前门禁

在独立 consumer 的 `composer.json` 使用本目录的 `path` repository 安装 `sand/iam-sdk`，并通过 mock transport 运行 issue/verify。执行 `composer dump-autoload`、PHP lint 与 SDK 测试后才可评审发布；本次不执行 Composer 发布或任何公网操作。

## 管理面客户端

`SandIamManagementClient` 与 `SandIamClient` 分离：前者只接受 SandAdmin 管理员 session/Bearer token，绝不接受工作负载凭证。每次请求自动带 `X-Request-Id` 和 `Cache-Control: no-store`，管理员 token 只进 `Authorization` 头。

- `onboardingPreview($manifest)` / `onboardingApply(new SandIamOnboardingOperation(...))`：预检或应用一份接入清单；`manifest.operation_id` 与写入用 `requestId` 均必填。
- `routeSyncPreview` / `routeSyncApply`：复用 onboarding manifest 的 `route_manifest` 阶段，不猜测不存在的独立路由同步 API。
- `policySimulate`、`policyRollback`：模拟或发布一个新的策略版本。
- `credentialIssue`、`credentialRotate`、`credentialRevoke`：写入均要求调用方显式提供 8–96 位 `requestId`。
- `providerPresetList`、`providerPresetDraft`：读取外部 IdP 预设或生成不保存的草稿；草稿不得提交 `client_secret`。

签发/轮换返回 `SandIamCredentialResult`：只有首次成功响应才会 `secretAvailable=true`，用 `revealSecretOnce()` 交给密钥存储；对象禁止转字符串和 JSON 明文输出。重放响应不含明文，若服务端异常返回明文会按 `SAND_IAM_SDK_INVALID_RESPONSE` 拒绝。

常见错误：`SAND_IAM_SDK_REQUEST_ID_REQUIRED` 表示写操作漏传 request ID；`SAND_IAM_ONBOARDING_APPLY_REQUIRED` 表示 apply 未显式确认；`SAND_IAM_ONBOARDING_PREVIEW_STALE` 表示预检后状态已变，需重新 preview；`SAND_IAM_IDP_PRESET_MANUAL_REQUIRED` 表示该身份源尚不能生成通用草稿。
