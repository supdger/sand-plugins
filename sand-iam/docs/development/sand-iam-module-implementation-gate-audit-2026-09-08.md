# SandIAM 模块实现关口归位审计（2026-09-08 历史快照）

> 本记录固定 2026-09-08 当日的模块实现归位结论，只重置 48 个 FLOW 原子中 `P01–P20` 的**模块实现**计分口径，不改变产品范围、原子名称、20 项分母，亦不改变 R/F/L/D、升级票据、实际恢复、candidate、release 或部署结论。它不是 2026-09-11 P1 候选的当前状态证明；P1 revision、候选包 SHA 与宿主验收必须以之后的独立证据为准。

## 1. 统一门槛

一个 P 原子通过，当且仅当当前权威 `sand-iam/` 源码已闭合该模块、所需 schema/migration 文件已同时存在于根与可安装插件载荷、至少一个测试验证公开契约或可观察行为，并有适用的静态或构建证据。迁移文件必须存在，但本审计不执行迁移、更不创建或连接数据库。

以下证据只属于后续关口，不能再阻断 P：真实供应商或目录、标准协议客户端、真实宿主 HTTP、浏览器、真实业务应用、worker/性能运行、备份恢复、宿主同步、发布和部署。它们仍按原账本留在 F/L/D。

源码文本检查只能作为 `behavior-test-gate: static-rule` 的辅助证据；不能单独使 P 通过。下表的“行为证据”均指真正运行源码、客户端或公开输出的测试，或当前可定位的隔离 ORM 执行记录。

## 2. 本轮无数据库命令

本轮只运行了下列已先审阅为不连接 PostgreSQL、不启动服务的命令；没有执行 migration、HTTP、浏览器、host 写入或服务启停：

- `php .../admin_application_grant_audit_behavior_non_pg_test.php`
- `php .../initialization_idempotency_behavior_non_pg_test.php`
- `php .../remote_directory_sync_driver_non_pg_test.php`
- `php .../message_provider_crypto_non_pg_test.php`
- `php .../webhook_crypto_transport_non_pg_test.php`
- `php .../sync_crypto_driver_non_pg_test.php`
- `php .../oauth_dynamic_registration_validation_non_pg_test.php`
- `php .../oidc_frontchannel_logout_non_pg_test.php`
- `php .../cas_protocol_validation_non_pg_test.php`
- `php .../kerberos_spnego_validation_non_pg_test.php`
- `php .../radius_packet_codec_non_pg_test.php`
- `php .../radius_accounting_non_pg_test.php`
- `php .../application_experience_message_provider_non_pg_contract_test.php`
- `php .../delegation_webhook_audit_non_pg_contract_test.php`
- `php .../portal_delivery_non_pg_contract_test.php`
- `php .../developer_openapi_event_catalog_non_pg_test.php`
- `php .../management_sdk_catalog_non_pg_test.php`
- `php .../self_service_non_pg_contract_test.php`
- `php .../self_service_behavior_non_pg_test.php`
- `find sand-iam/plugin/sand-iam -name '*.php' -type f -print0 | xargs -0 -n 1 php -l`
- 用户级 Codex 测试质量扫描器：`scan_test_quality.py sand-iam/plugin/sand-iam/tests`（当时通过本机安装路径调用；该机器路径不作为项目契约）

上述运行结果均为通过；独立审查者实际以 `-n 1` 跑得 PHP lint **419/419**（该复核在新增本测试前完成；本测试以 `php` 直接运行已解析并通过）。测试质量扫描为 `tests=114, SOURCE_MATCH=0, heuristic_leads=1`，唯一 `ALGORITHM_HEURISTIC` 是人工复核提示而不是失败。门户本轮 `tsc --noEmit` 未运行，因为本地未安装 `portal/node_modules/.bin/tsc`；未下载依赖。v17 的既有 build/type/lint/54-route 和 ZIP 独立消费者证据继续只作为可复核历史构建证据。

## 3. P01–P20 逐项结果

| P | 状态 | 当前源码与 schema/migration | 行为与静态/构建证据 | 归位说明 |
| --- | --- | --- | --- | --- |
| P01 | ✅ 通过 | `config/route.php`、`ApplicationAuthorizationMiddleware.php`、`IdentityContextProvider.php`；`003_human_auth_core.pgsql`、`005_oauth_oidc.pgsql`。 | `human_auth_service_integration_test.php`、`oauth_oidc_integration_test.php`；路由/门户静态契约。 | 会话平面和 machine context 已是源码模块；真实宿主会话在 F。 |
| P02 | ✅ 通过 | 管理 `OrganizationController.php`、`ApplicationController.php`、`EnvironmentController.php`；`install.sql` 与 `001_iam04_admin_organization_grant.pgsql`。 | `environment_lifecycle_behavior_non_pg_test.php`、`environment_lifecycle_non_pg_contract_test.php`。 | CRUD、隔离、停用和审计不再要求先做真实宿主页面。 |
| P03 | ✅ 通过 | `HumanAuthService.php`、`AuthController.php`；`003_human_auth_core.pgsql`。 | `human_auth_service_integration_test.php` 覆盖注册、验证、登录、轮换、锁定、重置、撤销与退出。 | 原有通过项，经本门槛复核保留。 |
| P04 | ✅ 通过 | `MfaService.php`、`AuthController.php`；`004_mfa_passkey.pgsql`。 | `mfa_passkey_integration_test.php`、`mfa_passkey_boundary_pg_integration_test.php`。 | 密码学/重放拒绝是模块行为；真实 WebAuthn 浏览器在 F。 |
| P05 | ✅ 通过 | `ScimService.php`、`ScimController.php`、LDAP adapter；`006_federation_directory_scim.pgsql`、`024_scim_group_member_lifecycle.pgsql`。 | `federation_ab_integration_test.php`、`federation_scim_admin_contract_test.php`。 | 协议服务实现与资源行为已闭合；目录实际联调归 F。 |
| P06 | ✅ 通过 | `OAuthOidcService.php`、`OAuthOidcController.php`；`005_oauth_oidc.pgsql`。 | `oauth_oidc_integration_test.php`、`oauth_oidc_contract_test.php`。 | Provider 模块通过；标准 RP 互操作归 F/D。 |
| P07 | ✅ 通过 | `PolicyAuthorizer.php`、角色/资源/用户类型控制器；`029_policy_versioning.pgsql`、`033_identity_group_role.pgsql`。 | `identity_group_role_pg_integration_test.php`、`identity_group_role_authorization_non_pg_contract_test.php`。 | RBAC 的后端拒绝优先为模块关口。 |
| P08 | ✅ 通过 | `PolicyAuthorizer.php`、`ScopeMatcher.php`、`PolicyVersionService.php`；`029_policy_versioning.pgsql`。 | `policy_versioning_pg_integration_test.php`、`policy_versioning_non_pg_test.php`。 | ABAC/scope 的正负决定已作为模块实现，不以页面筛选计分。 |
| P09 | ✅ 通过 | `ApiGovernanceService.php`、`RouteBindingSynchronizer.php`、`ApplicationAuthorizationMiddleware.php`；`008_api_governance.pgsql`。 | `api_governance_pg_integration_test.php`、`api_governance_openapi_action_non_pg_test.php`。 | 路由治理与 fail-closed 中间件已闭合；业务应用联调归 L。 |
| P10 | ✅ 通过 | `IdentityContextProvider.php`、`CredentialIssuanceService.php`、`RuntimeContextController.php`；`install.sql` 的 workload/grant schema。 | `context_revocation_contract_non_pg_test.php`、服务目录行为测试。 | 机器身份签发、验证、撤销属实现；真实调用方归 L。 |
| P11 | ✅ 通过 | `ServiceInvocationAuthorizer.php`、事实解析器、`NetworkPolicy.php`；`030_service_grant_invocation_control.pgsql`。 | `service_grant_invocation_control_pg_integration_test.php`、`service_grant_invocation_control_non_pg_test.php`。 | quota/network/data class 已进入授权决定。 |
| P12 | ✅ 通过 | `FederationService.php`、`FederationController.php`、SAML verifier；`006_federation_directory_scim.pgsql`、`007_federation_handoff.pgsql`。 | `federation_ab_integration_test.php`（OAuth/OIDC/SAML、绑定/解绑、冲突、审计与 handoff 正负行为）；静态 `federation_ab_contract_test.php`。 | 真实 IdP、标准客户端和宿主 HTTP 改列 F/D，不再否定本模块实现。 |
| P13 | ✅ 通过 | `ApplicationExperienceController.php`、`MessageProviderController.php`、`MessageProviderService.php`；`011_application_experience_message_provider.pgsql`、`025_message_provider_mount_scope_integrity.pgsql`。 | `message_provider_pg_integration_test.php`（挂载、投递、Captcha、停用、跨组织拒绝）、本轮 message crypto 行为测试与 T09 static-rule。 | 真实邮件/SMS/Captcha 供应商与管理浏览器属于 F/D。 |
| P14 | ✅ 通过 | `SyncConnectorService.php`、三种远程目录 driver、sync models，以及 2026-09-08 新增 `DirectorySyncScheduler.php`、`DirectorySyncWorker.php`；`015_identity_sync_connector.pgsql`、`026_sync_connector_scope_integrity.pgsql`。 | 既有 `sync_connector_pg_integration_test.php` 是唯一真实生产 `SyncConnectorService::run` 的游标、冲突、停用和审计行为证据；它会创建 PostgreSQL 夹具，本轮无数据库/宿主写入授权，故未重跑。新增 `directory_sync_worker_behavior_non_pg_test.php` 经真实 `worker → scheduler → ServiceDirectorySyncRunExecutor` 与可注入 runner，观察 due/disabled、窗口外跨组织、17 个同组织连接的 keyset 公平、conflict 重新 eligible 后的跨组织/同组织公平、retry state 清理和 stop during run；不伪造 `SyncConnectorService` 或声称端到端游标/审计。`directory_sync_worker_process_static_test.php` 标记 `behavior-test-gate: static-rule`，核对双开关注册。运维说明列出 5 个 worker 配置、默认关闭、keyset/wrap 公平、启用前提和恢复边界。 | 独立最终复核 **ACCEPT（P0/P1/P2=0）**，满足模块实现计分。真实 service PostgreSQL 行为本轮未重跑；真实 worker/目录/宿主/HTTP 仍属于后续授权与 F/L/D。 |
| P15 | ✅ 通过 | DCR/OIDC logout、CAS、Kerberos/SPNEGO、RADIUS authority service/API；`016_oauth_dynamic_registration_logout.pgsql`、`017_cas_protocol.pgsql`、`018_radius_server.pgsql`。 | 本轮 DCR/OIDC/CAS/Kerberos/RADIUS 的公开输入、输出和 fail-closed 负例通过；static-rule 仅辅助，部分局部安全测试使用私有函数反射，不作完整协议运行验证。 | 源码、API/schema 与公开行为满足模块门槛；标准 client/Realm/NAS、真实协议运行和宿主 HTTP 均留 F/D。 |
| P16 | ✅ 通过 | `AdminOrganizationAccess.php`、`AdminApplicationGrantController.php` 与所有 `*ResourceController` 范围守卫；`001_iam04_admin_organization_grant.pgsql`、`009_admin_application_grant.pgsql`。 | 本轮 `admin_application_grant_audit_behavior_non_pg_test.php` 验证真实控制器 create/update/disable、范围和逐请求审计；`delegation_webhook_integration_test.php` 覆盖撤权即时拒绝。 | 三角色真实页面归 F/L，不再作为管理委派源码关口。 |
| P17 | ✅ 通过 | `EventCatalog.php`、`WebhookService.php`、`WebhookWorker.php`、审计安全运营服务；`010_webhook_delivery.pgsql`、`019_security_operations.pgsql`、`027_security_operation_idempotency.pgsql`。 | `delegation_webhook_integration_test.php` 覆盖 outbox、签名、重试、轮换和审计；本轮 crypto/transport 行为与 T06 static-rule 通过。 | HTTPS 接收器、worker 并发、告警出口和备份恢复归 F/D。 |
| P18 | ✅ 通过 | PHP/TypeScript/Dart SDK、CLI、`ManagementApiCatalog.php`、`DeveloperController.php`；`019_security_operations.pgsql`。 | v17 ZIP 解包后的独立消费者 loopback allow/403/401；本轮 OpenAPI/event catalog、SDK catalog static-rule 通过；v17 build/type/lint/54-route 记录可复核。 | 真实业务应用和宿主 HTTP 是 L/F，不再阻断 SDK/CLI/目录实现。 |
| P19 | ✅ 通过 | `InitializationService.php`、`InitializationController.php`、`InitializationPackage.php`、草稿/修订模型与管理端初始化页面；`020_initialization_package.pgsql`、`037_initialization_draft.pgsql`。 | `initialization_idempotency_behavior_non_pg_test.php` 覆盖 preview/apply/replay/stale、错误确认零副作用、多资源逆序 rollback 与漂移拒绝；草稿 service/controller 行为测试覆盖秘密拒绝、revision、并发冲突、停用和范围拒绝；管理端隔离副本 ESLint、类型检查和 Vite build 通过。 | 显式草稿列表/读取/保存/更新/停用与既有不可变运行记录兼容，后端和前端分别独立复核 ACCEPT（P0/P1/P2=0）。真实 PostgreSQL、宿主 HTTP 和浏览器仍归 F/L/D。 |
| P20 | ✅ 通过 | `AccountPortalController.php`、`SelfServiceController.php`、`SelfServiceService.php`、`portal/src/*`；`003_human_auth_core.pgsql`、`004_mfa_passkey.pgsql`、`006_federation_directory_scim.pgsql`、`007_federation_handoff.pgsql` 均存在于根与插件载荷。 | 新增 `self_service_behavior_non_pg_test.php` 真实调用生产 `SelfServiceService` 的 `profile`、`updateProfile`、`connections`、`securityOverview`：断言 profile read/update 的公开返回和持久状态、应用/身份隔离、事务完成、`identity.updated` 事件与 `identity.profile_update` 审计，以及已撤销/过期会话和跨应用 MFA/Passkey 不进入安全概览；`self_service_non_pg_contract_test.php` 仅作 static-rule 辅助。 | 该独立自助模块公开行为证据不再借 P03/P04 计分。多视口浏览器、真实 HTTP、实际会话撤销操作和清理仍归 F/L。 |

## 4. 计数与保留风险

- 2026-09-08 调整前：模块实现 **11/20（55%）**，完整目标 **19/48（39.6%）**。
- 2026-09-08 调整后：模块实现 **20/20（100.0%）**，完整目标 **28/48（58.3%）**。
- P12、P13、P14、P15、P16、P17、P18 由旧部分归位为通过；P20 以独立公开行为测试通过，P19 再以草稿持久化、管理端流程和回滚公开行为闭合并经双重独立复核通过。R **8/9**、F **0/7**、L **0/4**、D **0/8** 不变。

这份 2026-09-08 历史快照不是正式 FLOW 验收、业务闭环、可部署、已部署或线上验证。快照当时尤其尚未验证真实宿主 HTTP/浏览器、供应商、目录、标准客户端、Realm/NAS、业务应用、scheduler、worker 并发、备份恢复和发布恢复；这些风险仍须由各自 F/L/D 原子的当前证据关闭。
