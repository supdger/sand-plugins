export interface LogoutDelivery {
  readonly id: number
  readonly event_id: string
  readonly state: 'pending' | 'sending' | 'delivered' | 'dead'
  readonly attempt_count: number
  readonly last_error_code: string | null
  readonly update_time: string | null
  readonly status: 1 | 2
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function integer(value: unknown, minimum: number): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= minimum
}

function nullableText(value: unknown): value is string | null {
  return value === null || typeof value === 'string'
}

export function parseLogoutDelivery(value: unknown): LogoutDelivery {
  if (
    !record(value) ||
    !integer(value.id, 1) ||
    typeof value.event_id !== 'string' ||
    value.event_id === '' ||
    !['pending', 'sending', 'delivered', 'dead'].includes(String(value.state)) ||
    !integer(value.attempt_count, 0) ||
    !nullableText(value.last_error_code) ||
    !nullableText(value.update_time) ||
    (value.status !== 1 && value.status !== 2)
  )
    throw new Error('登出投递数据格式异常，请刷新后重试。')
  const state = value.state
  if (state !== 'pending' && state !== 'sending' && state !== 'delivered' && state !== 'dead') {
    throw new Error('登出投递状态无法识别。')
  }
  return {
    id: value.id,
    event_id: value.event_id,
    state,
    attempt_count: value.attempt_count,
    last_error_code: value.last_error_code,
    update_time: value.update_time,
    status: value.status
  }
}

export function parseLogoutDeliveryPage(value: unknown): {
  readonly data: LogoutDelivery[]
  readonly total: number
} {
  if (!record(value) || !Array.isArray(value.data) || !integer(value.total, 0)) {
    throw new Error('登出投递列表格式异常，请刷新后重试。')
  }
  return { data: value.data.map(parseLogoutDelivery), total: value.total }
}

export function parseLogoutRecovery(value: unknown): {
  readonly already_reissued: boolean
} {
  if (
    !record(value) ||
    !integer(value.source_delivery_id, 1) ||
    !integer(value.delivery_id, 1) ||
    typeof value.event_id !== 'string' ||
    value.event_id === '' ||
    typeof value.state !== 'string' ||
    !['pending', 'sending', 'delivered', 'dead'].includes(value.state) ||
    typeof value.already_reissued !== 'boolean'
  )
    throw new Error('恢复结果格式异常，请刷新列表确认后再操作。')
  return { already_reissued: value.already_reissued }
}

export function logoutDeliveryRecoverable(row: LogoutDelivery): boolean {
  return row.state === 'dead' && row.status === 2
}

const recoveryErrors: Readonly<Record<string, string>> = {
  SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_DISABLED: '登出通知服务未启用，请联系平台管理员。',
  SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE: '客户端、应用或客户主体不可用，请先恢复启用。',
  SAND_IAM_OIDC_BACKCHANNEL_URI_UNAVAILABLE: '未配置有效的后通道地址，请先修改客户端配置。',
  SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_FOUND: '投递记录已不存在，请刷新列表。',
  SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_RECOVERABLE: '该投递当前不可恢复，请刷新列表确认状态。',
  SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED: '原会话尚未撤销，不能创建登出恢复任务。',
  SAND_IAM_OIDC_LOGOUT_RECOVERY_CONFLICT: '恢复操作冲突，请刷新列表确认已有任务。',
  SAND_IAM_IDEMPOTENCY_CONFLICT: '请求编号冲突，请刷新列表后重新发起操作。'
}

export function logoutRecoveryError(detail: string): string {
  const code = Object.keys(recoveryErrors).find((candidate) => detail.includes(candidate))
  return code === undefined ? detail : `${code}：${recoveryErrors[code]}`
}
