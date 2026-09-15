import type { SandIamResourceRow } from '../api/types'

export type WizardStep = 'organization' | 'application' | 'environment'
export type WizardScreen = WizardStep | 'complete'
export type WizardPhase =
  | 'draft'
  | 'created_pending_confirmation'
  | 'save_outcome_unknown'
  | 'lookup_required'
  | 'confirmed'
  | 'disabled'
export type VerificationRecovery = 'retain_pending' | 'lookup_required'

export interface WizardRecord {
  readonly id: number
  readonly name: string
  readonly code: string
  readonly status: number
  readonly organizationId?: number
  readonly applicationId?: number
}

export interface WizardContext {
  readonly version: 2
  readonly savedAt: number
  readonly ids: Readonly<Record<WizardStep, number | null>>
  readonly phases: Readonly<Record<WizardStep, WizardPhase>>
  readonly pendingCodes: Readonly<Record<WizardStep, string | null>>
}

export const WIZARD_CONTEXT_VERSION = 2 as const
export const WIZARD_CONTEXT_MAX_AGE_MS = 24 * 60 * 60 * 1000

export function positiveId(value: unknown): number | null {
  if (typeof value === 'number' && Number.isInteger(value) && value > 0) return value
  if (typeof value !== 'string' || !/^\d+$/.test(value.trim())) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function stringValue(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

function numericStatus(value: unknown): number | null {
  const parsed = positiveId(value)
  return parsed === 1 || parsed === 2 ? parsed : null
}

export function recordFromResponse(value: unknown, step: WizardStep): WizardRecord | null {
  const source = responseRecord(value)
  if (source === null) return null
  const id = positiveId(source.id)
  const name = stringValue(source.name)
  const code = stringValue(source.code)
  const status = numericStatus(source.status)
  if (id === null || name === '' || code === '' || status === null) return null

  if (step === 'organization') return { id, name, code, status }
  const parentKey = step === 'application' ? 'organization_id' : 'application_id'
  const parentId = positiveId(source[parentKey])
  if (parentId === null) return null
  return step === 'application'
    ? { id, name, code, status, organizationId: parentId }
    : { id, name, code, status, applicationId: parentId }
}

export function saveResponseId(value: unknown): number | null {
  const source = responseRecord(value)
  return source === null ? null : positiveId(source.id)
}

export function responseRecord(value: unknown): SandIamResourceRow | null {
  if (!isRecord(value)) return null
  if (positiveId(value.id) !== null) return value
  return isRecord(value.data) && positiveId(value.data.id) !== null ? value.data : null
}

export function parseWizardContext(value: string | null, now: number): WizardContext | null {
  if (value === null) return null
  try {
    const parsed: unknown = JSON.parse(value)
    if (!isRecord(parsed) || parsed.version !== WIZARD_CONTEXT_VERSION) return null
    const savedAt = typeof parsed.savedAt === 'number' ? parsed.savedAt : NaN
    if (!Number.isFinite(savedAt) || savedAt > now || now - savedAt > WIZARD_CONTEXT_MAX_AGE_MS)
      return null
    if (!isRecord(parsed.ids) || !isRecord(parsed.phases) || !isRecord(parsed.pendingCodes))
      return null
    const phases = parsePhases(parsed.phases)
    if (phases === null) return null
    return {
      version: WIZARD_CONTEXT_VERSION,
      savedAt,
      ids: {
        organization: positiveId(parsed.ids.organization),
        application: positiveId(parsed.ids.application),
        environment: positiveId(parsed.ids.environment)
      },
      phases,
      pendingCodes: {
        organization: stringOrNull(parsed.pendingCodes.organization),
        application: stringOrNull(parsed.pendingCodes.application),
        environment: stringOrNull(parsed.pendingCodes.environment)
      }
    }
  } catch {
    return null
  }
}

export function createWizardContext(
  ids: Readonly<Record<WizardStep, number | null>>,
  phases: Readonly<Record<WizardStep, WizardPhase>>,
  pendingCodes: Readonly<Record<WizardStep, string | null>>,
  now: number
): WizardContext {
  return { version: WIZARD_CONTEXT_VERSION, savedAt: now, ids, phases, pendingCodes }
}

export function phaseAfterSave(id: number | null): WizardPhase {
  return id === null ? 'save_outcome_unknown' : 'created_pending_confirmation'
}

/** These create rejections occur before writing, or after atomic rollback. */
export function phaseAfterCreateError(http: number | null, code: string | null): WizardPhase {
  if (http === 401 || http === 403) return 'draft'
  if (http === 400 && code === 'SAND_IAM_VALIDATION_ERROR') return 'draft'
  if (http === 409 && code === 'SAND_IAM_ENVIRONMENT_CONFLICT') return 'draft'
  return 'save_outcome_unknown'
}

export function canPostForPhase(phase: WizardPhase): boolean {
  return phase === 'draft'
}

export function phaseAfterVerifiedRecord(record: WizardRecord): WizardPhase {
  return record.status === 1 ? 'confirmed' : 'disabled'
}

export function verificationRecoveryForHttp(http: number | null): VerificationRecovery {
  return http === 401 || http === 403 || http === 404 ? 'lookup_required' : 'retain_pending'
}

export function canNavigateTo(
  step: WizardStep,
  phases: Readonly<Record<WizardStep, WizardPhase>>
): boolean {
  if (step === 'organization') return true
  if (step === 'application') return phases.organization === 'confirmed'
  return phases.organization === 'confirmed' && phases.application === 'confirmed'
}

function parsePhases(value: SandIamResourceRow): Record<WizardStep, WizardPhase> | null {
  const phases: Record<WizardStep, WizardPhase> = {
    organization: 'draft',
    application: 'draft',
    environment: 'draft'
  }
  for (const step of Object.keys(phases) as WizardStep[]) {
    const phase = value[step]
    if (!isWizardPhase(phase)) return null
    phases[step] = phase
  }
  return phases
}

function isWizardPhase(value: unknown): value is WizardPhase {
  return (
    value === 'draft' ||
    value === 'created_pending_confirmation' ||
    value === 'save_outcome_unknown' ||
    value === 'lookup_required' ||
    value === 'confirmed' ||
    value === 'disabled'
  )
}

function stringOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
}

export function isRecord(value: unknown): value is SandIamResourceRow {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
