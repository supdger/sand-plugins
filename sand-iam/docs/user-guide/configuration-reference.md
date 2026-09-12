# 配置参考

SandIAM 从宿主运行环境读取配置。秘密必须由部署平台或密钥管理系统注入，不应写入仓库、镜像、
启动命令、日志或截图。修改环境变量后，只有按宿主运维流程重载或重启相应进程才会生效；先取得
环境授权，并在变更前记录当前值、密钥版本和回滚方法。

注入配置后、启动或重载服务前，可在插件目录执行离线预检：

```sh
php plugin/sand-iam/bin/check-runtime-configuration.php --profile=release
```

验收环境临时开启受控夹具清理时改用 `--profile=acceptance`；自动化可追加 `--json`。命令只读取
`SAND_IAM_*` 环境变量，只报告配置键和错误码，不回显值；它不加载宿主、不连接数据库、不启动
服务，也不探测外部端点。预检通过只表示配置形状、公开安全范围和开关依赖通过，不等于运行验收。

## 密钥格式

`*_ENCRYPTION_KEY` 使用 **32 个随机字节的标准 base64**；对应 `*_KEY_VERSION` 使用稳定的
`v1`、`v2` 等版本标识。生成命令只应在受控终端运行，并把输出直接存入密钥系统：

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

pepper、HMAC 和指纹键至少使用 32 个随机字节，可保存为 64 位十六进制文本：

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

不要让不同用途共用密钥。带 `*_KEYS` 的值是旧版本到旧 base64 密钥的 JSON 对象，例如
`{"v1":"<由密钥系统注入>"}`；轮换时先保留仍有密文引用的旧版本，再切换当前版本和当前键。
不带版本化 keyring 的密钥不能直接原地轮换，应先按对应功能的迁移方案处理存量数据或兼容窗口。

## 基础运行配置

| 配置键 | 默认值 | 用途与启用条件 |
| --- | --- | --- |
| `SAND_IAM_DEBUG` | `0` | 插件调试开关；发布和生产保持 `0`，只在受控本地排障窗口临时设为 `1`。 |
| `SAND_IAM_CONTEXT_SIGNING_KEY` | 空 | 机器运行上下文 HMAC 键；空值时签发关闭失败。至少 32 随机字节。 |
| `SAND_IAM_AUTH_PEPPER` | 空 | 密码、令牌、SCIM/CAS 等不可逆摘要；认证能力必需，建议至少 32 随机字节。 |
| `SAND_IAM_AUTH_PEPPER_VERSION` | `v1` | 新认证记录的 pepper 版本；不能在没有兼容迁移时直接改动。 |
| `SAND_IAM_MFA_ENCRYPTION_KEY` | 空 | MFA 与短期 WebAuthn 材料，base64 32 字节。 |
| `SAND_IAM_MFA_ENCRYPTION_KEY_VERSION` | `v1` | 当前 MFA 密钥版本。 |
| `SAND_IAM_MFA_ENCRYPTION_KEYS` | 空 | 解密历史 MFA 记录的版本化 JSON keyring。 |
| `SAND_IAM_OIDC_ISSUER` | 空 | HTTPS issuer，必须以 `/api/sand-iam/v1` 结尾。 |
| `SAND_IAM_OIDC_PRIVATE_KEY_BASE64` | 空 | 首次托管轮换前使用的 base64 私钥 PEM；不得包含在公开包中。 |
| `SAND_IAM_OIDC_KID` | 空 | 与当前 OIDC 签名公钥对应的稳定 key id。 |
| `SAND_IAM_OIDC_SUBJECT_KEY` | 空 | pairwise subject HMAC 键，至少 32 字节；直接轮换会改变 subject。 |
| `SAND_IAM_OIDC_SIGNING_KEY_CLOCK_SKEW_SECONDS` | `300` | 旧签名公钥保留窗口的时钟偏差，源码限制为 0–3600 秒。 |
| `SAND_IAM_FEDERATION_ENCRYPTION_KEY` | 空 | 外部身份源配置及联合事务材料，base64 32 字节。 |

## 应用体验、消息与身份生命周期

| 配置键 | 默认值 | 用途与启用条件 |
| --- | --- | --- |
| `SAND_IAM_APPLICATION_EXPERIENCE_ENABLED` | `0` | 应用用户体验配置总开关。迁移和页面验收完成后才设为 `1`。 |
| `SAND_IAM_MESSAGE_PROVIDER_ENABLED` | `0` | 组织级消息/验证码 provider 总开关。 |
| `SAND_IAM_MESSAGE_ENCRYPTION_KEY` | 空 | provider 配置密文键，base64 32 字节。 |
| `SAND_IAM_MESSAGE_ENCRYPTION_KEY_VERSION` | `v1` | 当前消息配置密钥版本。 |
| `SAND_IAM_MESSAGE_ENCRYPTION_KEYS` | 空 | 历史消息配置 JSON keyring。 |
| `SAND_IAM_MESSAGE_DRIVERS` | 空 | driver code 到部署方 PHP 类的 JSON 映射；数据库内容不能选择任意类。 |
| `SAND_IAM_AUTH_EMAIL_SENDER` | 空 | 兼容的邮件发送类；必须实现源码声明的静态 `send` 契约。 |
| `SAND_IAM_AUTH_PHONE_SENDER` | 空 | 兼容的短信发送类；未配置时真实发送关闭失败。 |
| `SAND_IAM_IDENTITY_LIFECYCLE_ENABLED` | `0` | 邀请、访客、导入与同步生命周期总开关。 |
| `SAND_IAM_INVITATION_ENCRYPTION_KEY` | 空 | 邀请目标等可逆秘密，base64 32 字节。 |
| `SAND_IAM_INVITATION_ENCRYPTION_KEY_VERSION` | `v1` | 当前邀请密钥版本。 |
| `SAND_IAM_INVITATION_ENCRYPTION_KEYS` | 空 | 历史邀请 JSON keyring。 |
| `SAND_IAM_INVITATION_TOKEN_PEPPER` | 空 | 邀请令牌摘要 pepper，至少 32 随机字节。 |
| `SAND_IAM_INVITATION_ACCEPT_URL` | 空 | 应用用户接受邀请的 HTTPS 入口。 |
| `SAND_IAM_GUEST_REFERENCE_PEPPER` | 空 | 访客外部引用摘要 pepper，至少 32 随机字节。 |
| `SAND_IAM_IMPORT_ENCRYPTION_KEY` | 空 | 导入暂存行密文键，base64 32 字节。 |
| `SAND_IAM_IMPORT_ENCRYPTION_KEY_VERSION` | `v1` | 当前导入密钥版本。 |
| `SAND_IAM_IMPORT_ENCRYPTION_KEYS` | 空 | 历史导入 JSON keyring。 |
| `SAND_IAM_SYNC_ENCRYPTION_KEY` | 空 | 目录连接秘密密文键，base64 32 字节。 |
| `SAND_IAM_SYNC_ENCRYPTION_KEY_VERSION` | `v1` | 当前同步密钥版本。 |
| `SAND_IAM_SYNC_ENCRYPTION_KEYS` | 空 | 历史同步 JSON keyring。 |
| `SAND_IAM_SYNC_REFERENCE_PEPPER` | 空 | 目录对象引用摘要 pepper，至少 32 随机字节。 |
| `SAND_IAM_SYNC_DRIVERS` | 空 | 同步 driver code 到部署方 PHP 类的 JSON 映射。 |
| `SAND_IAM_SYNC_OUTBOX_MAX_ATTEMPTS` | `10` | 单个出站事件在拒绝或驱动失败后进入终态前的最大尝试次数，允许 1–100；终态记录只能经同应用的显式重试操作重新排队。 |

## Webhook、事件、审计与安全运营

| 配置键 | 默认值 | 用途与启用条件 |
| --- | --- | --- |
| `SAND_IAM_WEBHOOK_ENCRYPTION_KEY` | 空 | Webhook 签名秘密密文键，base64 32 字节。 |
| `SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION` | `v1` | 当前 Webhook 密钥版本。 |
| `SAND_IAM_WEBHOOK_ENCRYPTION_KEYS` | 空 | 历史 Webhook JSON keyring。 |
| `SAND_IAM_WEBHOOK_POLL_INTERVAL_MS` | `1000` | 投递轮询间隔，源码下限 250 ms。 |
| `SAND_IAM_WEBHOOK_BATCH_SIZE` | `20` | 单批领取数，源码限制为 1–100。 |
| `SAND_IAM_WEBHOOK_WORKER_ENABLED` | `0` | Webhook worker 进程开关；先完成迁移、真实接收端与幂等验收。 |
| `SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED` | `0` | 身份事件 outbox 写入开关。 |
| `SAND_IAM_AUDIT_EVENT_OUTBOX_ENABLED` | `0` | 审计事件 outbox 写入开关。 |
| `SAND_IAM_APPLICATION_NETWORK_POLICY_ENABLED` | `0` | 应用网络规则执行开关；须先验证可信代理/IP 来源。 |
| `SAND_IAM_SECURITY_ALERT_ENABLED` | `0` | 安全告警生成开关。 |
| `SAND_IAM_SECURITY_ALERT_FINGERPRINT_KEY` | 空 | 告警去重指纹 HMAC 键，至少 32 随机字节。 |
| `SAND_IAM_AUDIT_PURGE_ENABLED` | `0` | 审计清理开关；没有保留策略、归档验证和授权时保持 `0`。 |
| `SAND_IAM_AUDIT_ARCHIVE_INTERVAL_SECONDS` | `3600` | 审计归档扫描间隔，运行时下限 300 秒。 |
| `SAND_IAM_AUDIT_ARCHIVE_BATCH_SIZE` | `200` | 审计归档单批数量。 |
| `SAND_IAM_AUDIT_ARCHIVE_WORKER_ENABLED` | `0` | 审计归档 worker 进程开关。 |
| `SAND_IAM_SECURITY_OPERATION_RETENTION_WORKER_ENABLED` | `0` | 运维状态清理 worker；同时处理已成功幂等操作和过期认证限流窗口。这是数据库删除开关，获得明确运维授权并完成恢复演练前保持 `0`。 |
| `SAND_IAM_SECURITY_OPERATION_RETENTION_DAYS` | `30` | 已成功幂等操作的最短保留期，允许 30–3650 天；pending 记录永不由该 worker 删除。 |
| `SAND_IAM_SECURITY_OPERATION_RETENTION_INTERVAL_SECONDS` | `3600` | 清理扫描间隔，下限 300 秒。 |
| `SAND_IAM_SECURITY_OPERATION_RETENTION_BATCH_SIZE` | `200` | 单批删除上限，允许 1–1000；只按已有时间索引读取有限集合。 |
| `SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS` | `24` | 已过期认证限流窗口保留期，允许 1–168 小时；实际限流窗口仅 60 秒，最小值仍留下至少 1 小时的并发和时钟偏差余量。 |

## 目录同步 worker

| 配置键 | 默认值 | 用途与启用条件 |
| --- | --- | --- |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED` | `0` | worker 开关；只有它和 `SAND_IAM_IDENTITY_LIFECYCLE_ENABLED=1` 同时满足才注册进程。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_INTERVAL_SECONDS` | `60` | 调度间隔，源码下限 1 秒。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_BATCH_SIZE` | `20` | 单批连接器数，源码限制为 1–100。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_BASE_SECONDS` | `5` | 失败退避基数，源码下限 1 秒。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS` | `300` | 失败退避上限；部署值不得小于基数。 |

## OAuth/OIDC、CAS、Kerberos 与 RADIUS

| 配置键 | 默认值 | 用途与启用条件 |
| --- | --- | --- |
| `SAND_IAM_OAUTH_DYNAMIC_REGISTRATION_ENABLED` | `0` | OAuth 动态客户端注册开关。 |
| `SAND_IAM_OIDC_FRONTCHANNEL_LOGOUT_ENABLED` | `0` | OIDC 前通道登出开关。 |
| `SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED` | `0` | OIDC 后通道登出生产开关。 |
| `SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY` | 空 | 后通道登出票据密文键，base64 32 字节。 |
| `SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION` | `v1` | 当前登出票据密钥版本。 |
| `SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS` | 空 | 历史登出票据 JSON keyring。 |
| `SAND_IAM_OIDC_LOGOUT_POLL_INTERVAL_MS` | `1000` | 后通道投递轮询间隔。 |
| `SAND_IAM_OIDC_LOGOUT_BATCH_SIZE` | `20` | 后通道投递单批数量。 |
| `SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED` | `0` | 后通道登出 worker 进程开关；与功能开关分别控制。 |
| `SAND_IAM_CAS_ENABLED` | `0` | CAS 协议开关；issuer、pepper、应用服务 URL 及标准客户端通过后再启用。 |
| `SAND_IAM_KERBEROS_ENABLED` | `0` | Kerberos/SPNEGO 开关。 |
| `SAND_IAM_KERBEROS_VERIFIER` | 空 | 部署方实现的 GSSAPI verifier 类。 |
| `SAND_IAM_KERBEROS_CONTEXT_RESOLVER` | 空 | 部署方提供的可信 Kerberos 请求上下文解析器。 |
| `SAND_IAM_RADIUS_SERVER_ENABLED` | `0` | RADIUS 业务能力开关。 |
| `SAND_IAM_RADIUS_WORKER_ENABLED` | `0` | RADIUS UDP auth/accounting 两个 worker 的共同进程开关。 |
| `SAND_IAM_RADIUS_BIND_HOST` | `127.0.0.1` | UDP 绑定地址；对外绑定前先配置防火墙和受控 NAS 来源。 |
| `SAND_IAM_RADIUS_AUTH_PORT` | `1812` | RADIUS Authentication UDP 端口。 |
| `SAND_IAM_RADIUS_ACCOUNTING_PORT` | `1813` | RADIUS Accounting UDP 端口。 |
| `SAND_IAM_RADIUS_ENCRYPTION_KEY` | 空 | NAS shared secret 密文键，base64 32 字节。 |
| `SAND_IAM_RADIUS_ENCRYPTION_KEY_VERSION` | `v1` | 当前 RADIUS 密钥版本。 |
| `SAND_IAM_RADIUS_ENCRYPTION_KEYS` | 空 | 历史 RADIUS JSON keyring。 |
| `SAND_IAM_RADIUS_REPLAY_KEY` | 空 | RADIUS 重放缓存 HMAC 键，至少 32 随机字节。 |

## 验收专用配置

| 配置键 | 发布默认值 | 规则 |
| --- | --- | --- |
| `SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED` | `0` | 只允许在隔离验收环境、固定数据前缀和明确清理授权下临时设为 `1`；发布和生产必须为 `0`。 |

## 启用顺序与回滚

1. 先完成匹配版本的数据库生命周期、备份和恢复验证。
2. 注入本能力所需密钥、key version、历史 keyring 和 driver；不要先打开功能开关。
3. 用一个受控对象验证加密、解密、允许、拒绝、撤销和审计。
4. 再打开功能开关；需要 worker 的能力最后打开 worker 开关，并按宿主流程重载进程。
5. 观察错误率、积压、Worker restart、连接池等待、RSS/FD 和拒绝审计；异常时先关闭 worker/功能开关，
   不删除队列、密文、审计或迁移账本。

配置文件存在、环境变量已写入或进程可见都不等于能力验收通过。外部协议、消息、同步、Webhook、
RADIUS 和安全运营仍需在受控真实对端完成成功、拒绝、撤销、重试与清理。
