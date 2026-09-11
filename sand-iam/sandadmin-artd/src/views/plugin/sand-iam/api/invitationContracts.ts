/**
 * IdentityInvitationController::safe 已冻结的列表字段。
 * 不解析 token、token_hash、密文目标或完整邮箱/手机号。
 */

export type SandIamInvitationState =
  | 'sending'
  | 'pending'
  | 'delivery_failed'
  | 'accepted'
  | 'revoked'
  | 'expired'

export interface SandIamInvitationRow {
  readonly id: number
  readonly application_id: number
  readonly target_type: 'email' | 'phone'
  readonly target_masked: string
  readonly guest_identity_name: string
  readonly initial_group_names: readonly string[]
  readonly state: SandIamInvitationState
  readonly expire_time: string | null
  readonly delivery_error_code: string | null
  readonly status: number
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

function readInvitationState(value: unknown): SandIamInvitationState | null {
  if (
    value === 'sending' ||
    value === 'pending' ||
    value === 'delivery_failed' ||
    value === 'accepted' ||
    value === 'revoked' ||
    value === 'expired'
  ) {
    return value
  }
  return null
}

export function invitationStateLabel(state: SandIamInvitationState): string {
  if (state === 'sending') return '发送中'
  if (state === 'pending') return '等待接受'
  if (state === 'delivery_failed') return '投递失败'
  if (state === 'accepted') return '已接受'
  if (state === 'revoked') return '已撤销'
  return '已过期'
}

/**
 * 重发只允许等待接受或投递失败，且后端会轮换 token，旧链接失效。
 */
export function invitationCanResend(state: SandIamInvitationState): boolean {
  return state === 'pending' || state === 'delivery_failed'
}

/**
 * 已接受、已撤销、已过期不能重放撤销。
 */
export function invitationCanRevoke(state: SandIamInvitationState): boolean {
  return state === 'sending' || state === 'pending' || state === 'delivery_failed'
}

export function parseSandIamInvitation(value: unknown): SandIamInvitationRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const targetType = value.target_type
  const targetMasked = value.target_masked
  const state = readInvitationState(value.state)
  const status = value.status
  if (
    id === null ||
    applicationId === null ||
    (targetType !== 'email' && targetType !== 'phone') ||
    typeof targetMasked !== 'string' ||
    state === null ||
    typeof status !== 'number'
  ) {
    return null
  }
  const groupNames = Array.isArray(value.initial_group_names)
    ? value.initial_group_names.filter((item): item is string => typeof item === 'string')
    : []
  return {
    id,
    application_id: applicationId,
    target_type: targetType,
    target_masked: targetMasked,
    guest_identity_name:
      typeof value.guest_identity_name === 'string' ? value.guest_identity_name : '',
    initial_group_names: groupNames,
    state,
    expire_time: typeof value.expire_time === 'string' ? value.expire_time : null,
    delivery_error_code:
      typeof value.delivery_error_code === 'string' ? value.delivery_error_code : null,
    status
  }
}

export function parseSandIamInvitations(value: unknown): SandIamInvitationRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamInvitation(item))
    .filter((item): item is SandIamInvitationRow => item !== null)
}

/**
 * 接受成功只留显示名称，不把 identity.id 当成门户会话。
 */
export function parseInvitationAcceptResult(
  value: unknown
): { readonly displayName: string } | null {
  const payload = isRecord(value) && 'data' in value ? value.data : value
  if (!isRecord(payload)) return null
  const displayName = payload.display_name
  if (typeof displayName !== 'string' || displayName.trim() === '') return null
  return { displayName: displayName.trim() }
}
