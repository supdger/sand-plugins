# SandIAM TypeScript SDK

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
