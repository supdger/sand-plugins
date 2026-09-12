# OSS-04 · 首条真实业务链精确授权单

## 签名链初审 checkpoint（2026-09-12）

签名链初审为 **3P2**；修复后 Astra 复核 **ACCEPT（P0/P1/P2=0）**，helper SHA-256 `78f9…`。临时测试签名仅是非正式测试材料；受控目录、同 UID TOCTOU、真实密钥信任与 Git 独立重建仍需独立证据。正式 signing 未执行，发布 **0/10**、FLOW **28/48**、F/L/D 不变。

- 日期：2026-09-12
- 状态：A′ 已提交为 `61a7f13821980deca8479f9c9e5e872be92cf72a` 且未 push；当前工作树 non-clean，有 22 项 tracked changes 与 6 个 untracked path roots。v12 内容自洽，但旧 verifier 对 `release/unsigned`、clean committed source/hygiene PASS 的自报已被独立 Astra 推翻：它只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件；v12 仅为历史快照，正式来源 **REJECT**，不能作升级包。B′/C′/D′ 未执行，G 未授权；历史 0.7.0 v71 B apply REJECT 不构成当前同步通过。
- v10 是历史 review-only artifact，不是当前候选。最终 ZIP、哈希和签名尚未形成；内部执行记录按 payload policy 排除，且任何候选均不计 FLOW 或称正式发布。

以下 v70/v71 均为历史 0.7.0 材料，不能作为 0.7.1 候选或证据。旧 v70 review-only ZIP（635 entries，archive SHA-256
  `6cae3a2f9880ef1a2818d04edc28dde4c65afc69a8f632a4b718b59cfd97cf82`，
  descriptor-excluded payload SHA-256
  `4bbf92897537f663e80c42e5dc31ae54c85df5ad17eb7741760929688d2a7035`）仅作历史基线。

当前权威源码静态状态：A′ 已提交为 `61a7f138…`，当前工作树仍 non-clean（22 项 tracked changes、6 个 untracked path roots）；through037 精确 preflight→原038，迁移 `001–038` 不变、无039，normal 包不含旧 recovery descriptor。safe 114、PHP lint 507、package 24 为静态证据；v12 的旧 verifier 自报已被独立推翻，正式来源 REJECT。最终 commit/tree/ZIP pending。历史 `fa344cd` 与 tree `6465…` 是 0.7.0 基线，不是当前候选。当前可复核 demo registry 为健康 `0.7.0`（`state=1`、`stage=completed`）；只读 DB 的 86 tables、ledger 38 rows、max revision 37、无038只限观察窗口，未执行数据库/registry 写入。

## 只读预检结果

- 正式 `scripts/sync-plugin-to-demo.sh sand-iam --dry-run` 因 SandIAM 源码未提交而退出 `3`；
  该门禁正确阻止 dirty source 用于正式验收。
- 仅探索的 `--dry-run --allow-dirty` 成功且没有写文件：当前有 206 个文件项、21 个目录元数据项
  和 8 个删除项差异。
- demo 当前 lock 仍指向 clean revision `069a9a19f152f28349cb23f92a598f86ff97234d`；旧
  authority 与 demo 的 `info.ini` 均为 `0.7.0`，仅说明历史基线，版本号相同不代表载荷相同。

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

## 2026-09-12 执行记录

- 以下为执行者的历史记录；不替代本次独立复核结论。
- A（历史执行记录）：仅精确范围的 175 个实际文件提交为
  `fa344cdfadfc7adb6306d2507809e4a493a0e319`，`HEAD:sand-iam` 为
  tree `6465a3b28ad249aa0da625e4fe4f866bda0741b6`（不是 commit），没有 push。提交前后 non-PG/contract
  **114/114**、PHP lint **507/507**、包完整性 **24/24** 均通过；发布卫生仍
  **11/14**，只差 LICENSE、私密漏洞报告入口和 DCO/CLA 三项用户决策。
- 历史 0.7.0 clean-source 重建生成 v71；它不能作为 0.7.1 候选、来源或升级包证据。archive
  `6cae3a2f9880ef1a2818d04edc28dde4c65afc69a8f632a4b718b59cfd97cf82`、payload
  `4bbf92897537f663e80c42e5dc31ae54c85df5ad17eb7741760929688d2a7035`、descriptor
  `a34677bbe7867aa9cd9896bbcc57c81dcbde77fd7ebef649cc7527dda8c403d5`、source snapshot
  `c48cbb78e68a6727e7a211f772bf5d1f8b0f96a66ac5508082929b50ec4832cb`，635 entries，
  repeat bit-identical；与 v70 完全一致。
- B（历史执行记录，当前验收已否决）：`scripts/sync-plugin-to-demo.sh sand-iam --apply` 已执行；
  同步后 normal `--dry-run` 曾输出 0 差异，但独立复核发现该脚本默认按 rsync size+mtime 判断，
  对 recovery descriptor 产生假阴性。权威源两份 descriptor SHA 为
  `a34677bbe7867aa9cd9896bbcc57c81dcbde77fd7ebef649cc7527dda8c403d5`，demo 两份为
  `e70418…`；两边 size 均为 `28046`、mtime 均为 epoch。因此 B apply 的独立验收结论为
  **REJECT**；需先修复 checksum 校验，再另行取得 apply 授权。
- C：`php webman sandpackage:recover inspect sand-iam` 只读返回
  `该插件不是可检查的数据库升级失败状态`。运行 registry 当前为
  `state=1` / `stage=completed`；随后显式 `BEGIN READ ONLY` 的 PostgreSQL 核对返回
  `database=sandadmin`、SandIAM 表 86、迁移账本 38 行、`max_revision=37`、
  `revision_38_rows=0`（无 038），并已 rollback。不存在可安全继续的
  `prepare → verify → replace → retry` 路径，未执行任何数据库或 registry 写入。
- F（历史执行记录，独立结论 PARTIAL）：Composer 2.9.5 经 Packagist 官方公告查询为 0；portal 锁文件 28 个依赖、
  TypeScript SDK 锁文件 1 个依赖经 `registry.npmjs.org` 审计，info/low/moderate/high/critical
  均为 0。没有 install/update 或 lock 改写。
- 防循环：任务列表中旧 SandIAM 任务均为 `notLoaded`；自动化配置中无
  SandIAM、sand_plugins 或旧任务 ID 匹配，未发现会继续唤醒的旧循环。

## 2026-09-12 A′ 后独立复核回写

- A′ 已按授权提交为 `61a7f13821980deca8479f9c9e5e872be92cf72a`，未 push。独立范围复核为 **ACCEPT**：父提交为 `fa344cdfadfc7adb6306d2507809e4a493a0e319`，diff-tree 共 43 files（A=4、M=39、D=0），全部在白名单；无删除、migration、Vue/TS 或白名单外路径。
- v12 archive 的内容自洽，但旧 verifier 对 manifest/validation 自报 `release/unsigned`、clean committed source/hygiene PASS 已被独立 Astra 推翻：它只看 tracked dirty 状态，漏掉 62 个 ignored vendor/dist 来源文件。v12 仅是历史快照，正式来源 **REJECT**，不能作为升级包；final commit/tree/ZIP pending。主树 integrity **25/26**，唯一失败为 clean/tracked。
- Composer 58 与 TypeScript `dist` 4 已完成双隔离重建及锁校验。两次测试选择器偏差已作为限制保留；只读 DB 复核仅能证明复核时间窗口内未见可见写入，不能证明窗口外或此前无写入：86 tables、ledger 38 rows、max revision 37、revision 038 rows 0、runtime 仍 0.7.0。
- B′/C′/D′ 尚未执行；G 仍未授权。发布门槛保持 **0/10**，FLOW 保持 **28/48**，不增加业务链、生命周期或部署分子。本回写未执行 sync、数据库/registry/service 操作。

## Endurance v2 checkpoint（不计分）

- v1 审计为 **4P1 + 3P2**；v2 分三批修复并经 Astra 工具最终 **ACCEPT（P0/P1/P2=0）**。这是离线 contract/tool 结构结果，不是实际长跑、外部互操作或发布 ready。
- 协议边界为协作式可信环境：哈希链非签名、身份认证或防伪；Git 未独立重建，collector/probe 真实性依赖受控环境、独立保管和可信对端。所有 fixture 均非 86400 秒，真实 24 小时仍未开始（**0**）；external validators fixture 修复的离线结构 ACCEPT 不代表 ready。
- 发布门槛、FLOW 和 F/L/D 维持 **0/10、28/48、不变**；本回写未执行 sync、数据库/registry/service 操作。

## 2026-09-12 本地提交回写（未 push）

- 本次授权范围已拆为三个已提交的、可复核的本地提交：宿主恢复契约 `6e190953dc260f32e7428e751c3c6318dc9fcd8d`、0.7.1 生命周期 `a7edcebb37ea06b2da3dc445d5b7307d3111a7ab`、外部验收证据 `1aba9b444ae8ec69972faa2c1e6fe8e6ebc32d48`。
- `a7edceb` 的非 vendor 路径 `git diff --cached --check` 为零；完整检查仅报 18 个原样纳入的第三方 vendor 文件的既有 whitespace，未改写其字节。此事实不构成整体 whitespace 检查通过声明。
- 以下 7 个备份恢复文件仍未提交，且不计入任何 G/备份恢复验收：`docs/user-guide/backup-and-restore.md`、`plugin/sand-iam/tests/backup_recovery_evidence_non_pg_test.php`、`plugin/sand-iam/tests/backup_restore_command_guard_non_pg_test.php`、`plugin/sand-iam/tests/external_acceptance_template_non_pg_test.php`、`tools/validate-backup-recovery.php`、`tools/prepare-external-acceptance.php`、`tools/README.md`。B′/C′/D′、G 和所有正式 FLOW 仍未执行。
