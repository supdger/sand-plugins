export type ConditionScalar = string | number | boolean | null
export type ConditionValueType = 'text' | 'number' | 'boolean' | 'null'
export interface ConditionValueDraft {
  type: ConditionValueType
  text: string
}

export function conditionValueDraft(value: ConditionScalar): ConditionValueDraft {
  if (value === null) return { type: 'null', text: '' }
  if (typeof value === 'number') return { type: 'number', text: String(value) }
  if (typeof value === 'boolean') return { type: 'boolean', text: String(value) }
  return { type: 'text', text: value }
}

export function conditionScalar(draft: ConditionValueDraft): ConditionScalar {
  if (draft.type === 'text') return draft.text
  if (draft.type === 'null') return null
  if (draft.type === 'boolean') {
    if (draft.text === 'true') return true
    if (draft.text === 'false') return false
    throw new Error('请选择“是”或“否”。')
  }
  const text = draft.text.trim()
  if (!/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(text) ||
    !Number.isFinite(Number(text))) throw new Error('请填写有效数字，例如 12；不要填写单位或文字。')
  return Number(text)
}

export function isConditionScalar(value: unknown): value is ConditionScalar {
  return value === null || typeof value === 'string' || typeof value === 'boolean' ||
    (typeof value === 'number' && Number.isFinite(value))
}
