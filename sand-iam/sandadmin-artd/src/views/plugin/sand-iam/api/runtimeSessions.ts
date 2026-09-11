export interface SandIamRuntimeSession {
  readonly id: number
  readonly create_time: string
  readonly last_used_time: string
  readonly access_expire_time: string
  readonly refresh_expire_time: string
  readonly current: boolean
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readTime(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * 只接受 HumanAuthService::sessions() 已冻结的六个字段；token 和 actor_ref 不进入解析结果。
 */
export function parseSandIamRuntimeSession(value: unknown): SandIamRuntimeSession | null {
  if (!isRecord(value)) return null
  const id = value.id
  const createTime = readTime(value.create_time)
  const lastUsedTime = readTime(value.last_used_time)
  const accessExpireTime = readTime(value.access_expire_time)
  const refreshExpireTime = readTime(value.refresh_expire_time)
  if (
    typeof id !== 'number' ||
    !Number.isInteger(id) ||
    id <= 0 ||
    createTime === null ||
    lastUsedTime === null ||
    accessExpireTime === null ||
    refreshExpireTime === null
  ) {
    return null
  }
  return {
    id,
    create_time: createTime,
    last_used_time: lastUsedTime,
    access_expire_time: accessExpireTime,
    refresh_expire_time: refreshExpireTime,
    current: value.current === true || value.current === 1
  }
}

/**
 * 兼容直接数组或 `{ data: [] }` 两种 success 包装；格式不对时抛错，避免空成功。
 */
export function parseSandIamRuntimeSessions(value: unknown): SandIamRuntimeSession[] {
  const list = Array.isArray(value)
    ? value
    : isRecord(value) && Array.isArray(value.data)
      ? value.data
      : null
  if (list === null) {
    throw new Error('会话列表返回格式不符合已冻结约定')
  }
  return list
    .map((item) => parseSandIamRuntimeSession(item))
    .filter((item): item is SandIamRuntimeSession => item !== null)
}
