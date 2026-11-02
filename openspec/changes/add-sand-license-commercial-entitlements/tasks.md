## 1. 复用与独占包基础

- [x] 1.1 在新 sand-license/ 建立设计冻结目录、元数据和最小运行入口；核对无其他插件dirty内容被修改，命名与API契约一致。
- [x] 1.2 将固定上游实际参考流程移植到Activation/Lease/Plan/Membership逻辑，补NOTICE及完整第三方许可；验证实际移植来源版本/原路径→改造路径和适配行为，不将仅参考接口计作移植，不凑文件数量；UTC月末截断注明本地补强。
- [x] 1.3 消费宿主既有tinywan/jwt及其传递Firebase公开API；移除插件独立JWT require/lock/bundle且无fallback，不改登录配置；按Tinywan官方Firebase约束及必要API/EdDSA检查兼容。实测宿主既有class来源、真实签验、缺依赖/不兼容拒绝，记录实际组合，不宣称未跑版本矩阵通过。

## 2. 数据与在线许可

- [x] 2.1 编写十五张单数sand_license_*表的PostgreSQL生命周期SQL、具名来源/幂等/安装唯一约束及CHECK；静态检查对象归属、FK/UTC命名和无建库/旧迁移语句；初始源码阶段不执行SQL，后续授权临时安装执行证据归6.1。
- [x] 2.2 实现产品、发布不可变套餐及首兑UTC期限逻辑；无DB行为回归覆盖月末、闰年、默认席位1、非法配置与首兑重复起算。
- [x] 2.3 实现卡密HMAC一次签发、request_id去重/状态查询、明确重签与秘密脱敏；无DB回归证明普通重试无明码、未兑可重签、已兑拒绝。
- [x] 2.4 实现Security冻结三类和实际Firebase调用；真实签验测试覆盖alg/kid/typ/必填claims/iat/nbf/exp/受众/产品/安装/proof原始body绑定拒绝；purpose/nonce/jti消费的域事务证据归2.5，不以签验工具测试代替。
- [x] 2.5 实现首兑、安装、续租及challenge/jti消费事务；行为回归覆盖单次权益、900秒上限、300秒续签建议、到期截断和撤权；逐项记录实际PostgreSQL并发结果与剩余门，不以无DB检查代替。
- [x] 2.6 实现release/reset与enrollment ticket一次展示/受控重签/enroll；回归证明旧lease_exp前席位冻结、已释放不能renew、团队ticket不绕seat_limit且不重复发权益。

## 3. 通用履约与会员

- [x] 3.1 实现SKU服务端映射、事件去重和商业来源锁/唯一的履约状态机；无DB回归覆盖异event同cycle、同键异内容、数量单位拆分及退款先到终态。
- [x] 3.2 实现paid资格、claim凭证与绑定会话/安装、只读状态、领取和明确重发，以及可信履约显式重签丢失领取凭证；回归证明至少128位熵、HMAC-only、可配TTL、响应丢失状态恢复、已兑禁止重签、重发与兑换互斥逻辑。
- [x] 3.3 移植并实现会员独立来源grant和有效区间汇总；回归覆盖A退款不影响B、重叠区间、支付重复、退款先到、取消保留周期及部分退款明确unsupported。
- [x] 3.4 实现正式SandIAM公开上下文/服务动作授权与自有resolver注入；调用契约回归覆盖真实端口形状、scope/audience/action匹配和缺上下文/端口失败关闭，不建平行凭证或直接写IAM表。

## 4. 管理与自助入口

- [x] 4.1 实现显式运行路由、管理登录/Permission与组织产品范围；最小控制器回归验证服务写操作不靠后台session、安装操作不靠workload凭证、稳定错误及统一分页/UTC契约。
- [x] 4.2 实现中文产品套餐、卡密发放、权益和设备、履约会员及操作记录管理端；执行适用TS静态检查和构建，验证typed分页、request_id恢复、敏感动作理由/影响和seat_available_time展示。
- [x] 4.3 实现独占claim静态页与固定资源服务；本地无宿主页面检查覆盖待领取/已领取/已兑换/退款/过期、自助明确重发和NoStore/NoReferrer，不把静态检查冒称真实界面验收。
- [x] 4.4 更新本插件简明README/CHANGELOG及现有Wiki受影响内容交付计划；按文档可执行的无DB步骤实际验证，未授权Wiki发布及宿主步骤明确待交付，不改旧架构文档。

## 5. 统一包与受限验收

- [x] 5.1 实现单ZIP builder及精确host-payload清单；构建并验证plugin/app/config/SQL/UI/许可完整、宿主依赖声明及能力消费、无重复JWT vendor、摘要一致、无秘密/临时日志，无手工复制宿主。
- [x] 5.2 汇总必要无DB回归、PHP语法、TS构建和源码差异审查；在本tasks记录真实命令与通过范围，确认不隐式调用DB/服务并保留未验证门。
- [x] 5.3 固定候选交不同上下文安全审查；覆盖来源/幂等、领取竞争、JWT/proof、席位冻结及IAM真实调用边界，按发现仅修本范围并复验。

## 6. 真实临时验收与后续交付

- [x] 6.1 冻结授权宿主、PostgreSQL目标、cleanrevision及包摘要后，经标准安装验证十五表/文件/依赖真实消费和HTTP允许/拒绝；不得隐式建库，临时建库仅由已有单独明确授权的setup执行者操作。
- [x] 6.2 在获授权隔离目标验证并发首兑/席位/重发兑换/来源幂等/乱序退款及失败恢复；实际PostgreSQL证据才能勾选，内存模拟不替代。
- [x] 6.3 固定可运行候选后由独立上下文真实页面验收运营发码、客服reset和买家领取/重发；准备构建启动仍CLI，界面例外仅验收，不代替真人或客户客户端结果。
- [x] 6.4 用户随后明确“授权建仓发布”：完成独立仓实际暂存审查/pre-commit、codex/initial-release提交推送、v0.1.0公共Release及匿名重下载校验；范围与具体证据见下方发布批次，不冒称目录发现、生产部署或线上业务验收。
- [x] 6.5 用户随后回答“授权”，承接“补齐 SandAdmin 目录登记、提交 PR 并合并、公开目录及下载校验。不涉及生产安装、数据库或服务变更”；完成官方目录登记、独立候选审查、正常 PR 合并与公共 main/ZIP 回读。此为原24项完成后的追加目录交付，不改写此前运行证据。

## 初始源码候选与历史证据边界（2026-10-04）

- Core/SQL源码候选已冻结并完成独立源码审查及新宿主依赖方案重打包。初始源码阶段仅新sand-license/及当前change，不修改其他既有dirty或实际密钥、宿主、服务；后续临时运行授权与实际证据见下述验收批次，不把源码证据当运行通过。
- 审查修复后实测 `php sand-license/tests/core_behavior.php`：72个生产policy helper检查，0.001秒；覆盖UTC月末/闰年、席位冻结、ticket状态及重发主体授权、期限截断、秘密HMAC、管理scope、发布应用引用固定、严格布尔/重置摘要及锁后产品归属核对。此测试未调用数据库，不证明首兑事务、去重写入或并发竞争真实通过。
- 实测 `php sand-license/tests/sql_static.php`：十五表及卸载匹配、具名约束/对象归属/无建库静态检查，0.001秒；SQL未执行。
- Security执行者真实 `php sand-license/tests/security.php`与php-l通过，且独立复核通过；Security测试使用已安装宿主Firebase7.1.1真实签验。当前消费宿主Tinywan传递能力的实际加载和包测试由包执行者重新验证，不能由Security工具证据推导新包验收。
- 依赖契约已按最新复用要求改为宿主优先，实际已验仅宿主Tinywan1.15.0/Firebase7.1.1；1.3旧勾选曾取消，本次依新方案真实证据重新勾选。官方Firebase约束`^6.8 || ^7.0`不代表其他组合实测通过。
- 当前宿主依赖源码复验已通过六种真实独立进程场景（缺宿主拒绝、已有宿主真实签验、先插件拒绝后加载宿主重验、异常类来源、混合registry、整套外来栈）；bootstrap绑定真实host base_path/vendor、InstalledVersions及两包来源，SHA256`7629ff71d6bbd366bbed7dd680a9e3301a689a4832a2020af2d23da6d4142da8`。实际新ZIP解出后也通过宿主签验及缺依赖/外来class/混合registry/整套外来栈拒绝，不将旧bundle成功当新发行通过。
- 历史证据（已替换，不算当前1.3/5.1通过）：旧四独立进程曾验证无宿主plugin vendor成功、host-first/plugin-first及旧版本拒绝；旧ZIP vendor实际解出签验成功。旧首包88文件/23app-config载荷SHA256`887128d9c40b0949a5318bbe0e7c056c3ac87b3769a7078a702b0f33e74d475f`及篡改/缺失拒绝仅为历史候选。当前新包必须无重复JWT vendor，缺宿主明确拒绝，修复后重测重打包。
- Core首批独立审查四项修复已落：实时授权先于敏感缓存返回/current不复用历史features、released安装不恢复null-source团队票、产品应用经正式IAM同组织校验且发布后固定、reset严格布尔并计入统一幂等摘要；另补管理save/publish产品→套餐/SKU锁序及锁后归属重验。`php sand-license/tests/admin_lock_order_static.php`通过，0.000秒，明确标识static-rule不伪称PG死锁实验。LicensingLogic SHA256：`f0b9f267c70371ec5b03fc3a63b5f095a2d6a29ac3ea31332429f455e84bf75b`；AdminLogic：`48984195f33bd7426bf9507c88937654761b4fb4298dd3d25aa0c01af9ef71b4`；install.sql仍为`04f7a83062c24ff0bbb50f0e01c0eb647f23492e1f82715d761cc438bcb3747c`。修复候选已独立源码复验并重打包；控制流替身测试不能当作真实PostgreSQL结果。
- API执行者复测45项真实Request/输入/失败关闭/公开IAM ABI及结果consumer检查；Fulfillment43项生产事件/来源/领取policy、Membership25项区间与功能投影检查通过。会员3.3勾选仅源码及上述纯policy层，不包含真实写入、唯一约束或并发退款，6.2保持未验。源码已有首次兑换/一次明码/状态恢复和事务路径，未被helper覆盖的事务行为不以纯policy结果补齐，2.2/2.3/2.5/2.6/3.1/3.2保留未勾。
- UI执行者冻结17个交付文件，strict TypeScript与Vite构建通过，claim七项静态行为及列表两项行为检查通过；4.2/4.3仅完成源码/本地构建/静态与行为helper，非实际浏览器或真人验收，6.3保持未验。
- 不同上下文Astra独立源码审查及修复复验通过，六项首批发现、管理锁序与宿主依赖来源补充均无剩余must-fix；其中控制流场景使用替身读取和真实Firebase签验，仅证明源控制流，不代表PostgreSQL并发、真实IAM授权或HTTP验收。5.3为源码审查完成，不是可生产交付结论。
- 历史共享宿主候选ZIP为`/private/tmp/sand-license-package-final-shared-LGZtgF/sand-license-0.1.0.zip`，63文件/24精确host载荷/零JWT vendor，SHA256`00741b066b418f093ca353ffe1475e1770097b91192175e85780f41565b956d6`；整个源码inventory摘要`e87eda525248b885fa7306181eb9f01431b61ecf8e936a64f2252f3b2d154062`。它固定IAM0.8.3，仅记录当时包构建/解析/隔离加载通过，不是当前IAM0.8.4候选，也未执行插件安装。
- 依赖升级批次保持`support=">=0.1.0"`，无host_max，不提高最低版本；配置、builder、包断言与README统一固定公开IAM0.8.4/SHA256`1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`。实际builder耗时0.09秒，新候选`/private/tmp/sand-license-iam084-candidate-sKj9DO/sand-license-0.1.0.zip`为63文件/24精确host载荷/零JWT vendor，SHA256`445c391a029d732071e82beecae25f3e37f8a9f53e9ebaf8d01acb68a9aa0ca6`；源码inventory`/private/tmp/sand-license-iam084-candidate-sKj9DO/source.sha256`摘要`a4454149782ae4d4dc75af49a2c38768b7635cca7f34a1a6f85902b763a22142`。`php sand-license/tests/packaging.php <新ZIP>`实际标准SandPackage解析、catalog/依赖身份、两种篡改负例及解出后真实宿主签验/缺依赖/外来栈拒绝通过；`php sand-license/tests/dependencies.php`六独立真实进程再次exit0，所改两个PHP文件lint通过。5.1据此恢复勾选，只代表包与隔离加载层。
- 依赖升级时实际宿主vendor登记Core0.2.0/Package0.2.1/Tinywan1.15.0/Firebase7.1.1；测试的HostPayloadManifest实际类来自权威`sand-package/server/plugin/sandpackage/app/service/HostPayloadManifest.php`，不是实际安装业务调用。当时仅已授权本插件源码与无DB回归，未由IAM安装授权推导License运行权限；随后用户已单独明确授权下述完整临时验收批次。
- 初始无DBREADME命令已实际运行：refresh-manifests、dependencies、api_adapter、security、build-package及packaging；README/CHANGELOG/NOTICE与宿主复用边界一致，本新插件尚无已有独立Wiki可同步，旧架构文档未改。Wiki发布仍未授权；宿主使用步骤已获临时验收授权，按新批实际结果记录，未运行项不能补称通过。
- 未勾选项不等于没有源码，而是完整任务验收证据尚未汇总或存在未覆盖要求。初始冻结候选未执行PostgreSQL、HTTP、实际界面、安装宿主、客户启动/续签、提交发布；新的运行证据逐项追加，不追溯改写初始结果。

## 完整临时验收批次（2026-10-04，进行中）

- 用户明确“授权”，后又确认“对，按完整目标实现执行”，覆盖既定SandLicense标准安装/生命周期SQL、隔离临时keys与fixtures、必要独立测试服务及本次新增临时资源清理；不改持久业务库或既有keys，不含commit/publish。完整目标仍是既定商业授权方案，不新增计价、升降级或各产品会员业务；复用只是实现手段，不将复用片段当完整交付。
- Setup执行者独占宿主/DB/keys/服务生命周期。其报告唯一临时宿主`/private/tmp/sand-license-live-WMjBV8/host/server`、数据库`sand_license_test_20261004_wmjbv8`及标准安装IAM86表/License15表完成；主控仍需核对完整安装证据。正式框架bootstrap暴露IAM插件Composer registry类来源与License启动校验冲突，修复及候选重打包前不绕校验继续，6.1暂不勾。
- 核心执行者准备任务tmp的真实HTTP/PG runner与setup执行的正式AdminLogic fixture helper；产品/策略fixture唯一前缀`slrt_wmjbv8`，每轮再追加随机后缀隔离幂等请求。helper调用真实UserInfoCache/AdminOrganizationAccess和安装内核，不伪造HTTP登录，也不作为HTTP权限证据。runner业务写操作全部实际HTTPS，PDO仅SELECT并启用只读会话，采用真实安装内核、真实Firebase签验和两个并发cURL连接，不使用provider/数据库替身。
- 首轮真实验收耗时13.81秒，126项检查中125项通过、1项失败，不能视为完整业务通过。已覆盖实际签码/状态恢复、首兑UTC与900秒租约、300秒续签建议、POST current/body selector/nonce重放拒绝、IAM错误受众/组织/动作拒绝、release/renew和最后席位及同码兑换竞争、领取/凭证恢复/明确重发。领取重发与旧码兑换真实并发时，重发成功而兑换返回内部错误10501；实际PostgreSQL报`SQLSTATE[40P01]`，redeem等待`sand_license_claim`，当前源码存在redeem先product锁与重发持claim后新码外键检查的锁环。必须修权威源码、重构候选并标准重装复测，不把内部错误算业务拒绝，不引通用重试掩盖问题。
- 首轮去秘密结果保留`/private/tmp/sand-license-http-pg-acceptance-h5KwFJ/report-first-deadlock.json`，SHA256`8cb9e256f804ee51d646ac7c5b4bfc94de1e2f2ddf45e2d7344c4e24b393c37d`。服务/数据库保持供单变量修复；尚未完成refund/chargeback/cancel/tombstone、来源并发、双方审计及自然900秒旧租约drain后的替换安装，6.1/6.2不勾选。静态锁序审查已通过不能抵消本次真实PG失败。
- 该真实失败的权威Core修复已冻结：`LicensingLogic`SHA256`bebec996eaf1297148b8decf687823ab627a0484838e1a44ef6b8cd71cc14748`，`CodeLogic`SHA256`61a6ab5bb6c5f141f6cdd5bbe4e6ed90f8c0391045813046b5a89a797aa35a6d`，`core_behavior.php`SHA256`04e8b0b7420789d9e434ba9d32fb782dfc940b2421fe0b8cbe8c17dafc965577`。claim关联兑换产品→来源→claim→code→绑定核对→retry→权益；手工码不引来源锁。生产绑定policy新增跨产品/来源/claim/currentcode、终态退款、缺字段拒绝，83项纯policy/.003秒及POST/锁序两项明确static gate、三文件lint通过；未执行修后HTTP/PG，不提前勾6.2。定向检查reset、adminTicket、revoke/suspend及runtime activation发现其现有产品首锁早于权益/安装及Records产品外键写入，无需另改AdminLogic；不能由此静态检查推广为任意事务均无死锁。
- 真实UI另发现初候选没有标准菜单登记，未授权角色menu为空/403，不能用手造测试菜单掩盖。已补权威install/update同幂等菜单块、动态RETURNING id和两锚点/31项实际Controller Permission；现有API/Vue slug不变且不自动授角色。实际临时PG只读核表为`sand_system_menu`/`sand_system_role_menu`，无`sa_menu`；SQL静态覆盖当前Permission及精确卸载范围通过。真实安装的Package0.2.1 `AbnormalPluginCleanup::parseDeclaration`解析当前源SQL，确认15 owned表与同一角色/菜单清理条件，并拒绝错误underscore slug声明；该parser检查仅使用只读PG连接quote，未执行菜单SQL，不替代新包标准安装/UI证明。
- 历史Bootstrap `7629ff...`的global InstalledVersions来源绑定已被正式启动发现后替换；当前`Bootstrap.php`SHA256`eaf55a9691781037ff5f488fd8350c92bb715b65d0444d668af66247a5fb6a4a`使用宿主installed.php权威并完成真实IAM-first进程检查（包执行者证据）。历史POST-only Core `6524d1...`亦被本批`bebec996...`锁序修复替换。最终当前candidate必须同时含启动、POST、并发锁序和菜单修复，标准安装后的PG/UI及SDK新900秒证据不得借用旧包窗口；6.4提交/发布未授权，是后续交付门，不阻塞已有授权本地完整验收结论。
- HTTP/PG待验路径映射：管理员真实登录/读写/签码与状态恢复；首兑UTC起算/签名/JWKS/renew/current及错误key、fingerprint、body、product、nonce/jti、scope/audience/action拒绝；release/renew竞争与自然900秒旧租约drain、source票恢复及team/admin-ticket/最后席位竞争；可信paid、claim只读/一次展示/凭证恢复、明确重发与兑换竞争、event与商业来源唯一；独立member周期、cancel保留、refund/chargeback精确撤来源与退款先到终态；双方真实审计和持久记录/日志无秘密。
- 当前协议安全修正：`current`从旧GET收敛为POST JSON，七proof claims不变，所有selector与nonce/request_id纳入raw-body SHA绑定，不提供旧GET兼容。API、域method gate和接入样例须一起更新并重构候选；runner补同安装钥多权益selector替换拒绝、query参数拒绝及旧GET无许可结果。Core唯一修复仅`LicensingLogic::verifyProof`的固定POST方法，SHA256`6524d1b1b906cb5e40a306e8a636faf65b30aa83b967f1ea3198ba228b2e6224`；`core_behavior.php`SHA256`c3c7a60f9fa0439779d7a5097513f1cad146720b534d3c8d7a5995d969a54a2d`。实际Core72个policy及额外1项明确static POST gate通过，0.001秒，两个PHP lint通过；没有HTTP/PG业务证据，不能提前勾选真实协议通过。上述IAM依赖升级候选亦成为本协议/正式bootstrap修复前的历史候选，待修复后标准重打包安装取证。
- 月末/闰年夹点保留生产日期helper实测与真实当前UTC首兑分别取证，不改serverclock或DB时间伪造900秒租约通过。真实管理/领取界面和协议接入样例仍是完整交付门；客户现有客户端重写明确不在原方案范围，HTTP成功不冒称客户客户端或生产长稳完成。

### 修后真实核心验收与管理日期投影发现

- 统一修复包`/private/tmp/sand-license-full-runtime-candidate-DuIbbU/sand-license-0.1.0.zip`的SHA256为`af9bb6a9b90abb345c559c4f946ab2ad79f916a3728dc24f213e7d8b18c95529`。标准安装后真实HTTPS/只读PG核对197/197项通过、901.36秒，结果`/private/tmp/sand-license-http-pg-acceptance-h5KwFJ/report.json`，SHA256`3bad0111eacaacfe67c9cd447e481850653606155d0e7111872578fa8c229aba`。报告candidate_sha256字段为空，不虚构报告字段；候选由外部实际ZIP摘要、安装记录和报告内四个真实加载组件摘要绑定。首轮40P01失败报告保留，未改写为成功。
- 该197项实际覆盖首兑/重试/签码与明确重签、proof与POST current body selector/nonce拒绝、续租撤权、释放/续签和最后席位竞争、团队与自身source票恢复、领取/凭证恢复/重发兑换竞争、同商业来源不同事件及不同来源同时paid、精确退款/拒付/取消/tombstone和双侧审计脱敏。原死锁场景修后返回正常业务拒绝且只兑换一次，无通用事务重试掩盖。旧租约自然900秒后更换安装仍为同一权益；未消费challenge自然超过120秒后拒绝。没有缩短TTL、修改时钟或篡改DB时间，不据此声称生产长稳/性能或客户端全平台通过。
- 独立真实UI随后发现该包设备列表拒绝`SAND_LICENSE_INPUT_INVALID: 时间格式不正确`，所以197项核心成功不代表管理完整通过。真实Think ORM `max`默认force=true将PG日期强转浮点；唯一受影响调用为`AdminLogic::display`聚合lease.expire_time。权威源码仅调用改为`max('expire_time', false)`并说明时间类型保留，不放宽Values时间验证、不改ORM或租约授权行为。`AdminLogic.php`SHA256`8c2384e9f95e26151b4e770d3e80c5859e2672ac2a4b2ad31363eefdca04dc22`。
- 新增长期可显式启用的`tests/admin_projection.php`（SHA256`3ebdf1edecad897b0dbddce9ba82b582dc783ce7abf92fb9fd917a373e7c4254`）：必须传`SAND_LICENSE_TEST_BOOTSTRAP`与`SAND_LICENSE_TEST_DATABASE`，验证current_database并强制只读，使用现有真实lease/activation。实际命令`SAND_LICENSE_TEST_BOOTSTRAP=/private/tmp/sand-license-live-WMjBV8/cli-bootstrap.php SAND_LICENSE_TEST_DATABASE=sand_license_test_20261004_wmjbv8 php sand-license/tests/admin_projection.php`通过11项、0.093秒；真实ORM默认float缺陷复现、false保留timestamp和权威生产display精确UTC投影通过，无fixtures/DB写入或HTTP授权替身。初次沙箱TCP拒绝，获得require_escalated审核后同命令实际通过，未换入口绕过。
- 本片修复后Core83项纯policy及POST/锁序两项static gate再次通过、0.002秒，两文件PHP lint通过。Licensing `bebec996...14748`、Code `61a6ab5b...35a6d`、Fulfillment `f4dbccba...dd40`字节不变；自然900秒等既有核心证据允许按组件等值透明继承，不能将旧包197项伪装新包重跑。af9包成为日期修复前历史候选，待标准新包和实际设备list/detail/reset/UI复验；4.1/6.3暂不勾，安装update/uninstall及本任务临时资源清理仍待setup唯一执行者证据。6.4提交/发布未授权，不执行。

## 最终已授权闭环与当前交付候选（2026-10-04）

前文按执行顺序保留历史失败、阶段未验和替换候选；本节记录发布授权前的已验收候选，不追溯改写历史结果。该时点23项已授权任务完成；6.4后来获“授权建仓发布”并完成，具体见下方发布批次。

- 最终统一ZIP为`/private/tmp/sand-license-final-product-candidate-0YDURj/sand-license-0.1.0.zip`，SHA256`0a2f8105b5c505f10f1c5d409341c6aa5677291b8cf1c264f9e4770a4e6af575`；67条目、24精确host-payload文件、无独立JWT vendor。源码清单`/private/tmp/sand-license-final-product-candidate-0YDURj/source.sha256`摘要`41f61b2fc75e08d169c86b39e3ac61398c4ba48cea922d390f485cc40c419dbc`，清理后全部条目重核通过。真实标准安装耗时0.816秒、正式bootstrap成功，宿主为clean官方SandAdmin `533ca1eb5bcd18a8237b74f6141bb0f8b14187d7`任务副本，Core0.2.0/Package0.2.1/PHP8.2.29，公开IAM0.8.4；JWT实际组合仅Tinywan1.15.0/Firebase7.1.1。
- 最终Admin投影`0aab2a4275217603bddd915979c56bac1337d7a9665bd8ca1dd967dc048da206`同时修日期和产品名：四个display调用保留原管理scope，activation沿真实权益→既有ProductLogic::scoped重核组织/产品→产品名，不扩大权限、不因停售隐藏可见历史；日期max(false)和秘密剔除保留。最新只读PG测试`7673cc030210652108c63c7148b6b29242b7e01929a9c14fdc69d883f7cf782e`必须显式传bootstrap/数据库/产品代码；最终安装后17/17项、0.122秒，通过真实谱系、UTC、公开index/read名称和空组织scope拒绝，未写DB。最终真实浏览器GET list/read也为200，产品名正确、UTC`2026-10-03T21:01:02Z`/本地05:01:02一致，秘密字段不存在。
- 原af9真实核心197/197、901.36秒和客户端session11498真实899.71秒自然到期结果按组件字节等值透明继承，不声称在最终整个ZIP重新跑197项或15分钟。实际客户端真实TLS/JWKS/EdDSA、POST current/renew及cURL errno7断网下新启动拒绝、当前运行仅到原签名exp、到期run/feature关闭已通过；本地118项注入transport/clock回归与这条真实运行证据分层，不将注入层称HTTP实测。SDK无秘密报告`/private/tmp/sand-license-sdk-evidence.YjIzzT/report.md`摘要`3e27580fcd2c22fe7d354223c0b487d8743335ecaa1491b101b03fb6b8a98ca4`，原生工具输出转录`session-11498.stdout.txt`摘要`f04dd160d9da557afa74bc1c03c6bcd787b215fb3643fcdea6b5eab2b8ff2245`，非新timer或rawPTY导出。最新映射`latest-component-mapping.md`摘要`9b60977d6fbc689247fad91e899158cdd36c3803fbf7b05cfd59c63debc503fa`逐条核对17个Core/Security/Client/run/Bootstrap；最终67条目中62不变，变化仅README、Admin、SDK文档/harness及host清单。Admin变化明确排除继承并单独实测。
- 独立真实UI完整通过：正常登录/标准菜单、产品套餐发布/手工发码与重签/SKU/会员行、买家一次领取/明确重发/退款，以及真实提交后丢响应→状态无秘密→明确重发→刷新清空；默认未勾选reset实际POST布尔false、不发票、席位冻结到原lease；无权账号正常登录和直达管理路由均403。最终变化的设备产品/时间投影单独重装复验；其余UI与SQL/Core字节相同的历史运行按上述边界继承。69个文本产物秘密扫描0命中，curated四张无秘密截图保留；瞬时loading遮罩的deny截图排除视觉证明。实际CLI Chromium验收不是生产TLS、真人或原客户桌面程序已改造。
- 标准生命周期真实通过：正式PostgresLifecycleSqlExecutor连续执行update两次，33完整菜单行/ID431–463和role2授权不变，所有IAM数据及其他菜单/角色引用不变（0.119秒）。标准License卸载15 owned表、33菜单及其引用为0，24精确载荷文件逐一不存在；其他表/菜单/角色与86 IAM全表数据保持一致。最初额外要求app父目录也消失的断言过严，原失败日志保留；独立续查证明仅五个空父目录、没有内核文件，随后随任务宿主删除，不假称安装器原已删除目录。IAM随后亦标准卸载成功（0.754秒），没有补丁提供方或安装副本。
- 精确临时资源清理及fresh进程回读完成：只删除owner=supdger的`sand_license_test_20261004_wmjbv8`，前后active0/exists=false、其他数据库名不变；仅删除task Redis前缀6键/回读0，无FLUSHDB。仅停止task服务及浏览器精确PID，端口18984无监听；隔离keys、四组设备state和66183条任务宿主副本删除、回读全部不存在。原demo四配置、21项持久storage摘要和可恢复backup保留，证据与源码ZIP保留。已删除本次临时私钥不保留可恢复副本，不触已有keys或持久业务库。
- 最终secret-free验收及清理证据统一在`/private/tmp/sand-license-final-evidence-AIqoVy/`：`verification.md`SHA256`8e47ceef717194975159435760b5cbecee651f7ca2ab29f56bec28fecca4796b`、`ui-checkpoint.md`SHA256`5f501a4d379e9e7979b931e0f90d85bb487ec6583724a9807b8dd5a0eb5ec464`、`final-resource-readback.log`SHA256`1391e48e3624ec091843e59e2087255edaea74e839eeedb86cca96ffaf653162`，以及实际update-twice/standard-uninstall/continued-uninstall和精确资源清理日志。原40P01非秘密诊断另留`/private/tmp/sand-license-http-pg-acceptance-h5KwFJ/deadlock-excerpt.txt`，摘要`d71df27f1910fa240f2a96155dbebf7308aa7edaa9cd728954a77ef272874585`，与原失败报告一并保留，不将失败竞争算正常业务拒绝。

### 需求到真实验收映射

| 既定spec | 当前证据与边界 |
| --- | --- |
| online-license | Core83月末/闰年与策略回归、真实197首兑/nonce/body/租约/席位/票/竞争、自身自然900drain及SDK断网自然exp；未改客户工作区客户端。 |
| fulfillment | 真实197来源/事件双幂等、quantity资格、claim状态/凭证恢复/一次明码/竞争、退款tombstone及审计；独立UI真实丢响应恢复、重发和退款。 |
| membership | 25项区间回归及真实197多来源grant、A退款保B区间、cancel保留、chargeback、partial unsupported和退款先到终态；不新增各产品定价/升级降级业务。 |
| package | 最终标准解析/安装/24载荷与公开依赖真实加载、管理allow/deny/UI两投影、两次幂等update和精确uninstall/清理；后续独立仓公共发布与下载校验见下节，未持久部署或线上业务验收。 |

## 独立仓 v0.1.0 公共发布（2026-10-04）

- 用户明确“授权建仓发布”，本批仅独立 SandLicense 源码仓、必要文档/构包/测试入口适配、提交推送、tag/Release/资产公开及公开重下载；没有把授权扩大到生产服务、数据库、密钥、其他仓目录或 Wiki。聚合工作树无关 dirty 全部保留，未在其根提交或推送。
- 独立公共权威仓为 https://github.com/supdger/sand-license 。最初 `sand-license/` 全部未跟踪，无已提交目录历史，因此按冻结源码清单快照建仓并记录 SOURCE_OF_TRUTH，不声称保留拆分历史。暂存实际88个长期维护文件，仓库外 index-review/source-link-review JSON 分别绑定 `85ecc12ab975c1635968d478b6e2d23a48626fdc` / `2768cffd219fd4f5fcf28c200a492a389030ba0e`；两次真实 pre-commit 均执行 metadata 与既有 scan_change_quality.py index检查通过，未跳 Hook、未改全局 Git 配置。
- 固定 clean 提交 `94bd4a437026c99771479a9214a46620819fc058` 已公开于 `codex/initial-release` 初始默认分支；未直接推送 main，未用 API 初始化额外 README。`v0.1.0` annotated tag 对象 `9d3e7a3e5d5ae394eead11e318af8f6ee29073d9`，匿名 API 与公共 clone 确认 peeled commit 为上述固定提交。
- Release https://github.com/supdger/sand-license/releases/tag/v0.1.0 于 `2026-10-04T00:30:48Z` 公开，非 draft、非 prerelease。正式 `sand-license-0.1.0.zip` 为 1,874,116 字节、68条目与24精确宿主文件，SHA256 `27ef49df888030f430a4bd6871361af447e3bd29eeee0a34c2fb7108434adb7e`；同时公开 SHA256SUMS。此工件来自 clean committed revision，原 `0a2f8105...` 为历史验收候选，不能替代正式工件摘要。
- 原83源码条目中75不变，8个变化仅 README/CHANGELOG/NOTICE、构包参数及测试入口；正式 ZIP 保留原67条目（仅三份文档内容改变），并新增 README 所引用的 SOURCE_OF_TRUTH.md。全部生产 API/Core/Security/UI/SQL/config/host manifests/PHP客户端实现与已验收候选逐字节一致，因此透明继承原197项及自然十五分钟证据，不声称本批重跑数据库、真实运行或全部页面。
- 独立 checkout 明确依赖环境后实跑 PHP8.2：52项真实Request/API输入与端口检查、安全签验、十种真实依赖独立进程、83项Core policy、SQL static、118项客户端注入回归、客户端 --help 和全部 PHP lint 通过。IAM函数/vendor由最终验收 ZIP 内公开0.8.4依赖解出并核 SHA `1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`；这是工件加载，不是重新安装。未建库、未连接数据库或管理服务。
- 不同上下文 `gpt-6.1-sol/high` 独立发布 gate 通过：clean commit、全部ZIP条目与提交字节、包内README链接、真实Hook/index审查树、真实SandPackage parser/24 payload/九种依赖模式与两种篡改拒绝。公开后先 gh 下载，再匿名正常 TLS curl 下载；两份下载与固定本地工件 byte-identical，SHA256SUMS -c OK。用公共tag clone内的 packaging.php 对匿名下载包再次执行真实SandPackage及解包消费检查通过；无 `--insecure` 或下载认证头。
- 发布证据与工件保留于 `/private/tmp/sand-license-independent-release-jKPJ6l-evidence/`，独立 checkout `/private/tmp/sand-license-independent-release-jKPJ6l`；未发布原始诊断日志、私有夹具或进程秘密。OpenSpec当前24/24任务完成；归档未执行。宿主目录自动发现、Wiki、生产部署和客户应用改造均未在本批执行。

## 官方目录追加交付（2026-10-04）

### README 首次使用修正候选

- 读者路径发现：原 README 先展示源码回归与构包，容易被误读为安装前置；缺少官方插件仓库入口、产品发布顺序和实际表单输入来源；运行密钥配置缺生成来源及确认结果；自动履约、买家领取与商城支付边界不够清晰；客户端信任值的交付来源未说明。
- 本轮只修 `sand-license/README.md`：安装前门、运营首次产品→套餐→发码、丢响应查询与明码恢复边界、客户端参数来源、POSIX 外部密钥生成与部署配置、通用履约范围；源码测试构包折叠。沿用现有客户端文档，不新增 Wiki、不改业务代码。
- 可观察目标：读者无需先运行测试或构包即可找到官方安装入口；能取得必要组织/应用及产品信任值，完成产品与套餐发布、签发并确认“未兑换”；能判断发码与兑换的分阶段配置前提及网络中断后的查询入口。作者源码核对与临时目录 CLI 示例验证不替代独立读者或真实安装验收；候选仍待独立 reader 判定。
- 当前仅本地文档候选；公开默认分支、v0.1.0 tag、Release ZIP 与目录工件尚未改变。后续若公开 README 单独修正，必须区分仓库 README 与已发布 ZIP 内旧 README，不能声称旧工件已更新。
- 后续完成：独立 reader 对最终 README SHA256 `42e5f1f35ea811d53cccf08ed5334a4d22eba336f83b905659b2b8e5cb1a309b` 冷读通过。作者 PHP 8.4.18 按 README 原代码临时生成三份文件，格式、公私钥对应、pepper 长度、0600 权限及重复执行拒绝覆盖通过；客户端 `--help`、13 个本地链接、3 组折叠结构及 OpenSpec strict 通过。本次临时密钥按精确任务路径删除并回读不存在，不保留恢复副本；未重新安装或运行业务、未改生产配置。
- 用户原“授权建仓发布”覆盖同一 README 交付缺陷修正，主控确认最终候选并放行。独立 checkout 原 clean，仅通过 apply_patch 同步 README；feature `codex/readme-first-use` 实际暂存 tree `98d118914d7cb23e5d1788b8ae546467f3faee2c` 经外部 quality 结论与真实既有 pre-commit 元数据/index 检查通过，提交 `5222343c2f9ca51aefff19287323f58edb299ef6`。仅 feature 推送，正常 [PR #1](https://github.com/supdger/sand-license/pull/1) 关联任务后于 `2026-10-04T01:04:04Z` merge 到现默认 `codex/initial-release`，merge/default SHA `ee7e66d0dee4bf8831b857ac29678044f6782902`。PR 仅 README，CLEAN/MERGEABLE、无 CI checks，不称 CI 通过；未 admin/bypass/force，未直接推目标或改默认分支。
- 合并后匿名正常 TLS 回读默认分支 README（14600 字节）SHA 为上述 `42e5...`；v0.1.0 tag object 仍 `9d3e7a3e5d5ae394eead11e318af8f6ee29073d9`、peeled source 仍 `94bd4a437026c99771479a9214a46620819fc058`。匿名重新下载 Release ZIP SHA 仍 `27ef49df888030f430a4bd6871361af447e3bd29eeee0a34c2fb7108434adb7e`，官方 catalog SHA 仍 `d4d4e6f8d8c99bd6dc5ac90f3d39ba3b1cd90eee04f6397ffa4bc327828f5468`。**在线仓库 README 已修；已发布 v0.1.0 ZIP 内仍为旧 README**，本批未替换 tag/Release/资产或目录，未做 Wiki、数据库、服务或生产密钥操作。公共回读工件保留 `/private/tmp/sand-license-readme-public-check.YwkhEg/`；聚合工作区仅记录本次文档，不在根提交。

- 人类“授权”明确承接上一条请求的 SandAdmin 目录登记、feature 分支提交推送、PR 与正常合并、公开目录及下载校验；不涉及生产安装、数据库、服务、密钥、Wiki 或 main 直接推送。原聚合工作区及 SandAdmin 本机既有 dirty 保留，本批不在聚合根提交或推送。
- 公开目标 `supdger/sandadmin` 默认 main 基线 `6637c2fef1a18b27fc1ec14ae4ecd277f2f1a183`；隔离 clone `/private/tmp/sandlicense-catalog.6HocZ6/sandadmin`，feature 分支 `codex/sandlicense-0-1-0-catalog`。候选只为根 `catalog.json` 新增一条 SandLicense，全部旧插件条目严格对比不变；索引树 `1c40ea93467fc7d52f2dd264250778062e42a8af`，catalog SHA256 `d4d4e6f8d8c99bd6dc5ac90f3d39ba3b1cd90eee04f6397ffa4bc327828f5468`。
- 正式 ZIP 从公开 Release 匿名正常 TLS 下载，1,874,116字节、SHA256 `27ef49df888030f430a4bd6871361af447e3bd29eeee0a34c2fb7108434adb7e`，根 app/title/about/author/version 从实际包元数据读取。目录为 v0.1.0 / sand-license-0.1.0.zip / host_min=0.1.0，无 host_max；notes 明确 SandIAM 0.8.4、既有 PostgreSQL/Redis/HTTPS、Windows 服务端密钥 ACL 尚未实现及生产边界。
- 使用官方宿主当前锁定 SandPackage 0.2.1 的公共源码 `39b9ee61dd7cbba11295c1b8ee6337feabecb0f9`，反射确认 RepositoryLogic/InstallLogic 实际类文件来自该隔离源码。借用已安装第三方依赖，不称 fresh Composer 安装；20项无数据库检查通过，覆盖整目录 parser、推荐 0.1.0（宿主0.1.0/0.2.0/1.0.0）、0.0.9拒绝、独立URL、标准README读取、未知版本/旧摘要/篡改拒绝、真实下载入口准备候选与标准包完整性。没有调用 install()、执行SQL、连接DB、启动服务或写共享宿主；过程脚本与日志仅在仓库外任务目录。
- 新隔离仓无既有 pre-commit 且无自定义 hooksPath，通过 `.git/hooks/pre-commit` 接入既有 `scan_change_quality.py --repository-review check`；prepare/record/check 对实际索引树均通过，外部结论为任务目录 `index-review.json`。不同上下文独立候选审查通过后，真实 pre-commit 提交 `87ac12a087802e6b67f77ae065db0588a354d3c6`，仅该 feature 分支推送；[PR #39](https://github.com/supdger/sandadmin/pull/39) 创建并关联本任务后正常合并，merge/main 为 `0737c41092b85c2899bffc7ad83c770c061a61b9`，时间 `2026-10-04T00:49:55Z`。GitHub 状态 CLEAN/MERGEABLE、statusCheckRollup 为空且明确 no checks reported，不冒称 CI 通过；未使用 admin、bypass、force 或 main 直接推送。
- 合并后执行者与独立审查者分别匿名正常 TLS fresh 下载公共 main `catalog.json`（4606字节、SHA256 `d4d4e6f8d8c99bd6dc5ac90f3d39ba3b1cd90eee04f6397ffa4bc327828f5468`）和正式 ZIP（上述 `27ef49df...`），均与固定候选逐字节一致。独立公开 gate 通过：标准 Package 0.2.1 parser/推荐、0.0.9拒绝、根元数据/runtime版本/support、IAM0.8.4依赖字节、标准README读取及错误身份拒绝；原始独立日志 `/tmp/sand-license-catalog-independent.UlfAbm/public-check.log`。6.5据此完成，当前25/25；本批未重跑197项或900秒运行，未安装或部署。
- 执行者 fresh 公共目录/ZIP 再跑实际加载器20/20通过，日志 `public-probe-complete.log` SHA256 `d2266c516be44077cc1c88ae39e8f2645f7629eafb5cdf74107ebff81bc46f2b`；首轮复验因当前进程临时目录 `/Users/supdger/.tmp` 超出沙箱写入范围而失败，日志保留，显式指定任务临时目录后通过，未修改产品或绕过审核。官方 CLI `openspec validate add-sand-license-commercial-entitlements --strict` 返回 valid。仅删除本批两份约2.3MiB的本地探针候选目录 `probe-host` / `public-probe-host` 并核对不存在；clean源码检出、工件和日志保留于任务目录，无本批服务或Docker资源。
- 只读核查已有8787宿主 cwd 为 `/Users/code/project/ttt/sandadmin/server`、3006前端为该宿主 `sandadmin-artd`，默认目录配置为 `supdger/sandadmin/main`；实际 GET `/tool/install/repository/catalog` 返回401登录凭证无效或过期。本轮未提供该宿主有效管理员身份，未猜凭据或改配置，因此真实登录页面卡片/动作未实跑，不能以CLI推荐和包解析声称页面已显示或已安装。


## 7. 商业模块导航修正（2026-10-04，追加切片）

- [x] 7.1 冻结九模块路由/权限，修 install/update/uninstall：31旧权限保留 type=3/ID/slug/角色/停用，仅新增九页面精准继承相应旧index导航关联；按钮归属与旧index兼容，不扩大API授权。
- [x] 7.2 管理端移除横向模块标签，九固定资源独立页面沿用宿主框架，权限和旧 index fallback、刷新/直达及状态回归。
- [x] 7.3 执行现有 SQL 静态门、真实 Package split/清理声明 parser、固定 IAM 本地构包与载荷检查；仅更新导航相关 README/CHANGELOG。
- [x] 7.4 固定候选交不同上下文真实 UI 和权限审查；本轮不执行数据库升级或服务/宿主写入，未验层明确记录，不继承旧 UI 通过来勾选本项。

本切片授权：用户“菜单变成了标签。这页面跟谁学的”“赶紧解决！！！”覆盖 SandLicense 指定源码修复和必要本地回归；不沿用 Package 发布授权、不执行迁移/建库、同步共享宿主、服务操作、提交推送或发布。既有公开 v0.1.0 和25项历史完成记录不被本切片改写。

### 本轮导航源码与包验证（7.1/7.3）

- 实时只读 git ls-remote 确认公共HEAD `ee7e66d0dee4bf8831b857ac29678044f6782902`，只fetch/archive到/tmp比对；旧update SHA `c265fc21a766f9b514d9f1ad73d4172c0079673f5f36b1ce31e0125a8457131a` 与改前基线一致。公共0.1.0/tag/Release/catalog未改。
- 旧index原行改type2方案经真实Core getAuthByRole/getAllAuth只读取type3的证据及独立review证伪，已彻底替换：31type3原ID/slug/status/role保留，九type2空slug新page。仅首次插入page时继承对应旧index角色的导航关联，不按center批量授予、不新增API动作；重复升级不恢复管理员撤掉的新page关联。停用center或旧index保持page停用。
- `php tests/sql_static.php`与修改PHP lint通过；static-rule门覆盖15表、2兼容锚点+9page+31type3、相同install/update块及精准卸载。真实DB仍未执行。
- 0.1.1候选构包78ZIP条目，固定公开IAM0.8.4 SHA `1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`，24host-payload摘要未改，不无故刷新manifest。包 `/private/tmp/sand-license-navigation-20261004/package/sand-license-0.1.1.zip` SHA `afd3b0a7fe26c125cb2e81861347f45361c25b696739eba97daa7c1f7136eb99`。最初0.1.0校验包保留为诊断，不能作为升级候选。
- PHP8.2.29实跑现有packaging.php，显式加载已有sand_demo vendor（不写宿主）与固定IAM解包functions：真实Legacy上传元数据parser、PG splitter、异常清理声明parser、九page/15表范围、0.1.1>0.1.0/运行版本、九种JWT依赖独立进程、篡改与缺失载荷拒绝全部通过。首次ttt依赖加载缺HostPayloadManifest，改显式完整demo依赖后通过；版本测试最初误以Legacy元数据返回version，核源码后纠正为真实parser核身份并读根INI，不改提供方。
- 实核Package InstallLogic：installed上传严格要求版本高于旧版并设置update=1，更新分支只执行update.sql；本update无业务DDL。existing-schema重接属于尚未安装但已有表或未安装IAM依赖的路径，本正常0.1.0->0.1.1不新增重接manifest。未执行install/PDO/SQL、建库、服务或共享host写入；真正DB幂等与角色差异仍待授权隔离验收，parser不能替代。
- README只同步左侧菜单及公开0.1.0/源码0.1.1未发布区别，CHANGELOG记录升级影响；无Wiki发布。原197项/旧页面验收不作为本轮通过；7.2/7.4的本轮桌面候选验证及限制见下方独立验收，不推为真实数据库升级通过。
- 完整源清单与source-vs-public.diff、candidate-proof.json在上述/tmp目录，source.sha256摘要为 61fb7ee12eb1c0e37a4a2d1c57dac61a935e05c41228747baae09f7063e414ca。

### 本轮前端候选与独立桌面验收（7.2/7.4）

- 前端实施者已完成九个固定资源页面和共享ResourcePage，移除横向模块标签；主控核对生产前端固定候选（前端候选摘要前缀 `d72618d`），严格TypeScript、生产构建与两项race行为回归通过。旧index兼容入口仅按既有index按钮权限选页，不发业务API；其重定向运行路径本轮独立验收仅源码审查，不冒称已实点。
- 不同上下文独立验收实际模型 `gpt-6-astra / medium / openai`。结果 `/private/tmp/sand-license-nav-review-kfjyj0db/review-result.json` 为 **PASS for desktop navigation candidate; SQL and ZIP static review pass**；使用从现有本地sand_ai复制到/tmp的宿主生产router/sidebar框架、预登录fixture和模拟user/menu/list API。独立验收者准备harness并已看菜单契约，属于Agent桌面候选验收，非冷读盲测、真人测试或clean生产部署。
- 实际桌面界面通过：九个原生sidebar子菜单及独立业务视图、路由/面包屑/表格对应；事件关键词随URL在刷新和前进后退后保持；product-index-only角色只见产品且创建隐藏；无权plan直达由宿主404拒绝；plan-index-only只调用plan业务端点并用产品ID文本筛选；过期菜单但空buttons时明确权限提示且业务API调用为0；最终页面console无错误。product-only/plan-only均无跨资源业务API请求。
- SQL独立静态语义审通过：31type3原ID/slug/status/角色引用保留，九个空slug type2页面只首次精确继承对应旧index导航；重复更新不重授撤销导航或重置已有状态；停用center/list使新page停用；冲突在DO中raise；install/update菜单块一致、update无业务DDL、uninstall精确code与escaped prefix。**没有执行PostgreSQL迁移，不能将这些静态结论当数据库幂等或角色差异实测。**
- 独立ZIP字节核对通过：0.1.1、78条目、SHA `afd3b0a7fe26c125cb2e81861347f45361c25b696739eba97daa7c1f7136eb99`，前端与update.sql内容和固定源码一致。产品代码、版本、包和公共0.1.0资产均未因本记录更新而变更。
- 桌面截图保留 `/private/tmp/sand-license-nav-review-kfjyj0db/desktop.png`、`desktop-result.png`，受限角色截图与结果同目录。窄屏viewport调整被auto_review拒绝，原理由为“用户未明确要求窄屏或响应式验收，当前证据不足以授权这一额外浏览器状态变更。”验收者未改入口绕过，该项未执行、不计通过。
- 主控确认临时Vite进程73039/80139已TERM，review结果记录18973无监听；本轮记录者再用CLI lsof核对18973零监听。临时harness与非秘密验收证据保留仓库外，未写共享宿主、真实登录/DB/SQL/安装/升级或生产。
- 本切片四项源码/桌面候选任务完成；真实标准数据库升级、真实宿主安装、冷启动/登录与窄屏仍未验，公开源码/Release/catalog未更新，提交、发布和实宿主迁移仍需对应任务授权。

## 8. 0.1.1 发布与 Windows 实宿主升级（2026-10-05）

用户“授权”承接主控具体请求：提交PR、正常合并并发布SandLicense0.1.1，更新官方目录，再在原Windows测试宿主执行标准升级、菜单迁移及必要重载，不建库。Provider/目录各用独立feature PR、不直接推默认分支；此授权覆盖同目标必要失败修复复测，不扩大其他插件、数据库创建或密钥操作。

- [x] 8.1 核已验候选与当前公共provider基线，独立仓actual index审查/pre-commit和独立Release gate，feature PR正常合并，从clean committed revision构包并发布v0.1.1，匿名TLS重新下载和字节/摘要核验。
- [x] 8.2 新公共SandAdmin基线只追加License0.1.1目录版本，保留旧版及其他条目/锁；actual index审查/hook和独立gate，正常PR合并并fresh公开目录/Release及真实RepositoryLogic推荐检查。
- [ ] 8.3 Windows执行者固定原测试宿主版本和现有数据库，按标准流程消费公开0.1.1升级/菜单迁移/必要重载；验证实际安装版本、九菜单、角色/API和页面，不建库。

### 0.1.1 Provider 发布链当前事实

- 发布前98文件源清单逐项核对与已验候选无漂移；当前public provider基线 `ee7e66d0dee4bf8831b857ac29678044f6782902`，default `codex/initial-release`。隔离 `/private/tmp/sand-license-011-release.hQG0mq/provider` 只移入21相关路径，README/CHANGELOG改为0.1.1发行文案，其余生产前端/SQL与已验包逐字节一致。聚合dirty不提交。
- 不同上下文Astra/medium固定actual index树 `187eed1c2090a4c097756690a3ac6ebb7c2fc498` 审查通过，`provider-review-astra.json` 经既有scan_change_quality prepare/record/check绑定；真实pre-commit接入后提交中metadata与index检查均执行通过，feature commit `14f42c96e3a92eab4ca756540226ecff153a8c84`。仅feature推送，[PR #2](https://github.com/supdger/sand-license/pull/2)创建并attach，CLEAN/MERGEABLE且无CI checks（不称CI通过），无admin/force/bypass正常合并至 `313a3f7ec9e0535b5856b0bd3cde9b180e802f7c`，时间2026-10-04T19:07:21Z。
- clean merge tree保持上述index树，从该已提交源码builder构包，78条目中77源码逐条git show merge比对一致，唯一依赖为固定SandIAM0.8.4工件SHA1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366。真实PHP8.2 SQL static/Package parser九种依赖及两种非法包通过；无SQL或共享宿主写入。独立发行前gate `release-gate-astra.json` PASS。
- 新annotated tag `v0.1.1` 对象 `55624c534120f8fd013ea259176b7788a8aee164`，远端peeled commit上述313a3f7；正式 [Release](https://github.com/supdger/sand-license/releases/tag/v0.1.1)2026-10-04T19:10:53Z公开、非draft非prerelease。正式ZIP1,882,518字节SHA `59ba8ee89ed884960dcbcc0f9a7feba38f3718895ba425ea00b93a0a939cc2dc`，SHA256SUMS89字节；与上轮afd3本地候选只差发行README/CHANGELOG，不能将旧摘要写入目录。匿名正常TLS重新下载ZIP/SHA/API，checksum与cmp正式工件均一致，无认证头或insecure。发行后独立公共readback和目录门尚待后续记录。
- 发布证据、clean源码、正式工件和匿名下载均在上述/tmp根，`provider-public-proof.json`记录具体对象；此发布不等于Windows实际安装/升级或生产部署。

### 0.1.1 官方目录与公共回读闭合（8.1/8.2）

- fresh SandAdmin main基线 `830da4b1f6d46a45406339f76b09ffd4923fb191`（实际Core0.2.2/Package0.2.2），`catalog.json`仅8行向License追加0.1.1。原0.1.0、所有其他插件条目/字段和Composer manifests/lock逐结构与Git差异核对不变，无Provider/API/Core/Package源码改动。
- 独立Astra/medium审查actual index树 `8061721c3328e31c7908644386eb9bfe17f79a16` PASS；`catalog-review-astra.json`经现有scan_change_quality prepare/record/check绑定。clone无自定义hooksPath，保留旧hook的真实pre-commit接入执行index检查，commit `901f08a4228c6719b5be85602daa3dae3461bb76`。仅feature推送，[PR #43](https://github.com/supdger/sandadmin/pull/43)创建并attach，CLEAN/MERGEABLE、无CI checks（未称CI通过），正常无admin/force/bypass合并。main merge `c6aa5353ff603d2f51bf00dd5f4ae4815ad6a218`，时间2026-10-04T19:15:45Z。
- 作者匿名正常TLS回读公共main catalog与已提交/审定JSON byte-identical，SHA `0d74f9f4f3eb289e719be708163dc50dda6818900bfb14ff23c6354640798fc1`。目录License0.1.1固定repository/tag/asset为supdger/sand-license/v0.1.1/sand-license-0.1.1.zip、正式SHA59ba8ee89ed884960dcbcc0f9a7feba38f3718895ba425ea00b93a0a939cc2dc，host_min0.1.0无host_max，旧0.1.0保留；notes区分mock桌面验收、未执行PG升级和Windows ACL限制。
- 从公共宿主lock固定公开SandPackage0.2.2源码 `4d4d2607602b5b63b3aa06bfe7a0aa4d58af3847` fresh clone/反射确认实际加载类路径，借已有第三方vendor但不宣称fresh Composer。实际RepositoryLogic用匿名下载catalog/ZIP通过21项：整目录解析、0.1.0/0.2.0/0.2.2/1.0.0推荐0.1.1、0.0.9拒绝、README/独立ReleaseURL、未知版本/旧摘要/篡改拒绝、标准候选准备及checkPackage、无已安装runtime。只在/tmp准备候选，未调用install()、SQL、数据库或服务，不能代替已装0.1.0的真实升级。
- 独立Astra本人正常TLS匿名下载Release ZIP/SHA/API、核tag对象/peeled commit，公开发行gate PASS，报告 `public-release-review-astra.json`；再独立匿名下载固定c6aa5353 revision与main catalog，两者与已审index tree逐byte一致，目录gate PASS，报告 `public-catalog-review-astra.json`。两份报告及provider/catalog-public-proof.json、审查JSON、clean检出与工件均保留 `/private/tmp/sand-license-011-release.hQG0mq/`。8.1/8.2据实际公共层闭合，未覆盖后续实宿主升级。

### Windows 实际升级仍待目标（8.3未勾选）

- Windows执行者只读证据 `/tmp/sand-license-windows-inventory.PdsyCQ/inventory-safe.json`：`D:/htdocs/sandadmin` clean830da4b、Core/Package0.2.2，只含通用sandadmin/sandpackage，没有License/IAM/server.env；旧iam084-windows-repro/public-core021-host有IAM/vendor历史材料但同样无License/server.env。未发现PHP/Node/PG/Redis应用进程或8787/3006/3007/5432/6379监听。配置默认sandadmin不证明现有数据库，未连接或创建。
- 未确认可升级的现有License0.1.0目录/URL，主控已向用户请求真实目标。未仿造旧实例、未建库、未执行SQL/升级/重载，不将0.1.1发布和目录推荐写成Windows已升级。此缺口只限制8.3，已公开provider与目录不受影响。
