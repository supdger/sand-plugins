import { isHttpError } from '@/utils/http/error'
import type { SandIamRequestError } from './types'

const STABLE_CODES = [
  'SAND_IAM_VALIDATION_ERROR',
  'SAND_IAM_RESOURCE_NOT_FOUND',
  'SAND_IAM_ORGANIZATION_ACCESS_DENIED',
  'SAND_IAM_POLICY_DENIED',
  'SAND_IAM_RESOURCE_SCOPE_DENIED',
  'SAND_IAM_AUTHENTICATION_FAILED',
  'SAND_IAM_CREDENTIAL_REVOKED',
  'SAND_IAM_CONTEXT_EXPIRED',
  'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH',
  'SAND_IAM_SERVICE_ACTION_FORBIDDEN',
  'SAND_IAM_IDEMPOTENCY_CONFLICT'
] as const

type StableCode = (typeof STABLE_CODES)[number]

const LABELS: Record<StableCode, string> = {
  SAND_IAM_VALIDATION_ERROR: '表单或筛选参数未通过校验',
  SAND_IAM_RESOURCE_NOT_FOUND: '关联对象不存在',
  SAND_IAM_ORGANIZATION_ACCESS_DENIED: '跨组织访问被拒绝',
  SAND_IAM_POLICY_DENIED: '策略拒绝本次操作',
  SAND_IAM_RESOURCE_SCOPE_DENIED: '数据范围不匹配',
  SAND_IAM_AUTHENTICATION_FAILED: '未登录或凭证无效',
  SAND_IAM_CREDENTIAL_REVOKED: '凭证已撤销或已停用',
  SAND_IAM_CONTEXT_EXPIRED: '调用上下文已过期',
  SAND_IAM_CONTEXT_AUDIENCE_MISMATCH: 'audience 与目标服务不匹配',
  SAND_IAM_SERVICE_ACTION_FORBIDDEN: '没有有效的服务授权',
  SAND_IAM_IDEMPOTENCY_CONFLICT: '幂等请求语义冲突'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readText(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function extractStableCode(text: string): StableCode | null {
  for (const code of STABLE_CODES) {
    if (text.includes(code)) return code
  }
  return null
}

export function describeSandIamError(error: unknown): SandIamRequestError {
  const http = isHttpError(error) ? error.code : null
  const body = isHttpError(error) && isRecord(error.data) ? error.data : null
  const combined = [
    error instanceof Error ? error.message : '',
    body ? readText(body.msg) : '',
    body ? readText(body.message) : '',
    body ? readText(body.code) : ''
  ]
    .filter((part) => part !== '')
    .join(' ')

  const code = extractStableCode(combined)

  if (http === 503) {
    return {
      code,
      http,
      title: '服务不可用（503）',
      detail: '运行时 signer 未配置或上游不可用。这不是空列表，也不能当作成功。'
    }
  }

  if (code !== null) {
    return {
      code,
      http,
      title: LABELS[code],
      detail: combined || LABELS[code]
    }
  }

  if (http === 401) {
    return {
      code: null,
      http,
      title: '未登录（401）',
      detail: '需要已有后台会话。不能猜测账号或伪称已验收。'
    }
  }

  if (http === 403) {
    return {
      code: null,
      http,
      title: '无权限（403）',
      detail: combined || '当前账号不能访问该组织或该操作。'
    }
  }

  if (http !== null && http >= 500) {
    return {
      code: null,
      http,
      title: `后端错误（${String(http)}）`,
      detail: '不把失败显示成空数据或全量成功。'
    }
  }

  return {
    code: null,
    http,
    title: '加载失败',
    detail: combined || '请稍后重试。空范围不得显示为全量数据。'
  }
}
