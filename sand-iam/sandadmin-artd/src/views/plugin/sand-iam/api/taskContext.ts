import type { SandIamResourceEndpoint, SandIamResourceRow } from './types'
import { isRecord } from './policyJson'

export const taskContextKeys = ['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as const
export type TaskContextKey = typeof taskContextKeys[number]
export type TaskContext = Partial<Record<TaskContextKey, number>>
export type TaskContextRows = Partial<Record<TaskContextKey, SandIamResourceRow>>

export function parseTaskContext(query: Readonly<Record<string, unknown>>): TaskContext {
  const context: TaskContext = {}
  for (const key of taskContextKeys) {
    const value = query[key]
    if (value === undefined || value === null || value === '') continue
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value) ||
      !Number.isSafeInteger(Number(value))) throw new Error('应用归属参数无效，请返回任务入口重新选择。')
    context[key] = Number(value)
  }
  return context
}

export function taskContextQuery(context: TaskContext, task?: string): Record<string, string> {
  const query: Record<string, string> = {}
  for (const key of taskContextKeys) {
    const value = context[key]
    if (value !== undefined) query[key] = String(value)
  }
  if (task === 'people-access' || task === 'connection') query.task = task
  return query
}

function readRow(value: unknown, id: number): SandIamResourceRow {
  if (!isRecord(value)) throw new Error('无法读取所选归属，请重新选择。')
  const row = isRecord(value.data) ? value.data : value
  if (row.id !== id || row.status === 2) throw new Error('所选归属已失效或停用，请重新选择。')
  return row
}

/** Only carry visible, mutually matching records; a URL is never an authority. */
export async function verifyTaskContext(
  input: TaskContext,
  read: (endpoint: SandIamResourceEndpoint, id: number) => Promise<unknown>
): Promise<{ context: TaskContext; rows: TaskContextRows }> {
  const context = { ...input }
  const rows: TaskContextRows = {}
  const acquire = async (key: TaskContextKey, endpoint: SandIamResourceEndpoint): Promise<SandIamResourceRow | null> => {
    const id = context[key]
    if (id === undefined) return null
    const row = readRow(await read(endpoint, id), id)
    rows[key] = row
    return row
  }
  const parent = (key: TaskContextKey, value: unknown): void => {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value <= 0 ||
      (context[key] !== undefined && context[key] !== value)) {
      throw new Error('所选应用、环境或调用身份不属于同一归属，请返回任务入口重新选择。')
    }
    context[key] = value
  }
  const client = await acquire('workload_client_id', 'client')
  if (client) parent('environment_id', client.environment_id)
  const environment = await acquire('environment_id', 'environment')
  if (environment) parent('application_id', environment.application_id)
  const application = await acquire('application_id', 'application')
  if (application) {
    parent('organization_id', application.organization_id)
    rows.organization_id = { id: application.organization_id, name: application.organization_name }
  } else {
    await acquire('organization_id', 'organization')
  }
  return { context, rows }
}

/** These existing list endpoints intersect application_id with the server access scope. */
const applicationScopedEndpoints = new Set<SandIamResourceEndpoint>([
  'environment', 'client', 'grant', 'credential', 'identity', 'identity-provider',
  'role', 'resource', 'policy', 'auth-policy', 'application-experience',
  'oauth-client', 'application-business-action', 'api-resource', 'api-route-binding'
])

export function taskStepParams(endpoint: SandIamResourceEndpoint | undefined, context: TaskContext):
  { page: number; limit: number; application_id: number } | null {
  if (endpoint === undefined || !applicationScopedEndpoints.has(endpoint) || context.application_id === undefined) return null
  return { page: 1, limit: 1, application_id: context.application_id }
}
