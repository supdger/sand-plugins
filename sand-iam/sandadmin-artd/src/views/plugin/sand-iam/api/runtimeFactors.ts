export type SandIamRuntimeFactorType = 'totp' | 'passkey'

export interface SandIamRuntimeFactor {
  readonly id: number
  readonly type: SandIamRuntimeFactorType
  readonly name: string
  readonly status: number
  readonly create_time: string
  readonly last_used_time: string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readTime(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * 只接受 MfaService::factors() 冻结字段；secret、otpauth、恢复码不得进入列表解析。
 */
export function parseSandIamRuntimeFactor(value: unknown): SandIamRuntimeFactor | null {
  if (!isRecord(value)) return null
  const id = value.id
  const type = value.type
  const name = value.name
  const status = value.status
  const createTime = readTime(value.create_time)
  if (
    typeof id !== 'number' ||
    !Number.isInteger(id) ||
    id <= 0 ||
    (type !== 'totp' && type !== 'passkey') ||
    typeof name !== 'string' ||
    name.trim() === '' ||
    typeof status !== 'number' ||
    createTime === null
  ) {
    return null
  }
  return {
    id,
    type,
    name,
    status,
    create_time: createTime,
    last_used_time: readTime(value.last_used_time)
  }
}

export function parseSandIamRuntimeFactors(value: unknown): SandIamRuntimeFactor[] {
  const list = Array.isArray(value)
    ? value
    : isRecord(value) && Array.isArray(value.data)
      ? value.data
      : null
  if (list === null) {
    throw new Error('认证方式列表返回格式不符合已冻结约定')
  }
  return list
    .map((item) => parseSandIamRuntimeFactor(item))
    .filter((item): item is SandIamRuntimeFactor => item !== null)
}

export function parseTotpStartSecret(value: unknown): {
  readonly factorId: number
  readonly secret: string
  readonly otpauthUri: string
} | null {
  if (!isRecord(value)) return null
  const factorId = value.factor_id
  const secret = value.secret
  const otpauthUri = value.otpauth_uri
  if (
    typeof factorId !== 'number' ||
    !Number.isInteger(factorId) ||
    factorId <= 0 ||
    typeof secret !== 'string' ||
    secret.trim() === '' ||
    typeof otpauthUri !== 'string' ||
    otpauthUri.trim() === ''
  ) {
    return null
  }
  return { factorId, secret, otpauthUri }
}

export function parseRecoveryCodes(value: unknown): string[] | null {
  if (!isRecord(value) || !Array.isArray(value.recovery_codes)) return null
  const codes: string[] = []
  for (const item of value.recovery_codes) {
    if (typeof item !== 'string' || item.trim() === '') return null
    codes.push(item)
  }
  return codes
}
