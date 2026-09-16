/**
 * U-T03 只消费已冻结的 OAuth / 接口治理 / onboarding / 模拟 / decide 字段。
 * 前端不得把 URL 当权限键，也不得自行计算 allow/deny。
 */

export const SAND_IAM_API_CODE_RULE =
  '接口或业务动作代码须为 2–96 位，以小写字母开头，只能包含小写字母、数字、点、下划线、冒号或短横线。创建后不可修改。'

export const SAND_IAM_ONBOARDING_FORMAT = 'sand-iam.onboarding/v1'
export const SAND_IAM_ROUTE_SYNC_FORMAT = 'sand-iam.route-sync/v1'

const API_CODE_PATTERN = /^[a-z][a-z0-9_.:-]{1,95}$/
const API_VERSION_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/
const AUDIENCE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$/
const SCOPE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/
const MAPPING_SCOPE_PATTERN = /^[A-Za-z0-9._:-]{1,128}$/

const API_OPERATIONS = ['list', 'read', 'create', 'update', 'delete', 'export', 'batch'] as const

const RISK_LEVELS = ['low', 'medium', 'high', 'critical'] as const
const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const
const ROUTE_SOURCES = ['manual', 'openapi', 'route_scan'] as const

export type SandIamApiOperation = (typeof API_OPERATIONS)[number]
export type SandIamRiskLevel = (typeof RISK_LEVELS)[number]

function isOneOf(list: readonly string[], value: string): boolean {
  return list.some((item) => item === value)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

function readStringArray(value: unknown): string[] | null {
  if (!Array.isArray(value)) return null
  const items: string[] = []
  for (const item of value) {
    if (typeof item !== 'string' || item.trim() === '') return null
    items.push(item.trim())
  }
  return items
}

/**
 * 接口目录与应用业务动作共用代码规则；与客户主体系统代码不同，这里允许点号和冒号。
 */
export function describeApiGovernanceCodeError(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') {
    return `请填写稳定代码。${SAND_IAM_API_CODE_RULE}`
  }
  if (!API_CODE_PATTERN.test(trimmed)) {
    return SAND_IAM_API_CODE_RULE
  }
  return null
}

/**
 * 机密客户端必须有回调地址；公开客户端允许本机回环 HTTP。
 */
export function describeOAuthClientPayloadError(
  payload: Readonly<Record<string, unknown>>,
  creating: boolean
): string | null {
  if (creating) {
    const type = readString(payload.client_type)
    if (type !== 'public' && type !== 'confidential') {
      return '请选择公开客户端或机密客户端。'
    }
    const redirects = readStringArray(payload.redirect_uris)
    if (redirects === null || redirects.length === 0) {
      return '至少需要一个登录回调地址。完整地址只在表单填写，不进入默认列表。'
    }
  }
  if (payload.allowed_scopes !== undefined) {
    const scopes = readStringArray(payload.allowed_scopes)
    if (
      scopes === null ||
      scopes.length === 0 ||
      scopes.some((scope) => !MAPPING_SCOPE_PATTERN.test(scope))
    ) {
      return '允许范围必须是 1–128 位的协议范围代码；不要把完整 URL 当作 scope。'
    }
  }
  return null
}

/**
 * 先有已启用业务动作，才能登记接口目录。
 */
export function describeApiResourcePayloadError(
  payload: Readonly<Record<string, unknown>>,
  creating: boolean
): string | null {
  if (!creating) return null
  const codeError = describeApiGovernanceCodeError(readString(payload.code))
  if (codeError !== null) return codeError
  const actionError = describeApiGovernanceCodeError(readString(payload.action))
  if (actionError !== null) return actionError
  if (!isOneOf(API_OPERATIONS, readString(payload.operation))) {
    return '数据操作类型只能选择列表、详情、新增、修改、删除、导出或批量。'
  }
  if (!API_VERSION_PATTERN.test(readString(payload.api_version))) {
    return '接口版本须为 1–32 位字母、数字、点、下划线或短横线。'
  }
  if (!AUDIENCE_PATTERN.test(readString(payload.audience))) {
    return '接口受众格式不正确，请填写服务约定的 audience，不要填网址。'
  }
  const requiredScope = readString(payload.required_scope)
  if (requiredScope !== '' && !SCOPE_PATTERN.test(requiredScope)) {
    return 'OAuth Scope 格式不正确。'
  }
  if (!isOneOf(RISK_LEVELS, readString(payload.risk_level))) {
    return '风险等级只能选择低、中、高或关键。'
  }
  return null
}

export function describeBusinessActionPayloadError(
  payload: Readonly<Record<string, unknown>>,
  creating: boolean
): string | null {
  if (creating) {
    const codeError = describeApiGovernanceCodeError(readString(payload.code))
    if (codeError !== null) return codeError
  }
  if (readString(payload.name) === '') {
    return '请填写业务动作中文名称。'
  }
  return null
}

export function describeRouteBindingPayloadError(
  payload: Readonly<Record<string, unknown>>,
  creating: boolean
): string | null {
  if (!creating) return null
  const method = readString(payload.http_method).toUpperCase()
  if (!isOneOf(HTTP_METHODS, method)) {
    return '请求方法只能选择 GET、POST、PUT、PATCH 或 DELETE。'
  }
  const route = readString(payload.route_template)
  if (
    route === '' ||
    route[0] !== '/' ||
    route.includes('?') ||
    route.includes('#') ||
    route.includes('//')
  ) {
    return '路由模板须以 / 开头，不含域名、查询参数、片段或连续斜杠。'
  }
  const source = readString(payload.source) || 'manual'
  if (!isOneOf(ROUTE_SOURCES, source)) {
    return '路由来源只能是手工登记、OpenAPI 导入或路由扫描。'
  }
  return null
}

export interface SandIamOnboardingPreview {
  readonly dryRun: true
  readonly canApply: boolean
  readonly operationId: string
  readonly previewHash: string
  readonly organizationId: number | null
  readonly applicationId: number | null
  readonly changeCount: number
  readonly changes: readonly SandIamOnboardingChange[]
}

export interface SandIamOnboardingChange {
  readonly objectType: string
  readonly objectKey: string
  readonly operation: string
}

/**
 * 只接受 onboarding preview 的 dry-run 字段；缺少 preview_hash 时禁止进入 apply。
 */
export function parseOnboardingPreview(value: unknown): SandIamOnboardingPreview | null {
  const record = unwrapData(value)
  if (record === null) return null
  if (record.dry_run !== true) return null
  const previewHash = readString(record.preview_hash)
  const operationId = readString(record.operation_id)
  if (previewHash === '' || operationId === '') return null
  const changes = Array.isArray(record.changes)
    ? record.changes
        .map((item) => parseOnboardingChange(item))
        .filter((item): item is SandIamOnboardingChange => item !== null)
    : []
  return {
    dryRun: true,
    canApply: record.valid !== false,
    operationId,
    previewHash,
    organizationId: readPositiveInt(record.organization_id),
    applicationId: readPositiveInt(record.application_id),
    changeCount: changes.length,
    changes
  }
}

/**
 * 路由清单预检沿用同一展示 DTO，但对象键来自请求方法与路由模板。
 */
export function parseRouteManifestPreview(value: unknown): SandIamOnboardingPreview | null {
  const record = unwrapData(value)
  if (record === null || record.dry_run !== true) return null
  const previewHash = readString(record.preview_hash)
  const operationId = readString(record.operation_id)
  if (!/^[a-f0-9]{64}$/.test(previewHash) || operationId === '') return null
  const candidates = [
    ...(Array.isArray(record.changes) ? record.changes : []),
    ...(Array.isArray(record.problems) ? record.problems : [])
  ]
  const changes = candidates
    .map((item) => parseRouteManifestChange(item))
    .filter((item): item is SandIamOnboardingChange => item !== null)
  return {
    dryRun: true,
    canApply: record.valid === true,
    operationId,
    previewHash,
    organizationId: readPositiveInt(record.organization_id),
    applicationId: readPositiveInt(record.application_id),
    changeCount: changes.length,
    changes
  }
}

function parseRouteManifestChange(value: unknown): SandIamOnboardingChange | null {
  if (!isRecord(value)) return null
  const method = readString(value.method)
  const routeTemplate = readString(value.route_template)
  const operation = readString(value.operation)
  if (method === '' || routeTemplate === '' || operation === '') return null
  return { objectType: 'route', objectKey: `${method} ${routeTemplate}`, operation }
}

function parseOnboardingChange(value: unknown): SandIamOnboardingChange | null {
  if (!isRecord(value)) return null
  const objectType = readString(value.object_type)
  const objectKey = readString(value.object_key)
  const operation = readString(value.operation)
  if (objectType === '' || operation === '') return null
  return { objectType, objectKey, operation }
}

export interface SandIamPolicySimulation {
  readonly requestId: string
  readonly allowed: boolean
  readonly code: string
  readonly finalReason: string
  readonly missingContext: readonly string[]
  readonly matchedCount: number
}

/**
 * 只回显 PolicyAuthorizer::simulate 已返回的字段，不在本地重算 allowed。
 */
export function parsePolicySimulation(value: unknown): SandIamPolicySimulation | null {
  const record = unwrapData(value)
  if (record === null || typeof record.allowed !== 'boolean') return null
  const missing = Array.isArray(record.missing_context)
    ? record.missing_context.filter((item): item is string => typeof item === 'string')
    : []
  const matched = Array.isArray(record.matched_rules) ? record.matched_rules.length : 0
  return {
    requestId: readString(record.request_id),
    allowed: record.allowed,
    code: readString(record.code) || (record.allowed ? 'allowed' : 'SAND_IAM_POLICY_DENIED'),
    finalReason: readString(record.final_reason) || '后端未给出原因摘要',
    missingContext: missing,
    matchedCount: matched
  }
}

export interface SandIamAuthorizationDecision {
  readonly allowed: boolean
  readonly code: string
  readonly apiCode: string
  readonly action: string
  readonly resourceCode: string
  readonly operation: string
}

/**
 * 只回显 /authorization/decide 的后端决策，前端不得补算 allow/deny。
 */
export function parseAuthorizationDecision(value: unknown): SandIamAuthorizationDecision | null {
  const record = unwrapData(value)
  if (record === null || typeof record.allowed !== 'boolean') return null
  return {
    allowed: record.allowed,
    code: readString(record.code),
    apiCode: readString(record.api_code),
    action: readString(record.action),
    resourceCode: readString(record.resource_code),
    operation: readString(record.operation)
  }
}

export interface SandIamOidcSigningStatus {
  readonly ownerScope: string
  readonly activeCount: number
  readonly legacyDeploymentKey: boolean
  readonly activeKid: string | null
  readonly activeState: string | null
}

/**
 * 签发密钥状态是 issuer 级摘要，不展示私钥或 JWK 原文。
 */
export function parseOidcSigningStatus(value: unknown): SandIamOidcSigningStatus | null {
  const record = unwrapData(value)
  if (record === null || typeof record.active_count !== 'number') return null
  const active = isRecord(record.active_key) ? record.active_key : null
  return {
    ownerScope: readString(record.owner_scope) || 'issuer',
    activeCount: record.active_count,
    legacyDeploymentKey: record.legacy_deployment_key === true,
    activeKid: active === null ? null : readString(active.kid) || null,
    activeState: active === null ? null : readString(active.state) || null
  }
}

/**
 * 同一页面接受完整接入清单和独立路由清单，并在提交时分流到各自的预检/应用接口。
 */
export function describeOnboardingManifestError(value: unknown): string | null {
  if (!isRecord(value)) {
    return '接入清单内容必须是对象格式。'
  }
  const format = readString(value.format)
  if (format === SAND_IAM_ROUTE_SYNC_FORMAT) {
    if (
      readString(value.organization_code) === '' ||
      readString(value.application_code) === '' ||
      readString(value.environment_code) === '' ||
      !Array.isArray(value.routes)
    ) {
      return '路由清单必须包含客户主体、应用、环境代码和 routes 列表。'
    }
    return null
  }
  if (format !== SAND_IAM_ONBOARDING_FORMAT) {
    return '接入清单版本不受支持。请向开发团队索取当前版本的清单。'
  }
  if (readString(value.operation_id) === '') {
    return '接入清单缺少唯一操作标识。请向开发团队索取完整清单。'
  }
  if (!isRecord(value.organization) || readString(value.organization.code) === '') {
    return '接入清单中的客户主体信息不完整，且系统不会自动创建客户主体。'
  }
  if (!Array.isArray(value.routes)) {
    return '接入清单中的路由列表不完整。请让开发团队补齐后重新导入。'
  }
  return null
}

/**
 * 摘要数组字段：默认列表只报数量，不展开完整 URL 或 JSON。
 */
export function summarizeStringList(value: unknown, unit: string): string {
  const items = readStringArray(value)
  if (items === null) return '—'
  if (items.length === 0) return `没有${unit}`
  return `${String(items.length)} 个${unit}`
}

function readPositiveInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function unwrapData(value: unknown): Record<string, unknown> | null {
  if (!isRecord(value)) return null
  if (isRecord(value.data)) return value.data
  return value
}
