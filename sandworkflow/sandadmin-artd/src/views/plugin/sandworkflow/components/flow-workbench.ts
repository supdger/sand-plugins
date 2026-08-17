export type WorkbenchTagType = 'primary' | 'success' | 'danger' | 'warning' | 'info'

import type { WorkflowRuntimeId } from '../api'

export interface FlowWorkbenchItem {
  key: WorkflowRuntimeId
  instanceId: WorkflowRuntimeId
  taskId?: WorkflowRuntimeId
  title: string
  nodeName?: string
  time?: string
  statusText: string
  statusType: WorkbenchTagType
  comment?: string
  initiatorName?: string
  summaryLines?: string[]
}

export function extractInitiatorName(source: {
  account_id?: string
  participants?: unknown
}): string | undefined {
  const participants = source.participants
  if (!Array.isArray(participants)) return undefined
  const accountId = source.account_id ? String(source.account_id) : ''
  for (const item of participants) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) continue
    const row = item as Record<string, unknown>
    const id = row.account_id != null ? String(row.account_id) : ''
    const snapshot =
      row.snapshot && typeof row.snapshot === 'object' && !Array.isArray(row.snapshot)
        ? (row.snapshot as Record<string, unknown>)
        : undefined
    const name =
      (typeof snapshot?.name === 'string' && snapshot.name.trim()) ||
      (typeof row.name === 'string' && row.name.trim()) ||
      ''
    if (!name) continue
    if (!accountId || id === accountId) return name
  }
  return undefined
}

export function extractSummaryLines(formValue: unknown, limit = 2): string[] {
  if (!formValue || typeof formValue !== 'object' || Array.isArray(formValue)) return []
  const entries = Object.entries(formValue as Record<string, unknown>)
  const lines: string[] = []
  for (const [key, value] of entries) {
    if (lines.length >= limit) break
    if (value === undefined || value === null || value === '') continue
    let text = ''
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      text = String(value)
    } else if (Array.isArray(value)) {
      text = value
        .map((item) => {
          if (typeof item === 'string' || typeof item === 'number') return String(item)
          if (item && typeof item === 'object' && !Array.isArray(item)) {
            const row = item as Record<string, unknown>
            if (typeof row.name === 'string') return row.name
          }
          return ''
        })
        .filter(Boolean)
        .join('、')
    }
    if (!text.trim()) continue
    lines.push(`${key}：${text.trim()}`)
  }
  return lines
}
