/**
 * T07 已冻结：应用委派、Webhook/投递、审计导出。
 * 只解析后端已经返回的字段；不编造 application_name / webhook_name / secret。
 */

export const SAND_IAM_WEBHOOK_EVENTS: readonly {
  readonly code: string
  readonly name: string
}[] = [
  { code: 'identity.created', name: '应用用户已创建' },
  { code: 'identity.updated', name: '应用用户资料已修改' },
  { code: 'identity.enabled', name: '应用用户已启用' },
  { code: 'identity.disabled', name: '应用用户已停用' },
  { code: 'identity.deleted', name: '应用用户已删除' },
  { code: 'identity.restored', name: '应用用户已恢复' },
  { code: 'identity.login.succeeded', name: '应用用户登录成功' },
  { code: 'identity.login.failed', name: '应用用户登录失败' },
  { code: 'identity.logout.succeeded', name: '应用用户已退出' },
  { code: 'identity.profile.updated', name: '应用用户资料已更新' },
  { code: 'directory.changed', name: '身份目录配置已变化' },
  { code: 'authorization.policy.changed', name: '授权策略已变化' },
  { code: 'credential.changed', name: '调用凭证已变化' },
  { code: 'oidc.signingkey.changed', name: 'OIDC 签名密钥已变化' },
  { code: 'security.operation.denied', name: '安全操作被拒绝' },
  { code: 'security.alert.raised', name: '安全告警已产生' }
]

export interface SandIamAdminOption {
  readonly id: number
  readonly name: string
  readonly username: string
}

export interface SandIamApplicationGrantRow {
  readonly id: number
  readonly admin_user_id: number
  readonly application_id: number
  readonly admin_user_name: string
  readonly status: number
}

export interface SandIamWebhookRow {
  readonly id: number
  readonly application_id: number
  readonly code: string
  readonly name: string
  readonly url: string
  readonly event_types: readonly string[]
  readonly secret_version: number
  readonly timeout_seconds: number
  readonly max_attempts: number
  readonly status: number
}

export interface SandIamWebhookSecretResult {
  readonly available: boolean
  readonly secret: string | null
  readonly secretVersion: number | null
  readonly id: number | null
}

export interface SandIamWebhookDeliveryRow {
  readonly id: number
  readonly application_id: number
  readonly webhook_endpoint_id: number
  readonly event_id: string
  readonly event_type: string
  readonly status: number
  readonly attempt_count: number
  readonly next_attempt_time: string | null
  readonly delivered_time: string | null
  readonly response_status: number | null
  readonly last_error_code: string | null
  readonly payload: Readonly<Record<string, unknown>> | null
}

export interface SandIamAuditRow {
  readonly original_audit_id?: number
  readonly id: number
  readonly create_time: string
  readonly application_id: number | null
  readonly actor_type: string
  readonly actor_ref: string
  readonly action: string
  readonly resource_type: string
  readonly resource_id: number | null
  readonly outcome: string
  readonly request_id: string
  readonly context: Readonly<Record<string, unknown>> | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readPositiveInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function readNonNegativeInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null
}

function readString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * 兼容宿主解包一层或两层后的列表：数组、`{data:[]}`、`{data:{data:[]}}`。
 */
export function unwrapSandIamList(value: unknown): unknown[] {
  if (Array.isArray(value)) return value
  if (!isRecord(value)) return []
  if (Array.isArray(value.data)) return value.data
  if (isRecord(value.data) && Array.isArray(value.data.data)) {
    return value.data.data
  }
  return []
}

/**
 * 管理写接口可能再包一层 `data`；密钥解析必须先剥到业务对象。
 */
export function unwrapSandIamPayload(value: unknown): unknown {
  if (!isRecord(value)) return value
  if (isRecord(value.data)) return value.data
  return value
}

/**
 * 后台管理员远程选项：只接受 id / name / username，提交仍只用 id。
 */
export function parseSandIamAdminOption(value: unknown): SandIamAdminOption | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const name = readString(value.name)
  const username = readString(value.username)
  if (id === null || name === null || username === null) return null
  return { id, name, username }
}

export function parseSandIamAdminOptions(value: unknown): SandIamAdminOption[] {
  return unwrapSandIamList(value)
    .map((item) => parseSandIamAdminOption(item))
    .filter((item): item is SandIamAdminOption => item !== null)
}

/**
 * 应用委派列表：可读名称来自 append `admin_user_name`；外键只用于提交和回显。
 */
export function parseSandIamApplicationGrant(value: unknown): SandIamApplicationGrantRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const adminUserId = readPositiveInt(value.admin_user_id)
  const applicationId = readPositiveInt(value.application_id)
  const adminUserName = readString(value.admin_user_name)
  const status = value.status
  if (
    id === null ||
    adminUserId === null ||
    applicationId === null ||
    adminUserName === null ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    admin_user_id: adminUserId,
    application_id: applicationId,
    admin_user_name: adminUserName,
    status
  }
}

export function parseSandIamApplicationGrants(value: unknown): SandIamApplicationGrantRow[] {
  return unwrapSandIamList(value)
    .map((item) => parseSandIamApplicationGrant(item))
    .filter((item): item is SandIamApplicationGrantRow => item !== null)
}

/**
 * 接收地址默认列只给主机+路径摘要；查询串、用户信息和完整 URL 不进列表。
 */
export function summarizeWebhookUrl(url: string): string {
  try {
    const parsed = new URL(url)
    if (parsed.protocol !== 'https:') return '非 HTTPS 地址'
    const path = parsed.pathname === '/' ? '' : parsed.pathname
    return `${parsed.host}${path}`
  } catch {
    return '地址无效'
  }
}

/**
 * 事件代码映射 EventCatalog 中文名；未知代码原样显示，不猜测新事件。
 */
export function webhookEventLabel(code: string): string {
  return SAND_IAM_WEBHOOK_EVENTS.find((item) => item.code === code)?.name ?? code
}

export function summarizeWebhookEvents(eventTypes: readonly string[]): string {
  if (eventTypes.length === 0) return '未订阅事件'
  if (eventTypes.length === 1) {
    const first = eventTypes[0]
    return first === undefined ? '未订阅事件' : webhookEventLabel(first)
  }
  const first = eventTypes[0]
  const firstLabel = first === undefined ? '事件' : webhookEventLabel(first)
  return `${firstLabel} 等 ${String(eventTypes.length)} 类`
}

export function parseSandIamWebhook(value: unknown): SandIamWebhookRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const code = readString(value.code)
  const name = readString(value.name)
  const url = readString(value.url)
  const secretVersion = readNonNegativeInt(value.secret_version)
  const timeoutSeconds = readPositiveInt(value.timeout_seconds)
  const maxAttempts = readPositiveInt(value.max_attempts)
  const status = value.status
  if (
    id === null ||
    applicationId === null ||
    code === null ||
    name === null ||
    url === null ||
    secretVersion === null ||
    timeoutSeconds === null ||
    maxAttempts === null ||
    typeof status !== 'number'
  ) {
    return null
  }
  const eventTypes = Array.isArray(value.event_types)
    ? value.event_types.filter((item): item is string => typeof item === 'string' && item !== '')
    : []
  return {
    id,
    application_id: applicationId,
    code,
    name,
    url,
    event_types: eventTypes,
    secret_version: secretVersion,
    timeout_seconds: timeoutSeconds,
    max_attempts: maxAttempts,
    status
  }
}

export function parseSandIamWebhooks(value: unknown): SandIamWebhookRow[] {
  return unwrapSandIamList(value)
    .map((item) => parseSandIamWebhook(item))
    .filter((item): item is SandIamWebhookRow => item !== null)
}

/**
 * 创建/轮换成功才有 secret；`secret_available=false` 或缺少 secret 都视为不可重放。
 * 解析结果不保留 encrypted_secret。
 */
export function parseWebhookSecretResult(value: unknown): SandIamWebhookSecretResult {
  const payload = unwrapSandIamPayload(value)
  if (!isRecord(payload)) {
    return { available: false, secret: null, secretVersion: null, id: null }
  }
  if (payload.secret_available === false) {
    return {
      available: false,
      secret: null,
      secretVersion: readNonNegativeInt(payload.secret_version),
      id: readPositiveInt(payload.id)
    }
  }
  const secret = readString(payload.secret)
  if (secret === null) {
    return {
      available: false,
      secret: null,
      secretVersion: readNonNegativeInt(payload.secret_version),
      id: readPositiveInt(payload.id)
    }
  }
  return {
    available: true,
    secret,
    secretVersion: readNonNegativeInt(payload.secret_version),
    id: readPositiveInt(payload.id)
  }
}

export function webhookDeliveryStatusLabel(status: number): string {
  if (status === 1) return '等待/已安排重试'
  if (status === 2) return '投递中'
  if (status === 3) return '已送达'
  if (status === 4) return '最终失败'
  return `未知状态 ${String(status)}`
}

/**
 * 仅等待(1)和最终失败(4)可手工重试，与 WebhookService::retry 一致。
 */
export function webhookDeliveryRetryable(status: number): boolean {
  return status === 1 || status === 4
}

export function parseSandIamWebhookDelivery(
  value: unknown,
  withPayload: boolean
): SandIamWebhookDeliveryRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const endpointId = readPositiveInt(value.webhook_endpoint_id)
  const eventId = readString(value.event_id)
  const eventType = readString(value.event_type)
  const status = value.status
  const attemptCount = readNonNegativeInt(value.attempt_count)
  if (
    id === null ||
    applicationId === null ||
    endpointId === null ||
    eventId === null ||
    eventType === null ||
    typeof status !== 'number' ||
    attemptCount === null
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    webhook_endpoint_id: endpointId,
    event_id: eventId,
    event_type: eventType,
    status,
    attempt_count: attemptCount,
    next_attempt_time: readString(value.next_attempt_time),
    delivered_time: readString(value.delivered_time),
    response_status: typeof value.response_status === 'number' ? value.response_status : null,
    last_error_code: readString(value.last_error_code),
    payload: withPayload && isRecord(value.payload) ? value.payload : null
  }
}

export function parseSandIamWebhookDeliveries(value: unknown): SandIamWebhookDeliveryRow[] {
  return unwrapSandIamList(value)
    .map((item) => parseSandIamWebhookDelivery(item, false))
    .filter((item): item is SandIamWebhookDeliveryRow => item !== null)
}

export function parseSandIamWebhookDeliveryPage(value: unknown): {
  data: SandIamWebhookDeliveryRow[]
  total: number
  currentPage: number
  pageSize: number
} {
  const page = isRecord(value) && isRecord(value.data) ? value.data : value
  const data = parseSandIamWebhookDeliveries(value)
  return {
    data,
    total: isRecord(page) ? readNonNegativeInt(page.total) ?? data.length : data.length,
    currentPage: isRecord(page) ? readPositiveInt(page.current_page) ?? 1 : 1,
    pageSize: isRecord(page) ? readPositiveInt(page.per_page) ?? 20 : 20
  }
}

export function parseSandIamAudit(value: unknown): SandIamAuditRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const createTime = readString(value.create_time)
  const actorType = readString(value.actor_type)
  const action = readString(value.action)
  const resourceType = readString(value.resource_type)
  const outcome = readString(value.outcome)
  const requestId = readString(value.request_id)
  if (
    id === null ||
    createTime === null ||
    actorType === null ||
    action === null ||
    resourceType === null ||
    outcome === null ||
    requestId === null
  ) {
    return null
  }
  return {
    id,
    create_time: createTime,
    application_id: value.application_id === null ? null : readPositiveInt(value.application_id),
    actor_type: actorType,
    actor_ref: readString(value.actor_ref) ?? '',
    action,
    resource_type: resourceType,
    resource_id: value.resource_id === null ? null : readPositiveInt(value.resource_id),
    outcome,
    request_id: requestId,
    context: isRecord(value.context) ? value.context : null
  }
}

export function parseSandIamAudits(value: unknown): SandIamAuditRow[] {
  return unwrapSandIamList(value)
    .map((item) => parseSandIamAudit(item))
    .filter((item): item is SandIamAuditRow => item !== null)
}

export function parseSandIamArchivedAudit(value: unknown): SandIamAuditRow | null {
  if (!isRecord(value)) return null
  const originalId = readPositiveInt(value.original_audit_id)
  const originalTime = readString(value.original_create_time)
  if (originalId === null || originalTime === null) return null
  const row = parseSandIamAudit({ ...value, create_time: originalTime })
  return row === null ? null : { ...row, original_audit_id: originalId }
}

export function parseSandIamArchivedAuditPage(value: unknown): ReturnType<typeof parseSandIamAuditPage> {
  const page = parseSandIamAuditPage(value)
  const payload = isRecord(value) && isRecord(value.data) ? value.data : value
  const data = unwrapSandIamList(value).map(parseSandIamArchivedAudit)
    .filter((row): row is SandIamAuditRow => row !== null)
  return {
    ...page,
    total: isRecord(payload) ? readNonNegativeInt(payload.total) ?? data.length : data.length,
    data
  }
}

export function parseSandIamAuditPage(value: unknown): {
  data: SandIamAuditRow[]
  total: number
  currentPage: number
  pageSize: number
} {
  const page = isRecord(value) && isRecord(value.data) ? value.data : value
  const data = parseSandIamAudits(value)
  return {
    data,
    total: isRecord(page) ? readNonNegativeInt(page.total) ?? data.length : data.length,
    currentPage: isRecord(page) ? readPositiveInt(page.current_page) ?? 1 : 1,
    pageSize: isRecord(page) ? readPositiveInt(page.per_page) ?? 50 : 50
  }
}

const AUDIT_ACTION_LABELS: Readonly<Record<string, string>> = {
  'identity.created': '创建了应用用户',
  'identity.updated': '修改了应用用户资料',
  'identity.enable': '启用了应用用户',
  'identity.restore': '恢复了应用用户',
  'identity.login': '应用用户登录',
  'identity.logout': '应用用户退出',
  'identity.refresh': '刷新了登录状态',
  'identity.refresh_replay': '拒绝了重复使用的登录凭据',
  'identity.session_revoke': '撤销了登录会话',
  'identity.mfa_login_start': '发起了二次验证',
  'identity.mfa_login_verify': '完成了二次验证',
  'identity.mfa_challenge_verify': '校验了二次验证',
  'identity.mfa_revoke': '移除了验证方式',
  'identity.totp_start': '开始设置验证器',
  'identity.totp_confirm': '确认了验证器设置',
  'identity.passkey_register_start': '开始设置通行密钥',
  'identity.passkey_register_finish': '完成了通行密钥设置',
  'identity.passkey_auth_start': '开始通行密钥验证',
  'identity.passkey_auth_finish': '完成了通行密钥验证',
  'identity_group.create': '创建了用户组',
  'identity_group.update': '修改了用户组',
  'identity_group.member_add': '添加了用户组成员',
  'identity_group.member_remove': '移除了用户组成员',
  'identity_group_role.grant': '为用户组授予了角色',
  'identity_group_role.revoke': '撤销了用户组角色',
  'credential.issue': '签发了调用凭据',
  'credential.rotate.revoke': '撤销了原调用凭据',
  'credential.revoke': '撤销了调用凭据',
  'context.issue': '签发了服务访问凭据',
  'context.verify': '校验了服务访问凭据',
  'service.invoke.authorize': '校验了服务调用权限',
  'service.invoke.revalidate': '重新校验了服务调用权限',
  'api_route.observe': '登记了接口路由',
  'api_route.disable': '停用了接口路由',
  'authorize.resolve': '校验了接口访问权限',
  'oauth.authorize': '校验了访问授权请求',
  'oauth.authorize_begin': '开始处理访问授权',
  'oauth.interaction_bind': '关联了访问授权会话',
  'oauth.authorize_consent': '处理了访问授权确认',
  'oauth.authorization_code': '签发了访问授权码',
  'oauth.token_exchange': '兑换了访问凭据',
  'oauth.refresh': '刷新了访问凭据',
  'oauth.refresh_replay': '拒绝了重复使用的访问凭据',
  'oauth.revoke': '撤销了访问授权',
  'oauth.logout': '退出了访问授权会话',
  'oauth.userinfo': '读取了用户资料',
  'oauth.client_credentials': '签发了服务访问凭据',
  'cas.login.begin': '开始处理单点登录',
  'cas.login.reject': '拒绝了单点登录',
  'cas.ticket.issue': '签发了单点登录凭据',
  'cas.ticket.validate': '校验了单点登录凭据',
  'admin_application_grant.create': '新增了应用管理委派',
  'admin_application_grant.update': '修改了应用管理委派',
  'admin_application_grant.disable': '停用了应用管理委派',
  'webhook.create': '创建了事件通知',
  'webhook.update': '修改了事件通知',
  'webhook.secret_rotate': '更新了事件通知密钥',
  'webhook.disable': '停用了事件通知',
  'webhook.delivery': '投递了事件通知',
  'webhook.delivery_retry': '重新投递了事件通知'
}

const AUDIT_RESOURCE_TYPE_LABELS: Readonly<Record<string, string>> = {
  identity: '应用用户',
  identity_group: '用户组',
  identity_role: '角色关系',
  identity_group_role: '用户组角色关系',
  identity_invitation: '用户邀请',
  identity_context: '服务访问凭据',
  mfa_factor: '验证方式',
  mfa_challenge: '验证请求',
  auth_challenge: '验证请求',
  policy: '授权策略',
  auth_policy: '授权规则',
  role: '角色',
  user_type: '用户类型',
  resource: '资源目录',
  api_resource: '接口',
  api_route_binding: '路由绑定',
  api_route: '接口路由',
  application: '接入应用',
  environment: '应用环境',
  organization: '客户主体',
  credential: '调用凭据',
  workload_client: '服务调用身份',
  service_grant: '服务授权',
  service: '服务目录',
  service_action: '服务动作',
  auth_session: '登录会话',
  auth_factor: '验证方式',
  oauth_client: 'OAuth 客户端',
  cas_service: '单点登录服务',
  identity_provider: '身份来源',
  application_network_policy: '应用访问规则',
  application_business_action: '应用业务动作',
  application_experience: '登录体验设置',
  admin_application_grant: '应用管理委派',
  admin_organization_grant: '客户主体管理委派',
  audit_retention_policy: '审计保留规则',
  audit_archive: '审计归档',
  radius_nas: '接入设备',
  service_invocation_operation: '服务调用记录',
  webhook_endpoint: '事件通知',
  webhook_delivery: '通知投递'
}

const AUDIT_MANAGED_RESOURCE_VERBS: Readonly<Record<string, string>> = {
  create: '创建了',
  update: '修改了',
  disable: '停用了'
}

const AUDIT_ACTOR_LABELS: Readonly<Record<string, string>> = {
  admin: '后台管理员',
  system: '系统服务',
  workload_client: '服务调用身份',
  context: '身份上下文',
  identity: '应用身份',
  application_user: '应用用户',
  oauth_client: 'OAuth 客户端',
  identity_provider: '身份来源',
  protocol: '单点登录服务',
  access_token: '访问凭据',
  scim: '身份同步服务'
}

const AUDIT_OUTCOME_LABELS: Readonly<Record<string, string>> = {
  succeeded: '成功',
  allowed: '允许',
  denied: '拒绝',
  failed: '失败'
}

export function auditActionLabel(action: string): string {
  const exact = AUDIT_ACTION_LABELS[action]
  if (exact !== undefined) return exact

  const managedResourceAction = action.match(/^([a-z_]+)\.(create|update|disable)$/)
  if (managedResourceAction !== null) {
    const [, resourceType, verb] = managedResourceAction
    const resourceLabel = AUDIT_RESOURCE_TYPE_LABELS[resourceType]
    const verbLabel = AUDIT_MANAGED_RESOURCE_VERBS[verb]
    if (resourceLabel !== undefined && verbLabel !== undefined) {
      return `${verbLabel}${resourceLabel}`
    }
  }
  if (action.startsWith('authorize.')) return '校验了访问权限'
  if (action.startsWith('scope.')) return '校验了访问范围'
  return '其他操作'
}

export function auditResourceTypeLabel(resourceType: string): string {
  return AUDIT_RESOURCE_TYPE_LABELS[resourceType] ?? '其他对象'
}

export function auditActorLabel(actorType: string): string {
  return AUDIT_ACTOR_LABELS[actorType] ?? '其他操作者'
}

export function auditOutcomeLabel(outcome: string): string {
  return AUDIT_OUTCOME_LABELS[outcome] ?? '其他结果'
}

export function auditTimeLabel(value: string): string {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  if (match === null) return '时间未知'
  const [, year, month, day, hour, minute] = match
  return `${year}年${String(Number(month))}月${String(Number(day))}日 ${hour}:${minute}`
}

const AUDIT_CONTEXT_KEYS: Readonly<Record<string, string>> = {
  fields: '变更字段',
  event_types: '订阅事件',
  secret_version: '密钥版本',
  reason: '原因',
  code: '错误码'
}

/**
 * 详情里的 context 用已冻结中文键；未知键保持原名，不把 JSON 平铺进列表。
 */
export function auditContextLabel(key: string): string {
  return AUDIT_CONTEXT_KEYS[key] ?? key
}

/**
 * 导出必须有起止时间且不超过 31 天；与 AuditLogController::applyFilters 一致。
 */
export function describeAuditExportRangeError(from: string, to: string): string | null {
  if (from.trim() === '' || to.trim() === '') {
    return 'SAND_IAM_AUDIT_EXPORT_RANGE_REQUIRED: 导出必须选择不超过 31 天的起止时间'
  }
  const fromTime = Date.parse(from.includes(' ') ? from : `${from} 00:00:00`)
  const toTime = Date.parse(to.includes(' ') ? to : `${to} 23:59:59`)
  if (Number.isNaN(fromTime) || Number.isNaN(toTime)) {
    return 'SAND_IAM_VALIDATION_ERROR: 时间格式须为 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS'
  }
  if (fromTime > toTime) {
    return 'SAND_IAM_AUDIT_TIME_RANGE_INVALID: 起始时间不能晚于结束时间'
  }
  if (toTime - fromTime > 31 * 86400 * 1000) {
    return 'SAND_IAM_AUDIT_EXPORT_RANGE_REQUIRED: 导出必须选择不超过 31 天的起止时间'
  }
  return null
}

export const SAND_IAM_AUDIT_DEFAULT_COLUMNS = [
  '发生时间',
  '接入应用',
  '操作者',
  '操作',
  '资源',
  '结果'
] as const

export const SAND_IAM_AUDIT_HIDDEN_DEFAULT_COLUMNS = [
  'id',
  'actor_ref',
  'action',
  'resource_type',
  'resource_id',
  'request_id'
] as const
