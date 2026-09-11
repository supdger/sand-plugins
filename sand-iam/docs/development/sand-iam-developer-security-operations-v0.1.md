# SandIAM 开发者生态与安全运营契约 v0.1

> 状态：IAM-T12 源码候选。Dart/Flutter SDK、CLI、管理 OpenAPI、事件目录、网络规则、审计归档/告警和初始化包已有非 PostgreSQL 证据；`019–020` 已进入根生命周期并通过 SandPackage 安装，仍未做真实宿主与业务应用验收。

## 1. 开发者接入

`sdk/dart` 是纯 Dart 包，可直接供 Flutter、Dart 服务和命令行使用。它与 PHP、TypeScript SDK 使用相同的 `organization_code + application_code` 应用边界，提供注册、登录、刷新、退出、资料、安全概况、外部连接、会话撤销、改密和接口代码授权。生产地址必须是 HTTPS，仅 loopback 开发地址允许 HTTP；SDK 不持久化或记录密码、令牌和响应正文。

CLI 只提供：

- `sand-iam doctor`：检查 OIDC 发现文档与指定应用登录外观；
- `sand-iam snippet`：生成不含密钥和令牌的 Flutter、PHP 或 TypeScript 最小配置片段。

CLI 不接收用户密码、访问令牌、刷新令牌、客户端密钥或 Webhook/RADIUS 共享密钥。

管理 API 的 OpenAPI 3.1 文档由 `GET /app/sand-iam/admin/developer/openapi` 输出，事件目录由 `GET /app/sand-iam/admin/developer/events` 输出，二者都使用 SandAdmin 登录、权限和委派范围。`ManagementApiCatalog` 与 `config/route.php` 做自动全量对账，并逐项核对路由目标控制器的 `Permission` 属性：任何新增管理路由未进入 OpenAPI，或 OpenAPI 权限码与实际鉴权属性不同，契约测试都会失败。OpenAPI 标明稳定权限码、敏感输入接口和统一成功/错误响应，但不嵌入登录态、示例密钥或环境地址。

## 2. 事务事件目录

Webhook 事件类型不再接受任意字符串，只允许 `EventCatalog` 中已登记并带 schema version 的事件。当前目录包含：

- 应用用户创建、修改、启用、停用、删除和恢复；
- 登录成功/失败、退出和资料更新；
- 身份目录变更、授权策略变更和调用凭证变更；
- 安全操作拒绝与安全告警产生。

`IdentityEventPublisher` 在身份变更事务内写 Webhook/同步 outbox；`AuditEventPublisher` 从已写入的安全审计生成最小事件投影。投影只含 action、outcome、resource type/id 和 request ID，不复制审计上下文、操作者标识、密码、令牌、密钥、原始网络地址或外部响应。新目录默认关闭，只有 `SAND_IAM_AUDIT_EVENT_OUTBOX_ENABLED=1` 且 `010` 已安装后才能启用。

## 3. IP allowlist 与网络规则

网络规则统一使用规范 IPv4/IPv6 CIDR：

```json
{
  "allow_cidrs": ["10.20.0.0/24", "2001:db8::/32"],
  "deny_cidrs": ["10.20.0.128/25"]
}
```

拒绝规则优先；允许列表非空时，来源必须命中至少一项。网络规则非空而来源地址无法解析时失败关闭。最多各 64 个 CIDR，主机位未归零的非规范 CIDR直接拒绝。

- `application_network_policy` 在 `SAND_IAM_APPLICATION_NETWORK_POLICY_ENABLED=1` 后约束应用注册、登录、验证码确认、密码重置、刷新和 RADIUS 密码验证；无策略的既有应用保持原行为。
- `service_grant.network_policy` 不再只是可保存 JSON：调用身份签发短期 context 时按真实传输对端地址执行。验证已有 context 时重验授权是否仍有效，但不把资源服务自身地址误当成原始调用方地址。
- Webman 控制器使用 `getRealIp(true)` 的安全模式，不直接信任客户端伪造的 `X-Forwarded-For`。反向代理部署必须先在宿主层建立受信代理边界，不能由请求自行声明。

## 4. 审计保留、归档和告警

`019_security_operations.pgsql` 候选增加客户主体级保留策略、不可变归档副本和安全告警：

- `archive_after_days` 1–3650 天；`retention_days` 不得短于归档期；
- opt-in 单 worker 使用 `FOR UPDATE SKIP LOCKED` 小批量复制旧审计，归档失败不删除原记录；
- 清除同时要求客户主体 `purge_enabled`、部署 `SAND_IAM_AUDIT_PURGE_ENABLED=1` 和针对主体/截止日期的确认摘要；默认不开 worker 自动清除；
- 登录、OAuth、授权、凭证、身份源、SCIM、CAS、RADIUS、Kerberos 等拒绝/失败在配置窗口内达到阈值时形成高危或关键告警；告警指纹使用至少 32 字节的独立部署密钥 HMAC，不使用原始 IP 或账号标识；
- 告警可查询、查看和标记处理；归档记录可按原发生时间、应用、动作、结果和 request ID 查询。

告警是审计的运营投影。密钥缺失或过短、告警存储故障都不得篡改或替换原授权/认证决定；原审计仍是事实源。投影失败只记录异常类型、文件名和行号，不记录业务输入、原始账号或网络地址。

所有显式管理与运行控制器均逐类关闭 Webman 默认路由，专用敏感中间件不能被另一条默认 URL 绕过。模型层同时隐藏密码、令牌、验证码、状态/目标哈希，以及身份源、消息、邀请、MFA、同步和 Webhook 密文；控制器仍须按业务字段构造响应，模型隐藏只是防止误用 `toArray()` 时泄漏的第二道门禁。

## 5. 初始化包

初始化包格式为 `sand-iam.initialization/v1`，只携带一个客户主体下某接入应用的可移植配置：应用、角色、用户类型、业务资源、本地身份源和角色策略。它明确不携带应用用户、外部 subject、密码、令牌、凭证、密钥、外部身份源密文或业务数据。

操作流程固定为：

```text
导出或编写 manifest
→ 严格字段/引用/敏感材料预检
→ 展示 create/update/no_change 差异
→ 用户确认 preview_hash
→ 事务内重新预检并锁定客户主体
→ 合并应用（不删除包外配置）
→ 保存每项 before/after 和绑定
→ 需要时按逆序回滚
```

应用时若预检哈希过期则返回 `SAND_IAM_INITIALIZATION_PREVIEW_STALE`。角色策略使用 `package_code + policy key` 的持久绑定作为包内稳定身份，后续可修改资源、角色、动作、效果和优先级；绑定丢失、指向用户直授策略或修改后的自然目标已被其他策略占用时拒绝应用，不猜测合并。回滚要求运行记录仍为 applied、确认摘要正确，并且每项当前值仍等于该次应用后的值；发现人工或其他任务后续修改时以 `SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT` 拒绝整个回滚，避免覆盖新配置。外部 OIDC/SAML/LDAP/Kerberos 等身份源只能在管理台通过只写不读接口单独配置，不允许借初始化包搬运密钥。

## 6. 开关与启用顺序

候选能力默认关闭，启用顺序不可颠倒：

1. 将 `019–020` 接入根 install/update/uninstall 并完成隔离 PostgreSQL 安装、重复升级、回滚和卸载；
2. 验证宿主菜单权限、管理 API、归档 worker 与备份恢复；
3. 配置足够长度的告警指纹密钥；
4. 先在观察环境启用事件 outbox、网络规则、告警和归档 worker；
5. 检查拒绝率、投递失败、归档计数和误告警后再扩大范围；
6. 清除开关继续保持关闭，除非另行完成合规保留期、备份和恢复确认。

## 7. 当前验收边界

已验证：Dart analyze 0 issue、7 项单测、CLI 帮助/片段/原生编译；管理路由与 OpenAPI 全量对账；事件映射、CIDR 正负例、服务授权网络执行、审计安全保护、默认路由封闭、敏感模型序列化隐藏及初始化敏感字段/引用/哈希/回滚门禁契约；连同 IAM-06 服务目录门禁，SandIAM 非 PostgreSQL 契约 47/47 通过。

尚未验证：`019–020` 的服务级 PostgreSQL 行为、真实事务失败注入、worker 并发、管理端页面、两个视口、真实业务应用 Flutter 接入、真实代理来源地址、告警 Webhook、备份恢复和清除演练。根 lifecycle 与两个 `006` 副本已统一并通过 SandPackage 生命周期验收，不再列为红灯。

## 验收字段与错误码对账

| 原子 ID | 字段 | 错误码/恢复 |
| --- | --- | --- |
| R08-T12-01 | `application_code`、`discovery_url`、`https_endpoint` | `SAND_IAM_ONBOARDING_MANIFEST_INVALID`：拒绝生成接入材料。 |
| R08-T12-02 | `event_type`、`CIDR`、`retention_days` | `SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID`、`SAND_IAM_AUDIT_RETENTION_INVALID`：失败关闭或修正后重试。 |
| R08-T12-03 | `preview_hash`、`initialization_run_id`、`rollback_scope` | `SAND_IAM_INITIALIZATION_PREVIEW_STALE`、`SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT`：重新预检或拒绝回滚，绝不覆盖后续变更。 |
