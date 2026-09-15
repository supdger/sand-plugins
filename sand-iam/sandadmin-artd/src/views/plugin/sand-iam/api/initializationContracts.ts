/**
 * 初始化只消费 preview/apply/rollback/index 已返回字段。
 * manifest 原文、内部表名和密钥即使误回也不进入展示对象。
 */

export interface SandIamInitializationChange {
  readonly object_type: string
  readonly object_key: string
  readonly operation: 'create' | 'update' | 'no_change'
  readonly object_name: string | null
}

export interface SandIamInitializationPreview {
  readonly preview_hash: string
  readonly package_hash: string
  readonly organization_id: number
  readonly application_id: number | null
  readonly changes: readonly SandIamInitializationChange[]
  readonly counts: Readonly<Record<string, number>>
  readonly warnings: readonly string[]
}

export interface SandIamInitializationRun {
  readonly id: number
  readonly organization_id: number
  readonly application_id: number | null
  readonly package_code: string
  readonly package_hash: string
  readonly state: string
  readonly applied_time: string | null
  readonly rollback_time: string | null
}

/**
 * 草稿列表刻意不含 manifest；只有通过 draft-read 的同范围读取才会带回内容。
 */
export interface SandIamInitializationDraft {
  readonly id: number
  readonly organization_id: number
  readonly application_id: number | null
  readonly package_code: string
  readonly manifest_hash: string
  readonly revision: number
  readonly status: 1 | 2
  readonly disabled_time: string | null
  readonly create_time: string | null
  readonly update_time: string | null
}

export interface SandIamInitializationDraftDetail extends SandIamInitializationDraft {
  readonly manifest: Readonly<Record<string, unknown>>
}

export interface SandIamInitializationDraftMutation {
  readonly draft_id: number
  readonly revision: number
  readonly status: 1 | 2
  readonly manifest_hash: string
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && 'data' in value ? value.data : value
}

export function parseInitializationPagination(value: unknown, fallbackTotal: number): { total: number } {
  const payload = isRecord(value) && isRecord(value.data) ? value.data : value
  const total = isRecord(payload) ? payload.total : null
  return { total: typeof total === 'number' && Number.isInteger(total) && total >= 0 ? total : fallbackTotal }
}

function objectTypeLabel(type: string): string {
  if (type === 'application') return '接入应用'
  if (type === 'role') return '角色'
  if (type === 'user_type') return '用户类型'
  if (type === 'resource') return '业务资源'
  if (type === 'application_business_action') return '应用业务动作'
  if (type === 'identity_provider') return '身份源'
  if (type === 'policy') return '授权策略'
  return type
}

function operationLabel(operation: string): string {
  if (operation === 'create') return '新增'
  if (operation === 'update') return '修改'
  if (operation === 'no_change') return '不变'
  return operation
}

function readName(value: unknown): string | null {
  if (!isRecord(value) || typeof value.name !== 'string' || value.name.trim() === '') {
    return null
  }
  return value.name
}

/**
 * 预检差异只保留对象类型、标识、操作和中文名称；before/after 原文留给详情。
 */
export function parseInitializationPreview(value: unknown): SandIamInitializationPreview | null {
  const payload = unwrap(value)
  if (!isRecord(payload)) return null
  const previewHash = payload.preview_hash
  const packageHash = payload.package_hash
  const organizationId = payload.organization_id
  if (
    typeof previewHash !== 'string' ||
    previewHash === '' ||
    typeof packageHash !== 'string' ||
    typeof organizationId !== 'number'
  ) {
    return null
  }
  const rawChanges = Array.isArray(payload.changes) ? payload.changes : []
  const changes: SandIamInitializationChange[] = []
  for (const item of rawChanges) {
    if (
      !isRecord(item) ||
      typeof item.object_type !== 'string' ||
      typeof item.object_key !== 'string'
    ) {
      continue
    }
    const operation = item.operation
    if (operation !== 'create' && operation !== 'update' && operation !== 'no_change') {
      continue
    }
    changes.push({
      object_type: item.object_type,
      object_key: item.object_key,
      operation,
      object_name: readName(item.after) ?? readName(item.before)
    })
  }
  const counts = isRecord(payload.counts) ? payload.counts : {}
  const normalizedCounts: Record<string, number> = {}
  for (const [key, count] of Object.entries(counts)) {
    if (typeof count === 'number') normalizedCounts[key] = count
  }
  const warnings = Array.isArray(payload.warnings)
    ? payload.warnings.filter((item): item is string => typeof item === 'string')
    : []
  return {
    preview_hash: previewHash,
    package_hash: packageHash,
    organization_id: organizationId,
    application_id: typeof payload.application_id === 'number' ? payload.application_id : null,
    changes,
    counts: normalizedCounts,
    warnings
  }
}

/**
 * 运行记录保留回滚所需 package_hash，但不把 manifest 或内部表名带进行对象。
 */
export function parseInitializationRuns(value: unknown): SandIamInitializationRun[] {
  const payload = unwrap(value)
  const rows = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.data)
      ? payload.data
      : []
  return rows
    .map((item) => {
      if (!isRecord(item)) return null
      const id = item.id
      const organizationId = item.organization_id
      const packageCode = item.package_code
      const packageHash = item.package_hash
      const state = item.state
      if (
        typeof id !== 'number' ||
        typeof organizationId !== 'number' ||
        typeof packageCode !== 'string' ||
        typeof packageHash !== 'string' ||
        typeof state !== 'string'
      ) {
        return null
      }
      return {
        id,
        organization_id: organizationId,
        application_id: typeof item.application_id === 'number' ? item.application_id : null,
        package_code: packageCode,
        package_hash: packageHash,
        state,
        applied_time: typeof item.applied_time === 'string' ? item.applied_time : null,
        rollback_time: typeof item.rollback_time === 'string' ? item.rollback_time : null
      }
    })
    .filter((item): item is SandIamInitializationRun => item !== null)
}

function parseInitializationDraftRow(value: unknown): SandIamInitializationDraft | null {
  if (!isRecord(value)) return null
  const id = value.id
  const organizationId = value.organization_id
  const packageCode = value.package_code
  const manifestHash = value.manifest_hash
  const revision = value.revision
  const status = value.status
  if (
    typeof id !== 'number' ||
    typeof organizationId !== 'number' ||
    typeof packageCode !== 'string' ||
    typeof manifestHash !== 'string' ||
    typeof revision !== 'number' ||
    !Number.isInteger(revision) ||
    revision < 1 ||
    (status !== 1 && status !== 2)
  ) {
    return null
  }
  return {
    id,
    organization_id: organizationId,
    application_id: typeof value.application_id === 'number' ? value.application_id : null,
    package_code: packageCode,
    manifest_hash: manifestHash,
    revision,
    status,
    disabled_time: typeof value.disabled_time === 'string' ? value.disabled_time : null,
    create_time: typeof value.create_time === 'string' ? value.create_time : null,
    update_time: typeof value.update_time === 'string' ? value.update_time : null
  }
}

/** 草稿列表只取可安全展示的摘要字段，不接受意外回传的 manifest。 */
export function parseInitializationDrafts(value: unknown): SandIamInitializationDraft[] {
  const payload = unwrap(value)
  const rows = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.data)
      ? payload.data
      : []
  return rows
    .map((item) => parseInitializationDraftRow(item))
    .filter((item): item is SandIamInitializationDraft => item !== null)
}

/** 只有 draft-read 允许把已由后端校验为无密的 manifest 送回编辑器。 */
export function parseInitializationDraftDetail(
  value: unknown
): SandIamInitializationDraftDetail | null {
  const payload = unwrap(value)
  const draft = parseInitializationDraftRow(payload)
  if (draft === null || !isRecord(payload) || !isRecord(payload.manifest)) return null
  return { ...draft, manifest: payload.manifest }
}

/** save/update/disable 的冻结响应只用于刷新草稿状态和 revision。 */
export function parseInitializationDraftMutation(
  value: unknown
): SandIamInitializationDraftMutation | null {
  const payload = unwrap(value)
  if (!isRecord(payload)) return null
  const draftId = payload.draft_id
  const revision = payload.revision
  const status = payload.status
  const manifestHash = payload.manifest_hash
  if (
    typeof draftId !== 'number' ||
    typeof revision !== 'number' ||
    !Number.isInteger(revision) ||
    revision < 1 ||
    (status !== 1 && status !== 2) ||
    typeof manifestHash !== 'string'
  ) {
    return null
  }
  return { draft_id: draftId, revision, status, manifest_hash: manifestHash }
}

/**
 * 预检差异用对象类型中文名 + 名称/标识，不展示 before/after 原文。
 */
export function initializationChangeLabel(change: SandIamInitializationChange): string {
  const name = change.object_name === null ? change.object_key : change.object_name
  return `${operationLabel(change.operation)} · ${objectTypeLabel(change.object_type)} · ${name}`
}

/**
 * 初始化状态：已应用 / 已回滚。
 */
export function initializationStateLabel(state: string): string {
  if (state === 'applied') return '已应用'
  if (state === 'rolled_back') return '已回滚'
  return state
}

export function initializationDraftStatusLabel(status: 1 | 2): string {
  return status === 1 ? '可继续编辑' : '已停用'
}

/**
 * 回滚确认摘要按后端冻结公式计算：sand-iam-init-rollback + 记录编号 + 包哈希。
 * 页面不展示该摘要，只在用户确认回滚后提交。
 */
export async function buildInitializationRollbackConfirmation(
  runId: number,
  packageHash: string
): Promise<string> {
  const material = `sand-iam-init-rollback\0${String(runId)}\0${packageHash}`
  const bytes = new TextEncoder().encode(material)
  const digest = await crypto.subtle.digest('SHA-256', bytes)
  return [...new Uint8Array(digest)].map((item) => item.toString(16).padStart(2, '0')).join('')
}

/**
 * 初始化包必须是 JSON 对象；敏感字段名由后端拒绝，前端只做语法检查。
 */
export function parseInitializationManifest(text: string): Record<string, unknown> {
  const parsed: unknown = JSON.parse(text)
  if (!isRecord(parsed)) {
    throw new Error('SAND_IAM_INITIALIZATION_INVALID: 初始化包内容必须是对象格式')
  }
  return parsed
}
