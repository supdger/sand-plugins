/**
 * IdentityImportController / IdentityImportService 已冻结的预检、任务和行摘要。
 * 不解析 encrypted_payload、完整邮箱/手机号或 content_digest 到默认列表。
 */

export const SAND_IAM_IMPORT_TEMPLATE_HEADERS = [
  '用户名',
  '显示名称',
  '邮箱',
  '手机号',
  '账号状态',
  '用户组代码'
] as const

export type SandIamImportMode = 'create' | 'update'
export type SandIamImportJobState = 'previewed' | 'running' | 'completed' | 'partial' | 'failed'
export type SandIamImportRowState =
  | 'valid'
  | 'invalid'
  | 'processing'
  | 'succeeded'
  | 'succeeded_with_warning'
  | 'failed'

export interface SandIamImportPreview {
  readonly id: number
  readonly digest: string
  readonly total: number
  readonly valid: number
  readonly invalid: number
}

export interface SandIamImportJobRow {
  readonly id: number
  readonly application_id: number
  readonly original_name: string
  readonly digest: string
  readonly mode: SandIamImportMode
  readonly state: SandIamImportJobState
  readonly total_count: number
  readonly valid_count: number
  readonly invalid_count: number
  readonly success_count: number
  readonly warning_count: number
  readonly failure_count: number
}

export interface SandIamImportRowSummary {
  readonly row_number: number
  readonly display_name: string
  readonly target_masked: string
  readonly account_state: string
  readonly group_names: readonly string[]
}

export interface SandIamImportRow {
  readonly id: number
  readonly row_number: number
  readonly summary: SandIamImportRowSummary | null
  readonly validation_errors: readonly string[]
  readonly state: SandIamImportRowState
  readonly error_code: string | null
}

export interface SandIamImportConfirmResult {
  readonly state: string
  readonly success: number
  readonly warning: number
  readonly failure: number
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

function unwrap(value: unknown): unknown {
  return isRecord(value) && 'data' in value ? value.data : value
}

function readPositiveInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function readCount(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null
}

export function importModeLabel(mode: SandIamImportMode): string {
  return mode === 'create' ? '新增邀请' : '更新已有用户'
}

export function importJobStateLabel(state: SandIamImportJobState): string {
  if (state === 'previewed') return '已预检'
  if (state === 'running') return '执行中'
  if (state === 'completed') return '已完成'
  if (state === 'partial') return '部分失败'
  return '失败'
}

export function importRowStateLabel(state: SandIamImportRowState): string {
  if (state === 'valid') return '可执行'
  if (state === 'invalid') return '错误'
  if (state === 'processing') return '处理中'
  if (state === 'succeeded') return '已执行'
  if (state === 'succeeded_with_warning') return '已执行有警告'
  return '失败'
}

export function importAccountStateLabel(state: string): string {
  if (state === 'active') return '正常'
  if (state === 'disabled') return '已停用'
  return '未提供账号状态'
}

/**
 * 有错误行时禁止确认；后端也会拒绝 SAND_IAM_IMPORT_HAS_INVALID_ROWS。
 */
export function importJobCanConfirm(job: SandIamImportJobRow): boolean {
  return (
    job.invalid_count === 0 &&
    (job.state === 'previewed' || job.state === 'partial' || job.state === 'failed')
  )
}

export function parseSandIamImportPreview(value: unknown): SandIamImportPreview | null {
  const payload = unwrap(value)
  if (!isRecord(payload)) return null
  const id = readPositiveInt(payload.id)
  const digest = payload.digest
  const total = readCount(payload.total)
  const valid = readCount(payload.valid)
  const invalid = readCount(payload.invalid)
  if (
    id === null ||
    typeof digest !== 'string' ||
    digest === '' ||
    total === null ||
    valid === null ||
    invalid === null
  ) {
    return null
  }
  return { id, digest, total, valid, invalid }
}

export function parseSandIamImportJob(value: unknown): SandIamImportJobRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const name = value.original_name
  const digest = value.content_digest
  const mode = value.mode
  const state = value.state
  const total = readCount(value.total_count)
  const valid = readCount(value.valid_count)
  const invalid = readCount(value.invalid_count)
  const success = readCount(value.success_count)
  const warning = readCount(value.warning_count)
  const failure = readCount(value.failure_count)
  if (
    id === null ||
    applicationId === null ||
    typeof name !== 'string' ||
    typeof digest !== 'string' ||
    (mode !== 'create' && mode !== 'update') ||
    (state !== 'previewed' &&
      state !== 'running' &&
      state !== 'completed' &&
      state !== 'partial' &&
      state !== 'failed') ||
    total === null ||
    valid === null ||
    invalid === null ||
    success === null ||
    warning === null ||
    failure === null
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    original_name: name,
    digest,
    mode,
    state,
    total_count: total,
    valid_count: valid,
    invalid_count: invalid,
    success_count: success,
    warning_count: warning,
    failure_count: failure
  }
}

export function parseSandIamImportJobs(value: unknown): SandIamImportJobRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamImportJob(item))
    .filter((item): item is SandIamImportJobRow => item !== null)
}

function parseSummary(value: unknown): SandIamImportRowSummary | null {
  if (!isRecord(value)) return null
  const rowNumber = readPositiveInt(value.row_number)
  if (rowNumber === null) return null
  const groupNames = Array.isArray(value.group_names)
    ? value.group_names.filter((item): item is string => typeof item === 'string')
    : []
  return {
    row_number: rowNumber,
    display_name: typeof value.display_name === 'string' ? value.display_name : '',
    target_masked: typeof value.target_masked === 'string' ? value.target_masked : '',
    account_state: typeof value.account_state === 'string' ? value.account_state : '',
    group_names: groupNames
  }
}

export function parseSandIamImportRow(value: unknown): SandIamImportRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const rowNumber = readPositiveInt(value.row_number)
  const state = value.state
  if (
    id === null ||
    rowNumber === null ||
    (state !== 'valid' &&
      state !== 'invalid' &&
      state !== 'processing' &&
      state !== 'succeeded' &&
      state !== 'succeeded_with_warning' &&
      state !== 'failed')
  ) {
    return null
  }
  const errors = Array.isArray(value.validation_errors)
    ? value.validation_errors.filter((item): item is string => typeof item === 'string')
    : []
  return {
    id,
    row_number: rowNumber,
    summary: parseSummary(value.summary),
    validation_errors: errors,
    state,
    error_code: typeof value.error_code === 'string' ? value.error_code : null
  }
}

export function parseSandIamImportRows(value: unknown): SandIamImportRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamImportRow(item))
    .filter((item): item is SandIamImportRow => item !== null)
}

export function parseSandIamImportConfirm(value: unknown): SandIamImportConfirmResult | null {
  const payload = unwrap(value)
  if (!isRecord(payload)) return null
  const state = payload.state
  const success = readCount(payload.success)
  const warning = readCount(payload.warning)
  const failure = readCount(payload.failure)
  if (typeof state !== 'string' || success === null || warning === null || failure === null) {
    return null
  }
  return { state, success, warning, failure }
}

/**
 * 模板只含冻结列名，前端本地生成，不经过后端、不含真实联系方式。
 */
export function sandIamImportTemplateCsv(): string {
  return `${SAND_IAM_IMPORT_TEMPLATE_HEADERS.join(',')}\nmember-001,张三,,,正常,customer-team\n`
}
