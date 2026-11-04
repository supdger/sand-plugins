import request from '@/utils/http'
import { createSandIamRequestId } from './requestId'

const PREFIX = '/app/sand-iam/admin'

/** Grid-facing types — parsing is defensive and strips unknown fields. */

export interface SandIamMatrixOrganization {
  id: number
  code: string
  name: string
}

export interface SandIamMatrixAction {
  id: number
  code: string
  name: string
}

export interface SandIamMatrixService {
  id: number
  code: string
  name: string
  actions: SandIamMatrixAction[]
}

export interface SandIamMatrixGrant {
  service_action_id: number
  action_code: string | null
  service_code: string | null
  status: number
  expire_time: string | null
}

export interface SandIamMatrixCredential {
  id: number
  name: string
  key_prefix: string
  expire_time: string | null
  status: number
}

export interface SandIamMatrixClient {
  id: number
  code: string
  audience: string
  status: number
  grants: SandIamMatrixGrant[]
  credentials: SandIamMatrixCredential[]
}

export interface SandIamMatrixEnvironment {
  id: number
  code: string
  name: string
  status: number
  clients: SandIamMatrixClient[]
}

export interface SandIamMatrixApplication {
  id: number
  organization_id: number
  code: string
  name: string
  status: number
  environments: SandIamMatrixEnvironment[]
}

export interface SandIamAccessMatrix {
  organizations: SandIamMatrixOrganization[]
  services: SandIamMatrixService[]
  applications: SandIamMatrixApplication[]
}

export interface SandIamMatrixGrantInput {
  organization_id?: number
  application: { id?: number; code?: string; name?: string }
  service_code: string
  action_codes: string[]
  environment_code?: string
  issue_credential?: boolean
}

export interface SandIamMatrixGrantedItem {
  service_action_id: number
  action_code: string
  service_code: string
  state: 'created' | 'reactivated' | 'active'
}

export interface SandIamIssuedCredential {
  id: number
  key_prefix: string
  /** Plaintext — shown once, never stored by the UI. */
  credential: string
  expire_time: string | null
}

export interface SandIamMatrixGrantResult {
  application_id: number
  environment_id: number
  workload_client_id: number
  organization_id: number
  granted: SandIamMatrixGrantedItem[]
  skipped_action_codes?: string[]
  credential?: SandIamIssuedCredential
  /** False on idempotent replays — the one-time plaintext is never re-served. */
  secret_available: boolean
}

function fail(message: string): never {
  throw new Error(message)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function positiveId(value: unknown): number {
  const n = Number(value)
  if (!Number.isInteger(n) || n <= 0) fail('invalid id')
  return n
}

function text(value: unknown): string {
  if (typeof value !== 'string' || value === '') fail('invalid text')
  return value
}

function optText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function statusOf(value: unknown): number {
  const n = Number(value)
  return n === 1 || n === 2 ? n : 1
}

function list(value: unknown): unknown[] {
  return Array.isArray(value) ? value : []
}

export function parseAccessMatrix(raw: unknown): SandIamAccessMatrix {
  const envelope = isRecord(raw) && isRecord(raw.data) ? raw.data : raw
  const source = isRecord(envelope) ? envelope : null
  if (source === null) fail('invalid matrix payload')
  return {
    organizations: list(source.organizations).map((o) => {
      const org = isRecord(o) ? o : fail('invalid organization')
      return { id: positiveId(org.id), code: text(org.code), name: text(org.name) }
    }),
    services: list(source.services).map((s) => {
      const service = isRecord(s) ? s : fail('invalid service')
      return {
        id: positiveId(service.id),
        code: text(service.code),
        name: text(service.name),
        actions: list(service.actions).map((a) => {
          const action = isRecord(a) ? a : fail('invalid action')
          return { id: positiveId(action.id), code: text(action.code), name: text(action.name) }
        }),
      }
    }),
    applications: list(source.applications).map((a) => {
      const app = isRecord(a) ? a : fail('invalid application')
      return {
        id: positiveId(app.id),
        organization_id: positiveId(app.organization_id),
        code: text(app.code),
        name: text(app.name),
        status: statusOf(app.status),
        environments: list(app.environments).map((e) => {
          const env = isRecord(e) ? e : fail('invalid environment')
          return {
            id: positiveId(env.id),
            code: text(env.code),
            name: text(env.name),
            status: statusOf(env.status),
            clients: list(env.clients).map((c) => {
              const client = isRecord(c) ? c : fail('invalid client')
              return {
                id: positiveId(client.id),
                code: text(client.code),
                audience: text(client.audience),
                status: statusOf(client.status),
                grants: list(client.grants).map((g) => {
                  const grant = isRecord(g) ? g : fail('invalid grant')
                  return {
                    service_action_id: positiveId(grant.service_action_id),
                    action_code: optText(grant.action_code),
                    service_code: optText(grant.service_code),
                    status: statusOf(grant.status),
                    expire_time: optText(grant.expire_time),
                  }
                }),
                credentials: list(client.credentials).map((cr) => {
                  const credential = isRecord(cr) ? cr : fail('invalid credential')
                  return {
                    id: positiveId(credential.id),
                    name: text(credential.name),
                    key_prefix: text(credential.key_prefix),
                    expire_time: optText(credential.expire_time),
                    status: statusOf(credential.status),
                  }
                }),
              }
            }),
          }
        }),
      }
    }),
  }
}

export function parseMatrixGrantResult(raw: unknown): SandIamMatrixGrantResult {
  const envelope = isRecord(raw) && isRecord(raw.data) ? raw.data : raw
  const source = isRecord(envelope) ? envelope : null
  if (source === null) fail('invalid grant result')
  const credential = isRecord(source.credential) ? source.credential : null
  return {
    application_id: positiveId(source.application_id),
    environment_id: positiveId(source.environment_id),
    workload_client_id: positiveId(source.workload_client_id),
    organization_id: positiveId(source.organization_id),
    granted: list(source.granted).map((g) => {
      const item = isRecord(g) ? g : fail('invalid granted item')
      const state = item.state
      return {
        service_action_id: positiveId(item.service_action_id),
        action_code: text(item.action_code),
        service_code: text(item.service_code),
        state: state === 'created' || state === 'reactivated' || state === 'active' ? state : 'active',
      }
    }),
    skipped_action_codes: list(source.skipped_action_codes).map(String),
    secret_available: source.secret_available !== false,
    credential: credential === null
      ? undefined
      : {
          id: positiveId(credential.id),
          key_prefix: text(credential.key_prefix),
          credential: text(credential.credential),
          expire_time: optText(credential.expire_time),
        },
  }
}

/** The business calling identity the one-click grant/ensure chain materializes. */
export const MATRIX_BUSINESS_CLIENT_CODE = 'sand-ai'
/**
 * The dedicated administrator-trial client. Its transient credentials and
 * trial-only grants are never the row's calling identity, so it is skipped
 * even when it is the only client a lazy trial-first provisioning created.
 */
export const MATRIX_TRIAL_CLIENT_CODE = 'admin_trial'

/**
 * Flatten clients: the grid shows one row per application. The canonical
 * business client (code `sand-ai`) always wins regardless of which lazy
 * chain materialized it first; the dedicated trial client is never reported
 * as the row's calling identity.
 */
export function matrixClientFor(app: SandIamMatrixApplication): SandIamMatrixClient | null {
  let fallback: SandIamMatrixClient | null = null
  for (const env of app.environments) {
    for (const client of env.clients) {
      if (client.status !== 1) continue
      if (client.code === MATRIX_BUSINESS_CLIENT_CODE) return client
      if (client.code !== MATRIX_TRIAL_CLIENT_CODE && fallback === null) fallback = client
    }
  }
  return fallback
}

/** Active grant check for a service action (status 1, not expired). */
export function grantActive(client: SandIamMatrixClient | null, actionId: number): boolean {
  if (client === null) return false
  const now = Date.now()
  return client.grants.some((g) =>
    g.service_action_id === actionId &&
    g.status === 1 &&
    (g.expire_time === null || Date.parse(g.expire_time.replace(' ', 'T')) > now))
}

export function activeCredential(client: SandIamMatrixClient | null): SandIamMatrixCredential | null {
  if (client === null) return null
  const now = Date.now()
  return client.credentials.find((c) =>
    c.status === 1 && (c.expire_time === null || Date.parse(c.expire_time.replace(' ', 'T')) > now)) ?? null
}

export async function fetchAccessMatrix(): Promise<SandIamAccessMatrix> {
  const raw = await request.get<unknown>({
    url: `${PREFIX}/access-matrix/index`,
    headers: { 'X-Request-Id': createSandIamRequestId() },
  })
  return parseAccessMatrix(raw)
}

export async function grantAccessMatrix(input: SandIamMatrixGrantInput): Promise<SandIamMatrixGrantResult> {
  const raw = await request.post<unknown>({
    url: `${PREFIX}/access-matrix/grant`,
    data: input,
    headers: { 'X-Request-Id': createSandIamRequestId() },
  })
  return parseMatrixGrantResult(raw)
}

export async function ensureAccessMatrixDefaults(): Promise<unknown> {
  return request.post<unknown>({
    url: `${PREFIX}/access-matrix/ensure-defaults`,
    data: {},
    headers: { 'X-Request-Id': createSandIamRequestId() },
  })
}

/**
 * Default capability set suggested when granting or onboarding an
 * application: AI chat + document parsing. Service-level defaults only —
 * the application identity is always chosen by the administrator.
 */
export const DEFAULT_GRANT_SERVICE_CODE = 'sand_ai'
export const DEFAULT_GRANT_ACTION_CODES: readonly string[] = [
  'sand_ai.chat.complete',
  'sand_ai.document_parse',
]
