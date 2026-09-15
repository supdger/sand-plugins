import type { SandIamFormField } from './types'
import { sandIamFieldLabel } from './presentation'
import { sandIamObjectCodeGuidance } from './uxContracts'

function field(
  key: string,
  kind: SandIamFormField['kind'],
  options: Omit<SandIamFormField, 'key' | 'label' | 'kind'> = {}
): SandIamFormField {
  return { key, label: sandIamFieldLabel(key), kind, ...options }
}

export const statusField: SandIamFormField = field('status', 'status')

function applicationContextFields(): [SandIamFormField, SandIamFormField] {
  return [
    field('organization_id', 'reference', {
      required: true,
      omitFromPayload: true,
      referenceEndpoint: 'organization',
      applicationGrantContext: 'organization'
    }),
    field('application_id', 'reference', {
      required: true,
      referenceEndpoint: 'application',
      applicationGrantContext: 'application',
      dependency: {
        sourceKey: 'organization_id',
        targetParam: 'organization_id'
      }
    })
  ]
}

function clientContextFields(): SandIamFormField[] {
  const [organization, application] = applicationContextFields()
  return [
    organization,
    { ...application, omitFromPayload: true },
    field('environment_id', 'reference', {
      required: true,
      omitFromPayload: true,
      referenceEndpoint: 'environment',
      dependency: { sourceKey: 'application_id', targetParam: 'application_id' }
    }),
    field('workload_client_id', 'reference', {
      required: true,
      referenceEndpoint: 'client',
      dependency: { sourceKey: 'environment_id', targetParam: 'environment_id' }
    })
  ]
}

export const organizationFields: SandIamFormField[] = [
  field('name', 'text', {
    required: true,
    placeholder: '例如：星河集团',
    help: '填写独立客户主体的名称，例如企业、学校或其他组织；具体产品请在「接入应用」中登记。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    systemObjectCode: true,
    ...sandIamObjectCodeGuidance('xinghe-group', '客户主体代码全平台唯一。')
  }),
  statusField
]

export const applicationFields: SandIamFormField[] = [
  field('organization_id', 'reference', {
    required: true,
    referenceEndpoint: 'organization',
    embeddedReferenceLabelKey: 'organization_name',
    embeddedReferenceEditableKey: 'organization_editable'
  }),
  field('name', 'text', {
    required: true,
    placeholder: '例如：客户服务平台',
    help: '填写需要接入的产品或项目名称；人员、客户主体和后台账号都不是接入应用。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    systemObjectCode: true,
    ...sandIamObjectCodeGuidance('customer-portal', '接入应用代码在客户主体内唯一。')
  }),
  statusField
]

export const environmentFields: SandIamFormField[] = [
  field('organization_id', 'reference', {
    required: true,
    createOnly: true,
    omitFromPayload: true,
    referenceEndpoint: 'organization',
    applicationGrantContext: 'organization'
  }),
  field('application_id', 'reference', {
    required: true,
    referenceEndpoint: 'application',
    applicationGrantContext: 'application',
    dependency: {
      sourceKey: 'organization_id',
      targetParam: 'organization_id'
    }
  }),
  field('name', 'text', {
    required: true,
    placeholder: '例如：生产环境',
    help: '同一接入应用的开发、测试或生产隔离边界，不是某个模型或某位用户。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    systemObjectCode: true,
    ...sandIamObjectCodeGuidance('production', '应用环境代码在接入应用内唯一。')
  }),
  statusField
]

export const clientFields: SandIamFormField[] = [
  field('organization_id', 'reference', {
    required: true,
    createOnly: true,
    omitFromPayload: true,
    referenceEndpoint: 'organization',
    applicationGrantContext: 'organization'
  }),
  field('application_id', 'reference', {
    required: true,
    createOnly: true,
    omitFromPayload: true,
    referenceEndpoint: 'application',
    applicationGrantContext: 'application',
    dependency: {
      sourceKey: 'organization_id',
      targetParam: 'organization_id'
    }
  }),
  field('environment_id', 'reference', {
    required: true,
    referenceEndpoint: 'environment',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' }
  }),
  field('name', 'text', {
    required: true,
    placeholder: '例如：客户服务平台生产后端',
    help: '应用后端调用已登记服务时使用的机器身份，不是人的登录账号。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    systemObjectCode: true,
    ...sandIamObjectCodeGuidance('backend', '服务调用身份代码在应用环境内唯一。')
  }),
  field('audience', 'text', {
    required: true,
    placeholder: '例如：sand-ai',
    help: '由服务提供方给出，用于确认调用发往正确服务；不要填写网址、模型名或临时备注。'
  }),
  statusField
]

export const grantFields: SandIamFormField[] = [
  ...clientContextFields(),
  field('service_id', 'reference', {
    required: true,
    omitFromPayload: true,
    referenceEndpoint: 'service',
    grantCandidate: 'services',
    dependency: { sourceKey: 'workload_client_id', targetParam: 'workload_client_id' }
  }),
  field('service_action_id', 'reference', {
    required: true,
    referenceEndpoint: 'action',
    grantCandidate: 'actions',
    dependency: { sourceKey: 'service_id', targetParam: 'service_id' },
    help: '选择已登记的服务能力，例如“文档解析”；它不是 HTTP 地址。'
  }),
  field('audience', 'text', {
    required: true,
    placeholder: '例如：sand-ai',
    help: '必须与服务调用身份和目标服务约定的 audience 一致；不一致会被拒绝。'
  }),
  field('quota_policy', 'json', {
    advanced: true,
    help: '高级可选配置：按需要填写，不影响基础服务授权。'
  }),
  field('data_class', 'text', {
    clearableToNull: true,
    help: '留空仅允许未指定数据分级的调用，不表示允许任意数据分级。'
  }),
  field('network_policy', 'json', {
    advanced: true,
    help: '高级可选配置：按需要填写，不影响基础服务授权。'
  }),
  field('expire_time', 'datetime', {
    clearableToNull: true,
    help: '可选。到期后授权不再生效；留空表示不在这里设置到期限制。'
  }),
  statusField
]

export const identityFields: SandIamFormField[] = [
  field('display_name', 'text', {
    required: true,
    placeholder: '例如：张三',
    help: '给人看的名称，例如成员姓名；不是后台管理员账号。'
  }),
  ...applicationContextFields(),
  field('code', 'text', {
    required: true,
    createOnly: true,
    systemObjectCode: true,
    ...sandIamObjectCodeGuidance('member-001', '应用用户代码在接入应用内唯一。')
  }),
  statusField
]

function policySwitch(
  key: string,
  onLabel: string,
  offLabel: string,
  help: string,
  defaultValue: string
): SandIamFormField {
  return field(key, 'select', {
    options: [
      { label: onLabel, value: '1' },
      { label: offLabel, value: '2' }
    ],
    help,
    defaultValue
  })
}

/**
 * 认证策略只绑定 AuthPolicyController 已冻结 writeFields。
 * 公开注册、密码规则、验证码开关和失败锁定都在这一条应用级记录上。
 */
export const authPolicyFields: SandIamFormField[] = [
  ...applicationContextFields(),
  policySwitch(
    'registration_enabled',
    '开放注册',
    '关闭注册',
    '默认关闭。开放后，应用用户可在接入应用中自行注册；关闭后只能由管理员创建用户。',
    '2'
  ),
  field('password_min_length', 'number', {
    required: true,
    defaultValue: '12',
    placeholder: '12',
    help: '允许范围 12–128。改短后不会自动改已有密码，只影响新密码和重置。'
  }),
  field('password_max_length', 'number', {
    required: true,
    defaultValue: '128',
    placeholder: '128',
    help: '允许范围 12–128，且不能小于最短位数。'
  }),
  policySwitch('require_uppercase', '需要', '不需要', '新密码是否必须包含大写字母。', '1'),
  policySwitch('require_lowercase', '需要', '不需要', '新密码是否必须包含小写字母。', '1'),
  policySwitch('require_digit', '需要', '不需要', '新密码是否必须包含数字。', '1'),
  policySwitch('require_symbol', '需要', '不需要', '新密码是否必须包含符号。', '1'),
  policySwitch(
    'require_email_verification',
    '需要',
    '不需要',
    '开启后，邮箱验证完成前不会签发登录会话。验证码由已挂载的消息服务发送。',
    '2'
  ),
  policySwitch(
    'require_phone_verification',
    '需要',
    '不需要',
    '开启后，手机验证完成前不会签发登录会话。如需短信验证，请先完成消息服务配置。',
    '2'
  ),
  policySwitch(
    'require_captcha',
    '需要',
    '不需要',
    '开启后登录必须通过验证码。验证码供应商配置属于消息服务，本页只控制开关。',
    '2'
  ),
  field('max_login_failures', 'number', {
    required: true,
    defaultValue: '5',
    placeholder: '5',
    help: '连续失败达到次数后锁定账号。允许范围 3–20。'
  }),
  field('lock_seconds', 'number', {
    required: true,
    defaultValue: '900',
    placeholder: '900',
    help: '锁定时长，单位秒。允许范围 60–86400。默认 900 秒（15 分钟）。'
  }),
  field('rate_limit_per_minute', 'number', {
    required: true,
    defaultValue: '10',
    placeholder: '10',
    help: '同一应用每分钟认证请求上限。允许范围 1–1000。'
  }),
  field('access_token_ttl_seconds', 'number', {
    advanced: true,
    defaultValue: '900',
    placeholder: '900',
    help: '访问令牌有效秒数，允许 60–3600。默认 15 分钟。'
  }),
  field('refresh_token_ttl_seconds', 'number', {
    advanced: true,
    defaultValue: '2592000',
    placeholder: '2592000',
    help: '刷新令牌有效秒数，允许 300–7776000。默认 30 天。'
  }),
  field('verification_ttl_seconds', 'number', {
    advanced: true,
    defaultValue: '600',
    placeholder: '600',
    help: '验证码有效秒数，允许 60–3600。默认 10 分钟。'
  }),
  field('webauthn_rp_id', 'text', {
    advanced: true,
    clearableToEmptyString: true,
    defaultValue: '',
    placeholder: 'login.example.com',
    help: '通行密钥 RP ID，必须是小写域名；与允许来源同时填写或同时留空。'
  }),
  field('webauthn_allowed_origins', 'json', {
    advanced: true,
    jsonArray: true,
    allowEmptyArray: true,
    defaultValue: '',
    help: '逐行填写允许来源，最多 20 项，例如 https://login.example.com。必须是小写 HTTPS 来源且不能带路径。'
  }),
  field('webauthn_user_verification', 'select', {
    advanced: true,
    defaultValue: 'required',
    options: [
      { label: '必须验证', value: 'required' },
      { label: '尽量验证', value: 'preferred' },
      { label: '不要求验证', value: 'discouraged' }
    ],
    help: '通行密钥用户验证策略。未配置通行密钥时可保持默认。'
  }),
  statusField
]

export const identityBindingFields: SandIamFormField[] = [
  ...applicationContextFields().map((item) => ({
    ...item,
    omitFromPayload: true
  })),
  field('identity_id', 'reference', {
    required: true,
    createOnly: true,
    referenceEndpoint: 'identity',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' }
  }),
  field('identity_provider_id', 'reference', {
    required: true,
    createOnly: true,
    referenceEndpoint: 'identity-provider',
    dependency: {
      sourceKey: 'identity_id',
      targetParam: 'application_id',
      sourceEndpoint: 'identity',
      sourceValueKey: 'application_id'
    }
  }),
  field('subject', 'text', { required: true, createOnly: true }),
  statusField
]

export const namedAppFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('code', 'text', {
    required: true,
    createOnly: true,
    ...codeGuidance('manager')
  }),
  field('name', 'text', { required: true }),
  statusField
]

export const resourceFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('code', 'text', {
    required: true,
    createOnly: true,
    ...codeGuidance('case')
  }),
  field('name', 'text', { required: true }),
  field('owner_field', 'text'),
  field('organization_field', 'text'),
  statusField
]

export const policyFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('resource_id', 'reference', {
    required: true,
    referenceEndpoint: 'resource',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' }
  }),
  field('role_id', 'reference', {
    referenceEndpoint: 'role',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' },
    help: '策略主体二选一：选择角色，或选择下面的应用身份。'
  }),
  field('identity_id', 'reference', {
    referenceEndpoint: 'identity',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' },
    help: '策略主体二选一：选择应用身份，或选择上面的角色。'
  }),
  field('action', 'text', {
    required: true,
    help: '稳定的业务语义动作，例如 work_item.read；不是页面按钮或 HTTP 方法。'
  }),
  field('effect', 'select', {
    required: true,
    options: [
      { label: '允许', value: 'allow' },
      { label: '拒绝', value: 'deny' }
    ]
  }),
  field('condition', 'condition', {
    help: '可选。用 equals 或 in 描述何时生效。'
  }),
  field('scope', 'condition', {
    help: '可选。用 equals 或 in 描述可访问的数据范围。'
  }),
  field('priority', 'number'),
  field('state', 'select', {
    options: [
      { label: '草稿', value: 'draft' },
      { label: '已发布', value: 'published' },
      { label: '已撤销', value: 'revoked' }
    ]
  }),
  statusField
]

export const adminGrantFields: SandIamFormField[] = [
  field('admin_user_id', 'reference', {
    required: true,
    referenceEndpoint: 'admin-organization-grant',
    organizationAdminCandidate: true,
    help: '输入至少 2 个字符搜索已有后台管理员。'
  }),
  field('organization_id', 'reference', {
    required: true,
    referenceEndpoint: 'organization'
  }),
  statusField
]

export const adminApplicationGrantFields: SandIamFormField[] = [
  field('admin_user_id', 'number', {
    required: true,
    createOnly: true,
    help: '必须通过管理员名称搜索选择，禁止手填编号。提交字段仍是 admin_user_id。'
  }),
  field('application_id', 'reference', {
    required: true,
    createOnly: true,
    referenceEndpoint: 'application'
  }),
  statusField
]

export const applicationExperienceFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('brand_name', 'text', {
    required: true,
    help: '登录页标题，取自当前接入应用品牌，不要写死其他产品名。'
  }),
  field('logo_url', 'text', {
    clearableToEmptyString: true,
    help: '仅 HTTPS。完整地址不进默认列表。'
  }),
  field('primary_color', 'text', {
    placeholder: '#1677ff',
    help: '品牌主色，格式 #RRGGBB。'
  }),
  field('theme_mode', 'select', {
    options: [
      { label: '跟随系统', value: 'system' },
      { label: '浅色', value: 'light' },
      { label: '深色', value: 'dark' }
    ],
    defaultValue: 'system'
  }),
  field('default_locale', 'text', {
    defaultValue: 'zh-CN',
    advanced: true
  }),
  field('terms_url', 'text', {
    clearableToEmptyString: true,
    help: '仅 HTTPS。',
    advanced: true
  }),
  field('privacy_url', 'text', {
    clearableToEmptyString: true,
    help: '仅 HTTPS。',
    advanced: true
  }),
  field('registration_mode', 'select', {
    required: true,
    options: [
      { label: '开放注册', value: 'open' },
      { label: '邀请注册', value: 'invite' },
      { label: '关闭注册', value: 'disabled' }
    ],
    defaultValue: 'disabled',
    help: '开放注册必须同时启用密码登录。邀请注册的接受页归后续任务。'
  }),
  field('login_methods', 'select', {
    required: true,
    multiple: true,
    options: [
      { label: '密码登录', value: 'password' },
      { label: '通行密钥登录', value: 'passkey' }
    ],
    defaultValue: 'password',
    help: '选择用户可用的本地登录方式。需要联合登录时，请先在「联合配置」中完成身份源设置，再由接入应用启用对应方式。'
  }),
  field('registration_fields', 'select', {
    multiple: true,
    options: [
      { label: '登录名', value: 'username' },
      { label: '显示名称', value: 'display_name' },
      { label: '邮箱', value: 'email' },
      { label: '手机号', value: 'phone' }
    ],
    defaultValue: 'username,display_name,email',
    help: '选择注册时收集的信息。必须包含登录名，并至少选择邮箱或手机号；保存前会检查。'
  }),
  statusField
]

export const serviceFields: SandIamFormField[] = [
  field('name', 'text', { required: true }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    ...codeGuidance('sand-ai')
  }),
  statusField
]

export const actionFields: SandIamFormField[] = [
  field('service_id', 'reference', {
    required: true,
    referenceEndpoint: 'service'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    placeholder: 'sand_ai.document_parse',
    help: '服务动作的稳定语义代码，不是 HTTP 地址；创建后不可修改。使用小写字母、数字、句点（.）、连字符（-）或下划线（_），例如：sand_ai.document_parse。'
  }),
  field('name', 'text', { required: true }),
  statusField
]

export const credentialFields: SandIamFormField[] = [
  ...clientContextFields(),
  field('name', 'text', { required: true }),
  field('expire_time', 'datetime', {
    help: '可选。请选择凭证停止可用的日期和时间；留空表示不在这里设置到期限制。'
  })
]

export const oauthClientFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('name', 'text', {
    required: true,
    placeholder: '例如：客户服务网页',
    help: '给人看的客户端名称。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    systemObjectCode: true,
    ...codeGuidance('customer-web')
  }),
  field('client_type', 'select', {
    required: true,
    createOnly: true,
    defaultValue: 'confidential',
    options: [
      { label: '机密客户端', value: 'confidential' },
      { label: '公开客户端', value: 'public' }
    ],
    help: '机密客户端会签发一次客户端密钥；公开客户端没有密钥。类型创建后不可改。'
  }),
  field('redirect_uris', 'json', {
    required: true,
    jsonArray: true,
    defaultValue: 'https://app.example.com/callback',
    help: '精确 HTTPS 回调地址列表，最多 50 项。公开客户端可使用 127.0.0.1 或 ::1 的本机 HTTP。完整地址不进入默认列表。'
  }),
  field('allowed_scopes', 'json', {
    jsonArray: true,
    defaultValue: 'openid\nprofile',
    help: '协议范围代码，不是 URL。'
  }),
  field('post_logout_redirect_uris', 'json', {
    jsonArray: true,
    allowEmptyArray: true,
    advanced: true,
    defaultValue: '',
    help: '可选。登出后允许回到的精确地址。'
  }),
  field('frontchannel_logout_uri', 'text', {
    clearableToEmptyString: true,
    advanced: true,
    help: '可选。必须是与某个登录回调同源的精确 HTTPS，不能带账号、片段或通配符。完整地址不进入默认列表。功能开关关闭时，后端不会伪造成功投递。'
  }),
  field('frontchannel_logout_session_required', 'select', {
    advanced: true,
    defaultValue: 'true',
    options: [
      { label: '携带会话标识', value: 'true' },
      { label: '不携带会话标识', value: 'false' }
    ],
    help: '前通道登出是否在地址上携带会话标识。这不是客户端密钥。开关关闭时页面不会显示已投递。'
  }),
  field('backchannel_logout_uri', 'text', {
    clearableToEmptyString: true,
    advanced: true,
    help: '可选。必须是与某个登录回调同源的精确 HTTPS，不能带账号、片段或通配符。完整地址不进入默认列表。功能开关关闭时，后端不会伪造成功投递。'
  }),
  field('backchannel_logout_session_required', 'select', {
    advanced: true,
    defaultValue: 'true',
    options: [
      { label: '携带会话标识', value: 'true' },
      { label: '不携带会话标识', value: 'false' }
    ],
    help: '后通道登出是否在请求中携带会话标识。这不是客户端密钥。开关关闭时页面不会显示已投递。'
  }),
  field('allowed_audiences', 'json', {
    jsonArray: true,
    allowEmptyArray: true,
    advanced: true,
    defaultValue: '',
    help: '可选。机器访问受众，最多 30 项。'
  }),
  field('default_audience', 'text', {
    clearableToEmptyString: true,
    advanced: true,
    help: '可选。必须已经出现在允许受众中。'
  }),
  statusField
]

/**
 * CAS 服务地址创建后不可改；可返回资料只允许 display_name / email。
 */
export const casServiceFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('name', 'text', {
    required: true,
    placeholder: '例如：业务系统 CAS',
    help: '给人看的接入服务名称。'
  }),
  field('service_url', 'text', {
    required: true,
    createOnly: true,
    placeholder: 'https://app.example.com/cas/callback',
    help: '精确 HTTPS 服务地址，不能带通配符、账号信息或片段。创建后不可修改；要改地址请新增服务并停用旧记录。完整地址只在表单出现。'
  }),
  field('released_attributes', 'json', {
    jsonArray: true,
    allowEmptyArray: true,
    defaultValue: 'display_name',
    help: '最多两项，只能选择 display_name（显示名称）和 email（邮箱）。留空不返回这两项资料。'
  }),
  statusField
]

/**
 * 网络规则每个应用一条；列表只显示网段数量，完整 CIDR 留在表单。
 */
export const applicationNetworkPolicyFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('allow_cidrs', 'json', {
    jsonArray: true,
    allowEmptyArray: true,
    defaultValue: '',
    help: '允许网段，规范 IPv4/IPv6 CIDR，例如 10.20.0.0/24。每类最多 64 项；留空不限制允许网段，仍检查拒绝网段。完整 CIDR 不进入默认列表。'
  }),
  field('deny_cidrs', 'json', {
    jsonArray: true,
    allowEmptyArray: true,
    defaultValue: '',
    help: '拒绝网段优先于允许网段；留空表示没有拒绝网段。启用后可能立即挡住当前登录来源。不要把自己的办公网填进拒绝列表后立刻保存。'
  }),
  statusField
]

/**
 * 审计保留每个客户主体一条。浏览器不能执行清除，只记录策略意图。
 */
export const auditRetentionPolicyFields: SandIamFormField[] = [
  field('organization_id', 'reference', {
    required: true,
    referenceEndpoint: 'organization'
  }),
  field('archive_after_days', 'number', {
    required: true,
    defaultValue: '90',
    help: '归档天数，1–3650。保留期不得短于归档期。'
  }),
  field('retention_days', 'number', {
    required: true,
    defaultValue: '365',
    help: '保留天数，必须大于或等于归档天数，最大 3650。'
  }),
  field('purge_enabled', 'select', {
    defaultValue: 'false',
    options: [
      { label: '仅记录策略，部署侧未开放清除', value: 'false' },
      { label: '记录为允许部署侧清除', value: 'true' }
    ],
    help: '浏览器不能计算确认摘要，也不能执行审计清除。此开关只记录策略意图。'
  }),
  field('alert_window_seconds', 'number', {
    advanced: true,
    defaultValue: '300',
    help: '告警窗口秒数，60–86400。'
  }),
  field('alert_failure_threshold', 'number', {
    advanced: true,
    defaultValue: '5',
    help: '窗口内失败次数阈值，2–10000。'
  }),
  statusField
]

export const businessActionFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('name', 'text', {
    required: true,
    placeholder: '例如：查看订单',
    help: '给人看的业务动作名称。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    placeholder: 'order.read',
    help: '稳定语义代码，不是 URL。须以小写字母开头，最长 96 位，可含点、下划线、冒号或短横线。创建后不可修改。'
  }),
  field('description', 'text', {
    placeholder: '读取一笔订单的已授权字段',
    help: '中文说明，供 SDK 常量和文档使用。'
  }),
  field('state', 'select', {
    defaultValue: 'draft',
    options: [
      { label: '草稿', value: 'draft' },
      { label: '已发布', value: 'published' }
    ],
    help: '发布只是把草稿固化为可审计声明；代码从创建起就不能改。'
  }),
  statusField
]

export const apiResourceFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('name', 'text', {
    required: true,
    placeholder: '例如：查看订单详情',
    help: '给人看的接口名称。权限长期依据是业务资源 + 语义动作，不是 URL。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    placeholder: 'order.detail',
    help: 'SDK 和文档使用的稳定接口代码。创建后不可修改。'
  }),
  field('resource_id', 'reference', {
    required: true,
    createOnly: true,
    referenceEndpoint: 'resource',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' },
    help: '必须先登记业务资源。'
  }),
  field('action', 'text', {
    required: true,
    createOnly: true,
    placeholder: 'order.read',
    help: '稳定的业务语义动作，必须引用同一应用中已启用的声明；不是页面按钮或 HTTP 方法。'
  }),
  field('operation', 'select', {
    required: true,
    createOnly: true,
    defaultValue: 'read',
    options: [
      { label: '列表', value: 'list' },
      { label: '详情', value: 'read' },
      { label: '新增', value: 'create' },
      { label: '修改', value: 'update' },
      { label: '删除', value: 'delete' },
      { label: '导出', value: 'export' },
      { label: '批量', value: 'batch' }
    ]
  }),
  field('api_version', 'text', {
    required: true,
    createOnly: true,
    defaultValue: 'v1',
    help: '支持并行升级。创建后不可修改。'
  }),
  field('audience', 'text', {
    required: true,
    placeholder: 'business-api',
    help: 'OAuth 访问凭据可访问的目标服务，不要填网址。'
  }),
  field('risk_level', 'select', {
    required: true,
    defaultValue: 'medium',
    options: [
      { label: '低', value: 'low' },
      { label: '中', value: 'medium' },
      { label: '高', value: 'high' },
      { label: '关键', value: 'critical' }
    ]
  }),
  field('required_scope', 'text', {
    advanced: true,
    clearableToEmptyString: true,
    help: '可选。使用 OAuth 时的最小范围；清空会移除此接口的最小 Scope 要求，其他授权校验仍然生效。'
  }),
  field('description', 'text', {
    advanced: true,
    help: '可选。业务用途说明，最多 500 字。'
  }),
  statusField
]

export const routeBindingFields: SandIamFormField[] = [
  ...applicationContextFields(),
  field('api_resource_id', 'reference', {
    required: true,
    createOnly: true,
    referenceEndpoint: 'api-resource',
    dependency: { sourceKey: 'application_id', targetParam: 'application_id' },
    help: '扫描只发现路由，不会自动创建接口、动作或策略。'
  }),
  field('http_method', 'select', {
    required: true,
    createOnly: true,
    defaultValue: 'GET',
    options: [
      { label: 'GET', value: 'GET' },
      { label: 'POST', value: 'POST' },
      { label: 'PUT', value: 'PUT' },
      { label: 'PATCH', value: 'PATCH' },
      { label: 'DELETE', value: 'DELETE' }
    ]
  }),
  field('route_template', 'text', {
    required: true,
    createOnly: true,
    placeholder: '/api/customer/v1/orders/{id}',
    help: 'Webman 路由模板，须以 / 开头。不要写域名、查询参数或真实用户输入。变更方法或模板请新建绑定。'
  }),
  field('source', 'select', {
    createOnly: true,
    defaultValue: 'manual',
    options: [
      { label: '手工登记', value: 'manual' },
      { label: 'OpenAPI 导入', value: 'openapi' },
      { label: '路由扫描', value: 'route_scan' }
    ]
  }),
  statusField
]

export const identityProviderFields: SandIamFormField[] = [
  field('name', 'text', {
    required: true,
    placeholder: '例如：本所统一登录',
    help: '给人看的身份源名称，例如本地账号或企业统一登录。'
  }),
  field('organization_id', 'reference', {
    required: true,
    referenceEndpoint: 'organization'
  }),
  field('scope_type', 'select', {
    required: true,
    createOnly: true,
    defaultValue: 'application',
    options: [
      { label: '仅本接入应用', value: 'application' },
      { label: '整个客户主体可挂载', value: 'organization' }
    ],
    help: '应用级只给一个产品使用；组织级可再挂到多个接入应用。创建后不可改范围。'
  }),
  field('application_id', 'reference', {
    createOnly: true,
    referenceEndpoint: 'application',
    dependency: {
      sourceKey: 'organization_id',
      targetParam: 'organization_id'
    },
    help: '仅本接入应用时必选；整个客户主体可挂载时请留空，再到联合配置页挂载。'
  }),
  field('code', 'text', {
    required: true,
    createOnly: true,
    placeholder: 'local-account',
    help: '身份源标识创建后不可修改。须以小写字母开头，最长 64 位，可含小写字母、数字、点、下划线、冒号或短横线。'
  }),
  statusField
]

function codeGuidance(example: string): Pick<SandIamFormField, 'help' | 'placeholder'> {
  return {
    help:
      '用于接口配置的稳定系统代码；创建后不可修改。使用小写字母、数字、连字符（-）和下划线（_），例如：' +
      example,
    placeholder: example
  }
}
