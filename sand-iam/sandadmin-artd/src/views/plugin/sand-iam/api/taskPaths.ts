import type { SandIamResourceEndpoint } from './types'

export type SandIamTaskPath =
  | 'connection'
  | 'people-access'
  | 'auth-session'
  | 'api-governance'
  | 'admin-scope'
  | 'event-notification'
  | 'audit-troubleshooting'

export type SandIamTaskStepState =
  | 'loading'
  | 'ready'
  | 'missing'
  | 'forbidden'
  | 'waiting'
  | 'inspection'
  | 'runtime'
  | 'error'

export interface SandIamTaskStepDefinition {
  readonly key: string
  readonly label: string
  readonly endpoint?: SandIamResourceEndpoint
  readonly permission?: string
  readonly path: string
  readonly emptyHint: string
  /**
   * frozen：总览可读取已有设置。
   * waiting：总览暂时不能自动确认，不发请求。
   * runtime：仅由应用用户在接入应用中使用。
   */
  readonly contractStatus?: 'frozen' | 'waiting' | 'runtime'
}

export interface SandIamTaskPathDefinition {
  readonly title: string
  readonly entryPath: string
  /** 任务路径的动态路由入口权限；总览只向有权账号提供入口。 */
  readonly entryPermission?: string
  /** 任一权限即可进入的路径，例如两类管理委派。 */
  readonly entryPermissions?: readonly string[]
  readonly audience: string
  readonly description: string
  readonly steps: readonly SandIamTaskStepDefinition[]
  readonly guides?: readonly SandIamTroubleshootingGuide[]
}

export interface SandIamTroubleshootingGuide {
  readonly title: string
  readonly symptom: string
  readonly recovery: string
  readonly path: string
  /** 恢复动作对应页面的查看权限。 */
  readonly permission: string
  readonly actionLabel: string
}

export interface SandIamTaskStepSnapshot {
  readonly definition: SandIamTaskStepDefinition
  readonly state: SandIamTaskStepState
  readonly total: number | null
  readonly detail: string
}

/** 应用用户门户：后台只提供入口说明，不承载用户自己的会话与安全操作。 */
export const SAND_IAM_APPLICATION_PORTAL_URL = '/app/sand-iam/account/'

export const sandIamTaskPaths: Readonly<Record<SandIamTaskPath, SandIamTaskPathDefinition>> = {
  connection: {
    title: '客户与应用接入',
    entryPath: '/sand-iam/connection',
    entryPermission: 'sand_iam:organization:index',
    audience: '平台管理员、受委派的应用管理员',
    description: '登记谁在使用 SandIAM，以及哪些系统需要接入。',
    steps: [
      {
        key: 'organization',
        label: '客户主体',
        endpoint: 'organization',
        permission: 'sand_iam:organization:index',
        path: '/sand-iam/organization',
        emptyHint: '先创建客户主体，再登记接入应用。'
      },
      {
        key: 'application',
        label: '接入应用',
        endpoint: 'application',
        permission: 'sand_iam:application:index',
        path: '/sand-iam/application',
        emptyHint: '为客户主体登记要接入的产品或项目。'
      },
      {
        key: 'environment',
        label: '应用环境',
        endpoint: 'environment',
        permission: 'sand_iam:environment:index',
        path: '/sand-iam/environment',
        emptyHint: '创建开发、测试或生产环境的隔离边界。'
      },
      {
        key: 'client',
        label: '服务调用身份',
        endpoint: 'client',
        permission: 'sand_iam:client:index',
        path: '/sand-iam/workload-client',
        emptyHint: '为应用后端登记服务调用身份。'
      },
      {
        key: 'grant',
        label: '服务授权',
        endpoint: 'grant',
        permission: 'sand_iam:grant:index',
        path: '/sand-iam/service-grant',
        emptyHint: '选择服务动作并授给服务调用身份。'
      },
      {
        key: 'credential',
        label: '调用凭证',
        endpoint: 'credential',
        permission: 'sand_iam:credential:index',
        path: '/sand-iam/credential',
        emptyHint: '签发凭证并在明文关闭前安全交付给应用。'
      }
    ]
  },
  'people-access': {
    title: '应用用户与权限',
    entryPath: '/sand-iam/people-access',
    entryPermission: 'sand_iam:identity:index',
    audience: '应用管理员',
    description: '管理用户从哪里来、能进入哪些应用、可以做什么。',
    steps: [
      {
        key: 'application',
        label: '接入应用',
        endpoint: 'application',
        permission: 'sand_iam:application:index',
        path: '/sand-iam/application',
        emptyHint: '先选择或创建要管理用户权限的接入应用。'
      },
      {
        key: 'identity-provider',
        label: '身份源',
        endpoint: 'identity-provider',
        permission: 'sand_iam:identity_provider:index',
        path: '/sand-iam/identity-provider',
        emptyHint: '登记该应用使用的身份源实例。'
      },
      {
        key: 'identity',
        label: '应用身份',
        endpoint: 'identity',
        permission: 'sand_iam:identity:index',
        path: '/sand-iam/identity',
        emptyHint: '创建或绑定需要授权的应用身份。'
      },
      {
        key: 'identity-group',
        label: '用户组',
        permission: 'sand_iam:identity_group:index',
        path: '/sand-iam/identity-group',
        emptyHint: '按接入应用名称管理用户组；成员只能按本应用用户名称选择。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity-invitation',
        label: '用户邀请',
        permission: 'sand_iam:identity_invitation:index',
        path: '/sand-iam/identity-invitation',
        emptyHint: '按应用邀请邮箱或手机号；列表只显示脱敏目标和用户组名称。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity-import',
        label: '用户导入导出',
        permission: 'sand_iam:identity_import:index',
        path: '/sand-iam/identity-import',
        emptyHint: '先预检再确认。有错误行时禁止执行。完整联系方式导出需独立权限。',
        contractStatus: 'frozen'
      },
      {
        key: 'sync-connector',
        label: '用户同步',
        permission: 'sand_iam:sync_connector:index',
        path: '/sand-iam/sync-connector',
        emptyHint: '按应用配置用户同步来源；保存后请进入页面核对当前设置。',
        contractStatus: 'frozen'
      },
      {
        key: 'role',
        label: '角色',
        endpoint: 'role',
        permission: 'sand_iam:role:index',
        path: '/sand-iam/role',
        emptyHint: '按应用内职责创建可复用角色。'
      },
      {
        key: 'resource',
        label: '业务资源',
        endpoint: 'resource',
        permission: 'sand_iam:resource:index',
        path: '/sand-iam/resource',
        emptyHint: '登记应用需要授权的业务资源。'
      },
      {
        key: 'policy',
        label: '策略',
        endpoint: 'policy',
        permission: 'sand_iam:policy:index',
        path: '/sand-iam/policy',
        emptyHint: '用角色或应用身份二选一创建并发布策略。'
      }
    ]
  },
  'auth-session': {
    title: '认证与会话',
    entryPath: '/sand-iam/auth-session',
    entryPermission: 'sand_iam:auth_policy:index',
    audience: '应用管理员',
    description: '设置登录方式和安全要求，管理登录状态。',
    steps: [
      {
        key: 'auth-settings',
        label: '认证设置',
        endpoint: 'auth-policy',
        permission: 'sand_iam:auth_policy:index',
        path: '/sand-iam/auth-policy',
        emptyHint: '每个接入应用一条认证策略，覆盖注册、密码、验证码开关和失败锁定。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity',
        label: '用户目录',
        endpoint: 'identity',
        permission: 'sand_iam:identity:index',
        path: '/sand-iam/identity',
        emptyHint: '创建或停用该应用的人类用户身份。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity-group',
        label: '用户组',
        permission: 'sand_iam:identity_group:index',
        path: '/sand-iam/identity-group',
        emptyHint: '按接入应用管理用户组层级；成员按本应用用户名称选择，不能跨应用。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity-invitation',
        label: '用户邀请',
        permission: 'sand_iam:identity_invitation:index',
        path: '/sand-iam/identity-invitation',
        emptyHint: '投递失败可重发；重发后旧链接失效。接受后必须登录，不会获得后台会话。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity-import',
        label: '用户导入导出',
        permission: 'sand_iam:identity_import:index',
        path: '/sand-iam/identity-import',
        emptyHint: '模板列名固定。有错误行时禁止确认。敏感导出需二次确认。',
        contractStatus: 'frozen'
      },
      {
        key: 'sessions',
        label: '会话',
        path: SAND_IAM_APPLICATION_PORTAL_URL,
        emptyHint: '会话属于应用用户。请在接入应用的个人中心查看设备并撤销异常会话。',
        contractStatus: 'runtime'
      },
      {
        key: 'mfa',
        label: 'MFA 与通行密钥',
        path: SAND_IAM_APPLICATION_PORTAL_URL,
        emptyHint: '验证器和通行密钥属于应用用户。请在接入应用的个人中心设置或撤销。',
        contractStatus: 'runtime'
      },
      {
        key: 'application-experience',
        label: '登录外观',
        endpoint: 'application-experience',
        permission: 'sand_iam:application_experience:index',
        path: '/sand-iam/application-experience',
        emptyHint: '每个接入应用一条品牌与登录/注册编排。密码关闭后门户不得展示登录表单。',
        contractStatus: 'frozen'
      },
      {
        key: 'message-provider',
        label: '消息服务',
        path: '/sand-iam/message-provider',
        permission: 'sand_iam:message_provider:index',
        emptyHint: '供应商配置只写不读。测试接收地址和挑战令牌不得进入 URL 或日志。',
        contractStatus: 'frozen'
      },
      {
        key: 'identity-provider',
        label: '身份源',
        endpoint: 'identity-provider',
        permission: 'sand_iam:identity_provider:index',
        path: '/sand-iam/identity-provider',
        emptyHint: '先登记该应用使用的身份源实例。',
        contractStatus: 'frozen'
      },
      {
        key: 'federation-config',
        label: '联合配置',
        permission: 'sand_iam:federation:configure',
        path: '/sand-iam/federation-config',
        emptyHint: '按名称选择身份源后配置登录协议；密钥只在创建或轮换时显示一次。',
        contractStatus: 'frozen'
      },
      {
        key: 'scim-tokens',
        label: 'SCIM 令牌',
        permission: 'sand_iam:scim:token_index',
        path: '/sand-iam/scim-tokens',
        emptyHint: '签发目录同步令牌；明文只展示一次。',
        contractStatus: 'frozen'
      },
      {
        key: 'radius-nas',
        label: 'RADIUS 网络设备',
        endpoint: 'radius-nas',
        permission: 'sand_iam:radius_nas:index',
        path: '/sand-iam/radius-nas',
        emptyHint: '登记来源 CIDR 并轮换共享密钥。当前只支持用户名+密码，MFA 账号会被拒绝。',
        contractStatus: 'frozen'
      }
    ]
  },
  'api-governance': {
    title: '接口与访问控制',
    entryPath: '/sand-iam/api-governance',
    entryPermission: 'sand_iam:api_resource:index',
    audience: '应用管理员、应用技术接入人员',
    description: '控制系统之间如何连接，以及可以调用哪些能力。',
    steps: [
      {
        key: 'oauth-client',
        label: 'OAuth 客户端',
        endpoint: 'oauth-client',
        permission: 'sand_iam:oauth_client:index',
        path: '/sand-iam/oauth-client',
        emptyHint: '先为接入应用登记标准客户端；密钥只展示一次。',
        contractStatus: 'frozen'
      },
      {
        key: 'oauth-registration-token',
        label: '动态注册令牌',
        permission: 'sand_iam:oauth_registration_token:index',
        path: '/sand-iam/oauth-registration-token',
        emptyHint: '按接入应用签发动态注册令牌；令牌只展示一次，请立即安全保存。',
        contractStatus: 'frozen'
      },
      {
        key: 'cas-service',
        label: 'CAS 接入服务',
        endpoint: 'cas-service',
        permission: 'sand_iam:cas_service:index',
        path: '/sand-iam/cas-service',
        emptyHint: '按精确 HTTPS 地址登记 CAS 服务；改地址需新增并停用旧记录。',
        contractStatus: 'frozen'
      },
      {
        key: 'business-action',
        label: '应用业务动作',
        endpoint: 'application-business-action',
        permission: 'sand_iam:api_resource:index',
        path: '/sand-iam/application-business-action',
        emptyHint: '先声明业务动作，再在接口目录中引用它。',
        contractStatus: 'frozen'
      },
      {
        key: 'api-resource',
        label: '接口目录',
        endpoint: 'api-resource',
        permission: 'sand_iam:api_resource:index',
        path: '/sand-iam/api-resource',
        emptyHint: '接口必须引用已启用业务动作；权限依据不是 URL。',
        contractStatus: 'frozen'
      },
      {
        key: 'route-binding',
        label: '路由绑定',
        endpoint: 'api-route-binding',
        permission: 'sand_iam:api_route_binding:index',
        path: '/sand-iam/api-route-binding',
        emptyHint: '扫描只发现路由，不自动创建权限、策略或授权。',
        contractStatus: 'frozen'
      },
      {
        key: 'route-manifest',
        label: '路由清单',
        permission: 'sand_iam:onboarding:preview',
        path: '/sand-iam/route-manifest',
        emptyHint: '先查看待处理的路由，再确认需要应用的变更。确认前不会标记为已完成。',
        contractStatus: 'frozen'
      },
      {
        key: 'policy-simulate',
        label: '策略模拟',
        permission: 'sand_iam:policy:index',
        path: '/sand-iam/policy-simulate',
        emptyHint: '输入访问条件后查看系统判断；页面不会自行推算允许或拒绝。',
        contractStatus: 'frozen'
      },
      {
        key: 'developer-docs',
        label: '开发者接入',
        permission: 'sand_iam:developer:openapi',
        path: '/sand-iam/developer-docs',
        emptyHint: '查看接口与事件接入说明，并先确认业务对象的识别方式。',
        contractStatus: 'frozen'
      }
    ]
  },
  'admin-scope': {
    title: '管理范围',
    entryPath: '/sand-iam/admin-scope',
    entryPermissions: [
      'sand_iam:admin_organization_grant:index',
      'sand_iam:admin_application_grant:index'
    ],
    audience: '平台管理员、客户主体管理员',
    description: '把指定客户或应用的管理工作交给合适的管理员。',
    steps: [
      {
        key: 'organization-grant',
        label: '客户主体管理委派',
        endpoint: 'admin-organization-grant',
        permission: 'sand_iam:admin_organization_grant:index',
        path: '/sand-iam/admin-organization-grant',
        emptyHint: '由平台管理员把客户主体委派给后台管理员。',
        contractStatus: 'frozen'
      },
      {
        key: 'application-grant',
        label: '应用管理员委派',
        endpoint: 'admin-application-grant',
        permission: 'sand_iam:admin_application_grant:index',
        path: '/sand-iam/admin-application-grant',
        emptyHint: '按名称搜索后台管理员，再选择可管理的接入应用；禁止手填管理员编号。',
        contractStatus: 'frozen'
      }
    ]
  },
  'event-notification': {
    title: '事件通知',
    entryPath: '/sand-iam/event-notification',
    entryPermission: 'sand_iam:webhook:index',
    audience: '应用管理员',
    description: '把用户和权限变化通知给业务系统，并查看是否送达。',
    steps: [
      {
        key: 'webhook',
        label: '事件通知',
        path: '/sand-iam/webhook',
        permission: 'sand_iam:webhook:index',
        emptyHint: '接收地址必须是 HTTPS；签名密钥只在创建或轮换成功后显示一次。',
        contractStatus: 'frozen'
      },
      {
        key: 'delivery',
        label: '投递记录',
        path: '/sand-iam/webhook-delivery',
        permission: 'sand_iam:webhook_delivery:index',
        emptyHint: '默认列表只显示处理结果；可重试的失败记录会显示重试操作。',
        contractStatus: 'frozen'
      }
    ]
  },
  'audit-troubleshooting': {
    title: '审计与排错',
    entryPath: '/sand-iam/audit-troubleshooting',
    entryPermission: 'sand_iam:audit:index',
    audience: '平台管理员、安全审计人员、应用技术接入人员',
    description: '查询谁在什么时候做了什么，并处理异常。',
    steps: [
      {
        key: 'audit',
        label: '访问审计',
        endpoint: 'audit',
        permission: 'sand_iam:audit:index',
        path: '/sand-iam/audit',
        emptyHint: '默认列表只显示处理所需信息。需要导出时，请按页面提示申请导出权限。'
      },
      {
        key: 'application-network-policy',
        label: '应用网络规则',
        endpoint: 'application-network-policy',
        permission: 'sand_iam:application_network_policy:index',
        path: '/sand-iam/application-network-policy',
        emptyHint: '拒绝优先。启用后可能立即挡住登录。完整 CIDR 只在详情。',
        contractStatus: 'frozen'
      },
      {
        key: 'security-alert',
        label: '安全告警',
        permission: 'sand_iam:security_alert:index',
        path: '/sand-iam/security-alert',
        emptyHint: '按应用查看告警等级、规则、次数和最近时间。指纹不进列表。',
        contractStatus: 'frozen'
      },
      {
        key: 'audit-retention-policy',
        label: '审计保留策略',
        endpoint: 'audit-retention-policy',
        permission: 'sand_iam:audit_retention_policy:index',
        path: '/sand-iam/audit-retention-policy',
        emptyHint: '按客户主体配置归档和保留天数。浏览器不能执行清除。',
        contractStatus: 'frozen'
      },
      {
        key: 'initialization',
        label: '初始化配置',
        permission: 'sand_iam:initialization:index',
        path: '/sand-iam/initialization',
        emptyHint: '先检查待应用的设置，再确认；发现变化时请重新检查后再继续。',
        contractStatus: 'frozen'
      }
    ],
    guides: [
      {
        title: '服务受众不匹配',
        symptom: '调用没有发送到目标服务。',
        recovery: '核对服务调用身份和服务授权中的目标服务，使用服务提供方给出的准确名称。',
        path: '/sand-iam/service-grant',
        permission: 'sand_iam:grant:index',
        actionLabel: '检查服务授权'
      },
      {
        title: '缺少服务动作授权',
        symptom: '调用身份没有目标动作的有效授权，或授权已到期、撤销。',
        recovery:
          '检查服务调用身份、服务动作、授权状态和有效期；不要通过扩大其他动作权限绕过拒绝。',
        path: '/sand-iam/service-grant',
        permission: 'sand_iam:grant:index',
        actionLabel: '核对服务授权'
      },
      {
        title: '调用凭证已撤销',
        symptom: '旧凭证不再可用，继续重试不会恢复。',
        recovery: '由有权管理员轮换或重新签发凭证，并通过安全渠道交付；旧凭证保持撤销。',
        path: '/sand-iam/credential',
        permission: 'sand_iam:credential:index',
        actionLabel: '处理调用凭证'
      },
      {
        title: '当前账号不在客户主体管理范围',
        symptom: '后台页面或操作超出了当前账号被委派的客户主体。',
        recovery:
          '确认当前对象所属客户主体；确需管理时联系平台管理员核对委派，不要改填其他对象编号。',
        path: '/sand-iam/admin-organization-grant',
        permission: 'sand_iam:admin_organization_grant:index',
        actionLabel: '查看管理委派'
      }
    ]
  }
}

export function recommendedSandIamTaskStep(
  steps: readonly SandIamTaskStepSnapshot[]
): SandIamTaskStepSnapshot | null {
  return (
    steps.find((step) => step.state === 'missing') ??
    steps.find((step) => step.state === 'error') ??
    steps.find((step) => step.state === 'forbidden') ??
    steps.find((step) => step.state === 'inspection') ??
    steps.find((step) => step.state === 'waiting') ??
    null
  )
}

export function sandIamTaskPathCanOpen(
  path: SandIamTaskPathDefinition,
  hasAuth: (permission: string) => boolean
): boolean {
  const permissions =
    path.entryPermissions ?? (path.entryPermission === undefined ? [] : [path.entryPermission])
  return permissions.some((permission) => hasAuth(permission))
}

/**
 * 总览不能自动确认时不发请求，避免把未知状态误报为空数据或读取失败。
 */
export function sandIamTaskStepNeedsRequest(step: SandIamTaskStepDefinition): boolean {
  return (
    step.contractStatus !== 'waiting' &&
    step.endpoint !== undefined &&
    step.permission !== undefined
  )
}

/**
 * 自定义页不能提供总览统计时，不由总览替用户判断“已设置”。
 * 仍会检查已知页面权限；页面自身再展示当前状态。
 */
export function sandIamTaskStepNeedsPageInspection(step: SandIamTaskStepDefinition): boolean {
  return step.contractStatus === 'frozen' && step.endpoint === undefined
}

export function sandIamTaskStepCanOpen(step: SandIamTaskStepSnapshot): boolean {
  return step.state !== 'forbidden'
}

/**
 * 只有每一步均有后端可核验记录时，才允许总览显示“当前已设置”。
 */
export function sandIamTaskPathIsComplete(steps: readonly SandIamTaskStepSnapshot[]): boolean {
  return steps.length > 0 && steps.every((step) => step.state === 'ready')
}
