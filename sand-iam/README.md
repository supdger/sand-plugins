# SandIAM

SandIAM 是基于 SandAdmin 的 PostgreSQL 插件，为 Sand 平台及接入应用提供通用的身份、组织、
应用登记、服务授权、策略判定、数据范围与审计能力。

SandIAM 可单独安装和运行，只依赖 SandAdmin 宿主，不依赖 SandAI、SandWorkflow 或任何业务插件。
它单独即可管理身份、组织、应用、角色、策略、数据范围、机器身份、凭证与审计；SandAI 等服务
插件接入时，才消费 SandIAM 的授权和身份上下文。

它借鉴 Casdoor 的身份/组织/应用管理能力、Casbin 的策略判定模型，并补齐 SaiAdmin 3.x 数据范围
仅覆盖部分列表查询的不足。它不内置任何行业或接入应用的业务规则。

## 当前状态

> FLOW 当前口径：需求/架构/票据 **8/9**、模块实现 **20/20**、正式 FLOW 验收 **0/7**、本地业务闭环 **0/4**、可上线部署 **0/8**，合计 **28/48（58.3%）**。R08 已登记 T09–T12 的 12 个末端规格原子，静态门禁 **9/9** 且独立复核 ACCEPT；P14、P19 已独立最终复核 ACCEPT，均只按各自原子计分。这不代表相应页面、HTTP、外部系统或业务闭环已经通过。当前源码包完整性检查 **23/23**；生命周期生成器已声明 `001–037` 共 37 个修订号（38 个迁移文件，两个 `006` 属于不同迁移），生成 SQL 的目标为 86 张 `sand_iam_*` 表。现场恢复前数据库仍是 83 张表、`baseline_060`/`prefix_033_034` 状态，不能当作当前源码的安装、升级或恢复验收。产品语言、首次使用向导、入口和闭环硬门槛见[产品体验与闭环交付契约](docs/development/sand-iam-product-experience-contract.md)，逐项证据口径见[终极验收矩阵](docs/development/sand-iam-terminal-acceptance.md)。

`0.2.0` 将身份绑定改为以身份源实例为边界，修正旧版 `provider_code + subject` 全局唯一导致不同产品账号被错误互斥的问题。当前身份源作用域是显式双选择：应用私有实例，或组织持有后再显式挂载到应用；`subject` 唯一键固定为 `identity_provider_id + subject`，不允许按代码、手机号或上游主体隐式共享。PostgreSQL 18 全新安装、`0.1.x → 0.2.0` 隔离升级、重复升级、跨应用/同应用/错绑约束及卸载清理均已验证。该结论只覆盖身份源作用域迁移，不代表 SandIAM 终极产品、管理端或发布验收已经完成。终极范围见[产品目标](docs/product/sand-iam-terminal-product-goal.md)，当前差距见[终极验收矩阵](docs/development/sand-iam-terminal-acceptance.md)。

插件包当前候选发行版本是 `0.7.0`；管理 OpenAPI 目录中的 `0.12.0-candidate` 是独立的接口契约版本，不能互相推导，也不表示任何一方已经发布或部署。

`0.3.0` 已完成第一条独立应用用户认证闭环：应用级注册策略、邮箱/手机号验证、登录锁定与数据库限流、Argon2id 密码、可撤销会话、刷新令牌轮换和重放撤销、退出、密码重置及审计。隔离 PostgreSQL 已用真实 SandAdmin ORM 依赖跑通服务流程和重复生命周期，且未修改或启动验收宿主；真实宿主 HTTP、管理端和发布验收仍属于后续 IAM-T07/T08，当前不能称为已部署或可上线。

`0.4.0` 在源码包补齐 TOTP、一次性恢复码和 WebAuthn/Passkey 后端核心。TOTP secret 与短期 WebAuthn challenge 使用独立部署密钥加密，恢复码和挑战令牌仅保存 HMAC；隔离 PostgreSQL 的真实 ES256/CBOR/COSE 夹具已验证注册和无密码认证。真实浏览器、宿主 HTTP 和部署验收仍待 IAM-T07/T08。

`0.5.0` 已补齐 OAuth 2.0 / OpenID Connect Provider 后端核心：授权码 + PKCE、客户端凭证、服务端授权交互、RS256/JWKS、userinfo、刷新轮换、撤销和登出已通过真实 ORM/服务/controller 测试与隔离 PostgreSQL 生命周期。真实 SandAdmin HTTP、标准客户端互操作、浏览器会话和发布验收仍待 IAM-T07/T08，当前不能称为可上线协议服务。

`0.7.0` 候选迁移范围为 `001–037`：已发布的 021 按 0.6.0 原字节冻结，034 单独补用户组角色的三项权限且不自动授予既有角色，035 在核验 0.6.0/033 结构指纹后才收养迁移账本。035 认可组织级身份源的 `application_id` 可为空，同时严格锁定 `ck_sand_iam_identity_provider_scope`：应用级必须有应用、组织级必须没有应用；缺失或定义不符一律拒绝收养。036 增加受控验收夹具支持并以规范化自校验值登记；037 增加初始化草稿和不可变修订历史，三项新权限只登记、不自动授予角色。fresh install 载荷完整覆盖 `001–037`；唯一支持的 `0.6.0 → 0.7.0` 更新载荷仅为 `033–037`，不重放 001–032。候选包另以 root/plugin 相同的 `recovery/failed-upgrade.v2.json` 内联声明失败于数据库升级阶段时可由 SandPackage 宿主核验的 `prefix_033_034` 精确 profile；它绑定描述器排除后的规范化候选载荷和 `update.sql`，仅使用宿主 v2 的受限断言词汇，不执行恢复、SQL、PHP 或 shell。SandPackage 的通用升级分支与已安装状态收养是宿主前置条件，不属于 SandIAM 包内修复。当前 v24 review candidate 为 `candidate/dirty-not-release`；构建完成后，以候选工件清单记录 artifact、entries、哈希与同快照复现证据。源码包完整性、恢复描述器与非 PG/合同套件的结果以当前源码复验为准；已完成 17-file demo 静态同步。101/101 仅为使用 probe/test identity 的 `read-only profile/catalog compatibility probe PASS`，fingerprint 仅属于探针；正式 identity-bound Gate A 仍 NOT STARTED。尚未执行正式 Gate A、数据库/registry/runtime recovery、浏览器或业务闭环验收。

当前唯一的人类可读路由分组见[API 路由权威表](docs/development/sand-iam-api-route-registry.md)，三条“是否比直接使用 Casdoor 更顺手”的可计时标准见[开发者旅程验收](docs/development/sand-iam-developer-journey-acceptance.md)。两边尚未完成同等环境实测，当前不作优劣宣称。

当前版本尚未取得重新执行隔离生命周期、宿主同步、服务重载与受控夹具的授权；因此安装、升级、卸载、浏览器和业务闭环均不能提前判定通过。静态包与源码契约通过不替代这些运行证据。卡密、商业许可和设备激活继续归独立 SandLicense。

IAM-T05 已进入后端候选实现：接口目录、路由到语义动作的绑定、远程决策 API、Webman 中间件以及 PHP/TypeScript SDK 已落源码；路由只负责定位，长期策略仍使用业务资源与语义动作。`008` 已进入 lifecycle 并通过包安装，应用/组织隔离、路由冲突、停用关闭失败、OpenAPI 语义保留和审计已通过 PostgreSQL 集成；真实 SandAI/非 AI 业务授权闭环尚未验收，详见[接口治理与业务接入契约](docs/development/sand-iam-api-governance-v0.1.md)。

IAM-T06 已进入后端候选实现：应用级后台委派、应用用户资料/安全概况/改密自助接口、Webhook 配置与安全投递 worker、按管理范围查询和导出审计已落源码；`009–010` 已进入 lifecycle，委托与 Webhook PostgreSQL 集成通过。并发 worker、真实 HTTPS、宿主 HTTP、自动事件生产者和管理端/自助端页面尚未验收，详见[委派、自助服务、Webhook 与审计契约](docs/development/sand-iam-self-service-webhook-v0.1.md)。

IAM-T09 已进入后端候选实现：应用登录外观、登录/注册方式编排、公开脱敏体验接口，以及组织级 Email/SMS/Captcha/Notification 服务、应用挂载、模板代码、测试和版本化秘密加密已落源码；`011` 与跨组织挂载约束 `025` 已进入生命周期，服务选择、密文配置、投递/Captcha 和停用关闭失败已通过 PostgreSQL 集成，功能开关默认关闭。真实供应商、管理端/终端用户页面和浏览器验收均未完成，详见[应用登录体验与消息服务契约](docs/development/sand-iam-application-experience-message-provider-v0.1.md)。

IAM-T10 已进入五阶段后端候选实现：账号生命周期、应用内用户组、邀请、访客原 ID 升级、两步 CSV 导入/分权导出，以及通用 Syncer 均已落源码。`012–015` 与 Syncer 组织/应用约束 `026` 已进入根生命周期；账号停用/删除/恢复、用户组隔离与环检测、跨应用独立邀请、访客幂等、同步游标/冲突/缺失保护/停用熔断已通过 PostgreSQL 集成。真实 HTTP、定时调度、具体目录驱动和页面仍未完成，详见[账号生命周期、用户组与同步契约](docs/development/sand-iam-user-lifecycle-group-sync-v0.1.md)。

三角色真人可用性 UX-01 已完成权威源码收敛：“管理总览 + 客户与应用接入 + 应用用户与权限 + 审计与排错”成为可进入的任务路径，实体页面保留为隐藏稳定路由。名称选择支持远程搜索、编辑回显、所属层级和组织 → 应用 → 环境级联；基础授权不提交折叠的高级 JSON；安全操作明确影响与恢复方式，受限角色升级时按既有权限补任务路径入口。隔离类型检查、生产构建和契约检查已通过；源码已同步宿主且登录页控制台无错误，但宿主数据库因一次隔离目标误判导致 SandIAM 表被误卸载，当前待恢复。验证码登录和两个视口的真实页面夹具闭环未执行，因此不得写成真人验收通过或可上线部署。事故和恢复状态见[最新本地生命周期记录](../.codex/autopilot/executions/IAM-T08-local-lifecycle-20260823.md)。

## 目录

- `docs/product/`：SandIAM 产品需求；
- `docs/architecture/`：SandIAM 与 SandAI 的接入边界；
- `docs/development/`：开发入口、[任务看板](docs/development/sand-iam-task-board.md)、[产品体验与闭环交付契约](docs/development/sand-iam-product-experience-contract.md)、[API 路由权威表](docs/development/sand-iam-api-route-registry.md)、[开发者旅程验收](docs/development/sand-iam-developer-journey-acceptance.md)、[包完整性与发布来源门禁](docs/development/sand-iam-package-integrity.md)、[协作约定](docs/development/sand-iam-pg-collaboration.md)、契约冻结和验收记录；
- `docs/user-guide/`：[SandIAM 手把手使用说明](docs/user-guide/sand-iam-operator-guide.md)（权威普通用户手册）与[第一次使用 SandIAM](docs/user-guide/sand-iam-first-connection.md)；
- `plugin/sand-iam/`：SandAdmin 后端插件包；
- `sandadmin-artd/`：管理端插件页面。
- `examples/`：[Webman 非 AI 业务接入](examples/webman-business-app/README.md)与[SandAI 机器身份](examples/sandai-machine-client/README.md)的可运行 quickstart；示例不含秘密或宿主改动。

## 关键约束

- 仅 PostgreSQL；不引入 MySQL 的 `ENGINE=InnoDB`、`AUTO_INCREMENT`、`LAST_INSERT_ID()` 或 `sa_*` 表；
- 显示名 `SandIAM`，插件/路由 `sand-iam`，PHP `SandIam`，表与权限 `sand_iam_*`；
- SandIAM 提供能力，应用配置自身用户类型、资源和策略；
- 真实密钥只可写、轮换或撤销，绝不写入文档、日志或版本库。
