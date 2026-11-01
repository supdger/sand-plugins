# SandIAM

SandIAM 是 SandAdmin 的身份与访问管理插件。管理员用它管理应用用户、角色、权限、服务调用凭证和审计；接入开发者将登录和授权能力接入自己的业务系统。

SandIAM 当前使用 [PHP-Casbin](https://github.com/php-casbin/php-casbin) **4.5.0** 作为应用用户策略内核，判定角色关系、资源动作、条件与允许/拒绝；版本随发行依赖管理，并非永久固定。

SandIAM 负责身份、组织与应用管理、策略配置和发布快照、范围校验及审计。接入业务负责提供可信属性、执行数据过滤，并在业务副作用前完成权限与状态复核。机器服务调用的凭证、服务授权、次数配额和执行前重验由 SandIAM 的服务调用链实现，不能把全部授权都称为 Casbin 判定。

Casdoor 也集成 Casbin，但它与 SandIAM 是不同的身份产品；使用 Casbin 不等于通过 Casdoor 提供能力。具体执行链与职责分工见下方完整对比文档。

## 与 Casdoor 的适用范围

SandIAM 面向 SandAdmin 与业务服务接入；Casdoor 面向通用身份提供与单点登录。下表按 SandIAM 当前源码和 Casdoor 官方文档比较能力范围，具体版本、启用条件与验收结果见[更新日志](CHANGELOG.md)及各自发布说明。

| 比较项 | SandIAM | Casdoor |
| --- | --- | --- |
| 架构与部署 | SandAdmin 插件，PHP/Webman 后端与 Vue 管理端；仅 PostgreSQL，通过 SandPackage 安装完整 ZIP | 独立 Go 服务与 React 管理端；支持多种数据库，可用二进制、Docker、Helm 部署，见[官方仓库](https://github.com/casdoor/casdoor) |
| 用户认证 | 注册、登录、恢复、会话管理、MFA、Passkey 与独立应用用户自助门户 | WebAuthn 登录及 TOTP、短信、邮件等 MFA，见[认证说明](https://casdoor.ai/docs/user/multi-factor-authentication/) |
| 协议与身份源 | OAuth/OIDC、联合身份、目录同步、SCIM、CAS、Kerberos/RADIUS；SAML 已核实为 SP 接入，RADIUS Access 为 PAP 密码路径 | 向应用提供 OAuth/OIDC、SAML IdP；也可接入外部 OAuth/SAML 身份源、LDAP，提供简单 LDAP 服务端及 SCIM 用户/组 API，见[OIDC 接入](https://casdoor.ai/docs/how-to-connect/oidc-client/)及[SAML 身份源](https://casdoor.ai/docs/category/saml-1/) |
| 组织与隔离 | 客户主体 → 应用 → 环境，身份、授权与接口操作按所属范围校验 | 以组织管理用户、应用和身份源；实际租户隔离要求需按项目核对，见[组织说明](https://casdoor.ai/docs/organization/overview/) |
| 用户生命周期 | 邀请、导入导出、目录同步、账号停用及会话撤销；用户可在独立门户管理自己的账号 | 用户管理、禁用、软删除、身份源同步及 SCIM 配置；导入导出与回收流程需按项目核对，见[用户说明](https://casdoor.ai/docs/user/overview/) |
| 授权与数据范围 | Casbin 策略判定、角色与业务资源动作，以及供业务执行的数据范围结果 | Casbin 模型、策略、角色及权限 API；业务数据过滤仍需接入应用实现，见[权限说明](https://casdoor.ai/docs/permission/overview/) |
| API 与路由治理 | 动作、接口目录、路由绑定、清单预检与策略模拟；OpenAPI 3.0/3.1 导入后映射已有业务资源与动作 | 可通过权限 API 对接业务接口；同等的接口目录、路由预检与导入流程需按项目核对 |
| 机器身份与服务授权 | 调用身份、服务目录与授权、凭证签发/轮换/撤销、调用次数配额、执行前实时重验及调用审计 | OAuth `client_credentials` 支持无用户的服务间认证；服务授权与凭证治理流程需按项目核对，见[OAuth 说明](https://casdoor.ai/docs/how-to-connect/oauth/) |
| 管理委派与运营 | 组织/应用范围管理委派、初始化配置预检、执行与回滚 | 全局管理员与组织管理员；细粒度委派、初始化预检和回滚流程需按项目核对 |
| 事件通知 | Webhook 订阅、投递记录、重试与退避 | 登录、用户及资源变更等 Webhook 事件；投递保障要求需按项目核对，见[Webhook 说明](https://casdoor.ai/docs/webhooks/overview/) |
| 安全与审计 | 重复失败阈值告警、认证/授权/调用审计及归档保留；不等同风险评分或 SIEM | 有操作记录及日志接入；告警、归档保留和审计合规要求需按项目核对，见[记录源码](https://github.com/casdoor/casdoor/blob/master/object/record.go) |
| SDK 与业务接入 | PHP、TypeScript、Dart SDK，业务与机器调用示例；Apache-2.0 | 多语言 SDK，包括 PHP；Apache-2.0，见[官方仓库](https://github.com/casdoor/casdoor) |

接入应用须在产生业务副作用前执行权限检查，并将数据范围转为业务查询过滤；登录成功或管理台保存配置不代表业务权限已经生效。SandIAM 的协议、外部身份源和 worker 按配置启用，worker 默认关闭；历史版本的验收不代表当前版本在所有宿主上已通过。

操作与接入见[管理员指南](https://github.com/supdger/sand-iam/wiki/Administrator-guide)、[业务接入](https://github.com/supdger/sand-iam/wiki/Application-integration)、[机器调用](https://github.com/supdger/sand-iam/wiki/Machine-service-integration)和[OpenAPI 导入](https://github.com/supdger/sand-iam/wiki/OpenAPI-import)。

完整能力、协议方向、原需求七个领域、Casbin 执行链及分版本证据见[SandIAM 与 Casdoor 完整对比](https://github.com/supdger/sand-iam/wiki/Comparison-with-Casdoor)。

## 版本更新

[Wiki 版本更新](https://github.com/supdger/sand-iam/wiki/Changelog)直接说明近期功能变化、修复和升级影响；[完整更新日志](CHANGELOG.md)保留逐版记录。下载与发布状态以[最新正式版](https://github.com/supdger/sand-iam/releases/latest)为准。

## 源码与安装包

直接使用请下载 Release 附件中的完整 `sand-iam-<版本>.zip`，或在 SandAdmin 的“插件仓库”选择 SandIAM。GitHub 的 `Source code (zip)` 是源码压缩包，不是插件安装包。

本仓包含插件后端、管理端源码和安装生命周期。开发与构建见 [开发指南](https://github.com/supdger/sand-iam/wiki/Development)。

## 安装与第一次使用

需要已有的 SandAdmin `>=0.1.0` PostgreSQL 宿主、SandPackage，以及有权安装插件和管理应用的账号。宿主兼容范围、运行环境和包校验要求见[安装与升级](https://github.com/supdger/sand-iam/wiki/Installation-and-upgrade)；安装或升级前备份已有数据。

1. 在 SandAdmin“插件仓库”选择 SandIAM，或上传完整安装 ZIP，核对安装计划后安装。
2. 按安装指南完成配置，并由宿主管理员构建、激活包内管理端源码。安装器报告成功后，重新登录并打开 **SandIAM → 总览**。
3. 在“这次要完成什么？”中选择目标，例如“让用户登录应用”。复用已有应用，按页面引导完成登录配置与账号开通，再到该应用的登录入口验证。

第一次登录的可确认结果是：正确密码能登录，错误密码被拒绝，退出后旧会话不能继续访问。缺少菜单或办理权限时联系宿主管理员；详细步骤见[第一次使用](https://github.com/supdger/sand-iam/wiki/First-use)。

## 使用文档

- [Wiki 首页](https://github.com/supdger/sand-iam/wiki)：按管理员、应用用户和接入开发者的任务选择指南。
- [管理员操作](https://github.com/supdger/sand-iam/wiki/Administrator-guide)、[应用用户操作](https://github.com/supdger/sand-iam/wiki/Application-user-guide)：管理应用与使用自己的账号。
- [业务接入](https://github.com/supdger/sand-iam/wiki/Application-integration)：接入登录、授权与数据范围。
- [配置](https://github.com/supdger/sand-iam/wiki/Configuration-reference)、[备份恢复](https://github.com/supdger/sand-iam/wiki/Backup-and-restore)、[排障](https://github.com/supdger/sand-iam/wiki/Troubleshooting)：部署与维护。

## 许可与反馈

项目按 [Apache-2.0](LICENSE) 分发，版权与第三方许可见 [NOTICE](NOTICE) 和 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。贡献见 [CONTRIBUTING.md](CONTRIBUTING.md)，安全问题按 [SECURITY.md](SECURITY.md) 私下报告；问题与建议请提交到 [Issues](https://github.com/supdger/sand-iam/issues)。
