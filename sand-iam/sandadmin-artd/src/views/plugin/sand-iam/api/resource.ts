import request from '@/utils/http'
import type { SandIamListParams, SandIamResourceEndpoint } from './types'

export const SAND_IAM_ADMIN_PREFIX = '/app/sand-iam/admin'

function compactParams(params: SandIamListParams): Record<string, string | number> {
  const query: Record<string, string | number> = {
    page: params.page,
    limit: params.limit
  }
  if (params.keywords !== undefined && params.keywords !== '') query.keywords = params.keywords
  if (params.status !== undefined) query.status = params.status
  if (params.organization_id !== undefined) query.organization_id = params.organization_id
  if (params.application_id !== undefined) query.application_id = params.application_id
  if (params.environment_id !== undefined) query.environment_id = params.environment_id
  if (params.workload_client_id !== undefined) query.workload_client_id = params.workload_client_id
  if (params.identity_id !== undefined) query.identity_id = params.identity_id
  if (params.service_id !== undefined) query.service_id = params.service_id
  if (params.actor_type !== undefined && params.actor_type !== '') query.actor_type = params.actor_type
  if (params.outcome !== undefined && params.outcome !== '') query.outcome = params.outcome
  return query
}

export function listSandIamResource(
  endpoint: SandIamResourceEndpoint,
  params: SandIamListParams
): Promise<unknown> {
  return request.get<unknown>({
    url: `${SAND_IAM_ADMIN_PREFIX}/${endpoint}/index`,
    params: compactParams(params)
  })
}
