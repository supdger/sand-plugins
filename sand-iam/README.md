# SandIAM

SandIAM 是面向 SandAdmin 6.x 的 PostgreSQL 身份与访问管理插件。它可以独立安装，
不依赖 SandAI、SandWorkflow 或特定业务插件，为接入应用提供：

- 客户主体、应用、环境和分级管理委派；
- 身份、用户组、角色、资源、策略与数据范围；
- 注册、登录、恢复、会话、MFA、Passkey 与应用用户自助门户；
- 机器身份、凭证轮换、服务授权、撤销和调用审计；
- OAuth 2.0 / OpenID Connect、联合身份、目录同步、SCIM、CAS、Kerberos/RADIUS；
- 接口登记、路由绑定、SDK、中间件、Webhook、重试、审计与安全告警。

SandIAM 只提供通用身份、授权和审计能力。业务资源、业务状态和业务规则仍由接入应用定义。

## 版本与兼容性

当前源码候选版本为 `0.7.0`，支持 SandAdmin `6.x`，数据库仅支持 PostgreSQL。
管理 OpenAPI 中的 `0.13.0-candidate` 是接口契约版本，不等于插件发行版本。
当前可证实的迁移与发布材料变化见[变更日志](CHANGELOG.md)。

本源码候选尚未正式发布。不要把源码构建、静态检查或候选包生成视为生产可用证明；
部署前应在隔离环境完成安装、升级、协议互操作、权限安全、备份恢复和业务闭环验证。

## 安装与升级

1. 准备一个符合 `info.ini` 要求的 SandAdmin 6.x PostgreSQL 宿主，并先完成备份。
2. 使用 SandPackage 从完整 SandIAM ZIP 安装，不要只复制 `plugin/sand-iam/` 子目录。
3. 按管理端提示执行安装或升级，并在完成后重新登录。
4. 按[第一次使用](docs/user-guide/sand-iam-first-connection.md)创建客户主体、应用和环境。
5. 按[管理员操作手册](docs/user-guide/sand-iam-operator-guide.md)完成允许、拒绝、撤销和审计检查。

完整步骤见[安装与升级](docs/user-guide/installation-and-upgrade.md)，恢复演练见[备份与恢复](docs/user-guide/backup-and-restore.md)。
下载候选包后，先按[发布包校验](docs/user-guide/release-package-verification.md)核对包外 Ed25519 签名、
manifest 和 ZIP 内逐文件摘要，再执行安装。

已发布迁移文件不可修改。升级修复必须通过新迁移追加；不要手工改写迁移账本或跳过失败恢复检查。
安装、升级和卸载都可能改变数据库，应先备份，并只在获得环境负责人授权后执行。

## 接入应用

- [Webman 业务应用示例](examples/webman-business-app/README.md)：应用、资源、动作、接口、路由和数据范围接入。
- [机器调用服务示例](examples/machine-service-client/README.md)：机器身份、服务授权、凭证和受众校验。
- `sdk/php/`、`sdk/typescript/`、`sdk/dart/`：三种客户端 SDK。
- `portal/`：独立应用用户自助门户源码与构建产物。

应用管理员可继续阅读[应用接入](docs/user-guide/application-integration.md)，最终用户可阅读
[应用用户操作](docs/user-guide/application-user-guide.md)，部署负责人应阅读[配置参考](docs/user-guide/configuration-reference.md)
和[安全加固](docs/user-guide/security-hardening.md)。

接入时始终使用稳定的组织代码、应用代码、资源代码和语义动作。路由只用于定位接口，
不能把 URL、页面名称或临时业务编号当作长期权限名称。凭证明文只显示一次，必须由运行环境或密钥管理系统注入。

## 安全默认值

- 生产地址必须使用 TLS；密钥、恢复码和凭证明文不得写入日志、截图或版本库。
- 凭证、令牌、会话和 Webhook 密钥应定期轮换；泄露后立即撤销。
- 所有授权都应验证允许、拒绝、错误受众、过期、重放和撤权后访问。
- 验收专用接口默认关闭，不应在公开环境启用。
- 运维状态清理 worker 默认关闭；启用前必须完成迁移 038、备份恢复演练并取得数据库删除授权。
  幂等操作保留期不得短于 30 天，pending 记录和审计日志不得清理；过期认证限流窗口保留 1–168 小时。
- 卸载前应确认业务依赖和备份；审计归档的保留策略由部署方负责。

## 包目录

- `plugin/sand-iam/`：后端插件与随包运行时依赖；
- `sandadmin-artd/src/views/plugin/sand-iam/`：管理端页面源码；
- `portal/`：应用用户自助门户；
- `sdk/`：PHP、TypeScript 和 Dart SDK；
- `docs/user-guide/`：公开中文操作文档；
- `examples/`：不含秘密的接入示例；
- `SBOM.cdx.json`：由锁文件和精确版本许可证策略生成的软件物料清单；
- `THIRD_PARTY_NOTICES.md`：第三方许可、分发形态和原许可证位置索引；
- `migrations/`、`lifecycle/` 和根生命周期 SQL：PostgreSQL 安装、升级与卸载载荷；
- `recovery/`：供 SandPackage 验证的失败升级恢复描述。

## 获取帮助

先查阅[排障指南](docs/user-guide/troubleshooting.md)。
报告问题时请提供 SandIAM 和 SandAdmin 版本、操作时间、错误码、请求 ID 与脱敏日志；不要提交凭证、令牌、个人数据或数据库备份。

参与开发请阅读[贡献说明](CONTRIBUTING.md)，安全问题请按[漏洞报告指南](SECURITY.md)私下报告。
项目许可证将在正式发布前写入 `LICENSE`。
