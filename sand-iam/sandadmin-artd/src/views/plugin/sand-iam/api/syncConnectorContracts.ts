/**
 * SyncConnectorController::safe、SyncRun 表列与 outboxPayload 已冻结。
 * 不解析 encrypted_config、encrypted_cursor、encrypted_payload、来源 ID、快照或驱动原始响应。
 */

export type SandIamSyncDirection = 'inbound' | 'outbound' | 'bidirectional'
export type SandIamSyncRunState = 'running' | 'succeeded' | 'failed'
export type SandIamSyncOutboxState = 'pending' | 'succeeded' | 'failed'
export type SandIamSyncOutboxOperation = 'create' | 'update' | 'disable' | 'delete'

export interface SandIamSyncConnectorRow {
  readonly id: number
  readonly application_id: number
  readonly code: string
  readonly name: string
  readonly direction: SandIamSyncDirection
  readonly driver_code: string
  readonly conflict_policy: string
  readonly missing_protection_hours: number
  readonly disable_threshold_percent: number
  readonly config_version: number
  readonly config_configured: boolean
  readonly cursor_configured: boolean
  readonly last_sync_time: string | null
  readonly status: number
}

export interface SandIamSyncRunRow {
  readonly start_time: string | null
  readonly finish_time: string | null
  readonly state: SandIamSyncRunState
  readonly pulled: number
  readonly pushed: number
  readonly created: number
  readonly updated: number
  readonly missing: number
  readonly disabled: number
  readonly conflict: number
  readonly error_code: string | null
}

/**
 * 对应 SyncConnectorController::outboxPayload。
 * 只保留列表与精确重试需要的字段；密文载荷即使出现在响应里也不读取。
 */
export interface SandIamSyncOutboxRow {
  readonly id: number
  readonly event_id: string
  readonly operation: SandIamSyncOutboxOperation
  readonly state: SandIamSyncOutboxState
  readonly attempt_count: number
  readonly error_code: string | null
  readonly time: string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrapList(value: unknown): unknown[] {
  if (Array.isArray(value)) return value
  if (isRecord(value) && Array.isArray(value.data)) return value.data
  if (isRecord(value) && isRecord(value.data) && Array.isArray(value.data.data)) {
    return value.data.data
  }
  return []
}

function readPositiveInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function readCount(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null
}

export function syncDirectionLabel(direction: SandIamSyncDirection): string {
  if (direction === 'inbound') return '外部写入本系统'
  if (direction === 'outbound') return '本系统写出'
  return '双向'
}

export function syncRunStateLabel(state: SandIamSyncRunState): string {
  if (state === 'running') return '执行中'
  if (state === 'succeeded') return '已完成'
  return '失败'
}

export function syncConfigLabel(configured: boolean, version: number): string {
  return configured ? `已配置（版本 ${String(version)}）` : '未配置'
}

export function parseSandIamSyncConnector(value: unknown): SandIamSyncConnectorRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const code = value.code
  const name = value.name
  const direction = value.direction
  const driver = value.driver_code
  const status = value.status
  if (
    id === null ||
    applicationId === null ||
    typeof code !== 'string' ||
    typeof name !== 'string' ||
    (direction !== 'inbound' && direction !== 'outbound' && direction !== 'bidirectional') ||
    typeof driver !== 'string' ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    code,
    name,
    direction,
    driver_code: driver,
    conflict_policy: typeof value.conflict_policy === 'string' ? value.conflict_policy : '',
    missing_protection_hours:
      typeof value.missing_protection_hours === 'number' ? value.missing_protection_hours : 0,
    disable_threshold_percent:
      typeof value.disable_threshold_percent === 'number' ? value.disable_threshold_percent : 0,
    config_version: typeof value.config_version === 'number' ? value.config_version : 0,
    config_configured: value.config_configured === true,
    cursor_configured: value.cursor_configured === true,
    last_sync_time: typeof value.last_sync_time === 'string' ? value.last_sync_time : null,
    status
  }
}

export function parseSandIamSyncConnectors(value: unknown): SandIamSyncConnectorRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamSyncConnector(item))
    .filter((item): item is SandIamSyncConnectorRow => item !== null)
}

export function parseSandIamSyncRun(value: unknown): SandIamSyncRunRow | null {
  if (!isRecord(value)) return null
  const state = value.state
  const pulled = readCount(value.pulled)
  const pushed = readCount(value.pushed)
  const created = readCount(value.created)
  const updated = readCount(value.updated)
  const missing = readCount(value.missing)
  const disabled = readCount(value.disabled)
  const conflict = readCount(value.conflict)
  if (
    (state !== 'running' && state !== 'succeeded' && state !== 'failed') ||
    pulled === null ||
    pushed === null ||
    created === null ||
    updated === null ||
    missing === null ||
    disabled === null ||
    conflict === null
  ) {
    return null
  }
  return {
    start_time: typeof value.start_time === 'string' ? value.start_time : null,
    finish_time: typeof value.finish_time === 'string' ? value.finish_time : null,
    state,
    pulled,
    pushed,
    created,
    updated,
    missing,
    disabled,
    conflict,
    error_code: typeof value.error_code === 'string' ? value.error_code : null
  }
}

export function parseSandIamSyncRuns(value: unknown): SandIamSyncRunRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamSyncRun(item))
    .filter((item): item is SandIamSyncRunRow => item !== null)
}

/** 两类同步历史共用宿主分页元数据；行解析仍各自保留字段白名单。 */
export function parseSandIamSyncPagination(value: unknown): {
  total: number
  currentPage: number
  pageSize: number
} {
  const page = isRecord(value) && isRecord(value.data) ? value.data : value
  return {
    total: isRecord(page) ? readCount(page.total) ?? unwrapList(value).length : unwrapList(value).length,
    currentPage: isRecord(page) ? readPositiveInt(page.current_page) ?? 1 : 1,
    pageSize: isRecord(page) ? readPositiveInt(page.per_page) ?? 20 : 20
  }
}

export function syncOutboxOperationLabel(operation: SandIamSyncOutboxOperation): string {
  if (operation === 'create') return '新增'
  if (operation === 'update') return '更新'
  if (operation === 'disable') return '停用'
  return '删除'
}

export function syncOutboxStateLabel(state: SandIamSyncOutboxState): string {
  if (state === 'pending') return '待处理'
  if (state === 'succeeded') return '已完成'
  return '失败'
}

/**
 * 解析单条脱敏出站事件。
 * 故意不读取 encrypted_payload / payload / ciphertext，避免页面接触密文。
 */
export function parseSandIamSyncOutbox(value: unknown): SandIamSyncOutboxRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const eventId = value.event_id
  const operation = value.operation
  const state = value.state
  const attemptCount = readCount(value.attempt_count)
  if (
    id === null ||
    typeof eventId !== 'string' ||
    eventId === '' ||
    (operation !== 'create' &&
      operation !== 'update' &&
      operation !== 'disable' &&
      operation !== 'delete') ||
    (state !== 'pending' && state !== 'succeeded' && state !== 'failed') ||
    attemptCount === null
  ) {
    return null
  }
  const updateTime = typeof value.update_time === 'string' ? value.update_time : null
  const createTime = typeof value.create_time === 'string' ? value.create_time : null
  return {
    id,
    event_id: eventId,
    operation,
    state,
    attempt_count: attemptCount,
    error_code: typeof value.error_code === 'string' ? value.error_code : null,
    time: updateTime ?? createTime
  }
}

export function parseSandIamSyncOutboxRows(value: unknown): SandIamSyncOutboxRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamSyncOutbox(item))
    .filter((item): item is SandIamSyncOutboxRow => item !== null)
}
