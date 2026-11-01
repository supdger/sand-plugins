import { sandIamTaskPaths } from './taskPaths'
import type { SandIamTaskPath, SandIamTaskStepDefinition } from './taskPaths'
import { taskStepHelp } from './taskInstructions'
import { taskContextQuery, taskStepParams } from './taskContext'
import type { TaskContext } from './taskContext'

export interface GoalStep extends SandIamTaskStepDefinition {
  readonly owner: string
  readonly input: string
  readonly result: string
  readonly recovery: string
  readonly optional?: boolean
}
export interface GuidanceGoal {
  readonly id: string
  readonly title: string
  readonly situation: string
  readonly outcome: string
  readonly skip: string
  readonly steps: readonly GoalStep[]
}
const recovery = '保存失败时按页面提示处理；没有权限请由该应用的管理员完成本步。离开后可从上方返回目标，重新读取状态，不要重复创建。'
function step(path: SandIamTaskPath, key: string, overrides: Partial<GoalStep> = {}): GoalStep {
  const definition = sandIamTaskPaths[path].steps.find(item => item.key === key)
  if (!definition) throw new Error(`Unknown IAM task step: ${path}/${key}`)
  const help = taskStepHelp(path, key)
  return { ...definition, owner: help?.owner ?? '应用管理员与接入开发者',
    input: help?.input ?? definition.emptyHint, result: help?.result ?? '保存后核对所选应用和配置，再在接入系统验证结果。', recovery, ...overrides }
}
const developer = step('api-governance', 'developer-docs', {
  label: '开发者接入与验证', owner: '接入开发者',
  input: '管理员提供客户主体与应用代码、已登记资源和动作。开发者按接入页的 SDK 示例接入。HTTP decide 需要 SandIAM 本地登录或 OAuth 签发的用户令牌；其他系统现有 token 不能直接当作 SandIAM 用户令牌。',
  result: '业务后端执行授权并落实返回的数据范围，分别验证允许、拒绝、范围外直接访问和撤销。不能仅隐藏按钮。'
})
const businessAction = step('api-governance', 'business-action', {
  input: '先核对当前应用已启用的业务动作并复用。没有所需动作时，请接入开发者提供准确的动作代码、名称和说明，由有权管理员声明；代码必须与业务后端实际鉴权保持一致。',
  result: '当前应用已有所需的启用动作，配置策略或接口时可以按名称选择。声明动作本身不会授予任何人权限。',
  emptyHint: '先声明并启用所需业务动作，再配置策略或接口。'
})
const portalExperience = step('auth-session', 'application-experience', {
  label: '内置门户登录外观',
  owner: '应用管理员',
  input: '先核对并复用当前应用已有的登录外观；没有时再新建，每个应用最多一条。填写应用显示名称，启用密码登录并保持记录启用；邀请开户选「邀请注册」，用户自行开户选「开放注册」。认证策略及部署开关仍须允许对应方式。',
  result: '内置应用门户能显示当前应用的品牌和登录页；开放注册时显示注册入口，邀请开户使用收到的邀请链接。随后用真实应用账号验证，保存记录不表示注册或登录已成功。',
  recovery: '已有停用记录时由有权管理员核对后恢复，不重复新建。门户提示登录外观未配置时，核对记录启用状态、客户主体代码和应用代码。'
})
export const guidanceGoals: readonly GuidanceGoal[] = [
  { id: 'login', title: '让用户登录应用', situation: '应用还没有可用的用户登录入口。', outcome: '用户能开通账号并登录，错误密码被拒绝。', skip: '已有登录需先衔接 SandIAM 的应用身份与可信令牌，再配置权限；企业账号登录请选择“使用已有企业账号登录”。', steps: [
    step('people-access', 'auth-settings'), portalExperience, step('people-access', 'identity-invitation'),
    step('people-access', 'employee-login'), step('people-access', 'identity'), { ...developer, input: '管理员提供组织与应用代码、已开放的登录方式。开发者将 SandIAM 登录及 MFA 接入应用，使用本地登录或 OAuth 签发的会话；不能直接使用其他系统 token。', result: '用户能登录本应用，错误密码被拒绝，退出后旧会话不可再访问账户。此目标不要求配置资源、策略或数据范围。' },
    step('people-access', 'audit') ] },
  { id: 'access', title: '控制权限和数据范围', situation: '已有用户，需要决定能做什么、能看哪些数据。', outcome: '同一业务操作有权允许、无权拒绝，范围外数据不能读取。', skip: '复用现有身份与登录；按用户直接授权时可跳过角色及角色分配。', steps: [
    step('people-access', 'identity'), step('people-access', 'resource'), businessAction,
    step('people-access', 'role', { optional: true }), step('people-access', 'identity-role', { optional: true }),
    step('people-access', 'policy'), step('people-access', 'policy-simulate'), developer,
    step('people-access', 'audit') ] },
  { id: 'machine', title: '让后端调用服务', situation: '应用后端要调用另一个服务，例如 AI 或工作流能力。', outcome: '服务真实完成一次调用，无权动作、错误受众及已撤销凭证被拒绝。', skip: '不需要开通员工账号或配置人员登录。复用已有应用、环境和调用身份。', steps: [
    step('connection', 'client'), step('connection', 'grant', {
      input: '从当前调用身份可用的服务及动作目录选择已有能力。若没有所需服务或动作，请服务提供方或平台管理员先登记并启用，再回来刷新；不要为调用服务另建应用业务动作。',
      result: '当前调用身份获得所需服务动作的授权，受众与提供方给出的准确值一致；尚需签发凭证并完成真实调用。'
    }), step('connection', 'credential'),
    { ...developer, key: 'machine-integration', label: '交付并执行一次服务调用',
      input: '管理员安全交付调用凭证及应用、环境、调用身份；服务提供方给出调用地址、服务动作、受众和 SDK。使用服务提供方的机器调用示例，不使用人员登录或 decide 示例。',
      result: '服务提供方确认业务结果，调用方确认错误受众、无权动作和撤销后请求被拒绝；按请求号核对双方审计。' },
    step('connection', 'audit') ] },
  { id: 'federation', title: '使用已有企业账号登录', situation: '企业已有账号系统，希望员工继续使用原账号。', outcome: '员工通过企业账号登录本应用，错误来源或停用账号被拒绝。', skip: '仅登录不需要目录同步；不必重新邀请同一批人员。', steps: [
    step('auth-session', 'identity-provider', { input: '向企业身份系统负责人获取协议、身份源标识和对接资料；登记到当前应用，不把“已有 token”当作可直接使用。' }),
    step('auth-session', 'federation-config', { input: '由企业身份管理员提供协议端点、客户端或证书配置，按当前页对应协议填写；回调地址交给对方登记。密钥通过安全渠道交付。', result: '从本应用入口完成一次企业登录；核对返回身份所属应用、拒绝错误来源，并验证停用后的访问。' }),
    { ...developer, input: '开发者按联合协议完成回调与身份映射，配置匹配的组织与应用代码；身份系统管理员核对签名、来源和重定向地址。', result: '企业账号登录后得到本应用可信身份会话；错误回调、错误来源、停用身份被拒绝。目录同步与业务权限另按需要配置。' }, step('people-access', 'audit') ] },
  { id: 'directory', title: '同步已有用户目录', situation: '需要把企业用户和停用状态持续同步到应用。', outcome: '目标应用用户同步正确，错误条目可定位，停用可验证。', skip: '目录同步不会自动开通密码或联合登录；只需要登录请选择企业账号登录。', steps: [
    step('people-access', 'sync-connector', { input: '向目录负责人取得来源与同步方式，按页面配置当前应用的连接器；先同步少量测试用户并查看失败结果。' }),
    step('auth-session', 'scim-tokens', { optional: true, input: '仅上游使用 SCIM 推送时签发令牌，交给目录负责人配置。使用其他连接器时可跳过；令牌明文仅展示一次。', result: '上游能向当前应用同步测试用户，错误令牌拒绝，撤销后旧令牌失效。' }),
    step('people-access', 'identity'), step('people-access', 'audit') ] },
  { id: 'api', title: '管理业务接口的访问', situation: '已有业务接口，需要将路由和权限动作对应起来。', outcome: '接口使用已登记业务动作鉴权，未授权调用被拒绝并可追溯。', skip: '不要求新建 OAuth/CAS 客户端；按应用实际登录方式接入。', steps: [
    step('people-access', 'resource'), businessAction,
    step('api-governance', 'api-resource', { input: '登记业务接口并选择已启用动作；代码由接入开发者提供，与应用鉴权时的 apiCode 一致。' }),
    step('api-governance', 'route-binding', { input: '由开发者提供实际路由和方法，绑定到已登记接口；扫描仅发现路由，不自动授权。' }),
    developer, step('api-governance', 'policy-simulate'), step('people-access', 'audit') ] }
]
export function guidanceGoal(value: unknown): GuidanceGoal | undefined {
  return typeof value === 'string' ? guidanceGoals.find(goal => goal.id === value) : undefined
}
export function canUseGoal(goal: GuidanceGoal, hasAuth: (permission: string) => boolean): boolean {
  return goal.steps.some(item => !['audit', 'developer-docs', 'machine-integration'].includes(item.key) && item.permission !== undefined && hasAuth(item.permission))
}
export function guidanceQuery(context: TaskContext, goal: string, stepKey?: string, method?: string): Record<string, string> {
  return { ...taskContextQuery(context), goal, ...(stepKey ? { step: stepKey } : {}), ...(method ? { method } : {}) }
}
/** The public menu path differs from the overview component's source directory (`index`). */
export function guidanceLocation(context: TaskContext, goal: string, stepKey?: string, method?: string) {
  return { path: '/sand-iam/overview', query: guidanceQuery(context, goal, stepKey, method) }
}
export function nextGoalStep(goal: GuidanceGoal, key: string): GoalStep | undefined {
  const index = goal.steps.findIndex(item => item.key === key)
  return index < 0 ? undefined : goal.steps[index + 1]
}

export function resolveGuidanceGoal(value: unknown, method: unknown): GuidanceGoal | undefined {
  const goal = guidanceGoal(value)
  if (!goal) return undefined
  if (goal.id === 'login' && method === 'custom') return {
    ...goal,
    skip: '使用已有的自有登录页面，无需配置 SandIAM 内置门户外观。页面仍须接入 SandIAM 的认证和会话，其他系统已有 token 不能直接当作 SandIAM 用户令牌。',
    steps: [
      step('people-access', 'auth-settings'),
      { ...developer, input: '开发者在现有登录页面按公开接入说明调用 SandIAM 登录接口，并按实际开户方式接入注册或邀请。管理员提供客户主体与应用代码、允许的登录方式；复用已接入的页面与用户，无需重新开通。',
        result: '自有登录页面取得当前应用的可信会话，并正确处理 MFA 或账号验证；不要求配置内置门户外观、资源或权限策略。' },
      step('people-access', 'employee-login', {
        label: '验证自有登录页面',
        input: '由应用负责人提供现有登录入口。使用已开通的应用用户账号登录，不使用后台管理员账号；已有账号无需重新注册或邀请。',
        result: '用户从自有页面登录本应用；错误密码被拒绝，退出后旧会话不可再访问账户。'
      }),
      step('people-access', 'identity'), step('people-access', 'audit')
    ]
  }
  if (goal.id === 'login' && method === 'register') return { ...goal, steps: goal.steps.map(item => item.key === 'identity-invitation' ? {
    ...item, key: 'self-register', label: '用户自行注册账号', permission: undefined, endpoint: undefined, contractStatus: 'runtime' as const,
    owner: '应用用户与部署负责人', input: '部署负责人启用账号生命周期与公开注册，管理员在认证策略中允许注册，并提供应用门户地址、组织及应用代码。用户从注册入口设置账号密码，无需另发邀请。',
    result: '注册成功产生本应用身份；如需验证码先完成验证，再继续登录。不能用后台手工新增身份代替注册。'
  } : item) }
  if (goal.id === 'directory') return { ...goal, steps: [...(method === 'scim' ? [step('auth-session', 'identity-provider', { input: '选择当前应用已有身份源；没有时按目录负责人提供的标识登记一个。随后为此身份源与应用签发 SCIM 令牌。' })] : []), ...goal.steps.filter(item => method === 'scim' ? item.key !== 'sync-connector' : item.key !== 'scim-tokens').map(item => item.key === 'scim-tokens' ? { ...item, optional: false } : item)] }
  return goal
}

export function goalStepParams(item: GoalStep, context: TaskContext) {
  const params = taskStepParams(item.endpoint, context)
  if (!params) return null
  if (item.endpoint === 'application-experience') return { ...params, status: 1 }
  if (item.endpoint && ['client', 'grant', 'credential'].includes(item.endpoint)) {
    if (context.environment_id === undefined) return null
    return { ...params, environment_id: context.environment_id,
      ...(['grant', 'credential'].includes(item.endpoint) && context.workload_client_id !== undefined ? { workload_client_id: context.workload_client_id } : {}) }
  }
  return params
}

/** Grant audience is defined by the selected active client, never inferred from service names. */
export function activeClientAudience(row: unknown, clientId: number): string | null {
  if (typeof row !== 'object' || row === null || Array.isArray(row)) return null
  const record: Record<string, unknown> = { ...row }
  return record.id === clientId && record.status === 1 && typeof record.audience === 'string' && record.audience.trim() !== '' ? record.audience : null
}
