# SandIAM

SandIAM 是面向 SandAdmin 0.1.x 的 PostgreSQL 身份与访问管理插件。它可以独立安装，
不依赖 SandAI、SandWorkflow 或特定业务插件，为接入应用提供：

- 客户主体、应用、环境和分级管理委派；
- 身份、用户组、角色、资源、策略与数据范围；
- 注册、登录、恢复、会话、MFA、Passkey 与应用用户自助门户；
- 机器身份、凭证轮换、服务授权、撤销和调用审计；
- OAuth 2.0 / OpenID Connect、联合身份、目录同步、SCIM、CAS、Kerberos/RADIUS；
- 接口登记、路由绑定、SDK、中间件、Webhook、重试、审计与安全告警。

SandIAM 只提供通用身份、授权和审计能力。业务资源、业务状态和业务规则仍由接入应用定义。

## 版本与兼容性

当前版本为 `0.7.6` 预发布候选，提供管理端首次接入、上下文选择和错误恢复改进。`info.ini` 的宿主范围声明为 `0.1.x`；本次安装升级验收以 SandPackage `0.1.9` 为目标，具体结果见该版本 Release 说明，未验收版本不视为已支持。此候选未完成完整产品正式发行门槛，不作为生产可用声明。

公开下载入口为 [GitHub Releases](https://github.com/supdger/sand-iam/releases)。本次包名为 `sand-iam-0.7.6.zip`，对应 `v0.7.6-preview`；下载后按同一 Release 的 `SHA256SUMS` 和构建清单核对，不因版本号相同就视为同一包。按 Wiki 的[包校验](https://github.com/supdger/sand-iam/wiki/Release-package-verification)核对来源、摘要与所需签名；没有所需签名材料时，不作为正式发行包使用。

本版本仅接受具有已发布 `0.7.3` 完整 43 条迁移账本的 `0.7.3` / `0.7.5` 安装升级；升级脚本在 PostgreSQL 只读事务中核对结构和账本，不执行业务结构或数据修改，异常时拒绝继续。插件管理器更新管理端源码后，宿主仍需按其构建与部署流程激活前端。

第一次使用从[登记公司与应用](https://github.com/supdger/sand-iam/wiki/First-use)开始，再按目标开通员工账号或系统调用。后台新增用户身份不等于已经设置登录账号与密码；员工须接受邀请或按应用开放的注册方式开通账号。管理员完成配置后，由接入应用验证真实登录、允许访问和拒绝访问；未接入业务系统时可以完成后台配置，不能据此认定业务权限已经生效。

## 安装与升级

1. 准备一个符合 `info.ini` 要求的 SandAdmin 0.1.x PostgreSQL 宿主，并先完成备份。
2. 使用 SandPackage 从完整 SandIAM ZIP 安装，不要只复制 `plugin/sand-iam/` 子目录。
3. 注入运行配置后，在宿主加载插件前执行
   `php plugin/sand-iam/bin/check-runtime-configuration.php --profile=release`；空配置必须关闭失败，
   预检通过只证明配置形状和开关依赖，不证明服务已经可用。
4. 按管理端提示执行安装或升级，并在完成后重新登录。
5. 按[第一次使用](docs/user-guide/sand-iam-first-connection.md)创建客户主体、应用和环境。
6. 按[管理员操作手册](docs/user-guide/sand-iam-operator-guide.md)完成允许、拒绝、撤销和审计检查。

完整步骤见[安装与升级](docs/user-guide/installation-and-upgrade.md)，恢复演练见[备份与恢复](docs/user-guide/backup-and-restore.md)。
下载候选包后，先按[发布包校验](docs/user-guide/release-package-verification.md)核对本次 unsigned preview 的 `SHA256SUMS`、manifest 和 ZIP。没有正式签名的预发布包不作为完整正式发行使用。

已发布迁移文件不可修改。本次只支持具有已发布 0.7.3 完整 43 条账本的 0.7.3 / 0.7.5 升级到 0.7.6；生命周期仅在 PostgreSQL 只读事务内核对结构与账本。安装和卸载会改动数据库，管理器升级也会更新文件和自身登记，均应先完成数据库与文件备份。管理端源码更新后仍须由宿主构建、部署后激活。

## 接入应用

- [Webman 业务应用示例](examples/webman-business-app/README.md)：应用、资源、动作、接口、路由和数据范围接入。
- [机器调用服务示例](examples/machine-service-client/README.md)：机器身份、服务授权、凭证和受众校验。
- [PHP SDK](sdk/php/README.md)、[TypeScript SDK](sdk/typescript/README.md) 和 [Dart SDK](sdk/dart/README.md)：三种客户端 SDK。
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
- `LICENSE`、`NOTICE`：SandIAM 的 Apache-2.0 许可证正文和版权告知；
- `THIRD_PARTY_NOTICES.md`：第三方许可、分发形态和原许可证位置索引；
- `migrations/`、`lifecycle/` 和根生命周期 SQL：PostgreSQL 安装、升级与卸载载荷；
- `recovery/`：保留的 0.7.0 历史恢复证据，不随当前正常包分发；当前正常升级不 opt-in 该描述器。

## 获取帮助

先查阅[排障指南](docs/user-guide/troubleshooting.md)。
报告问题时请提供 SandIAM 和 SandAdmin 版本、操作时间、错误码、请求 ID 与脱敏日志；不要提交凭证、令牌、个人数据或数据库备份。

参与开发请阅读[贡献说明](CONTRIBUTING.md)，安全问题请按[漏洞报告指南](SECURITY.md)私下报告。
SandIAM 以 [Apache-2.0](LICENSE) 发布；版权告知见 [NOTICE](NOTICE)。
