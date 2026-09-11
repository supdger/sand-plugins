import request from '@/utils/http'
import { createSandIamRequestId } from './requestId'
import type { SandIamResourceEndpoint } from './types'

export const SAND_IAM_ADMIN_PREFIX = '/app/sand-iam/admin'

export type SandIamWriteBody = Readonly<Record<string, unknown>>

function requestHeaders(): Readonly<Record<string, string>> {
  return { 'X-Request-Id': createSandIamRequestId() }
}

export function readSandIamResource(
  endpoint: SandIamResourceEndpoint,
  id: number
): Promise<unknown> {
  return request.get<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/read`,
    params: { id }
  })
}

export function saveSandIamResource(
  endpoint: SandIamResourceEndpoint,
  data: SandIamWriteBody,
  showErrorMessage = true
): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/save`,
    data,
    showErrorMessage
  })
}

export function updateSandIamResource(
  endpoint: SandIamResourceEndpoint,
  data: SandIamWriteBody
): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/update`,
    data
  })
}

export function disableSandIamResource(
  endpoint: SandIamResourceEndpoint,
  id: number
): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/disable`,
    data: { id }
  })
}

export function postSandIamAction(path: string, data: SandIamWriteBody): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${path}`,
    data,
    headers: requestHeaders()
  })
}

/**
 * 导入预检必须走 multipart，不能把 CSV 行放进 JSON 或 URL。
 */
export function postSandIamForm(path: string, data: FormData): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${path}`,
    data,
    headers: requestHeaders()
  })
}

export function getSandIamAdmin(
  path: string,
  params: Readonly<Record<string, string | number>> = {}
): Promise<unknown> {
  return request.get<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${path}`,
    params,
    headers: requestHeaders()
  })
}

/**
 * 审计导出是 CSV 附件，不能走普通 list/JSON 解包。
 * 每次请求仍带唯一 X-Request-Id；失败由调用方按 HTTP/稳定码解释。
 */
export function downloadSandIamAdminBlob(
  path: string,
  params: Readonly<Record<string, string | number>> = {}
): Promise<Blob> {
  return request.get<Blob>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${path}`,
    params,
    headers: requestHeaders(),
    responseType: 'blob',
    showErrorMessage: false
  })
}
