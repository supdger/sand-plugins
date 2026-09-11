/**
 * 安全告警只消费 index/read 已返回字段。fingerprint、原始 IP 即使误回也不进入行对象。
 */

export interface SandIamSecurityAlertRow {
  readonly id: number
  readonly organization_id: number
  readonly application_id: number | null
  readonly rule_code: string
  readonly severity: string
  readonly occurrence_count: number
  readonly first_seen_time: string | null
  readonly last_seen_time: string | null
  readonly status: string
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && 'data' in value ? value.data : value
}

/**
 * 单行告警只保留等级、规则、次数和时间；fingerprint 即使误回也丢弃。
 */
export function parseSecurityAlertRow(value: unknown): SandIamSecurityAlertRow | null {
  if (!isRecord(value)) return null
  const id = value.id
  const organizationId = value.organization_id
  const ruleCode = value.rule_code
  const severity = value.severity
  const status = value.status
  const count = value.occurrence_count
  if (
    typeof id !== 'number' ||
    !Number.isInteger(id) ||
    id <= 0 ||
    typeof organizationId !== 'number' ||
    typeof ruleCode !== 'string' ||
    typeof severity !== 'string' ||
    typeof status !== 'string' ||
    typeof count !== 'number'
  ) {
    return null
  }
  return {
    id,
    organization_id: organizationId,
    application_id: typeof value.application_id === 'number' ? value.application_id : null,
    rule_code: ruleCode,
    severity,
    occurrence_count: count,
    first_seen_time: typeof value.first_seen_time === 'string' ? value.first_seen_time : null,
    last_seen_time: typeof value.last_seen_time === 'string' ? value.last_seen_time : null,
    status
  }
}

/**
 * 兼容宿主解包后的分页或数组；fingerprint 不进入行对象。
 */
export function parseSecurityAlertRows(value: unknown): SandIamSecurityAlertRow[] {
  const payload = unwrap(value)
  const rows = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.data)
      ? payload.data
      : []
  return rows
    .map((item) => parseSecurityAlertRow(item))
    .filter((item): item is SandIamSecurityAlertRow => item !== null)
}

/**
 * 告警状态用中文：待处理 / 已确认 / 已处理。
 */
export function securityAlertStatusLabel(status: string): string {
  if (status === 'open') return '待处理'
  if (status === 'acknowledged') return '已确认'
  if (status === 'resolved') return '已处理'
  return status
}

/**
 * 告警等级用中文，不直接展示 low/medium/high/critical。
 */
export function securityAlertSeverityLabel(severity: string): string {
  if (severity === 'low') return '低'
  if (severity === 'medium') return '中'
  if (severity === 'high') return '高'
  if (severity === 'critical') return '关键'
  return severity
}
