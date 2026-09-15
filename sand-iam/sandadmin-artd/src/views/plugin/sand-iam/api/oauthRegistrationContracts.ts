/**
 * 动态注册令牌只消费 OAuthRegistrationTokenController::safe / issue 已返回字段。
 * token、token_hash、HMAC 和最后 IP 即使误回也不进入列表行。
 */

export interface SandIamRegistrationTokenRow {
  readonly id: number
  readonly application_id: number
  readonly name: string
  readonly allowed_redirect_hosts: readonly string[]
  readonly allowed_scopes: readonly string[]
  readonly remaining_uses: number
  readonly expire_time: string | null
  readonly status: number
}

export interface SandIamRegistrationTokenIssue {
  readonly token: string
  readonly expire_time: string | null
  readonly max_uses: number | null
}

const HOST_PATTERN = /^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|127\.0\.0\.1|::1)$/
const SCOPE_PATTERN = /^[A-Za-z0-9._:-]{1,128}$/

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && 'data' in value ? value.data : value
}

function readStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.filter((item): item is string => typeof item === 'string')
}

/**
 * 列表行只保留名称、主机摘要、scope、剩余次数、到期和状态。
 */
export function parseRegistrationTokenRow(value: unknown): SandIamRegistrationTokenRow | null {
  if (!isRecord(value)) return null
  const id = value.id
  const applicationId = value.application_id
  const name = value.name
  const remaining = value.remaining_uses
  const status = value.status
  if (
    typeof id !== 'number' ||
    !Number.isInteger(id) ||
    id <= 0 ||
    typeof applicationId !== 'number' ||
    !Number.isInteger(applicationId) ||
    applicationId <= 0 ||
    typeof name !== 'string' ||
    typeof remaining !== 'number' ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    name,
    allowed_redirect_hosts: readStringArray(value.allowed_redirect_hosts),
    allowed_scopes: readStringArray(value.allowed_scopes),
    remaining_uses: remaining,
    expire_time: typeof value.expire_time === 'string' ? value.expire_time : null,
    status
  }
}

/**
 * 兼容宿主解包后的分页或数组；token 字段即使误回也不会进入行对象。
 */
export function parseRegistrationTokenRows(value: unknown): SandIamRegistrationTokenRow[] {
  const payload = unwrap(value)
  const rows = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.data)
      ? payload.data
      : []
  return rows
    .map((item) => parseRegistrationTokenRow(item))
    .filter((item): item is SandIamRegistrationTokenRow => item !== null)
}

export function parseRegistrationTokenPage(value: unknown) {
  const payload = isRecord(value) && isRecord(value.data) ? value.data : value
  const rows = parseRegistrationTokenRows(value)
  const total = isRecord(payload) && typeof payload.total === 'number' &&
    Number.isInteger(payload.total) && payload.total >= 0 ? payload.total : rows.length
  return { rows, total }
}

/**
 * 签发成功才取出明文 token；缺失时返回 null，调用方只能引导重新签发。
 */
export function parseRegistrationTokenIssue(value: unknown): SandIamRegistrationTokenIssue | null {
  const payload = unwrap(value)
  if (!isRecord(payload) || typeof payload.token !== 'string' || payload.token === '') {
    return null
  }
  return {
    token: payload.token,
    expire_time: typeof payload.expire_time === 'string' ? payload.expire_time : null,
    max_uses: typeof payload.max_uses === 'number' ? payload.max_uses : null
  }
}

/**
 * 主机必须是 hostname 或回环地址，不能是完整 URL 或通配符。
 */
export function describeRegistrationTokenIssueError(
  name: string,
  hostsText: string,
  scopesText: string,
  ttlHours: number,
  maxUses: number
): string | null {
  if (name.trim() === '' || name.trim().length > 128) {
    return 'SAND_IAM_DCR_TOKEN_NAME_INVALID: 请填写 1 至 128 字的用途名称。'
  }
  const hosts = hostsText
    .split(/[\n,]+/)
    .map((item) => item.trim().toLowerCase())
    .filter((item) => item !== '')
  if (hosts.length === 0 || hosts.length > 50 || hosts.some((host) => !HOST_PATTERN.test(host))) {
    return 'SAND_IAM_DCR_TOKEN_POLICY_INVALID: 回调主机必须是主机名或 127.0.0.1/::1，不能写完整 URL 或通配符。'
  }
  const scopes = scopesText
    .split(/[\s,]+/)
    .map((item) => item.trim())
    .filter((item) => item !== '')
  if (
    scopes.length === 0 ||
    scopes.length > 30 ||
    scopes.some((scope) => !SCOPE_PATTERN.test(scope))
  ) {
    return 'SAND_IAM_DCR_TOKEN_POLICY_INVALID: 允许范围必须是 1–128 位协议范围代码。'
  }
  if (!Number.isInteger(ttlHours) || ttlHours < 1 || ttlHours > 168) {
    return 'SAND_IAM_DCR_TOKEN_POLICY_INVALID: 有效期必须是 1–168 小时。'
  }
  if (!Number.isInteger(maxUses) || maxUses < 1 || maxUses > 1000) {
    return 'SAND_IAM_DCR_TOKEN_POLICY_INVALID: 最大使用次数必须是 1–1000。'
  }
  return null
}

/**
 * 签发入参：每行或逗号分隔的主机，去重并小写。
 */
export function parseRegistrationHosts(hostsText: string): string[] {
  return [
    ...new Set(
      hostsText
        .split(/[\n,]+/)
        .map((item) => item.trim().toLowerCase())
        .filter((item) => item !== '')
    )
  ]
}

/**
 * 签发入参：空格或逗号分隔的协议范围代码。
 */
export function parseRegistrationScopes(scopesText: string): string[] {
  return [
    ...new Set(
      scopesText
        .split(/[\s,]+/)
        .map((item) => item.trim())
        .filter((item) => item !== '')
    )
  ]
}

/**
 * 默认列表只保留主机摘要，不展示完整回调 URL。
 */
export function summarizeHosts(hosts: readonly string[]): string {
  if (hosts.length === 0) return '未设置主机'
  if (hosts.length === 1) return hosts[0] ?? '未设置主机'
  return `${hosts[0] ?? ''} 等 ${String(hosts.length)} 个主机`
}

/**
 * 默认列表只保留前两个范围代码。
 */
export function summarizeScopes(scopes: readonly string[]): string {
  if (scopes.length === 0) return '未设置范围'
  if (scopes.length <= 2) return scopes.join('、')
  return `${scopes.slice(0, 2).join('、')} 等 ${String(scopes.length)} 项`
}

/**
 * 令牌状态：1 正常，其它视为已停用，不展示 status=1/2。
 */
export function registrationTokenStatusLabel(status: number): string {
  return status === 1 ? '正常' : '已停用'
}
