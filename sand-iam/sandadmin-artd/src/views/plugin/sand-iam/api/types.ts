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
  | 'identity-provider'
  | 'identity-binding'
  | 'user-type'
  | 'role'
  | 'resource'
  | 'policy'
  | 'admin-organization-grant'
  | 'admin-application-grant'
  | 'application-experience'
  | 'identity-role'
  | 'identity-user-type'
  | 'auth-policy'
  | 'oauth-client'
  | 'api-resource'
  | 'application-business-action'
  | 'api-route-binding'
  | 'cas-service'
  | 'radius-nas'
  | 'application-network-policy'
  | 'audit-retention-policy'

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
  | 'scope_type'

export type SandIamWriteMode =
  | 'crud'
  | 'readonly'
  | 'policy'
  | 'grant'
  | 'relation'
  | 'credential'
  | 'binding'
  | 'oauth-client'
  | 'business-action'

export type SandIamFieldKind =
  | 'text'
  | 'number'
  | 'reference'
  | 'status'
  | 'json'
  | 'condition'
  | 'datetime'
  | 'select'

export interface SandIamReferenceDependency {
  readonly sourceKey: string
  readonly targetParam: Exclude<keyof SandIamListParams, 'page' | 'limit' | 'keywords' | 'status'>
  readonly sourceEndpoint?: SandIamResourceEndpoint
  readonly sourceValueKey?: string
}

export interface SandIamFormField {
  readonly key: string
  readonly label: string
  readonly kind: SandIamFieldKind
  readonly required?: boolean
  /** 可选文本或日期清空时显式提交 null，以移除已有约束。 */
  readonly clearableToNull?: boolean
  /** 空文本显式提交空字符串；仅用于后端以空字符串清除配置的字段。 */
  readonly clearableToEmptyString?: boolean
  readonly allowEmptyArray?: boolean
  readonly createOnly?: boolean
  readonly updateOnly?: boolean
  readonly referenceEndpoint?: SandIamResourceEndpoint
  readonly grantCandidate?: 'services' | 'actions'
  readonly organizationAdminCandidate?: boolean
  readonly dependency?: SandIamReferenceDependency
  /**
   * 应用委派只可读取已获授应用。组织名称由 application/index 的
   * organization_id / organization_name 派生，不请求 organization/index。
   */
  readonly applicationGrantContext?: 'organization' | 'application'
  /**
   * 已由当前资源行携带的父级显示字段。它是页面专用上下文，不是
   * 通用 403 回退；例如 application 的 organization_name。
   */
  readonly embeddedReferenceLabelKey?: string
  /** 父级是否可编辑的行字段；false 时只回显嵌入式父级且不提交该字段。 */
  readonly embeddedReferenceEditableKey?: string
  readonly omitFromPayload?: boolean
  readonly options?: readonly {
    readonly label: string
    readonly value: string
  }[]
  /** 多选项以字符串数组提交，适用于已由后端声明的枚举列表。 */
  readonly multiple?: boolean
  readonly help?: string
  readonly placeholder?: string
  /** 默认收起的高级可选配置，不阻塞基础授权流程。 */
  readonly advanced?: boolean
  /** 客户主体/接入应用/应用环境/服务调用身份的系统代码，提交时按同一条可读规则校验。 */
  readonly systemObjectCode?: boolean
  /** 新建时预填的冻结默认值，例如认证策略的密码最短位数。 */
  readonly defaultValue?: string
  /** JSON 字段按数组提交；通行密钥允许来源使用此标记，禁止当成对象。 */
  readonly jsonArray?: boolean
}

export interface SandIamResourceColumn {
  readonly key: string
  readonly label: string
  readonly minWidth?: number
  /** 次要列可复制原文，例如系统代码。默认主列不要打开。 */
  readonly copyable?: boolean
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
  readonly scope_type?: string
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
