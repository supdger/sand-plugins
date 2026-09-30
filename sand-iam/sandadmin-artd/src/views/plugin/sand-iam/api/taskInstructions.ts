import type { SandIamTaskPath } from './taskPaths'

interface TaskStepHelp {
  readonly requirement: string
  readonly owner: string
  readonly input: string
  readonly result: string
}
const people: Readonly<Record<string, TaskStepHelp>> = {
  application: { requirement: '必做', owner: '应用管理员', input: '选择员工要使用的业务系统，例如工作项系统；单公司自用的客户主体填自己公司，不填部门。', result: '页面上方显示本次要配置的应用。' },
  'auth-settings': { requirement: '必做', owner: '应用管理员与部署负责人', input: '选择应用并启用计划使用的登录方式。邀请开通需部署负责人启用账号生命周期、HTTPS邀请入口及消息发送；公开注册需启用注册。管理员不填写服务器密钥。', result: '登录方式与员工收到的入口一致，缺少部署条件时交给部署负责人处理。' },
  'identity-invitation': { requirement: '邀请或注册，选一种', owner: '应用管理员与员工', input: '邀请填写员工邮箱或手机号；员工接受邀请并设置密码。若使用公开注册，由负责人提供应用门户地址、客户主体代码和应用代码，员工在门户注册。不要先手工新建同名身份来代替注册。', result: '员工收到邀请或能打开注册入口；投递失败先检查消息发送，重发会让旧链接失效。' },
  'employee-login': { requirement: '必做', owner: '员工与接入开发者', input: '员工用刚设置的账号密码登录应用门户；客户主体与应用代码由管理员从登记记录提供。业务系统入口由应用负责人提供，门户登录成功不等于已接入业务系统。', result: '员工进入自己的账户；错误密码被拒绝。若有验证器等后续挑战，按页面完成。' },
  identity: { requirement: '必做核对', owner: '应用管理员', input: '查看注册或接受邀请后产生的应用用户。后台“新建用户”只建立身份记录，不设置登录密码。已有记录不会自动与新注册账号合并。', result: '能在所选应用找到该员工，再为此用户分配权限。' },
  role: { requirement: '按角色授权时必做', owner: '应用管理员', input: '按职责命名，例如工作项处理员（operator）；角色名称由业务负责人决定。只给单个用户授权时可以在策略中直接选择用户。', result: '所选应用下出现该角色。' },
  'identity-role': { requirement: '按角色授权时必做', owner: '应用管理员', input: '选择刚开通的员工和本应用角色。使用用户组管理时，在用户组页面设置成员与角色。', result: '员工拥有目标角色；角色存在本身不代表已经分配。' },
  resource: { requirement: '必做', owner: '接入开发者提供，管理员录入', input: '从开发者取得资源代码、名称、所有者字段与组织字段。仅使用配套工作项示例时填 standalone_work_item；不要为已有业务凭空发明代码。', result: '资源与应用实际鉴权时发送的对象一致；部门字段须由业务系统另行提供。' },
  policy: { requirement: '必做', owner: '管理员与接入开发者', input: '选择角色或用户、资源、动作和允许/拒绝。工作项示例读取动作是 work_item.read。数据范围的字段、值及类型由开发者提供；保存后发布。留空范围可能允许全部匹配记录。', result: '发布后的策略引用正确用户或角色；应用负责把范围用于查询、分页、搜索和直接访问检查。' },
  'policy-simulate': { requirement: '必做', owner: '管理员与接入开发者', input: '用开发者提供的真实用户、动作与业务属性进行模拟，再在已接入的业务系统分别使用有权和无权账号操作。', result: '有权操作成功，无权操作被拒绝；数据范围外记录不能查询或直接读取。模拟成功不能替代业务验证。' },
  audit: { requirement: '必做核对', owner: '管理员与接入开发者', input: '根据真实请求的时间、应用和请求号查访问审计。', result: '允许与拒绝均可追溯；再验证撤销策略后新请求被拒绝。' },
  'identity-provider': { requirement: '可选：已有企业账号系统', owner: '身份系统管理员与开发者', input: '仅接入已有企业登录或目录时配置；让对方提供协议、地址和标识。登记身份源之后还需联合配置。使用本应用注册或邀请无需先创建身份源。', result: '完成协议接入后，员工可用已有企业账号登录。' },
  'identity-group': { requirement: '可选：按组管理', owner: '应用管理员', input: '按实际管理需要创建组，选择同一应用成员与角色。用户组不会自动成为部门字段。', result: '组成员与角色正确，随后验证成员实际权限。' },
  'identity-import': { requirement: '可选：批量录入', owner: '应用管理员', input: '下载模板、填写后先预检；录入身份不等于设置登录密码。', result: '无错误行才确认导入；登录开通仍按邀请或注册流程核对。' },
  'sync-connector': { requirement: '可选：同步目录', owner: '开发者与身份系统管理员', input: '已有用户目录需要持续同步时配置，由目录负责人提供来源。', result: '同步用户属于正确应用，失败可在页面定位。' }
}
const connection: Readonly<Record<string, TaskStepHelp>> = {
  organization: { requirement: '已登记可跳过', owner: '客户主体管理员', input: '选择使用服务的公司或客户；单公司自用填自己公司。', result: '与本次应用所属客户主体一致。' },
  application: people.application,
  environment: { requirement: '必做', owner: '应用管理员与运维', input: '选择本次调用所在的测试或生产环境，代码例如 test 或 production。', result: '调用身份属于正确应用与环境。' },
  client: { requirement: '必做', owner: '应用管理员', input: '给应用后端创建调用身份，例如 backend。受众由服务提供方给出；配套文档服务示例为 provider-b，实际服务使用其提供值。', result: '记录应用、环境、调用身份和受众，交给接入开发者。' },
  grant: { requirement: '必做', owner: '应用管理员与服务提供方', input: '从已登记目录选择服务动作；文档服务示例为 provider-b-document / document.process。受众逐字使用调用身份中的值。目录没有目标服务时找服务提供方登记。', result: '授权处于启用且未过期状态，调用身份、动作、受众均正确。' },
  credential: { requirement: '必做', owner: '应用管理员与接入开发者', input: '为上述调用身份签发凭证，通过安全配置渠道交付；调用地址由服务提供方提供。凭证不是员工密码。', result: '开发者完成一次正确调用、错误受众拒绝、无权动作拒绝，再查审计；测试凭证撤销后新请求被拒绝。' },
  audit: { requirement: '必做核对', owner: '管理员与接入开发者', input: '按应用、时间和请求号核对调用记录，服务提供方同时核对业务处理结果。', result: '允许、拒绝与撤销结果符合预期，服务真实完成一次业务操作。' }
}
export function taskStepHelp(path: SandIamTaskPath, key: string): TaskStepHelp | undefined {
  return (path === 'people-access' ? people : path === 'connection' ? connection : {})[key]
}
