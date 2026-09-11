# SandIAM 终极产品目标

> 状态：2026-08-21 冻结为终极产品方向。本文描述最终产品，不代表当前实现已经完成。2026-09-08 恢复并向前冻结的 48 个验收原子、当前 `28/48（58.3%）` 计数和证据边界见[终极验收原子账本](../development/sand-iam-terminal-acceptance-ledger.md)；新增分子来自 R08 需求规格冻结，以及 P14、P19 独立最终复核 ACCEPT，不代表真实外部执行通过。

## 1. 产品定义

SandIAM 是依托 SandAdmin 安装、治理和发布的完整身份与访问管理插件。SandAdmin 提供宿主和平台管理入口；SandIAM 提供独立于宿主后台账号的应用用户认证、机器身份、联合登录、授权决策、服务访问控制和安全审计。

最终使用者不需要理解 SandAdmin 菜单、数据库表或 Casbin 文本才能完成应用接入。一个普通开发者应能通过中文管理台、标准协议、API、SDK 和中间件完成：

1. 创建客户主体和接入应用；
2. 配置注册、登录、密码策略和身份源；
3. 定义用户类型、角色、资源、动作和数据范围；
4. 接入应用登录页或标准 OIDC 客户端；
5. 为业务接口声明权限并获得稳定允许/拒绝结果；
6. 查询登录、授权、凭证、会话和管理操作审计。

“比直接使用 Casdoor 更顺手”不是设计自评。三条开发者旅程必须按[开发者旅程验收](../development/sand-iam-developer-journey-acceptance.md)在同等环境计时、记录操作数和失败，双方都完成后才能作比较。

## 2. 两个运行平面

```text
SandAdmin 管理平面
├── 平台管理员：插件、客户主体、全局安全、服务目录
└── 应用管理员：本应用身份源、用户、角色、策略、客户端和审计

SandIAM 运行平面
├── 应用用户：注册、登录、恢复、MFA、会话、自助资料
├── 标准协议：OAuth/OIDC、SAML、LDAP、SCIM
├── 业务接入：SDK、中间件、远程授权、数据范围
└── 机器访问：工作负载身份、服务授权、短期上下文、撤销与审计
```

应用用户、会话和业务授权不复用 `sa_system_user`，也不依赖 SandAdmin 菜单权限。插件物理运行在宿主中，不等于业务身份属于宿主后台。

## 3. 终极能力范围

| 能力域 | 最终必须具备 |
| --- | --- |
| 组织与应用 | 客户主体、应用、环境、委派管理员、应用隔离、应用安全配置 |
| 应用用户 | 注册、邀请、激活、禁用、软删除、资料、身份绑定、用户类型、组与角色 |
| 本地认证 | 用户名/邮箱/手机号登录，密码策略、安全哈希、失败锁定、验证码、密码重置 |
| 会话 | 短期访问令牌、刷新令牌轮换、浏览器会话、设备/会话列表、单会话和全局登出 |
| MFA/无密码 | TOTP、恢复码、WebAuthn/Passkey，因子注册、挑战、验证、撤销和恢复 |
| 联合身份 | 外部 OAuth/OIDC、SAML，明确的属性映射、账号绑定/解绑和冲突处理 |
| 企业目录 | LDAP 查询/同步、SCIM 2.0 provisioning、停用传播、同步游标与失败重试 |
| OAuth/OIDC 服务端 | 授权码 + PKCE、客户端凭证、发现文档、JWKS、userinfo、撤销、刷新、登出 |
| 授权 | RBAC、ABAC、资源动作、条件、数据范围、拒绝优先、策略版本、模拟和解释 |
| API/路由治理 | 语义动作目录、API 登记、路由绑定、版本/风险等级、SDK/中间件和 OpenAPI |
| 机器身份 | 工作负载客户端、凭证轮换、服务授权、audience/action、网络/额度/数据等级限制 |
| 安全事件 | 登录、注册、恢复、MFA、协议、凭证、授权、管理操作的统一审计与告警出口 |
| 开发者能力 | PHP/Webman SDK、前端 SDK、标准 OIDC 接入示例、稳定错误码、中文文档 |
| 用户体验 | 平台管理员、应用管理员、终端用户三套清晰入口；只展示有用字段和可恢复错误 |

Casdoor 的组织、应用、用户、Provider、会话、Token、MFA、角色、权限、资源、Webhook、Syncer、标准协议、API/SDK 和用户自助能力是完整度基线；Casdoor 的支付、订阅、AI Agent、MCP 和其他商业扩展不自动纳入 IAM 必选范围。

对照官方仓库和文档后识别出的应用品牌/登录编排、消息 Provider、邀请/组/导入、通用 Syncer、CAS/Kerberos/RADIUS、移动 SDK/CLI、Webhook 事件生产者与审计运营缺口，必须按[Casdoor 基线缺口](../development/sand-iam-casdoor-baseline-gap.md)进入正式任务，不能在 T08 中以“后续优化”跳过。

## 4. Sand 原生增强

SandIAM 不用 HTTP URL 直接充当长期权限键。它在通用 IAM 之上提供：

- `service → service_action` 语义服务目录；
- `application → environment → workload_client → service_grant` 机器访问链；
- `subject + resource + action + condition + scope` 统一授权决策；
- API 路由到语义动作的可升级绑定；
- SandAI、SandWorkflow 和业务应用共同消费的身份上下文与审计关联。

## 5. 明确不属于 SandIAM

SandIAM 不保存业务订单、文档、模型任务、流程实例、商品、会员权益或其他业务实体；其他域只能引用 SandIAM 的应用、身份和授权结果，不能把业务状态复制进 `sand_iam_*`。插件与业务应用的权威边界见[独立插件与服务接入边界](../architecture/sand-iam-access-boundary.md)。

- **卡密和商业许可归独立 SandLicense。** 卡密生成、批次、分发、兑换、套餐、订阅、授权期限、设备激活和商业许可状态不由 SandIAM 实现。SandLicense 可以引用 SandIAM 的 `organization`、`application` 或操作者身份，但必须拥有自己的领域表、权限、生命周期和审计；SandIAM 只负责“谁能操作 SandLicense”，不负责“许可是否有效”。
- 订单、支付和商品权益仍归各自业务域，不得借“权限”名义进入 SandIAM。

## 6. 安全默认

- OAuth 客户端默认使用授权码 + PKCE S256；不提供隐式授权和资源所有者密码模式。
- 公共客户端刷新令牌必须轮换或发送方约束；访问令牌必须限制 audience 与最小 scope。
- 密码使用部署环境支持的强自适应哈希；令牌、恢复码和客户端秘密只保存不可逆摘要。
- WebAuthn challenge、OAuth code、验证码、重置 token 均一次性、短时、绑定应用和会话。
- 重定向 URI 精确匹配；回调校验 `state`、OIDC `nonce`、issuer、audience 和签名。
- 所有敏感动作都必须限流、审计，并支持撤销；日志不得包含密码、完整令牌、验证码或私钥。

## 7. 完成定义

只有以下事实同时成立，才可称 SandIAM 终极目标完成：

1. 每项能力（含 Casdoor 基线缺口 T09–T12）有模块契约、实现、自动测试和隔离 PostgreSQL 生命周期证据；
2. 标准协议通过标准客户端与协议测试，不以自写单元测试替代；
3. SandAdmin 管理平面和应用用户运行平面均完成真实浏览器流程；
4. 至少一个 SandAI 服务调用和一个非 AI 业务应用真实接入；
5. 安装、升级、卸载、备份恢复、密钥轮换、并发、限流和安全基线通过；
6. 源码实现、正式 FLOW、本地业务闭环、可部署、已部署和线上验证分别留证。
7. 三条开发者旅程在 SandIAM 和 Casdoor 的同等环境留下计时、操作数、失败和清理证据；没有对照数据时不得宣称更顺手。

上述完成定义按[终极验收原子账本](../development/sand-iam-terminal-acceptance-ledger.md)的 R01–R09、P01–P20、F01–F07、L01–L04、D01–D08 逐项留证。七条业务链、升级票据和恢复子检查只作映射与执行追踪，不改变 `9 + 20 + 7 + 4 + 8 = 48` 的分母。

## 8. 一手基线

- Casdoor 官方能力与接入：[How to connect](https://casdoor.org/docs/category/how-to-connect-to-casdoor/)、[SDK](https://casdoor.org/docs/how-to-connect/sdk/)、[官方仓库](https://github.com/casdoor/casdoor)
- OAuth 安全基线：[RFC 9700](https://www.rfc-editor.org/info/rfc9700/)
- OpenID Connect：[Core/Discovery Errata 2](https://openid.net/second-errata-set-for-openid-connect-specifications-approved/)、[RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)
- WebAuthn：[W3C Web Authentication Level 3](https://www.w3.org/TR/webauthn-3/)
