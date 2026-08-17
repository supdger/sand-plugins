import request from '@/utils/http'
import type { SandIamResourceEndpoint } from './types'

export const SAND_IAM_ADMIN_PREFIX = '/app/sand-iam/admin'

export type SandIamWriteBody = Readonly<Record<string, unknown>>

export function readSandIamResource(endpoint: SandIamResourceEndpoint, id: number): Promise<unknown> {
  return request.get<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/read`,
    params: { id }
  })
}

export function saveSandIamResource(endpoint: SandIamResourceEndpoint, data: SandIamWriteBody): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/save`,
    data
  })
}

export function updateSandIamResource(endpoint: SandIamResourceEndpoint, data: SandIamWriteBody): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/update`,
    data
  })
}

export function disableSandIamResource(endpoint: SandIamResourceEndpoint, id: number): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/disable`,
    data: { id }
  })
}

export function postSandIamAction(path: string, data: SandIamWriteBody): Promise<unknown> {
  return request.post<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${path}`,
    data
  })
}
