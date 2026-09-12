# SandIAM TypeScript SDK

使用强类型 `issueContext({ credential, serviceCode, audience, actions, subjectScope?, requestId? })` 和 `verifyContext({ context, serviceCode, audience, actions, sourceIp?, requestId? })`。凭证只会置于 `Authorization: Bearer`，不会出现在 URL、body 或日志；组织、应用、环境和 workload client ID 由 SandIAM 解析，SDK 不接收它们。

公开 HTTP verify 路由不接受调用方 `sourceIp`，因此该字段仅为服务端适配器保留且不会序列化。verify 会逐 action 校验，所有请求带 `Cache-Control: no-store`。`requestId` 对应一次 HTTP；SandAI 的 `operation_id` 是独立的业务幂等键。

网络、事实、数据分级、配额和幂等冲突均以稳定 `SAND_IAM_*` code 抛出：`SAND_IAM_SERVICE_NETWORK_FORBIDDEN`、`SAND_IAM_INVOCATION_FACTS_UNVERIFIED`、`SAND_IAM_DATA_CLASS_FORBIDDEN`、`SAND_IAM_SERVICE_QUOTA_EXCEEDED`、`SAND_IAM_IDEMPOTENCY_CONFLICT`。遇到它们必须拒绝，不得回退为匿名调用。

## 本地安装与发布前门禁

先执行 `pnpm test`，再用 `pnpm pack --pack-destination /private/tmp` 生成 tarball；在空目录以 `pnpm add /private/tmp/<tarball>` 安装并运行 issue/verify mock。该步骤不调用 `npm publish`。包仅导出 `dist` 和本说明；SandIAM 项目采用 [Apache-2.0](../../LICENSE)，但 npm 发布元数据、registry 与公开发布仍须由维护者单独确认。

`dist/` 是受锁定的本地 `pnpm-lock.yaml` 与 TypeScript 工具链从 `src/` 生成的 ESM
消费载荷，不是手工维护的源码。修改 SDK 时只改 `src/`，执行 `pnpm run build`，并确认
两次构建的 `dist/index.js`、`dist/index.d.ts`、`dist/management.js`、`dist/management.d.ts`
字节一致。安装候选时只应消费 ZIP 内这四个构建物；不要从开发工作树、`node_modules` 或
自行编辑的 `dist` 回退。

当前可审查构建参数固定在 [`release-build-contract.json`](../../release-build-contract.json)：
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
