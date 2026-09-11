/**
 * 向导行为/视口入口在模块边界替换 `@/utils/http`。
 * 用内存记录模拟客户主体 / 接入应用 / 应用环境的 list/read/save/update，不访问网络。
 */
import { HttpError } from './getting-started.http-error-mock'

export type WizardHttpEndpoint = 'organization' | 'application' | 'environment'

export type WizardHttpSaveMode = 'ok' | 'conflict' | 'network' | 'missing_id'
export type WizardHttpListMode = 'ok' | 'empty' | 'error'

interface WizardHttpRecord {
  id: number
  name: string
  code: string
  status: number
  organization_id?: number
  application_id?: number
}

interface HttpRequestConfig {
  url?: string
  params?: unknown
  data?: unknown
}

const tables: Record<WizardHttpEndpoint, Map<number, WizardHttpRecord>> = {
  organization: new Map(),
  application: new Map(),
  environment: new Map()
}

let nextId = 1
let saveMode: WizardHttpSaveMode = 'ok'
let listMode: WizardHttpListMode = 'ok'
let remainingReadFailures = 0
let savePostCount = 0
let readGetCount = 0

/**
 * 从请求体取出字符串或数字字段；缺省或类型不对时返回 null，避免猜测。
 */
function fieldValue(body: Readonly<Record<string, unknown>>, key: string): string | number | null {
  const value = body[key]
  if (typeof value === 'string' || typeof value === 'number') return value
  return null
}

/**
 * 把 params 收成可读取的键值表。未知形状当作空表，不伪造筛选条件。
 */
function isParamRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function paramRecord(params: unknown): Readonly<Record<string, unknown>> {
  return isParamRecord(params) ? params : {}
}

function positiveNumber(value: unknown): number | null {
  if (typeof value === 'number' && Number.isInteger(value) && value > 0) return value
  if (typeof value !== 'string' || !/^\d+$/.test(value.trim())) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function parseTarget(url: string): { endpoint: WizardHttpEndpoint; action: string } | null {
  const match = url.match(
    /\/app\/sand-iam\/admin\/(organization|application|environment)\/(index|read|save|update)$/
  )
  if (match === null) return null
  const endpoint = match[1]
  if (endpoint !== 'organization' && endpoint !== 'application' && endpoint !== 'environment') {
    return null
  }
  return { endpoint, action: match[2] }
}

function cloneRecord(record: WizardHttpRecord): WizardHttpRecord {
  return { ...record }
}

/**
 * 写入一条内存记录，并按端点补上级 ID。创建后的编号从 1 递增，便于断言级联。
 */
function insertRecord(
  endpoint: WizardHttpEndpoint,
  body: Readonly<Record<string, unknown>>
): WizardHttpRecord {
  const name = fieldValue(body, 'name')
  const code = fieldValue(body, 'code')
  const status = fieldValue(body, 'status')
  if (typeof name !== 'string' || typeof code !== 'string') {
    throw new HttpError('SAND_IAM_VALIDATION_ERROR', 400, {
      data: { code: 'SAND_IAM_VALIDATION_ERROR', msg: 'SAND_IAM_VALIDATION_ERROR' }
    })
  }
  const record: WizardHttpRecord = {
    id: nextId,
    name,
    code,
    status: status === 2 || status === '2' ? 2 : 1
  }
  nextId += 1
  if (endpoint === 'application') {
    const organizationId = positiveNumber(body.organization_id)
    if (organizationId === null) {
      throw new HttpError('SAND_IAM_VALIDATION_ERROR', 400, {
        data: { code: 'SAND_IAM_VALIDATION_ERROR', msg: 'SAND_IAM_VALIDATION_ERROR' }
      })
    }
    record.organization_id = organizationId
  }
  if (endpoint === 'environment') {
    const applicationId = positiveNumber(body.application_id)
    if (applicationId === null) {
      throw new HttpError('SAND_IAM_VALIDATION_ERROR', 400, {
        data: { code: 'SAND_IAM_VALIDATION_ERROR', msg: 'SAND_IAM_VALIDATION_ERROR' }
      })
    }
    record.application_id = applicationId
  }
  tables[endpoint].set(record.id, record)
  return cloneRecord(record)
}

function listRecords(endpoint: WizardHttpEndpoint, params: unknown): WizardHttpRecord[] {
  const query = paramRecord(params)
  const organizationId = positiveNumber(query.organization_id)
  const applicationId = positiveNumber(query.application_id)
  const rows: WizardHttpRecord[] = []
  for (const record of tables[endpoint].values()) {
    if (
      endpoint === 'application' &&
      organizationId !== null &&
      record.organization_id !== organizationId
    ) {
      continue
    }
    if (
      endpoint === 'environment' &&
      applicationId !== null &&
      record.application_id !== applicationId
    ) {
      continue
    }
    rows.push(cloneRecord(record))
  }
  return rows
}

async function get(config: HttpRequestConfig): Promise<unknown> {
  const url = config.url ?? ''
  const target = parseTarget(url)
  if (target === null) throw new Error(`unmocked GET ${url}`)
  if (target.action === 'index') {
    if (listMode === 'error') {
      throw new HttpError('服务暂时不可用', 503, { url, method: 'GET' })
    }
    const rows = listMode === 'empty' ? [] : listRecords(target.endpoint, config.params)
    return { data: rows, total: rows.length }
  }
  if (target.action === 'read') {
    readGetCount += 1
    if (remainingReadFailures > 0) {
      remainingReadFailures -= 1
      throw new HttpError('服务暂时不可用', 503, { url, method: 'GET' })
    }
    const id = positiveNumber(paramRecord(config.params).id)
    if (id === null) throw new HttpError('目标不存在', 404, { url, method: 'GET' })
    const record = tables[target.endpoint].get(id)
    if (record === undefined) throw new HttpError('目标不存在', 404, { url, method: 'GET' })
    return cloneRecord(record)
  }
  throw new Error(`unmocked GET ${url}`)
}

async function post(config: HttpRequestConfig): Promise<unknown> {
  const url = config.url ?? ''
  const target = parseTarget(url)
  if (target === null) throw new Error(`unmocked POST ${url}`)
  const body = isParamRecord(config.data) ? config.data : {}
  if (target.action === 'save') {
    savePostCount += 1
    if (saveMode === 'network') throw new Error('Failed to fetch')
    if (saveMode === 'conflict') {
      throw new HttpError('SAND_IAM_API_CONFLICT', 409, {
        data: { code: 'SAND_IAM_API_CONFLICT', msg: 'SAND_IAM_API_CONFLICT' }
      })
    }
    if (saveMode === 'missing_id') return {}
    return insertRecord(target.endpoint, body)
  }
  if (target.action === 'update') {
    const id = positiveNumber(body.id)
    if (id === null) throw new HttpError('目标不存在', 404, { url, method: 'POST' })
    const existing = tables[target.endpoint].get(id)
    if (existing === undefined) throw new HttpError('目标不存在', 404, { url, method: 'POST' })
    const name = fieldValue(body, 'name')
    const status = fieldValue(body, 'status')
    if (typeof name === 'string') existing.name = name
    if (status === 2 || status === '2') existing.status = 2
    if (status === 1 || status === '1') existing.status = 1
    return cloneRecord(existing)
  }
  throw new Error(`unmocked POST ${url}`)
}

const api = {
  get,
  post,
  put: get,
  del: get,
  request: get
}

export interface WizardHttpFixture {
  readonly saveMode?: WizardHttpSaveMode
  readonly listMode?: WizardHttpListMode
  readonly readFailures?: number
  readonly seed?: readonly WizardHttpRecord[]
}

/**
 * 重置内存表与失败模式。seed 只写入明确给出的记录，不会自动补上级对象。
 */
export function configureWizardHttpMock(fixture: WizardHttpFixture = {}): void {
  tables.organization.clear()
  tables.application.clear()
  tables.environment.clear()
  nextId = 1
  saveMode = fixture.saveMode ?? 'ok'
  listMode = fixture.listMode ?? 'ok'
  remainingReadFailures = fixture.readFailures ?? 0
  savePostCount = 0
  readGetCount = 0
  for (const record of fixture.seed ?? []) {
    tables[endpointOf(record)].set(record.id, cloneRecord(record))
    if (record.id >= nextId) nextId = record.id + 1
  }
}

function endpointOf(record: WizardHttpRecord): WizardHttpEndpoint {
  if (record.application_id !== undefined) return 'environment'
  if (record.organization_id !== undefined) return 'application'
  return 'organization'
}

export function wizardHttpSavePostCount(): number {
  return savePostCount
}

export function wizardHttpReadGetCount(): number {
  return readGetCount
}

export default api
