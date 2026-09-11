/**
 * A foreign-key reference is always a positive integer. Keep this separate
 * from business codes, which may legitimately be arbitrary strings.
 */
export function normalizeReferenceValue(value: unknown): number | null {
  if (typeof value === 'number') {
    return Number.isSafeInteger(value) && value > 0 ? value : null
  }
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

export function normalizeReferenceRow(value: unknown): Readonly<Record<string, unknown>> | null {
  if (!isRecord(value)) return null
  const row = value
  const id = normalizeReferenceValue(row.id)
  return id === null ? null : { ...row, id }
}

export function hasNormalizedReferenceOption(
  options: readonly Readonly<{ value: unknown }>[],
  selected: unknown
): boolean {
  const value = normalizeReferenceValue(selected)
  return value !== null && options.some((option) => normalizeReferenceValue(option.value) === value)
}

export type SandIamReferenceSelectState = 'neutral' | 'missing' | 'resolved'

export function sandIamReferenceSelectState(
  selected: unknown,
  options: readonly Readonly<{ value: unknown }>[]
): SandIamReferenceSelectState {
  if (normalizeReferenceValue(selected) === null) return 'neutral'
  return hasNormalizedReferenceOption(options, selected) ? 'resolved' : 'missing'
}

export function sandIamReferenceSelectKey(
  fieldKey: string,
  selected: unknown,
  options: readonly Readonly<{ value: unknown }>[]
): string {
  return `${fieldKey}:${sandIamReferenceSelectState(selected, options)}`
}

export function resolvedReferenceOptions<T extends Readonly<{ value: unknown }>>(
  options: readonly T[],
  selected: unknown,
  fallback: T
): readonly T[] {
  return sandIamReferenceSelectState(selected, options) === 'missing'
    ? [fallback, ...options]
    : options
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
