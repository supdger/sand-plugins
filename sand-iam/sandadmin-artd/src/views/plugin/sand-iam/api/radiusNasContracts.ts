/**
 * RADIUS 网络设备只消费 RadiusNas 列表字段和 configure 成功结果。
 * encrypted_shared_secret / secret_version 即使误回也不进入行对象。
 */

export interface SandIamRadiusNasRow {
  readonly id: number
  readonly application_id: number
  readonly name: string
  readonly source_cidr: string
  readonly secret_configured: boolean
  readonly accounting_enabled: boolean
  readonly status: number
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && 'data' in value ? value.data : value
}

function readBoolean(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true'
}

/**
 * 列表只保留设备名、应用、CIDR、密钥是否已配置和状态。
 */
export function parseRadiusNasRow(value: unknown): SandIamRadiusNasRow | null {
  if (!isRecord(value)) return null
  const id = value.id
  const applicationId = value.application_id
  const name = value.name
  const cidr = value.source_cidr
  const status = value.status
  if (
    typeof id !== 'number' ||
    !Number.isInteger(id) ||
    id <= 0 ||
    typeof applicationId !== 'number' ||
    !Number.isInteger(applicationId) ||
    applicationId <= 0 ||
    typeof name !== 'string' ||
    typeof cidr !== 'string' ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    name,
    source_cidr: cidr,
    secret_configured: readBoolean(value.secret_configured),
    accounting_enabled: readBoolean(value.accounting_enabled),
    status
  }
}

/**
 * 兼容宿主解包后的分页或数组；密文和版本不进入行对象。
 */
export function parseRadiusNasRows(value: unknown): SandIamRadiusNasRow[] {
  const payload = unwrap(value)
  const rows = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.data)
      ? payload.data
      : []
  return rows
    .map((item) => parseRadiusNasRow(item))
    .filter((item): item is SandIamRadiusNasRow => item !== null)
}

/**
 * configure 成功只确认 secret_configured；共享密钥不会回显。
 */
export function parseRadiusSecretConfigured(value: unknown): boolean {
  const payload = unwrap(value)
  return isRecord(payload) && readBoolean(payload.secret_configured)
}
