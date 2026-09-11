import type { SandIamResourceEndpoint } from './types'

const fieldLabels: Readonly<Record<string, string>> = {
  keywords: '名称关键词',
  id: '编号',
  code: '系统代码（用于接口配置）',
  name: '名称',
  status: '状态',
  organization_id: '所属客户主体',
  application_id: '所属接入应用',
  environment_id: '所属应用环境',
  workload_client_id: '服务调用身份',
  service_id: '平台服务',
  service_action_id: '服务动作',
  identity_id: '应用身份',
  role_id: '角色',
  user_type_id: '用户类型',
  admin_user_id: '后台管理员',
  display_name: '显示名称',
  identity_provider_id: '身份源',
  provider_code: '旧版身份源标识',
  subject: '外部账号标识',
  audience: '服务受众',
  key_prefix: '凭证前缀',
  expire_time: '过期时间',
  update_time: '最近更新',
  create_time: '操作时间',
  data_class: '数据等级',
  owner_field: '所有者字段',
  organization_field: '组织字段',
  resource_id: '业务资源',
  action: '业务动作',
  effect: '授权效果',
  priority: '优先级',
  state: '发布状态',
  condition: '生效条件',
  scope: '数据范围',
  quota_policy: '额度规则',
  network_policy: '网络限制',
  actor_type: '操作主体类型',
  actor_ref: '操作主体',
  resource_type: '资源类型',
  outcome: '处理结果',
  registration_enabled: '公开注册',
  password_min_length: '密码最短位数',
  password_max_length: '密码最长位数',
  require_uppercase: '要求大写字母',
  require_lowercase: '要求小写字母',
  require_digit: '要求数字',
  require_symbol: '要求符号',
  require_email_verification: '要求验证邮箱',
  require_phone_verification: '要求验证手机',
  require_captcha: '登录验证码',
  access_token_ttl_seconds: '访问令牌有效秒数',
  refresh_token_ttl_seconds: '刷新令牌有效秒数',
  verification_ttl_seconds: '验证码有效秒数',
  max_login_failures: '失败锁定次数',
  lock_seconds: '锁定秒数',
  rate_limit_per_minute: '每分钟请求上限',
  webauthn_rp_id: '通行密钥域名',
  webauthn_allowed_origins: '通行密钥允许来源',
  webauthn_user_verification: '通行密钥用户验证',
  scope_type: '使用范围',
  provider_type: '服务类型',
  secret_configured: '密钥状态',
  public_code: '公开标识',
  config_version: '配置版本',
  client_type: '客户端类型',
  redirect_uris: '登录回调地址',
  post_logout_redirect_uris: '登出回跳地址',
  frontchannel_logout_uri: '前通道登出地址',
  frontchannel_logout_session_required: '前通道携带会话标识',
  backchannel_logout_uri: '后通道登出地址',
  backchannel_logout_session_required: '后通道携带会话标识',
  service_url: '服务地址',
  released_attributes: '允许返回的用户资料',
  source_cidr: '来源网段',
  accounting_enabled: '记账',
  allow_cidrs: '允许网段',
  deny_cidrs: '拒绝网段',
  archive_after_days: '归档天数',
  retention_days: '保留天数',
  purge_enabled: '清除策略',
  alert_window_seconds: '告警窗口秒数',
  alert_failure_threshold: '告警失败次数',
  severity: '告警等级',
  rule_code: '告警规则',
  occurrence_count: '出现次数',
  first_seen_time: '首次时间',
  last_seen_time: '最近时间',
  package_code: '初始化包代码',
  applied_time: '应用时间',
  remaining_uses: '剩余次数',
  allowed_scopes: '允许范围',
  allowed_audiences: '机器访问受众',
  default_audience: '默认机器访问受众',
  secret_version: '客户端密钥状态',
  resource_code: '业务资源代码',
  operation: '数据操作类型',
  api_version: '接口版本',
  required_scope: 'OAuth Scope',
  risk_level: '风险等级',
  description: '说明',
  api_resource_id: '绑定接口',
  http_method: '请求方法',
  route_template: '路由模板',
  source: '来源',
  request_id: '请求标识',
  event_type: '事件类型',
  event_id: '事件标识',
  event_types: '订阅事件',
  last_error_code: '错误码',
  attempt_count: '尝试次数',
  admin_user_name: '后台管理员',
  brand_name: '应用显示名称',
  logo_url: 'Logo 地址',
  primary_color: '品牌主色',
  theme_mode: '默认主题',
  default_locale: '默认语言',
  terms_url: '服务协议',
  privacy_url: '隐私政策',
  registration_mode: '注册方式',
  login_methods: '登录方式',
  registration_fields: '注册字段',
  driver_code: '驱动代码',
  config_configured: '配置状态'
}

const valueLabels: Readonly<Record<string, Readonly<Record<string, string>>>> = {
  status: { '1': '已启用', '2': '已停用' },
  registration_enabled: { '1': '已开放', '2': '已关闭' },
  require_uppercase: { '1': '需要', '2': '不需要' },
  require_lowercase: { '1': '需要', '2': '不需要' },
  require_digit: { '1': '需要', '2': '不需要' },
  require_symbol: { '1': '需要', '2': '不需要' },
  require_email_verification: { '1': '需要', '2': '不需要' },
  require_phone_verification: { '1': '需要', '2': '不需要' },
  require_captcha: { '1': '需要', '2': '不需要' },
  webauthn_user_verification: {
    required: '必须验证',
    preferred: '尽量验证',
    discouraged: '不要求验证'
  },
  scope_type: {
    application: '仅本接入应用',
    organization: '整个客户主体可挂载'
  },
  provider_type: {
    local: '本地账号',
    oidc: 'OpenID Connect',
    oauth2: 'OAuth 2.0',
    saml: 'SAML',
    ldap: 'LDAP 目录',
    scim: 'SCIM 供给',
    kerberos: 'Kerberos',
    email: '邮件',
    sms: '短信',
    captcha: '人机验证',
    notification: '站外安全通知'
  },
  secret_configured: {
    true: '已配置',
    false: '未配置',
    '1': '已配置',
    '2': '未配置'
  },
  effect: { allow: '允许', deny: '拒绝' },
  state: { draft: '草稿', published: '已发布', revoked: '已撤销' },
  actor_type: {
    admin: '后台管理员',
    workload_client: '服务调用身份',
    context: '身份上下文',
    identity: '应用身份'
  },
  outcome: {
    succeeded: '成功',
    denied: '拒绝',
    allowed: '允许',
    failed: '失败'
  },
  alert_status: {
    open: '待处理',
    acknowledged: '已确认',
    resolved: '已处理'
  },
  initialization_state: {
    applied: '已应用',
    rolled_back: '已回滚'
  },
  client_type: { public: '公开客户端', confidential: '机密客户端' },
  frontchannel_logout_session_required: {
    true: '携带会话标识',
    false: '不携带会话标识'
  },
  backchannel_logout_session_required: {
    true: '携带会话标识',
    false: '不携带会话标识'
  },
  accounting_enabled: {
    true: '已开启',
    false: '未开启',
    '1': '已开启',
    '2': '未开启'
  },
  purge_enabled: {
    true: '仅记录策略，部署侧未开放清除',
    false: '仅记录策略，部署侧未开放清除'
  },
  severity: {
    low: '低',
    medium: '中',
    high: '高',
    critical: '关键'
  },
  rule_code: {},
  released_attributes: {
    display_name: '显示名称',
    email: '邮箱'
  },
  operation: {
    list: '列表',
    read: '详情',
    create: '新增',
    update: '修改',
    delete: '删除',
    export: '导出',
    batch: '批量'
  },
  risk_level: {
    low: '低',
    medium: '中',
    high: '高',
    critical: '关键'
  },
  http_method: {
    GET: 'GET',
    POST: 'POST',
    PUT: 'PUT',
    PATCH: 'PATCH',
    DELETE: 'DELETE'
  },
  source: {
    manual: '手工登记',
    openapi: 'OpenAPI 导入',
    route_scan: '路由扫描'
  },
  theme_mode: { light: '浅色', dark: '深色', system: '跟随系统' },
  registration_mode: {
    open: '开放注册',
    invite: '邀请注册',
    disabled: '关闭注册'
  },
  config_configured: {
    true: '已配置',
    false: '未配置',
    '1': '已配置',
    '2': '未配置'
  }
}

const referenceEndpoints: Readonly<Record<string, SandIamResourceEndpoint>> = {
  organization_id: 'organization',
  application_id: 'application',
  environment_id: 'environment',
  workload_client_id: 'client',
  service_id: 'service',
  service_action_id: 'action',
  identity_id: 'identity',
  role_id: 'role',
  user_type_id: 'user-type',
  resource_id: 'resource',
  identity_provider_id: 'identity-provider',
  api_resource_id: 'api-resource'
}

export function sandIamFieldLabel(key: string, fallback = key): string {
  return fieldLabels[key] ?? fallback
}

export function sandIamValueLabel(key: string, value: string): string {
  return valueLabels[key]?.[value] ?? value
}

export function sandIamReferenceEndpoint(key: string): SandIamResourceEndpoint | null {
  return referenceEndpoints[key] ?? null
}
