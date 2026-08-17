export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isScalar(value: unknown): boolean {
  return value === null || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
}

function assertEqualsIn(value: unknown, label: string): Record<string, unknown> {
  if (!isRecord(value)) {
    throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label} 必须是对象`)
  }
  for (const key of Object.keys(value)) {
    if (key !== 'equals' && key !== 'in') {
      throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label} 只允许 equals/in`)
    }
  }
  const equalsValue = value.equals
  if (equalsValue !== undefined) {
    if (!isRecord(equalsValue)) {
      throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label}.equals 必须是对象`)
    }
    for (const [field, fieldValue] of Object.entries(equalsValue)) {
      if (!isScalar(fieldValue)) {
        throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label}.equals.${field} 只能是标量`)
      }
    }
  }
  const inValue = value.in
  if (inValue !== undefined) {
    if (!isRecord(inValue)) {
      throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label}.in 必须是对象`)
    }
    for (const [field, fieldValue] of Object.entries(inValue)) {
      if (!Array.isArray(fieldValue) || fieldValue.some((item) => !isScalar(item))) {
        throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label}.in.${field} 必须是标量数组`)
      }
    }
  }
  return value
}

export function parseJsonObject(raw: string, label: string): Record<string, unknown> {
  const trimmed = raw.trim()
  if (trimmed === '') return {}
  let parsed: unknown
  try {
    parsed = JSON.parse(trimmed) as unknown
  } catch {
    throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label} 不是合法 JSON`)
  }
  if (!isRecord(parsed)) {
    throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label} 必须是对象`)
  }
  return parsed
}

export function parseConditionOrScope(raw: string, label: string): Record<string, unknown> {
  return assertEqualsIn(parseJsonObject(raw, label), label)
}
