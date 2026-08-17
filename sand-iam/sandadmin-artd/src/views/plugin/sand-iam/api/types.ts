export type SandIamResourceEndpoint =
  | 'organization'
  | 'application'
  | 'environment'
  | 'client'
  | 'grant'
  | 'audit'
  | 'service'
  | 'action'
  | 'credential'
  | 'identity'
  | 'identity-binding'
  | 'user-type'
  | 'role'
  | 'resource'
  | 'policy'
  | 'admin-organization-grant'
  | 'identity-role'
  | 'identity-user-type'

export type SandIamFilterKey =
  | 'keywords'
  | 'status'
  | 'organization_id'
  | 'application_id'
  | 'environment_id'
  | 'workload_client_id'
  | 'identity_id'
  | 'service_id'
  | 'actor_type'
  | 'outcome'

export type SandIamWriteMode =
  | 'crud'
  | 'readonly'
  | 'policy'
  | 'grant'
  | 'relation'
  | 'credential'
  | 'binding'

export type SandIamFieldKind = 'text' | 'number' | 'status' | 'json' | 'datetime' | 'select'

export interface SandIamFormField {
  readonly key: string
  readonly label: string
  readonly kind: SandIamFieldKind
  readonly required?: boolean
  readonly createOnly?: boolean
  readonly updateOnly?: boolean
  readonly options?: readonly { readonly label: string; readonly value: string }[]
}

export interface SandIamResourceColumn {
  readonly key: string
  readonly label: string
  readonly minWidth?: number
}

export type SandIamResourceRow = Readonly<Record<string, unknown>>

export interface SandIamListParams {
  readonly page: number
  readonly limit: number
  readonly keywords?: string
  readonly status?: number
  readonly organization_id?: number
  readonly application_id?: number
  readonly environment_id?: number
  readonly workload_client_id?: number
  readonly identity_id?: number
  readonly service_id?: number
  readonly actor_type?: string
  readonly outcome?: string
}

export interface SandIamResourcePage {
  readonly data: SandIamResourceRow[]
  readonly total: number
  readonly currentPage: number
  readonly pageSize: number
}

export interface SandIamRequestError {
  readonly code: string | null
  readonly http: number | null
  readonly title: string
  readonly detail: string
}
