import { describeSandIamError, SandIamTransportError } from './errors'
import {
  parseAuthorizationDecision,
  type SandIamAuthorizationDecision
} from './governanceContracts'
import { createSandIamRequestId } from './requestId'
import type { SandIamRequestError } from './types'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * 应用用户访问授权判断：Bearer 应用会话或 OAuth access token，不带 check_admin。
 */
export async function decideSandIamAuthorization(
  accessToken: string,
  body: Readonly<{
    organization_code: string
    application_code: string
    api_code: string
    api_version: string
    attributes: Readonly<Record<string, unknown>>
  }>
): Promise<{
  readonly requestId: string
  readonly data: SandIamAuthorizationDecision
}> {
  const token = accessToken.trim()
  if (token === '') {
    throw new SandIamTransportError(
      'SAND_IAM_AUTHENTICATION_FAILED: 请先填写当前应用用户的访问凭据',
      401,
      null
    )
  }
  const requestId = createSandIamRequestId()
  let response: Response
  try {
    response = await fetch('/api/sand-iam/v1/authorization/decide', {
      method: 'POST',
      credentials: 'omit',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'X-Request-Id': requestId
      },
      body: JSON.stringify(body)
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
      typeof record?.msg === 'string'
        ? record.msg
        : `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
      record
    )
  }
  const decision = parseAuthorizationDecision(record?.data ?? record)
  if (decision === null) {
    throw new SandIamTransportError(
      '授权判断结果不完整，页面不会自行推断允许或拒绝。请稍后重试。',
      null,
      record
    )
  }
  return { requestId, data: decision }
}

export function describeRuntimeDecideError(error: unknown): SandIamRequestError {
  return describeSandIamError(error)
}
