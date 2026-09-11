import { describeSandIamError, SandIamTransportError } from './errors'
import {
  parseRecoveryCodes,
  parseSandIamRuntimeFactors,
  parseTotpStartSecret,
  type SandIamRuntimeFactor
} from './runtimeFactors'
import { parseSandIamRuntimeSessions, type SandIamRuntimeSession } from './runtimeSessions'
import { createSandIamRequestId } from './requestId'
import type { SandIamRequestError } from './types'

export const SAND_IAM_RUNTIME_AUTH_PREFIX = '/api/sand-iam/v1/auth'
export { createSandIamRequestId } from './requestId'
export {
  parseSandIamRuntimeSession,
  parseSandIamRuntimeSessions,
  type SandIamRuntimeSession
} from './runtimeSessions'
export {
  parseSandIamRuntimeFactor,
  parseSandIamRuntimeFactors,
  type SandIamRuntimeFactor
} from './runtimeFactors'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readResponseMessage(body: Record<string, unknown> | null): string {
  if (body === null) return ''
  const message = body.msg ?? body.message ?? body.code
  return typeof message === 'string' ? message : ''
}

/**
 * 只发送 Authorization Bearer 与 X-Request-Id，明确不带 check_admin。
 */
async function runtimeAuthRequest(
  method: 'GET' | 'POST',
  path: string,
  accessToken: string,
  body?: Readonly<Record<string, unknown>>
): Promise<unknown> {
  const token = accessToken.trim()
  if (token === '') {
    throw new SandIamTransportError('请先填写当前应用用户的访问凭据', 401, null)
  }
  const requestId = createSandIamRequestId()
  let response: Response
  try {
    response = await fetch(`${SAND_IAM_RUNTIME_AUTH_PREFIX}${path}`, {
      method,
      credentials: 'omit',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        'X-Request-Id': requestId,
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' })
      },
      body: body === undefined ? undefined : JSON.stringify(body)
    })
  } catch {
    throw new SandIamTransportError(
      '网络不可用，请检查连接后重试；持续失败时联系平台管理员。',
      null,
      null
    )
  }
  let parsed: unknown = null
  try {
    parsed = (await response.json()) as unknown
  } catch {
    parsed = null
  }
  const record = isRecord(parsed) ? parsed : null
  if (!response.ok) {
    throw new SandIamTransportError(
      readResponseMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
      record
    )
  }
  if (record !== null && 'data' in record) {
    return {
      requestId,
      data: record.data
    }
  }
  return { requestId, data: parsed }
}

export interface SandIamRuntimeResult<T> {
  readonly requestId: string
  readonly data: T
}

export async function listSandIamRuntimeSessions(
  accessToken: string
): Promise<SandIamRuntimeResult<SandIamRuntimeSession[]>> {
  const result = await runtimeAuthRequest('GET', '/sessions', accessToken)
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('会话列表响应缺少请求编号', null, null)
  }
  try {
    return {
      requestId: result.requestId,
      data: parseSandIamRuntimeSessions(result.data)
    }
  } catch (error: unknown) {
    throw new SandIamTransportError(
      error instanceof Error ? error.message : '会话列表返回格式不符合已冻结约定',
      null,
      isRecord(result.data) ? result.data : null
    )
  }
}

export async function revokeSandIamRuntimeSession(
  accessToken: string,
  sessionId: number
): Promise<SandIamRuntimeResult<null>> {
  if (!Number.isInteger(sessionId) || sessionId <= 0) {
    throw new SandIamTransportError('SAND_IAM_VALIDATION_ERROR: 会话编号无效', 400, null)
  }
  const result = await runtimeAuthRequest('POST', '/sessions/revoke', accessToken, {
    id: sessionId
  })
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('撤销会话响应缺少请求编号', null, null)
  }
  return { requestId: result.requestId, data: null }
}

export function describeRuntimeAuthError(error: unknown): SandIamRequestError {
  return describeSandIamError(error)
}

export async function listSandIamRuntimeFactors(
  accessToken: string
): Promise<SandIamRuntimeResult<SandIamRuntimeFactor[]>> {
  const result = await runtimeAuthRequest('GET', '/mfa/factors', accessToken)
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('认证方式列表响应缺少请求编号', null, null)
  }
  try {
    return {
      requestId: result.requestId,
      data: parseSandIamRuntimeFactors(result.data)
    }
  } catch (error: unknown) {
    throw new SandIamTransportError(
      error instanceof Error ? error.message : '认证方式列表返回格式不符合已冻结约定',
      null,
      isRecord(result.data) ? result.data : null
    )
  }
}

export async function startSandIamTotp(
  accessToken: string,
  name: string,
  currentPassword: string
): Promise<
  SandIamRuntimeResult<{
    readonly factorId: number
    readonly secret: string
    readonly otpauthUri: string
  }>
> {
  const result = await runtimeAuthRequest('POST', '/mfa/totp/start', accessToken, {
    name,
    current_password: currentPassword
  })
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('添加验证器响应缺少请求编号', null, null)
  }
  const started = parseTotpStartSecret(result.data)
  if (started === null) {
    throw new SandIamTransportError('添加验证器响应缺少一次性密钥', null, null)
  }
  return { requestId: result.requestId, data: started }
}

export async function confirmSandIamTotp(
  accessToken: string,
  factorId: number,
  code: string
): Promise<SandIamRuntimeResult<string[]>> {
  const result = await runtimeAuthRequest('POST', '/mfa/totp/confirm', accessToken, {
    factor_id: factorId,
    code
  })
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('确认验证器响应缺少请求编号', null, null)
  }
  const codes = parseRecoveryCodes(result.data)
  if (codes === null) {
    throw new SandIamTransportError('确认验证器响应缺少一次性恢复码', null, null)
  }
  return { requestId: result.requestId, data: codes }
}

export async function renameSandIamRuntimeFactor(
  accessToken: string,
  factorId: number,
  type: 'totp' | 'passkey',
  name: string
): Promise<SandIamRuntimeResult<null>> {
  const result = await runtimeAuthRequest('POST', '/mfa/factors/rename', accessToken, {
    factor_id: factorId,
    type,
    name
  })
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('重命名响应缺少请求编号', null, null)
  }
  return { requestId: result.requestId, data: null }
}

export async function revokeSandIamRuntimeFactor(
  accessToken: string,
  factorId: number,
  type: 'totp' | 'passkey',
  password: string
): Promise<SandIamRuntimeResult<null>> {
  const result = await runtimeAuthRequest('POST', '/mfa/factors/revoke', accessToken, {
    factor_id: factorId,
    type,
    password
  })
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('撤销认证方式响应缺少请求编号', null, null)
  }
  return { requestId: result.requestId, data: null }
}

export async function regenerateSandIamRecoveryCodes(
  accessToken: string,
  password: string
): Promise<SandIamRuntimeResult<string[]>> {
  const result = await runtimeAuthRequest('POST', '/mfa/recovery/regenerate', accessToken, {
    password
  })
  if (!isRecord(result) || typeof result.requestId !== 'string') {
    throw new SandIamTransportError('恢复码响应缺少请求编号', null, null)
  }
  const codes = parseRecoveryCodes(result.data)
  if (codes === null) {
    throw new SandIamTransportError('恢复码响应缺少一次性明文', null, null)
  }
  return { requestId: result.requestId, data: codes }
}
