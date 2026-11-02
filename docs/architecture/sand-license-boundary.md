# SandLicense：卡密签发与商业许可最终架构结论

> 最终结论：线上卡密属于软件商业许可与权益，不属于身份认证。**当前 Sand 平台应把它落地为独立、可安装的 `SandLicense` 完整插件**：首期安装在同一个 SandAdmin PostgreSQL 宿主，拥有自己的运行 API、管理页面、PostgreSQL 生命周期和 `sand_license_*` 领域表。它是独立限界上下文，不是 SandIAM 的子模块；也不应为了“独立”而立即拆成一个依赖 SandIAM 才能工作的微服务。未来只有在需要跨多个互不共享数据库的宿主统一签发/撤销，或单宿主无法满足密钥隔离、可用性和吞吐时，才把**同一个领域内核**演进为独立平台服务。服务化不能让许可事实迁回 SandIAM，也不能复制第二份许可状态。

> 非目标：跨产品自然人识别、用户中心和画像分析不属于 SandLicense、SandIAM 或任何现有插件。本文件不把手机号、身份证、宠物、案件、订单或画像标签加入许可模型；产品只以自己的稳定业务主体和许可事实接入。

> 证据边界：用户已提供旧 `app\api\controller\sn\LicenseController::verify()` 与 `app\logic\sandnt\LicenseLogic::encryptData()` 的完整代码，足以确认下文算法问题；但本仓及当前已知 `/Users/code/project`、`/Users/supdger/Projects` 工作区仍未检索到这两个类的权威文件路径。正式迁移前必须补录旧服务、客户端和数据库的准确仓库、版本与负责人，逐文件核对，不能只根据对话代码直接删除线上实现。

## 1. 归属、运行边界与落地形态

SandIAM 判断“谁可以访问什么”；SandLicense 判断“某产品、版本、功能、期限、席位或安装是否已经购买并可继续使用”。卡密还需要发码、兑换、续费、暂停、撤销、设备转移、离线宽限和商业生命周期审计。这些不是身份、角色或服务调用凭证。

### 1.1 当前落地形态与未来演进

当前选择独立 **SandLicense 插件**，不是因为许可证只服务 SandAdmin，而是因为它与现有平台的运营入口、组织/应用登记、后台权限和可安装交付方式一致：运营人员可在一个宿主中完成产品、套餐、卡密、权益和激活管理；业务客户端可调用插件自己的运行 API；每个安装的宿主都保有自己的许可事实。这是“独立领域 + 插件交付”，不是“SandIAM 里多几张许可表”。首期不是微服务：部署、事务、审计和备份仍随 SandAdmin 宿主治理；边界是数据和决策边界，不是进程数。

```text
SandAdmin 宿主
├── SandIAM：组织/应用/环境、调用方身份、谁可操作、API 治理与访问审计
├── SandLicense：产品、套餐、卡密、权益、激活、租约、签名、领域审计
└── 业务应用/客户端：购买或兑换后消费自己的许可与功能
```

未来满足以下任一条件时，再把 SandLicense 的**同一领域内核**部署为独立平台服务：需要跨多个互不共享数据库的宿主统一签发/撤销；签名密钥必须由专属 KMS/HSM 服务托管；或许可校验的可用性、吞吐和隔离级别已不能由单个 SandAdmin 宿主承担。届时 SandAdmin 插件保留为运营控制台或受控客户端，领域 API 与事件契约保持兼容；不能复制一套许可状态，也不能让每个业务产品各自实现卡密算法。

下列能力不得为了“集成方便”放进 SandIAM：产品和套餐、卡密批次与分发、兑换次数、权益有效期、席位、设备激活、租约、离线凭证、签名密钥及许可领域事件。SandIAM 只管理组织、应用、环境、调用方身份、谁可操作以及 API 治理；它不读取、推导或判定“这份许可证是否仍有效”。SandLicense 在本地领域事实和签名/租约上做许可判断，即使 SandIAM 的管理或授权能力暂时不可用，也不能把有效许可误判为无效或把无效许可放行。

正确集成关系：

- SandLicense 在服务器端引用 SandIAM `application_id`，客户端自报的 `appId` 不能作为权威事实；
- 登录后的兑换可引用 SandIAM 应用身份，但许可证也允许按业务决定无账号兑换；
- 管理员签发、撤销和查询使用 SandIAM 的**应用业务动作**与接口目录/策略授权；
- 业务服务代理调用使用 SandIAM 的 workload client、服务 `sand_license` 和 `sand_license.*` **服务动作**；桌面和移动终端运行接口不依赖后台登录或 service grant；
- SandIAM 记录访问授权审计，SandLicense 记录发码、兑换、激活、续租、撤销等许可事件。

### 1.2 两个平面，不能混用

```text
SandAdmin 管理平面
  宿主后台登录 + SandIAM 授权
  → 管理产品、套餐、卡密批次、撤销、恢复和审计导出

SandLicense 运行平面
  终端：安装公钥持有证明 / challenge / 短期租约
  服务代理：受控服务调用身份
  → 兑换、续租、验签材料、当前安装权益、主动释放
```

运行面绝不使用 `sa_system_user`、SandAdmin 菜单会话或后台 `CheckLogin` 作为客户端许可证的前提。桌面和移动终端的 `redemptions`、续租和主动释放**不默认要求** SandIAM workload client 或 service grant；它们由卡密、安装公钥、一次性 challenge、私钥持有证明和幂等键共同约束。已关联应用身份的恢复流程才可额外使用 SandIAM 登录和 MFA。管理面是否允许某位操作者签发、暂停、撤销或导出，由 SandIAM 应用业务动作和接口目录/策略进行授权；许可是否到期、是否占用席位、是否被撤销，只能由 SandLicense 领域状态决定。

## 2. 旧接口风险与退役门槛

已提供代码中的具体位置为：`LicenseController::verify()` 负责按明文 code 查询、首次绑定 fingerprint、计算到期时间并返回加密包；`LicenseLogic::encryptData()` 负责 AES/MD5 封装。以下是从已给代码可直接确认的风险，不对未提供的模型、网关、客户端或部署配置作假设：

| 等级 | 旧实现风险 | 必须替代为 |
| --- | --- | --- |
| P0 | `SHA-256(appId)` 是客户端可计算的“密钥”，`MD5(code)` 派生固定 IV；AES-CBC 又没有认证。任意持有 `appId` 与卡密者可解密、重加密或篡改响应。 | 服务器签发 Ed25519 许可证；私钥只在受限签名边界。若确有机密载荷需求，采用独立标准加密信封，不能用公开 `appId` 派生密钥。 |
| P0 | `MD5(payload . appId)`、`MD5(id . appId . fingerprint)` 没有服务端秘密，且 MD5 不适合作为签名。 | 彻底退役 MD5；只验签有明确 `kid`、算法约束和受众绑定的签名凭证。 |
| P0 | `appId` 完全由客户端自报，且未与卡密记录、安装实例或签名受众强绑定。 | 服务端从兑换码和产品配置决定产品；签名凭证同时绑定 `aud`、`product_code`、`activation_id` 与安装公钥指纹。 |
| P0 | `fingerprint` 可为空、可伪造，且首次请求直接写入；它既不能证明设备也无法阻止并发首绑。 | 拒绝空/无效安装公钥；首次兑换在事务中锁定兑换码和席位，创建唯一安装实例。硬件指纹最多做风险信号。 |
| P0 | 没有许可 `state`、吊销时间或撤销路径；仅按到期时间判断，无法处理暂停、欺诈、退款、设备遗失和密钥泄露。 | 使用明确许可状态机、撤销事件和短租约；在线续租每次重读当前状态。 |
| P0 | 时间戳不带 challenge、nonce、`jti`、服务端重放记录或时窗验证，不能防重放。 | challenge + 安装私钥持有证明 + 短时唯一 nonce/`jti`；同一证明重放统一拒绝。 |
| P1 | `findCode($code)` 暗示明文卡密可查询；若数据库、备份、慢查询、异常或日志泄露，卡密可直接被使用。 | 只保存显示前缀、部署 pepper 版本和 HMAC lookup；原始卡密只在签发响应和 TLS 请求体的短生命周期存在。 |
| P1 | 先读再写的首绑流程没有条件更新、行锁或幂等键；并发可造成不同指纹绑定、重复激活或重复扣减席位。 | 事务锁定 + 数据库唯一约束 + 请求去重；相同幂等键返回原结果，摘要不同明确冲突。 |
| P1 | 无卡密格式/长度/熵校验，空值、异常超长值和低熵码的处理边界未定义。 | 服务端生成高熵码；接入层设长度和字符集上限，统一无效响应，不能回显输入值。 |
| P1 | 缺少产品/版本/功能、席位、激活上限、设备转移、恢复、审计、限流和风控。 | 由产品、套餐、权益、激活、租约和追加事件分别建模；限流同时按来源、卡密查询摘要和安装实例执行。 |
| P1 | 区分“授权码不正确”“指纹不匹配”“有效期已过”会帮助枚举有效卡密；列表接口若直接返回 code/fingerprint/密文包，会扩大泄露面。 | 运行面统一返回 `SAND_LICENSE_CODE_INVALID`；后台只在受限详情中显示掩码前缀、状态和时间，禁止默认列表、导出和日志出现原卡密、完整安装公钥或签名载荷。 |

旧 `verify()` 在新服务投入前只能作为临时隔离入口：禁止用其签发新卡密；先补充入口限流、卡密脱敏、统一对外失败和领域审计。它不能通过“再包一层加密”获得继续长期使用的资格。

## 3. 最小领域模型与状态机

| 表 | 最小责任与关键边界 |
| --- | --- |
| `sand_license_product` | 软件产品；`organization_id`、稳定 `code`、`name`、可选 SandIAM `application_id` 引用和 `status`。产品不是应用用户或画像主体。 |
| `sand_license_plan` | 套餐；版本、功能、期限、席位、离线策略。发布后用版本或策略快照保障既有权益可解释。 |
| `sand_license_redemption_code` | 卡密批次/单码；只存显示前缀、带部署 pepper 的 HMAC lookup、使用次数、失效时间和 `state`，不存明码。 |
| `sand_license_entitlement` | 已发放商业权益；产品、套餐快照、期限、席位、`state`、暂停/撤销/到期事实。它是许可有效性的权威。 |
| `sand_license_activation` | 安装实例；安装公钥、其 thumbprint、激活/释放/撤销状态和最后活动时间。公钥是稳定绑定，不把硬件指纹当主键。 |
| `sand_license_signing_key` | `kid`、用途、状态、公钥、轮换时间；私钥只在 KMS/HSM 或受限签名服务，不能落库。 |
| `sand_license_event` | 不可变领域事件；发码、兑换、签发、续租、暂停、撤销、转移、恢复和失败原因分类。 |
| `sand_license_lease` | 短期在线租约；签名令牌 ID、到期/离线截止、撤销事实。 |
| `sand_license_request_dedup` | 幂等键摘要、请求摘要、处理状态和结果引用；不保存原始卡密或原始 DPoP 证明。 |
| `sand_license_challenge` | 一次性、短时 challenge/nonce 的摘要、安装范围和使用状态；用于兑换、续租、恢复等高风险运行操作。 |

所有表使用 `sand_license_*`，只支持 PostgreSQL；主键、外键、`status`、`state`、时间字段、审计和秘密字段命名遵循 Sand 平台约定。`product` 保存稳定 `code`、运营主体、关联 SandIAM 应用和状态；客户端传入的产品代码只能用于查找候选配置，最终产品、套餐和权益必须来自服务端兑换码记录。

核心状态机固定为：

```text
兑换码：待发行 → 可兑换 → 已用尽
                 ↘ 已暂停 / 已撤销 / 已过期

权益：待生效 → 生效中 → 宽限期 → 已过期
                  ↘ 已暂停 / 已撤销

安装激活：待确认 → 已激活 → 已释放
                       ↘ 已撤销 / 设备遗失

租约：已签发 → 已续租 → 已过期
               ↘ 已撤销
```

状态只能通过领域动作迁移，禁止管理端直接填写数字状态。兑换码撤销默认不自动撤销已经发放的权益；若业务需要连带撤销，必须作为一次明确操作展示影响数量并写审计。

## 4. 在线激活、challenge 与短租约

客户端首次安装生成 Ed25519 密钥对并保存到 Keychain、TPM 或系统安全存储。运行面先申请一次性 challenge，再提交高熵卡密、产品代码、安装公钥、challenge、安装私钥持有证明和 `Idempotency-Key`：

1. `POST /challenges` 只产生短时、一次性 challenge；其服务端仅保存摘要、用途、产品候选范围、过期时间和使用事实；
2. HTTPS 与网关限流；日志在进入应用前脱敏；
3. 服务器按兑换码记录查找产品和套餐，不信任客户端传入的产品归属；
4. 事务锁定兑换码、权益和席位并原子创建 entitlement、activation 与不可变事件；
5. 校验安装私钥持有证明：请求方法、目标 URI、challenge、`iat`、唯一 `jti`、安装公钥与本次请求摘要都必须匹配；challenge 和 `jti` 只能消耗一次；
6. 用 Ed25519 签发短期 lease。每次续租重读 entitlement、activation、席位和签名 key 的当前状态，因此撤销、暂停、功能和版本限制在线即时生效。

卡密只用于兑换或受控恢复，不作为日常接口 secret。安装密钥不是万能账号：它只能证明某一 activation 的持有；换设备、恢复、转移和管理员操作仍走各自受限的领域动作。

## 5. 离线许可证

使用带 `kid` 的 Ed25519 JWS 或 COSE/CWT，不再自创 AES 包。客户端内置公钥验证签名、`aud`、`exp`、产品、权益、安装公钥指纹和离线截止时间。需要保密时可以再使用标准加密信封，但签名不可省略。

纯离线无法即时知道服务器撤销，因此必须限定离线宽限期、定期在线续租，或分发签名撤销列表。签名能防伪造，不能防客户端二进制被 patch；高价值能力必须放在在线服务端强制执行。

签名载荷至少包含：`iss`、`aud`、`jti`、`iat`、`nbf`、`exp`、`kid`、`product_code`、`plan_code`、`entitlement_id`、`activation_id`、安装公钥 JWK thumbprint、功能集合、版本范围、席位摘要和 `offline_until`。客户端必须拒绝未知算法、未知 `kid`、错误受众、未来生效、过期、超出离线截止或安装公钥不匹配；不能把“签名正确”误当成“当前在线未撤销”。

## 6. 管理与运行 API

### 6.1 运行面

| 方法 | 地址 | 用途 |
| --- | --- | --- |
| `POST` | `/api/sand-license/v1/challenges` | 获取一次性短时 challenge；不返回任何许可事实 |
| `POST` | `/api/sand-license/v1/redemptions` | 兑换卡密并创建权益/安装激活；必须带幂等键与 DPoP proof |
| `POST` | `/api/sand-license/v1/leases/renew` | 续租并取得新的短期在线/离线凭证 |
| `GET` | `/api/sand-license/v1/entitlements/current` | 查询当前安装可用功能与期限，不返回卡密 |
| `POST` | `/api/sand-license/v1/activations/release` | 当前设备主动释放席位；须 DPoP 证明 |
| `POST` | `/api/sand-license/v1/activations/recover` | 设备遗失后的受控恢复；需要 SandIAM 登录 + MFA 或人工审核 |
| `GET` | `/api/sand-license/v1/.well-known/jwks.json` | 发布当前和验签过渡期公钥 |

所有失败对外使用稳定、不过度泄露的错误码。不存在、错误、已用尽或不属于产品的卡密统一返回 `SAND_LICENSE_CODE_INVALID`；失效 challenge/证明返回与码枚举无关的稳定错误；后台审计保留内部原因分类。原始卡密只允许出现在 TLS 请求体的短生命周期内，网关、Webman SystemLog、异常追踪和分析日志必须在解析前脱敏。

运行 API 的认证前提是安装密钥证明、短期租约或已验证的受控服务调用身份，**不是** SandAdmin 后台登录。运行 API 不在管理菜单下，也不允许从管理 API 继承后台会话；所有运行响应中禁止返回原卡密、私钥、完整安装密钥或可用于替代持有证明的材料。

其中，桌面和移动终端直连 `challenges`、`redemptions`、`leases/renew`、`entitlements/current`、`activations/release` 时，只走 SandLicense challenge、安装密钥持有证明、租约和本地领域状态；不得向终端下发 SandIAM workload credential，也不得因为缺少 `service_grant` 拒绝一个本来有效的终端许可操作。服务间代理才可以把相应的 SandLicense 运行能力登记为 SandIAM service/action 后调用。

### 6.2 管理面

管理动作使用 SandIAM 授权，不复用 SandLicense 状态作为权限。管理 API 以 SandAdmin 后台登录建立操作者会话，但每个敏感动作还需在对应组织/应用范围内完成 SandIAM **application business action**、接口目录和策略授权。它们属于某一接入应用的业务动作目录，不是 service grant：

```text
license.product.manage
license.plan.manage
license.code.issue
license.code.revoke
license.entitlement.read
license.entitlement.suspend
license.entitlement.revoke
license.activation.release
license.activation.recover
license.audit.export
```

`license.*` 是管理面的 application business action，不是 HTTP URL：每个管理路由只绑定到已经登记的接口目录，再由接口目录引用对应的业务动作和策略。它们不写入 `sand_iam_service_action`，也不能被 `service_grant` 授给 workload client。

服务间运行面另设 SandIAM 服务目录中的 service `sand_license`，以及其 service_action：

```text
sand_license.redeem
sand_license.lease_renew
sand_license.entitlement_read
sand_license.activation_release
```

这些是业务服务代理调用的技术服务动作：调用方必须是受控 `workload_client`，并按 `organization → application → environment → workload_client → service_grant → service_action` 校验最小授权、audience 和审计。它们不是管理动作的第二份目录，也不等同于任何 HTTP 路径；除确有代理需求的服务动作外，不预先创建或把全部终端路由塞进服务目录。

| 调用类别 | SandIAM 的动作类型与职责 | SandLicense 的职责 |
| --- | --- | --- |
| 后台签发、暂停、撤销、导出 | application business action，例如 `license.code.issue`；接口目录定位、策略授权、操作者和组织/应用范围审计 | 执行状态迁移、计算影响、写领域事件 |
| 业务服务代理 | service `sand_license` + service_action，例如 `sand_license.lease_renew`；workload client、service grant、环境、audience 和访问审计 | 仅处理已登记的许可服务能力，不让调用方任意改写许可事实 |
| 桌面/移动终端兑换、续租、主动释放 | 不默认使用 application business action、workload client 或 service grant；可选关联身份仅用于恢复 | challenge、安装密钥证明、幂等、席位、状态、租约、签名与领域事件 |

SandIAM 审计“谁被允许调用管理/服务动作”，SandLicense 事件记录“许可领域发生了什么”。两边用同一个 `request_id` 关联，任何一边都不跨库联表写另一边的数据。SandIAM 不接收卡密、安装公钥私密材料、套餐、席位或 lease 作为授权输入；SandLicense 不复制 SandIAM 的用户、组织、应用、客户端、凭证、角色、grant 或 API 路由表。它只保留必要的外键/稳定引用和本地审计关联。

## 7. 设备解绑、转移和恢复

1. **当前设备主动释放**：安装私钥签署 DPoP proof；事务锁定 activation 和 entitlement，状态改为“已释放”，立即撤销未过期 lease，再释放一个激活名额。
2. **账号自助恢复**：已关联 SandIAM identity 的权益可在重新登录并完成 MFA 后发起；默认进入冷却期，向原会话/安全联系方式通知，超过产品配置的月度转移次数则转人工。
3. **无账号或设备遗失**：凭购买证明/订单走业务客服审核；客服只能发起恢复，关键产品需双人复核，不能要求用户提供完整卡密截图。
4. **管理员强制解绑**：展示受影响产品、安装、最后活动和未过期 lease；二次确认后撤销并写操作者、理由、工单号和请求标识。
5. **并发规则**：同一 activation 只能有一个终态迁移成功；释放和续租并发时锁顺序固定为 entitlement → activation → lease，释放优先，续租必须重新读状态后拒绝。

指纹只作为风险信号，不是设备身份证。稳定绑定对象是安装公钥；硬件信息变化不能静默创建新席位，也不能仅凭相同指纹继承旧权益。

### 7.1 管理台中文体验与最小展示

SandLicense 管理台首先是给运营、客服和产品负责人使用的，不是数据库浏览器。菜单和页面应使用下列中文名称：

```text
商业许可
├── 软件产品
├── 套餐与功能
├── 卡密批次
├── 已发放权益
├── 设备激活
├── 密钥轮换
└── 许可操作记录
```

默认列表只展示完成当前判断所需的信息：

| 页面 | 默认列 | 不应作为默认列 |
| --- | --- | --- |
| 软件产品 | 产品名称、产品代码（用于接口配置）、状态、更新时间 | 数据库 ID、关联外键 ID、原始配置 JSON |
| 套餐与功能 | 套餐名称、所属产品、有效期、席位、状态 | 策略快照、内部版本 ID |
| 卡密批次 | 批次名称、所属产品、可兑换/已用尽数量、失效时间、状态 | 原始卡密、HMAC、pepper 版本 |
| 已发放权益 | 产品、套餐、状态、有效期、已用/可用席位、最近活动 | 卡密、完整安装公钥、请求摘要 |
| 设备激活 | 产品、设备别名或安装名称、状态、最后活动、到期时间 | 硬件指纹、完整公钥、签名载荷 |
| 许可操作记录 | 操作时间、动作、对象、结果、操作者/调用方 | 原卡密、私钥、完整 challenge、完整证明、请求标识 |

`ID`、系统代码、请求标识和技术状态进入详情、复制动作或“技术信息”折叠区；系统代码标签统一为“产品代码（用于接口配置）”或“套餐代码（用于接口配置）”，需说明创建/发布后的变更规则。敏感值只在确有必要的受限详情中展示掩码；签发卡密可下载一次性受保护文件或显示一次，之后只能显示前缀和批次统计。暂停、撤销、强制解绑、恢复和密钥停用必须使用中文影响说明、二次确认、理由和审计，不允许靠填写 `state=2` 完成。

## 8. 事务、幂等与并发

- 兑换锁顺序：product → redemption_code → entitlement → activation → request_dedup；相同幂等键和相同请求摘要返回原结果，不同请求摘要返回冲突；
- 唯一约束至少覆盖 `(product_id, code_lookup_hmac)`、`(entitlement_id, installation_key_thumbprint)`、`(client_scope, idempotency_key_hmac)` 和未释放激活的业务唯一条件；
- 兑换次数、席位数和设备转移次数必须在持锁后重算，禁止“先查剩余再更新”；
- DPoP `jti`/nonce 具有短时唯一约束，重放统一拒绝；服务器时间窗口和时钟偏差可配置但有上限；
- 签名服务超时不得产生“数据库已成功但许可证未返回”的未知结果：事务先落待签发状态，签名结果以幂等任务完成，客户端用同一幂等键查询原结果；
- 事件表追加写；更正使用补偿事件，不覆盖历史。隐私字段采用最小化摘要，不能把设备全量硬件信息写入审计。

若 LIC-P1 需要把许可领域事件发布给其他系统，必须在实施开始前冻结事件名、`schema_version`、最小载荷、允许接收方、保留期、失败重试、删除/更正传播和幂等语义；随后才可在同一 PostgreSQL 事务内写入 `sand_license_outbox` 与领域状态/事件，并由独立发布器投递。outbox 不得承载原卡密、私钥、完整安装公钥、完整 DPoP 证明或业务正文。本文件不声明已有 outbox 表、消息队列、发布器或订阅方。

## 9. 密钥治理

- 兑换码 lookup 使用独立部署 pepper 的 HMAC-SHA-256，并带 `pepper_version`；显示前缀用于客服定位但不足以校验；
- 许可证签名私钥与 SandIAM OAuth、Webhook、凭证 pepper 完全分开；生产环境位于 KMS/HSM 或受限签名服务；
- `kid` 轮换顺序：发布新公钥 → 新签名改用新 key → 保留旧公钥到所有旧许可证最长验证期结束 → 停用旧 key；
- 测试、预发、生产使用不同 issuer、audience 和密钥；生产私钥禁止导出到仓库、数据库、日志或构建产物；
- 备份必须覆盖状态数据库和公钥/元数据；KMS 恢复演练必须证明旧许可证仍可验、新许可证可签。

## 10. 渐进迁移

1. 先定位旧服务端、各平台客户端、网关、数据库和运营入口的权威版本；建立“旧端点/算法/字段/调用方 → 新领域动作/表/客户端版本”的可审核清单。没有该清单，不宣布旧端点下线。
2. 立即停止用旧算法签发**新**卡密；旧端点只做临时隔离：限制来源与速率、统一对外失败、入口脱敏和领域审计。不能把旧端点包装成新 API，也不能将旧加密包作为新许可证。
3. 建立 SandLicense 后以影子记录观察旧成功校验；已激活记录按稳定旧记录映射幂等导入 entitlement，保留原到期日，但不把旧 fingerprint 当强设备身份，也不导出原始卡密。
4. 新客户端把旧卡密一次性兑换为新签名许可证并绑定安装公钥；新签发的卡密只走新端点。迁移期间同一稳定映射只能占用一次席位。
5. 客户端双验签仅保留到已公布截止日；灰度报告达到预先冻结的差异和错误阈值后关闭旧签发，最后关闭旧验证。回滚只能切换验证入口，不能倒转已发生的合法兑换、暂停或撤销事件。

迁移开始前新增四项硬门槛：

1. 导出现状只允许输出内部随机旧记录 ID、状态和到期日，原始卡密不得进入迁移文件、日志或对账表；
2. 影子导入不改变旧系统结果；新系统差异报告达到事前冻结阈值后才允许灰度签发；
3. 对每个迁移批次证明：原卡密、私钥、完整指纹和旧加密包不进入新库、事件、日志、CSV、追踪或分析仓；
4. 双写或双验期间用稳定 mapping 防止两边各占一次席位；迁移失败具备按批次暂停和受控恢复，不得静默重试造成重复权益。

## 11. 验收矩阵

### 正常流程

- 首次兑换、相同幂等键重试、在线续租、离线验签、当前设备释放、MFA 恢复和管理员强制解绑均通过；
- 功能、版本、期限、席位和离线截止在客户端与服务端一致执行；
- SandIAM allow/deny 与 SandLicense 领域事件可用同一 `request_id` 关联。

### 拒绝与并发

- 错误/撤销/过期/已用尽卡密得到统一对外错误且内部原因可审计；
- 两台设备并发兑换最后一个席位只能一台成功；同设备重复兑换不重复占席；
- 释放与续租、撤销与续租、迁移兑换与旧端验证并发不会恢复已撤销权益；
- DPoP proof、nonce、lease 和离线许可证重放或跨产品、跨安装错用均拒绝。

### 安全与生命周期

- 数据库、消息、日志、追踪、CSV 和异常中都找不到原始卡密、私钥或完整设备信息；
- 新装、重复升级、旧数据影子导入、卸载、备份恢复和签名 key 轮换在一次性 PostgreSQL 环境有证据；
- 客户端在断网、系统时钟回拨、公钥轮换、离线到期和撤销列表过期时均 fail closed 或进入明确受限宽限，而非永久放行；
- 旧算法关闭后，旧端点不能继续签发或把旧响应当新许可证。

## 12. 实施顺序与不作出的承诺

1. **LIC-P0 设计冻结**：确认旧实现位置、产品/套餐/席位规则、离线最长宽限和恢复审批；
2. **LIC-P1 服务端核心**：独立 SandLicense 插件、PostgreSQL 生命周期、兑换/权益/安装/租约/事件、KMS 签名适配；如需跨系统发布事件，先冻结 outbox 契约后再实现；
3. **LIC-P2 客户端 SDK**：macOS/iOS Keychain、Android Keystore、Windows TPM/CNG 等平台适配及 DPoP/离线验签；
4. **LIC-P3 影子迁移**：旧成功记录对照、差异率和并发报告，不改变生产许可结果；
5. **LIC-P4 灰度与退役**：新码新协议、旧码一次兑换、分版本扩大、到期关闭旧签发和旧验证。

本设计只完成领域归属与安全迁移方案，不授权创建 SandLicense 插件、迁移生产数据、签发真实卡密或修改客户端。

当前仓库没有 SandLicense 代码、生命周期 SQL、客户端 SDK、旧服务权威路径或生产数据证据。因此本文件冻结的是**目标边界和实施验收条件**，不是“SandLicense 已实现”“旧算法已关闭”“线上已迁移”或“可上线”的声明。创建插件前还必须按 Sand 平台约定登记 `sand-license` 的名称、包、路由、PHP 命名空间、表前缀、权限、接口和生命周期契约；不得从本文直接推断已经获得密钥、生产迁移、服务启停或对外签发授权。

## 13. 仓库内依据

- Sand 平台命名、所有权与 PostgreSQL 约定（本文件修改时遵循的工作区规范）：插件独立域、`sand_<domain>_*`、秘密只写入、事件不可变与应用—环境—调用方层级。
- [SandIAM 终极产品目标](../../sand-iam/docs/product/sand-iam-terminal-product-goal.md)：管理平面与运行平面分离，SandIAM 只负责“谁可以操作”，卡密/商业许可属于独立 SandLicense。
- [SandIAM P0 契约](../../sand-iam/docs/development/sand-iam-p0-contract.md)：组织、应用、环境、workload client、语义服务动作、请求标识、一次性秘密和审计边界。
- [SandIAM 接口治理与业务接入契约](../../sand-iam/docs/development/sand-iam-api-governance-v0.1.md)：路由只负责定位，授权使用稳定语义动作，范围与审计不能由客户端输入伪造。
- [SandIAM 管理台对象与显示用语](../../sand-iam/docs/product/sand-iam-control-plane-language.md)：名称优先、系统代码作为受控机器引用、默认列表避免展示技术字段的中文体验原则。
