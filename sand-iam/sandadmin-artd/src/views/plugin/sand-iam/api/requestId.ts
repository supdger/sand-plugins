/**
 * 只用于聚合一轮验收证据，不得当作授权输入、幂等键或 X-Request-Id。
 */
export function createSandIamAcceptanceRunId(): string {
  return `accept-${createSandIamRequestId()}`
}

export function createSandIamRequestId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `sand-iam-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}
