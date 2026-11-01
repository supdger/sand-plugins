import { parseTaskContext } from '../api/taskContext'
import type { TaskContext } from '../api/taskContext'
import { isRecord, WIZARD_CONTEXT_MAX_AGE_MS } from './wizardState'

const CONTEXT_KEY = 'sand-iam.getting-started.v1'
export const NEW_APPLICATION_INTENT = 'new-application'

export function newApplicationQuery(
  context: TaskContext, goal: string, method: string | undefined, flow: string
): Record<string, string> {
  return {
    intent: NEW_APPLICATION_INTENT, flow, goal,
    ...(context.organization_id === undefined ? {} : { organization_id: String(context.organization_id) }),
    ...(method === undefined ? {} : { method })
  }
}

export interface WizardDraft {
  readonly name: string
  readonly code: string
  readonly status: string
}

export function readWizardDraft(value: string | null, now: number): WizardDraft | null {
  if (value === null) return null
  try {
    const draft: unknown = JSON.parse(value)
    if (!isRecord(draft) || typeof draft.savedAt !== 'number' || !Number.isFinite(draft.savedAt) ||
      draft.savedAt > now || now - draft.savedAt > WIZARD_CONTEXT_MAX_AGE_MS ||
      typeof draft.name !== 'string' || typeof draft.code !== 'string' ||
      !['1', '2'].includes(String(draft.status))) return null
    return { name: draft.name, code: draft.code, status: String(draft.status) }
  } catch { return null }
}

/** Each explicit registration owns its progress; an existing application's progress cannot seed it. */
export function wizardEntry(query: Readonly<Record<string, unknown>>): {
  readonly newApplication: boolean
  readonly storageKey: string | null
  readonly supplied: TaskContext | null
} {
  if (query.intent === NEW_APPLICATION_INTENT) {
    const flow = typeof query.flow === 'string' && /^[a-f0-9-]{36}$/i.test(query.flow) ? query.flow : null
    return {
      newApplication: true,
      storageKey: flow === null ? null : `${CONTEXT_KEY}.registration.${flow}`,
      supplied: parseTaskContext({ organization_id: query.organization_id })
    }
  }
  return {
    newApplication: false,
    storageKey: CONTEXT_KEY,
    supplied: query.application_id || query.organization_id ? parseTaskContext(query) : null
  }
}
