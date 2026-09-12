# OSS-03 执行记录

- 日期：2026-09-12
- 范围：仅权威源码、公开文档、SDK/portal 构建、只读扫描和 review-only 候选；未同步宿主、未执行数据库、未启停服务。

## 修正

- 唯一 payload policy 同时排除 `test/` 与 `tests/`，Dart SDK 测试不再进入发行包。
- 根 README 移除内部任务状态、历史事故和不随包发布的链接。
- Webman 与机器调用示例改为通用工作项/文档服务，不含 SandAI 或行业专属业务。
- 新增 `THIRD_PARTY_NOTICES.md`、`CONTRIBUTING.md`、`SECURITY.md` 及安装升级、应用接入、应用用户、安全、备份恢复和排障文档。
- 新增 `check-release-payload.php` 和回归测试，检查许可证、第三方声明、测试载荷、公开链接、本机路径、内部任务引用、行业词与高置信凭证。
- 新增 `release/unsigned` 封闭构建入口及包外 Ed25519 signer/verifier；artifact manifest v6
  同时绑定 clean commit 与 `HEAD:sand-iam` tree，隔离测试证明缺失 tree、ZIP 篡改、符号链接路径
  和 dirty/review manifest 均被拒绝。未生成真实发布密钥。
- 新增公开发布包校验指南，明确 ZIP、artifact manifest、签名证明和独立可信公钥四件套及信任边界。
- 新增不伪造历史的 `CHANGELOG.md`，只记录 0.7.0 当前可证实的兼容、033–038、公开材料和未完成发布条件。
- 修复机器服务示例：拒绝非 HTTPS（本机 loopback 除外）、非 2xx、非法 JSON、缺失 data 及 service/audience/action 不一致；verify 成功也不回显短期 context。
- 补齐公开 PostgreSQL 备份恢复演练：归档列表与摘要校验、预先创建的隔离恢复库、源/恢复 DSN 防同库、单事务失败即停，并明确禁止 `--create`/`--clean`。
- 新增公开配置参考并由源码反查门禁覆盖 app/process 当前 85/85 个环境键，明确密钥格式、用途隔离、历史 keyring、默认关闭功能、worker 双开关、启用和回滚顺序。
- 修正插件发布默认值：`debug=true` 改为 `SAND_IAM_DEBUG` 部署开关且默认 `0`；配置参考和源码反查门禁当前为 85/85 keys、8/8 checks。
- 新增随包发布的离线运行配置预检：release/acceptance 两种 profile 覆盖 85/85 配置键，检查开关、密钥、keyring、URL、整数溢出/数值范围和 worker 依赖；缺少 OpenSSL 时 legacy OIDC 私钥检查结构化失败。输出只含键名与错误码，不回显秘密，也不连接数据库、加载宿主或启动服务。行为测试 8/8，配置文档契约为 8/8。
- 新增随包发布的完整运行环境预检：检查 PHP >=8.2 与 ctype/curl/dom/json/ldap/libxml/mbstring/openssl/PDO/pdo_pgsql/sodium/zip/zlib 共 13 项能力，缺失项按用途一次性列全；不以关闭功能替代完整成品要求。Composer manifest/lock 同步声明全部 platform 要求，标准 `composer check-platform-reqs` 14/14；纯行为测试 5/5，本机及候选 ZIP 解包后均为 14/14 PASS。
- 修复一次性秘密响应缓存与重试边界：OAuth 机密客户端创建与密钥轮换补齐 `Cache-Control: no-store` 和 `Pragma: no-cache`；机器凭证、动态注册令牌、开发者接入以及登录/MFA 令牌响应统一双头保护。OAuth 客户端创建/轮换纳入 `X-Request-Id` 幂等事务，同请求重放不再次生成或轮换秘密，并补齐通用结果脱敏器的 `client_secret` 键；行为测试证明首次响应含秘密、重放回调只执行一次且只返回 `secret_available=false`。
- 继续闭合协议令牌重试：RFC 7591 动态注册按初始访问令牌记录、规范化元数据和 request id 原子消费额度并创建客户端；初始令牌签发/撤销及 SCIM 令牌签发/撤销也纳入同一幂等事务。重放不重复创建、消费、轮换或审计，且不再次返回 `token`/`client_secret`。幂等结果递归脱敏清单扩展到 recovery codes、private/shared keys、authorization/code verifier、CSRF/nonce、CAS/SAML 等已知秘密，同时行为测试证明 `credential_id`、`secret_version` 等安全元数据不被误删。
- 闭合 MFA 一次性材料的响应丢失重试：TOTP 建立、确认和恢复码再生成按应用用户、操作、请求指纹与 `X-Request-Id` 在同一事务提交状态、审计和脱敏结果；同请求重放不创建第二因子、不重复启用或覆盖恢复码，也不重复消耗当前密码限流。密码和 TOTP code 只进入部署 pepper 的 HMAC proof，不以明文或裸摘要持久化；重放移除 `secret`、`otpauth_uri`、`recovery_codes` 并返回 `secret_available=false`。PostgreSQL 集成用例已补三类重试断言，但本轮未获数据库授权，尚未执行。
- 闭合 refresh token 轮换响应丢失：首次轮换把状态、单条成功审计和幂等记录置于同一事务；30 秒内同一旧 token 与同一 `X-Request-Id` 只恢复首次生成的 access/refresh token，不再次轮换。恢复密文使用 XChaCha20-Poly1305，密钥绑定部署 pepper、旧 token 和 request id；行为测试实际覆盖正常恢复、明文不落密文、错 token、错 request id、篡改、过期和 TTL 边界。不同 request id 的旧 token 重放仍撤销会话族。PostgreSQL 集成断言已补，但未获数据库授权执行。
- 闭合 Passkey 注册 finish 响应丢失：同一应用用户、Credential 响应和 `X-Request-Id` 在单一幂等事务中只消费一次 challenge、创建一把 credential 并写一条成功审计；同请求恢复不会重复消耗 finish 限流，不同内容复用 request id 冲突。PostgreSQL 重试断言已补但未获授权执行。
- 闭合 MFA/Passkey 登录 finish 响应丢失：challenge、TOTP/恢复码或 Passkey signCount、会话、成功审计和短期恢复密文原子提交；30 秒内相同 challenge、验证响应、来源网络和 request id 可恢复首次会话令牌，不重复消费恢复码、推进 signCount 或创建会话。恢复密文按 MFA/Passkey 用途隔离，错上下文、请求或密文均拒绝；不同 request id 的已消费 challenge 仍拒绝。PostgreSQL 断言已补但未获授权执行。
- 闭合 Passkey authentication options 响应丢失：挑战创建、options 限流、成功审计和只含密文的幂等结果在同一事务提交；同 request id 与来源网络重试返回完全相同 challenge，不再创建第二条挑战、重复审计或占用第二次限流。来源变化触发幂等冲突，挑战已消费、过期或密文不匹配均关闭失败。PostgreSQL 断言已补但未获授权执行。
- 闭合密码登录成功响应丢失：登录状态、单条成功审计、会话或 MFA challenge 与认证密文在同一幂等事务提交；30 秒内相同应用、身份、密码、captcha、来源网络、user agent 和 request id 可恢复首次响应，不再创建第二个会话/challenge 或重复审计。登录限流、captcha 结果和认证失败又分别按同一请求指纹原子提交，同一错误请求的顺序或并发重试不重复占用限流、调用已成功的 captcha、增加失败次数或写审计；不同登录意图仍独立计数并锁定。双进程 PostgreSQL 竞争用例已编写，但未获数据库授权执行。任一输入变化冲突，challenge 已消费、会话失效或密文不可用均关闭失败。
- 补齐长期运行保留边界：默认关闭的 retention worker 以一次 tick 分别清理过期 `succeeded` 幂等记录和过期认证限流窗口；两者均锁定有限批次并在删除时重验截止时间，pending 与审计永不进入清理。幂等记录保留 30–3650 天，限流状态保留 1–168 小时，迁移 038 增加 `(window_start, id)` 索引。24 小时 runner/verifier 把两类 backlog 都升为必填且零容忍指标。纯行为/配置/源码契约通过，两套 PostgreSQL 精确夹具已编写但未获授权执行；迁移/worker 未执行、未删除任何现有数据。
- 修正外部 24 小时验收模板：生成器此前仍遗漏两类 retention backlog，且测试被占位主机错误提前短路；现在模板直接包含指标和零容忍阈值，测试会替换全部必填占位后要求 `--validate-only` 真正通过，避免“能生成但不能执行”的假准备状态。
- 冻结 24 小时指标采样口径：12 个指标逐一明确进程组、队列、累计值/瞬时值边界；两类 retention backlog 使用 PostgreSQL `CURRENT_TIMESTAMP`、真实配置保留期和两个完整维护间隔计算，区分正常 tick 边界与持续积压，禁止查询失败时回填零。独立契约测试锁定 SQL 口径及生成器/runner/verifier 一致性。
- 闭合目录同步出站恢复链：远端部分拒绝、驱动级异常和不可解密载荷现在都会有界累计尝试，达到 `SAND_IAM_SYNC_OUTBOX_MAX_ATTEMPTS` 后进入 `failed`，不再无限 pending；同应用管理员可通过新增的脱敏 `outbox`/`outbox-retry` 管理 API 精确重新排队，重试与 running 同步互斥、按 request id 幂等并留 `sync.outbox_retry` 审计。纯入站连接、跨应用、非 failed 记录均关闭失败；管理端交互已冻结给 Cursor，未跨改 Vue。
- 闭合 OIDC back-channel dead 恢复链：原投递在第五次失败后保持 dead 且密文不变，管理员只能在同一 active 客户端/应用/主体和已撤销会话范围内重新签发；新 token 带新的有效期和确定性后继 `jti`，现有 event 唯一键把相同来源的并发或重复请求收敛到一条 pending 后继。列表、响应和审计不暴露 token/密文；后继再次死亡时可继续形成下一段链。管理 OpenAPI 已提升到 `0.13.0-candidate`，精确公开分页参数、关闭的请求/响应 DTO、八类稳定错误码及 404/503；公开运维指南和变更日志已同步。PostgreSQL 行为断言已写入但未获授权执行。
- 把 24 小时 `queue_depth`/`unrecoverable_backlog` 绑定为当前四类持久状态机的精确 PostgreSQL 状态公式。只有因客户端/应用/主体停用、后通道地址移除或会话不再满足撤销条件而无法走公开重签接口的 OIDC dead，停用端点的 Webhook dead，以及因连接/应用/主体停用或改为纯入站而不能走公开恢复接口的 Sync failed 计入不可恢复积压，阈值为零；active 可恢复终态仍须在验收证据列出。
- 冻结 OIDC dead 恢复的 Cursor 消费契约：列表分页、五个展示字段、内部资格字段、精确 POST body、读写权限、`X-Request-Id` 复用边界、成功 DTO、八类稳定失败码及禁止消费 session/响应摘要/令牌密文。旧 Autopilot/DETECT 继续 `enabled=false`；最近 Cursor 文件证据停在 10:22+08，本轮没有把历史“在线”描述当作当前运行事实，也没有跨改 Vue。

## 当前证据

- 包内一致性：24/24 PASS。
- non-PG/contract：114/114 PASS。
- PHP lint：507/507 PASS。
- 测试质量：139 项，`SOURCE_MATCH=0`、`heuristic_leads=1`；新增 PostgreSQL 集成用例不以静态扫描替代执行。
- 从候选 ZIP 解包后在空环境直接运行随包预检：配置 release profile 退出 `0`；注入 `DEBUG=1` 与短测试 pepper 后退出 `1`，错误码为 `RELEASE_UNSAFE_SWITCH,SHORT_SECRET`，秘密泄露扫描为 `0`；完整环境预检与配置预检均退出 `0`。ZIP 内标准 `composer check-platform-reqs --no-dev` 对 PHP 和 13 项扩展逐项返回 success，共 14/14。
- TypeScript SDK：build/test PASS；Dart SDK：analyze 0、test 11/11；portal：typecheck、contract、build PASS。
- 验收夹具：配置默认 `0`，controller 行为测试验证关闭时服务未构造、未调用。
- 敏感模型序列化：自动发现并锁定全部 35 个声明 `$hidden` 的模型、61 个字段；逐模型实际执行 `toArray()` 与 JSON 序列化，当前均不泄露隐藏值。CAS、OAuth 客户端、机器凭证、OIDC logout、RADIUS 和安全告警不再遗漏于回归登记。
- 发布卫生：11/14；失败项为根 `LICENSE`、具体私密漏洞报告入口，以及 DCO/CLA 二选一贡献机制尚未获得用户确认或配置。
- CycloneDX SBOM：79 components，79/79 含 SPDX 与许可证证据引用，SHA-256 `f0ba2e64be8afb77b29abc20393c08c4050f9348b18a68ed810daf285421f2ba`；未审计锁坐标会关闭失败，复合许可证不降格为单许可证。
- review-only artifact：`.artifacts/sand-iam-0.7.0-v70-20260912T023540Z/`
- entries：635
- archive SHA-256：`6cae3a2f9880ef1a2818d04edc28dde4c65afc69a8f632a4b718b59cfd97cf82`
- descriptor-excluded payload SHA-256：`4bbf92897537f663e80c42e5dc31ae54c85df5ad17eb7741760929688d2a7035`
- descriptor SHA-256：`a34677bbe7867aa9cd9896bbcc57c81dcbde77fd7ebef649cc7527dda8c403d5`
- source snapshot SHA-256：`c48cbb78e68a6727e7a211f772bf5d1f8b0f96a66ac5508082929b50ec4832cb`
- repeat bit-identical：true

## 边界

该工件明确为 `candidate-dirty-not-release`。新的 `--release-unsigned` 入口已实测因缺少获批项目 LICENSE 而在创建 artifact 前关闭失败，因此 v70 不可送签。它不含项目 LICENSE、外部签名或干净来源证明，且没有安装、升级、卸载、浏览器、协议、业务闭环、实际备份恢复或 24 小时运行证据，因此不计任何 F/L/D 或发布门槛通过。
