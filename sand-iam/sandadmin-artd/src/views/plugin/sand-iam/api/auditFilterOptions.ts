import { normalizeReferenceValue } from './referenceValues'
import type { SandIamResourceRow } from './types'

export interface SandIamAuditFilterOption {
  readonly id: number
  readonly name: string
}

export function auditFilterEndpoints(
  canIndexOrganization: boolean
): readonly ('application' | 'organization')[] {
  return canIndexOrganization ? ['application', 'organization'] : ['application']
}

export function auditApplicationOptions(
  rows: readonly SandIamResourceRow[]
): readonly SandIamAuditFilterOption[] {
  return rows.map((row) => optionFromRow(row, 'id', 'name')).filter(isAuditFilterOption)
}

export function auditOrganizationOptionsFromApplications(
  rows: readonly SandIamResourceRow[]
): readonly SandIamAuditFilterOption[] {
  const seen = new Set<number>()
  return rows.flatMap((row) => {
    const option = optionFromRow(row, 'organization_id', 'organization_name')
    if (option === null || seen.has(option.id)) return []
    seen.add(option.id)
    return [option]
  })
}

function optionFromRow(
  row: SandIamResourceRow,
  idKey: string,
  nameKey: string
): SandIamAuditFilterOption | null {
  const id = normalizeReferenceValue(row[idKey])
  const name = row[nameKey]
  if (id === null || typeof name !== 'string' || name.trim() === '') return null
  return { id, name }
}

function isAuditFilterOption(
  value: SandIamAuditFilterOption | null
): value is SandIamAuditFilterOption {
  return value !== null
}
