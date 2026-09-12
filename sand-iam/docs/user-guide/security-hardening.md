# 安全加固

所有实际环境变量、默认值、密钥格式、worker 双开关和启用顺序见[配置参考](configuration-reference.md)。
不要凭功能名称猜配置键，也不要把“环境变量已设置”当成运行验收。

## 传输与秘密

- 所有浏览器、SDK、Webhook 和协议端点使用 TLS，并校验证书与目标主机。
- 登录密钥、OIDC 签名密钥、Webhook 密钥和机器凭证使用独立用途与受控密钥存储。
- 设置轮换周期和紧急撤销流程；日志只记录摘要、key id、request id 和结果。
- 一次性秘密创建或轮换遇到超时，应以完全相同的请求和同一 `X-Request-Id` 重试；重放结果不得含
  `client_secret`、调用凭证或其他明文。只有明确的新轮换意图才能更换 request id。
- 同一幂等 request id 的冲突保护至少保留 30 天。只有完成数据库备份恢复演练并取得删除授权后，
  才可开启 `SAND_IAM_SECURITY_OPERATION_RETENTION_WORKER_ENABLED=1`；worker 只分批删除超过保留期的
  succeeded 记录，不删除 pending 记录或审计日志。调用方不得在保留期后故意复用旧 request id。
- 同一个默认关闭的维护 worker 也按 `SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS` 分批物理删除过期限流窗口；
  它只处理早于截止时间的数据库限流状态，不改变当前 60 秒窗口、认证策略或审计证据。迁移 038 未完成、
  备份恢复未演练或没有数据库删除授权时，不得开启该 worker。
- refresh token 轮换响应丢失时，仅在首次请求后 30 秒内以同一旧 token、同一 `X-Request-Id` 和同一来源网络重试；
  成功恢复后立即替换本地 token。恢复窗口外或使用新 request id 重放旧 token 会撤销整条会话族，客户端必须转入重新认证。

## 最小权限与隔离

- 平台、客户主体和应用管理员只获得所需管理范围。
- 策略默认拒绝，显式登记资源、动作、环境和 audience。
- 对跨组织、跨应用、错误 audience、过期、重放和撤权后访问建立持续回归。
- 批量和导出接口逐对象验证真实数据范围，不信任客户端范围字段。

## 认证安全

- 生产启用合理密码策略、登录限流、失败锁定和安全恢复。
- 高风险操作要求 MFA 或 Passkey，并为恢复码设置一次性使用和撤销能力。
- Cookie 使用 Secure、HttpOnly 和适当 SameSite；令牌限制寿命、受众和可重放范围。

## 外部协议与 worker

- OIDC、SAML、CAS、SCIM、LDAP、Kerberos/RADIUS 只连接受控真实对端，固定回调地址、issuer、audience 和证书。
- Webhook 验证签名、时间窗和幂等键；失败重试不得无限增长或重复副作用。
- 目录同步、投递和审计 worker 设置并发、超时、退避、积压告警与安全停止流程。

## 验收接口

验收夹具和清理接口在发布状态默认关闭。只有隔离验收环境可以临时开启，使用独立前缀、权限和会话标识，
完成后关闭并核对清理审计。生产环境不得启用。
