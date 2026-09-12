# OSS-04 · 首条真实业务链精确授权单

- 日期：2026-09-12
- 状态：待用户授权，未执行任何提交、同步、数据库写入、迁移或服务启停。
- 当前输入：v70 review-only ZIP，635 entries，archive SHA-256
  `6cae3a2f9880ef1a2818d04edc28dde4c65afc69a8f632a4b718b59cfd97cf82`，
  descriptor-excluded payload SHA-256
  `4bbf92897537f663e80c42e5dc31ae54c85df5ad17eb7741760929688d2a7035`。

## 只读预检结果

- 正式 `scripts/sync-plugin-to-demo.sh sand-iam --dry-run` 因 SandIAM 源码未提交而退出 `3`；
  该门禁正确阻止 dirty source 用于正式验收。
- 仅探索的 `--dry-run --allow-dirty` 成功且没有写文件：当前有 206 个文件项、21 个目录元数据项
  和 8 个删除项差异。
- demo 当前 lock 仍指向 clean revision `069a9a19f152f28349cb23f92a598f86ff97234d`，
  authority 与 demo 的 `info.ini` 均为 `0.7.0`；版本号相同不代表载荷相同。

## 请求的一次性授权范围

### A. 范围限定提交（不推送）

允许只提交本 Goal 的 SandIAM 权威源码与记录：

- `sand-iam/**` 当前 164 个状态项（88 modified、68 untracked、8 deleted）；
- `.codex/autopilot/tasks.md`；
- `.codex/autopilot/executions/OSS-00.md`、`OSS-03.md`、`OSS-04-authorization.md`、
  `OSS-05-preflight.md`。

明确排除 `.artifacts/**`、`.codex/autopilot/state.json`、`.cursor/**`、`sand-ai/**`、
`sandworkflow/**`、`sandadmin-demo-host/**` 和 `/Users/code/project/sandadmin/**`。不推送远端。
提交前后重新运行完整 non-PG、PHP lint、包完整性和发布卫生检查，并从 clean SandIAM tree
重建同载荷候选；若载荷摘要变化则停止，不进入 B。

### B. 受控同步 demo

允许仅执行 `scripts/sync-plugin-to-demo.sh sand-iam --apply`，把 A 的 clean SandIAM revision
单向同步到 `sandadmin-demo-host/plugins/sand-iam` 并更新 `demo-plugin-locks/sand-iam.lock`。
同步后立即用无 `--allow-dirty` 的 dry-run 验证 0 差异。禁止直接编辑 demo、禁止反向同步、
禁止修改 SandAdmin。

### C. 既有 demo 数据库的 SandPackage 恢复

允许仅针对现有 demo 数据库和 SandIAM registry 项，按公开状态机执行：只读 `inspect` →
从 A 的 clean 候选 `prepare` → read-only `verify/reverify` → `replace` → `retry` → 只读复核。
`retry` 可以执行候选声明的 `0.6.0 → 0.7.0` `033–038` 更新并写迁移账本、SandIAM
自有菜单/权限。禁止手工 SQL、禁止创建/删除数据库、禁止卸载、禁止改非 `sand_iam_*` 业务表；
任一步候选摘要、状态或允许动作不匹配即停止并保留诊断证据。

### D. demo 运行配置与服务窗口

允许为本次验收注入隔离测试密钥及
`SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED=1`，先运行随包 release/acceptance 配置预检，
再按需重启 demo Webman 与 Vite。只使用固定验收前缀，不记录秘密；完成 C01 后把夹具清理开关
恢复为 `0` 并再次重启/复核。禁止修改生产配置或启动其他宿主。

### E. C01 客户主体/应用/环境真实闭环

允许在现有 demo 数据库创建唯一固定前缀的组织、应用和环境夹具，完成创建、读取、修改、
禁用、禁用后拒绝、恢复、恢复后允许、审计关联及清理；仅清理由本轮清单登记的对象。
不执行后续 C02–C07，不连接外部真实身份源，不发送外部消息。

### F. 官方依赖漏洞公告查询（只读外部元数据）

允许把 `plugin/sand-iam/composer.lock`、`portal/pnpm-lock.yaml`、`sdk/typescript/pnpm-lock.yaml`
中公开包名与锁定版本发送给各生态官方安全公告入口，仅执行 Composer/Packagist 与 npm registry
的只读 audit；不发送源码、私有仓库地址、凭证、环境变量、SBOM 本机路径或业务数据，不安装、
升级或改写依赖。当前 Composer 查询已被安全审批拒绝，未取得本项授权前不改用 OSV、网页搜索
或其他第三方接口绕过。Dart 生态没有等价的当前锁文件官方安全审计入口时只记录缺口，不向非官方
服务上传依赖清单。

### G. PostgreSQL 隔离备份恢复演练

允许从现有 demo 数据库执行只读 `pg_dump --format=custom --no-owner --no-acl` 和
`pg_restore --list`，仅向环境负责人预先创建并确认空置、非生产、与源库指纹不同的隔离数据库执行
`pg_restore --exit-on-error --single-transaction --no-owner --no-acl`。允许为恢复后业务探针临时切换
隔离验收进程配置并启停该隔离进程，写入一条带固定验收 ID 的恢复后审计；验证迁移账本、逻辑状态、
允许/拒绝、撤销不复活、验签、审计连续性及宿主/其他插件无损。禁止 `--create`、`--clean`、手工 SQL、
源库写入、删除数据库或复用生产服务；归档、证据和隔离库在用户另行授权删除前保留。

### H. 密码登录重试与双进程竞争验证

允许仅在 C 已完成、现有 demo SandIAM schema 与 v70 候选一致后，设置
`SAND_IAM_RUN_PG_TESTS=1` 并执行候选内
`plugin/sand-iam/tests/human_auth_login_concurrency_pg_integration_test.php`。该测试用两个独立 PHP
进程同时提交同一错误密码请求；必须证明只占用一次登录限流、只增加一次失败计数、只写一条失败
审计且不落明文身份或密码。只允许创建测试自身随机
前缀的组织、应用、身份、会话和认证记录，并由测试精确清理；禁止创建数据库、修改 schema、启停
服务、复用业务账号或运行其他集成测试。清理不完整、两进程结果不同或任一计数不为一即停止并保留证据。

### I. 幂等操作保留仓储验证

允许仅在 C 已完成、现有 demo SandIAM schema 与 v70 候选一致后，设置
`SAND_IAM_RUN_PG_TESTS=1` 并执行候选内
`plugin/sand-iam/tests/security_operation_retention_pg_integration_test.php`。测试只创建四条带随机
`retention-pg:` actor ref 的 `sand_iam_security_operation` 夹具，证明单批上限、30 天截止、recent
保留和 pending 永不删除，再按同一 actor ref 精确清理。禁止启动 retention worker、删除现有操作
记录、修改 schema、创建数据库或启停服务；任一非夹具记录变化即停止并保留证据。

### J. 认证限流过期状态保留仓储验证

允许仅在 C 已完成、现有 demo SandIAM schema 已执行迁移 038 后，设置
`SAND_IAM_RUN_PG_TESTS=1`、`SAND_IAM_RETENTION_TEST_APPLICATION_ID=<受控既有应用 ID>` 并执行候选内
`plugin/sand-iam/tests/auth_rate_limit_retention_pg_integration_test.php`。测试只在该应用创建三条
`action=retention_fixture` 且主体哈希随机的 `sand_iam_auth_rate_limit` 夹具，证明单批上限、24 小时
截止、recent 保留和过期行物理删除，再按该应用、动作及精确哈希集合清理。禁止启动 retention worker、
删除现有限流状态、修改 schema、创建数据库或启停服务；任一非夹具记录变化即停止并保留证据。

### K. 目录同步出站失败与恢复验证

允许仅在 C 已完成、现有 demo SandIAM schema 与 v70 候选一致后，设置
`SAND_IAM_RUN_PG_TESTS=1`、隔离的 Sync 密钥/引用 pepper 和测试 driver 映射，并执行候选内
`plugin/sand-iam/tests/sync_connector_pg_integration_test.php`。测试只创建自身固定 `t10-sync-*`
前缀的组织、应用、身份、用户组、连接、运行和 outbox 夹具，验证远端部分拒绝、驱动异常、不可解密
载荷均在两次尝试后进入 failed；跨应用、纯入站和 running 并发重试被拒绝；修复后从公开重试操作恢复，
并核对成功/失败运行与 `sync.outbox_retry` 审计。禁止连接真实企业目录、启动 worker、修改 schema、
创建数据库或复用业务账号；夹具清理不完整、出现明文载荷或非夹具记录变化即停止并保留证据。

### L. OIDC 后通道登出死亡投递恢复验证

允许仅在 C 已完成、现有 demo SandIAM schema 与 v70 候选一致后，设置隔离 OIDC issuer、临时
RSA 签名密钥、logout encryption key 和 `SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED=1`，并执行候选内
`plugin/sand-iam/tests/oidc_logout_recovery_pg_integration_test.php`。该测试只在一个外层事务内创建随机
`oidc-recovery-*` 主体、应用、身份、会话、客户端和投递夹具，验证 dead 原记录不变、新 token 的
`typ/aud/sid/jti/exp`、相同 request id 幂等、不同 request id 单后继收敛、pending/跨客户端/未撤销会话/
停用客户端拒绝及审计不泄露秘密，最后整体回滚并确认夹具不存在。禁止启动 logout worker、连接真实 RP、
创建或修改 schema、创建数据库、复用业务账号；既有 managed signing-key 历史不为空、回滚不完整、任一
越界记录或秘密泄露即停止并保留证据。

## 停止条件

- A–L 任一项未获授权，只阻塞该项及后续依赖项；继续不需该授权的检查。
- 同一候选、同一状态和同一错误没有新证据时不重试；最多三轮单变量诊断。
- 本授权即使全部执行，也只可能增加安装恢复和 C01 的当前证据，不自动提升其他 FLOW、
  Casdoor、24 小时、部署或线上验证门槛。
