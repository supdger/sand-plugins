## 1. 建立 sand-core

- [x] 1.1 创建 `sand-core/server/`、`sand-core/sandadmin-artd/` 与 Composer 元数据，并通过 `composer validate --strict` 和归档排除检查
- [x] 1.2 从锁定的 SandAdmin 基线复制核心后端与核心前端，按归一化路径逐文件校验 SHA-256
- [x] 1.3 配置当前运行时命名空间并实现 autoload/config 探针，验证核心类和 Webman 配置载荷可发现
- [x] 1.4 实现前端源码发布工具、版本和基线清单，测试首次发布、幂等发布及未知修改拒绝

## 2. 建立 sand-package

- [x] 2.1 创建 `sand-package/server/`、`sand-package/sandadmin-artd/` 与 Composer 元数据，并通过 `composer validate --strict` 和归档排除检查
- [x] 2.2 从锁定基线复制安装器后端、兼容层、测试和专属前端，按归一化路径逐文件校验 SHA-256
- [x] 2.3 配置运行时命名空间与包级探针，运行现有非数据库契约、状态机、仓库和恢复测试
- [x] 2.4 用隔离 Composer 安装探针证明安装/更新不创建数据库、不执行迁移、不启停服务

## 3. 更新权威入口与交接

- [x] 3.1 更新 `AGENTS.md` 与 `README.md` 的基础包所有权和目录入口，并扫描确认旧“SandPackage 仅由 SandAdmin 内置维护”说明已消除
- [x] 3.2 生成两个包的可发布归档并检查无 `node_modules`、缓存、密钥、本地配置或其他工作树文件
- [x] 3.3 记录给 SandAdmin 宿主 change 的 path repository、版本、校验和和回滚交接，不执行提交、推送或发布

## 4. SandAI issue #21 的 SandPackage 候选交付修复（2026-10-01）

- [x] 4.1 核对正式 `0.1.9`、原始 issue 和已有权威源码候选，固定改动前摘要，区分正式版本缺少能力与本地候选状态
- [x] 4.2 保留已有 host-payload 生命周期，移植正式版本的 Composer 前端自动发布及 Windows 路径修复，补齐受影响夹具和候选包加载检查
- [x] 4.3 完成 host-payload 录制式生命周期、普通 ZIP 路径预检、版本拒绝、Composer 发布和包构建回归，记录验证层次
- [ ] 4.4 固定候选后由未参与实现的独立上下文核对数据完整性与正式基线回退风险；正式发布及目标宿主验收另行授权

本轮证据：

- 正式 tag `0.1.9` 对应 `3d5a25363ff75298b7e2fa4689984cb8ac89a287`，只读下载源码树确认无 `HostPayload*` / `ExistingSchema*`。原需求为 [SandAdmin #21](https://github.com/supdger/sandadmin/issues/21)，[最新复核评论](https://github.com/supdger/sandadmin/issues/21#issuecomment-5925544177)；[#20](https://github.com/supdger/sandadmin/issues/20) 的宿主核心已有数据库接入流程另属 SandAdmin。本轮不添加 host-payload 与 existing-schema 的组合重接。
- 进入时 `sand-package/` 整目录未跟踪；119 个既有文件的改动前 SHA-256 在 `/private/tmp/sandpackage-issue21-baseline.json`。只做正式基线行为的最小整合，保留既有候选能力与其他工作树改动。
- 首轮 9/11 检查通过，2 个录制式生命周期夹具借用旧消费端 autoload 时缺新类；改用候选命名空间后揭示夹具缺少当前兼容声明和已存在表只读查询响应。修正夹具后，本轮 `HostPayload*Test.php`（10 项）、fresh recovery、两项 native path、两项 compatibility、Composer frontend lifecycle、repository package build、显式 publisher、package probe 共 **19/19** 通过，耗时 5.55 秒；记录式 SQL 由 fake connection 接收，没有连接真实数据库。
- `composer validate --strict --no-check-publish --no-interaction sand-package/composer.json` 通过；隔离候选副本生成 Composer PSR-4 后，使用生成的 autoload（没有测试 fallback loader）通过 package probe，并核对关键类实际来自候选副本。这里只验证加载映射，不声称已完成正式依赖下载安装；无依赖安装的 `dump-autoload` 缺 `InstalledVersions.php`，其 publisher 探针产生 warning，因此发布行为证据使用不依赖该临时残缺 Composer runtime 的独立 hook fixture。hook fixture验证缺核心基线拒绝、完整前后端发布、幂等更新、拒绝覆盖用户修改，不操作数据库或服务。
- PHP 语法 **97/97** 通过；本轮没有修改管理端产品源码，因此不将 backend/录制证据当作页面验收。native path 测试在 macOS 实跑，不代表 Windows 实机、UNC 或 Windows CI 已通过。
- 尚未提交、推送、tag、Release、宿主依赖更新、安装或部署；没有执行真实 SQL、数据库迁移、建库、服务启停或宿主同步。既有 schema 重接和 SandAI 真实安装仍须锁定正式候选及现有 `sandai` 数据库另行验收。

独立审查返工：

- 首轮固定候选在 Windows 上将原生 `getPathname()` 的反斜线相对键直接与 ZIP 清单或恢复备份清单的正斜线键比较，导致合法 host-payload 上传及备份检查失败。仅统一两处清单键生成：`HostPayloadPlan::relativePath()` 在 Windows 规范分隔符，原生路径继续用于读文件与摘要；POSIX 含字面反斜线文件明确拒绝，不把它改解释为子目录。驱动器、混合分隔符、UNC 和备份键使用合成 Windows 输入验证，POSIX 字面反斜线文件使用真实临时文件验证，不据此声称 Windows 实机通过。
- 新增公开路径的已有 schema 录制回归：inspect→attach、身份与候选变更拒绝、过期确认拒绝、普通安装拒绝已有表、附着后禁止卸载/自动清理、失败后确认继续、升级候选保留保护标记。fake connection 仅允许 SELECT，任何 exec 都失败并计数；成功与恢复流程均为零 exec，不连接真实 PostgreSQL。
- 只读核对远端 tag，目前最高正式版本仍为 `0.1.9`；下一修复发布可考虑 `0.1.10`。本轮仅写 `Unreleased` 发布准备，不创建版本、tag、Release，也不更新宿主锁文件。正式发布前须统一包运行版本、锁定提交和资产摘要，完成独立审查及授权的目标宿主验收。

Windows 实机返工（v3，待原入口复测）：

- Windows Agent 通过 SSH 在 `DESKTOP-F1A13NL` 的原生 PHP 8.4.25/WINNT 执行固定 v2，复现正式路径预检、已有 schema 和 host-payload 生命周期的失败。原始日志证明不是盘符丢失：`installDir` / `appDir` 以 `\` 结尾，原 `rtrim(..., '/')` 后拼 `/` 产生 `\/`，规范化后出现重复分隔符。保持绝对路径解析和内部重复分隔符拒绝；仅在目录拼接边界按平台去尾并保留根，避免生成非法路径。
- 同族 InstallLogic 的登记读取、生命周期、恢复，以及 HostPayload 的 journal、ownership、candidate、backup 目录使用同一去尾/拼接入口；增加 Windows 驱动器根、UNC share 根、单一尾分隔符、POSIX 字面反斜线及驱动器相对/重复分隔符拒绝的合成回归。
- Windows 原生 NTFS 探针同一文件 `rb` 句柄 `fsync=false`，`r+b` 和 `wb` 句柄 `fsync=true`，`fflush` 均为 true。旧候选复制完成后的同步改用 `r+b`，不截断或改写文件，保留 `fsync=false` 时拒绝恢复的检查。证据原始路径：`/private/tmp/sand-windows-issue21-5-20261001/v2/evidence/native-probe.log`。
- 修复后本地受影响 **15/15** 回归通过，耗时 2.28 秒；PHP 语法 **99/99** 通过。记录 `/private/tmp/sandpackage-issue21-windows-repair-tests.log`。本段尚不主张 Windows 复测通过；须由原 Windows Agent 核对 v3 增量摘要后重跑原入口，并由独立上下文核对安全边界。

Windows v3 剩余失败的有界独立诊断与 v4：

- v3 原生复测 10/12 通过，确认去尾拼接与可写同步已奏效，且真实候选 ZIP 在 native/mixed 路径下均通过上传预检后停在数据库边界。两项生命周期未通过，不能把路径预检结果当作完整 Windows 安装验收。
- 第三轮由独立 Astra/high 核对原始日志：构造器 `appDir` 尾为 `DIRECTORY_SEPARATOR`、上传入口尾为 `/`，产生不同的 source 路径字节，导致完成 attach 后上传 upgrade 时无法读取既有运行文件事务；统一上传入口与构造器的路径产生方式，不放宽 journal 的 source/target 严格校验。
- `Server::installByRelation()` 原以 `strrpos($dest, '/')` 求父目录，漏识别 Windows 原生后缀，Webman 的非递归 `copy_dir` 因父目录缺失失败。改为 `dirname($dest)`，继续递归准备父目录和传播错误，不修改宿主 vendor helper。
- 原生 `HostPayloadRuntimeChangeFilesTest` 进一步复现备份清单混合分隔符：外层 `target-N/` 与当前平台原生 tree key 不一致。只把该外层前缀改为 `DIRECTORY_SEPARATOR`，保留 `FreshInstallRecovery::tree()` 已存在的原生持久化摘要契约；补嵌套 `config/app.php` 升级夹具。
- v4 本地七项相关检查 **7/7** 通过，耗时 1.17 秒，记录 `/private/tmp/sandpackage-issue21-v4-tests.log`。再次固定 v4，等待同一 Windows 原入口复测及新独立上下文审查；本段不主张 v4 已在 Windows 通过。

## 5. SandPackage issue #11 依赖执行器修复（2026-10-04）

- [x] 5.1 在无数据库临时目录实跑现有固定进程 launcher，确认两层环境丢失及 executable 参数重复；核对公开配置无法消费侧修复
- [x] 5.2 由未参与实现的审查者核对 design 中必要性、安全边界和最小变更范围，通过后修改提供方执行器
- [x] 5.3 真实进程验证 Composer/前端任务宿主环境、精确 argv、成功 nonce 完成、非零失败补偿和受影响生命周期守卫
- [x] 5.4 固定源码候选与摘要，独立核对直接相关测试及环境传递安全边界；保留实际宿主、公开包及业务验收缺口

本轮源码实施与验证（固定候选，5.4 独立验收通过）：

- `TerminalRunner.php` 两层环境继承与正确 argv 已修改；仅继承操作系统宿主环境，保留全部权限、状态与恢复守卫。入场 SHA-256 `5262f92aa662e98b39de88307502c5cdb7e3d87dc68b442a76e57dff0b259b8c`，候选 `9127f36f30bf1525c5c0ab7b601544bbd149da4fc312a8d47d36a5f2e1313080`；精确补丁 `/private/tmp/sandpackage-executor-fix.patch`。
- 新增 `TerminalRunnerNativeProcessTest.php`：旧执行器运行该测试 exit 255（Composer real process contract failed）；修后实跑 Composer / npm / pnpm / yarn 精确 argv、宿主 HOME/Composer 配置/代理/CA/npm cache/任意 sentinel、缺失变量无 fallback、PHP `$_ENV` 与请求输入不覆盖宿主值。四个成功使用正确 callback/nonce（Composer restart=true），真实 exit 7 不推进，仅失败补偿；五个进程全部确认 reaped 并释放租约。此测试使用 InstallLogic double，不把它当作真实宿主业务完成证据。
- `php -d sys_temp_dir=/private/tmp` 执行 TerminalRunnerContractTest、TerminalRunnerOutcomeContractTest、新 NativeProcessTest、legacy `lifecycle_non_db_contract_test.php` 均通过，4/4、耗时 7 秒；三个变更 PHP 文件 lint 3/3。native test 后续增加 `$_ENV` 污染/缺失变量反例，单独复跑通过。原生临时 fixture 与子进程已清理；legacy fixture按原测试策略留在 `/private/tmp`，没有连接数据库或启停服务。
- 额外 `UpstreamPostgresLifecycleTest.php` 先因消费 autoload 缺 PluginDependencyPolicy 失败；以仓库外 prepend loader加载权威候选后，旧 neutral ZIP 夹具 `info.ini` 未声明 `support`，当前兼容预检拒绝（ordinary package检查通过后失败），未取得正常生命周期通过证据。未修改此旧夹具或放宽门槛；实际 state 4→1 / 卸载由另一个生命周期任务核验。
- 原始工具输出可在本轮执行记录核查，仓库外复现脚本 `/private/tmp/sandpackage-executor-minimal.php`、回归脚本 `/private/tmp/sandpackage-executor-regression.sh`；固定候选结果摘要 `/private/tmp/sandpackage-executor-results.json`。源码验收交回时尚未宿主消费；后续本轮真实消费见 5.5。尚未提交、推送或发布，不改变 SandAI 8/32、业务 0/4。

独立固定候选验收：`solhigh__provider_review` 实际 gpt-6.1-sol / high / openai，核对原始 Runner `5262f92...9b8c` 精确基线及本轮三文件 SHA 前后无漂移，四项直接回归 4/4 通过（6.822 秒）。已有 argv/cwd/鉴权、进程隔离/nonce、失败补偿/恢复卸载守卫保留。仅执行器源码切片通过，未声明全包、真实宿主或公开包通过。

- [x] 5.5 另一个已授权生命周期任务在锁定 Core 0.2.0 / Package 0.2.1 发行 snapshot、仅应用本轮 Runner 差异的隔离宿主中，实跑标准 Composer hooks 和正常 SSE，验证依赖成功后 state 4→1、正常卸载及临时资源清理；记录实际来源、数据库/HTTP层证据与剩余业务闭环

真实隔离生命周期独立验收（2026-10-04，`solhigh__lifecycle_validation`，实际 gpt-6.1-sol / high / openai）：

- 最新公开 SandAdmin `6637c2fef1a18b27fc1ec14ae4ecd277f2f1a183` 的标准 Composer 安装含正常 hooks，102/102、exit 0，实际锁 Core 0.2.0 / Package 0.2.1。从其正式 Package 0.2.1 创建 87 文件 snapshot，仅替换已独立通过的 Runner `9127f36...13080`，全量前后 SHA 唯一变更路径为该 Runner；使用标准 Composer path package（`symlink=false`）及正常 update/hooks 发布，vendor 全量吻合候选，未直接编辑 vendor 或已安装 Runner。
- 固定 IAM 0.8.4 ZIP `1a3352a6...e9366`、SandAI 0.1.6 ZIP `03021dfb...ded3a1`。IAM 正常安装 state 1；AI 正常安装执行 SQL 后 state 4，官方 authenticated Composer SSE 23.358 秒、`exec-success` / `exec-completed`，由原 nonce 完成回调到 state 1，等待标记和进程 journal 清除。首次官方固定 `composer update` 自然新增 Neuron 4.0.2 并更新 34 个普通依赖，Core / Package 版本和候选保持；不把单包候选消费前的其余 101 包未变证据扩称全链未变。
- 第一次标准卸载 2.245 秒、HTTP/business 200；同固定 ZIP 再次全新安装及正常 SSE 11.196 秒后 state 1，依赖无新变化，health 200。两次安装均为 AI 125 表 / 124 函数 / 120 条迁移，逐版本与 checksum 相同，811/811 受管文件 SHA 等于固定 ZIP。第二次标准卸载 3.762 秒、HTTP/business 200；两次卸载后 AI 表 / 函数 / 关系 / 菜单及 811 受管文件均为零，插件和前端目录不存在；IAM 86 表 / 44 条迁移 / 222 菜单保留，完整 IAM 账本摘要不变，第二次卸载后 Core / IAM 正常 API 200。
- 仅使用 absence 核查后单独创建的临时库 `sand_ai_runner_20261004_01a10449_tmp`。正常 `stop -g` exit 0、native session 22431 终止，master 89832 不存在、exact task WorkerMan 进程零，18911 / 12231 / 18912 无监听；数据库 active 0 / drop 0 / exists 0，owned host / private / composer-home 全不存在。保留脱敏证据和候选，无 Docker 新资源、无共享 `sandai` 或正式宿主改动。
- 原失败 fresh 恢复检查及卸载拒绝属于 state 4 守卫，本轮通过正常依赖完成后标准卸载；未执行 `FreshInstallRecovery` rollback / recover，不声称恢复验收通过。本生命周期子任务未运行 GUI、AI 业务闭环、Provider / Key / grant 操作、SandAI 单元测试 / TypePHP、提交 / 推送 / 公开发行或生产部署；Package 四项直接回归见 5.4。候选通过不代表正式 0.2.1 已修复，SandAI 8/32、业务 0/4 不变。

验收入口：`/private/tmp/sandpackage-runner-lifecycle-20261004-01a10449/evidence/RUNTIME-LIFECYCLE-REPORT.md`；实际阶段日志 `RUNTIME-STAGES-SAFE.json`，两次 SSE `first-AI-COMPOSER-SSE-SAFE.json` / `AI-COMPOSER-SSE-SAFE.json`，数据库现场 `installed016-snapshot.json` / `fresh-reinstalled016-snapshot.json` / `uninstalled1-snapshot.json` / `uninstalled2-snapshot.json`，全量载荷和候选证明 `INSTALLED-PAYLOAD-ACTUAL-SAFE.json` / `FRESH-REINSTALLED-PAYLOAD-LEDGER-SAFE.json` / `UNINSTALL1-PAYLOAD-SAFE.json` / `UNINSTALL2-PAYLOAD-SAFE.json` / `CANDIDATE-EXACT-DIFF-SAFE.json` / `STANDARD-CANDIDATE-CONSUMPTION-SAFE.json`，清理终态 `CLEANUP-ACTUAL-SAFE.json`。`ORIGINAL-AUTHORIZATION-SAFE.json` 保存原用户授权与指代，`FINAL-MODEL-RECORD.txt` 保存实际模型记录。


## 6. SandPackage issues #22 / #23 与 0.2.6（2026-10-10）

- [x] 6.1 已安装依赖按 Webman 的 scoped config 与包内 autoload files 协议激活；保留版本/摘要/路径与 SQL 前目录预检，继续 fresh 恢复核对目录并登记，不重放已提交 SQL。
- [x] 6.2 独立恢复 CLI 从可信 PID 文件核对当前宿主 master、启动身份与 HTTP worker，只向该 master 重载；等待轮换，失败非零退出并保留原 SQL checkpoint。无 POSIX 的独立 CLI 明确拒绝；不把调用者父 PID 当 master。
- [x] 6.3 固定九文件候选经独立 Astra / medium 验收；macOS PHP 8.4.18 依赖 10 场景、原生 CLI 18 项、既有真实 updater 6 项、fresh recording 61 项、兼容 57 项及 35 项发布工具等相关回归通过。Windows native-path CI success；该 CI 不代表 Windows CLI 恢复业务验收。
- [x] 6.4 [PR #24](https://github.com/supdger/sand-package/pull/24) 合并并发布 [0.2.6](https://github.com/supdger/sand-package/releases/tag/0.2.6)，固定 `2795e1b12b046058c1f3f3bdf26486751b23783e`；85 文件升级契约及公开 ZIP / Composer vendor 摘要一致。契约 SHA-256 `0adcd96e1f9a48a74518caadd3bd7c4b9cb77d9b2f4508264544d5600da0d877`。
- [x] 6.5 公共宿主 `a45cb75` 的正常 Composer install（102 包）后按 README require `^0.2.6`，仅 Package 0.2.4→0.2.6，其余 101 包记录不变；正常 hooks 部署 InstallLogic/runtime 与前端 manifest 0.2.6，实际 Composer Server 类指向公开包 compat。公开消费载荷复跑依赖 10 场景及原生 CLI 18 项通过。issues #22/#23 已给出版本、消费命令与证据范围并关闭。

本次未运行真实 PostgreSQL / SandAI 安装业务、Linux / Windows 原生 CLI 或 TypePHP 验收，未修改公开宿主 lock、Wiki 或用户宿主/数据库/服务。权威工作区只合入本任务九文件必要差异；InstallLogic 既有版本比较差异及文档中的其他候选保留。临时源码、补丁、原生/录制证据与消费目录位于 `/private/tmp/sandpackage-issues22-23-20261010/`，不作为发行载荷提交。


## 7. SandPackage issue #25 容量规则与交付（2026-10-10，已发布并验证公开消费）

- [x] 7.1 统一压缩 ZIP 硬限 16 MiB；既有宿主配置仅降低，缺失默认、非法/超硬限失败，归档器不加 CLI/config 分支。
- [x] 7.2 用户可得构包入口、正式上传 controller 与仓库下载入口验收大于旧 5 MiB 的中立完整包；覆盖容量 actual/max、宿主较低门、测量/摘要分离、流式 received bytes 和既有解压安全门，不走内部安装函数绕过。
- [x] 7.3 固定源码独审及必要回归后发布明确补丁版本；公开 ZIP/升级契约、正常 Composer hooks 与真实载荷/autoload 核对，已提交/已公开/已消费分别记录。
- [x] 7.4 只同步受影响 README 与 issue 完工证据，既有 Wiki 容量说明仅准备仓库外草稿、跨仓推送待明确授权；不改宿主 locks、SandAI 宿主/数据库/密钥或共享服务，保留未验平台及真实业务层。

本任务从公开 0.2.6 基线 `2795e1b12b046058c1f3f3bdf26486751b23783e` 开始，已完成 0.2.7 构包、发布和公开消费验证。任务临时材料：`/private/tmp/sandpackage-capacity-20261010/`。

验收：固定源码容量/安全 69 项、仓库分发 90 项、构包 75 项、response 18 项、transport 25 项与 lint 通过；Windows [native-path CI](https://github.com/supdger/sand-package/actions/runs/38019933524) 36 秒成功。PR [#26](https://github.com/supdger/sand-package/pull/26) 已合并，公开 [0.2.7](https://github.com/supdger/sand-package/releases/tag/0.2.7) ref `3482b8c9c8a1908901dbd063e0670d1095ce58d9`，86 载荷契约 SHA `62c2d78ed48d27eb949830e718a2a2c7e2e9cc63854c809ac344b2034ef5f866`。

公开消费：任务专属 clean SandAdmin `a45cb75` 正常 Composer hooks 安装后执行 `composer require supdger/sand-package:^0.2.7`，只更新 Package，其他 101 个依赖锁记录不变；实际 vendor 反射路径、86 vendor/63 部署后端文件摘要及前端源码 manifest 0.2.7 匹配。公开包容量 69、构包 75 项通过，同一大于 5 MiB 中立构包工件经正式上传 controller 与 RepositoryLogic 下载通过；真实专属 HTTP multipart 的 16 MiB 接受、+1 与宿主较低上限拒绝 3/3，公开下载中断只报告 received bytes，一次 callback。临时 listener 和 fixture 根已精确清理，checkout/证据保留。

权威源码：公开提交为 14+2 文件，权威工作区仅 15 文件必要差异精确同步，保留既有本地版本比较、README VCS 与更新日志文档候选；新增测试清理归属保护。Wiki `supdger/sandadmin.wiki/repository-plugin-distribution.md` 只在任务目录准备草稿，未推送。未操作既有 SandAI 宿主、HTTP 配置、业务数据库、密钥或共享服务；SQL 使用录制连接，公开消费与源码验证不代表真实业务 PostgreSQL 或 SandAI 安装闭环。原始证据：`/private/tmp/sandpackage-capacity-20261010/public027/`、`authority-task-applied.diff` 和 `authority-after-sha.json`。

Issue [#25 完工说明](https://github.com/supdger/sand-package/issues/25#issuecomment-6093301616) 已公开并回读一致，issue 由 PR 自动关闭；该状态不代表宿主升级或业务验收完成。

本地原缺少 `PluginVersion.php` 与 `RepositoryUpgradeVersionParityTest.php`；不将公开既有版本比较功能扩大同步，撤回本次新增的本地 Parity 测试副本。公开 PR 的两项 config fixture 修正及 Windows CI 结果保留；本地只同步适用差异，未声称本地 Parity 已运行。


## 8. SandPackage SQL 前宿主升级恢复（2026-10-11，0.2.8 已公开消费验证）

- [x] 8.1 修复新候选在 SQL 尚未开始、创建新文件事务前遇到未登记文件冲突时，历史 complete change 记录使正式恢复 inspect/rollback 不可用的问题；保留冲突文件、旧 owned 与未开始 SQL 的事实，不把旧记录冒认为当前候选事务。
- [x] 8.2 以中立录制连接和独立临时文件现场复现旧失败，验证正式 inspect-host-upgrade / restore-host-upgrade 入口、候选绑定和恢复结果；篡改、其他候选及已提交 SQL 分支保持安全拒绝，不执行真实 PostgreSQL 或操作消费宿主。
- [x] 8.3 固定补丁与直接必要回归独审通过后准备下一补丁版本、真实 index/precommit 审查、feature PR/CI 合并及干净 ref 发行契约；公开 Release 与正常 Composer payload/实际 autoload 消费分别验收。
- [x] 8.4 README/CHANGELOG 仅更新受影响恢复说明，既有 Wiki 仅仓库外草稿；最后精确同步权威源码并保留已有候选差异，不改宿主配置、数据库、密钥或共享服务。

当前基线：公开 0.2.7 `3482b8c9c8a1908901dbd063e0670d1095ce58d9`；Issue [#27](https://github.com/supdger/sand-package/issues/27) 复用本切片。第二轮固定源码与 0.2.8 完整 9 文件候选已通过不同上下文 Astra 独审，正式恢复 CLI 96 项录制检查、InstallRecording / SQLOutcome 与 lint 通过；缺失/矛盾/错误数据库证明在动作和写入前拒绝，候选/宿主树不变、生命周期 SQL 写入为零。此前第一轮证明门缺口已补修，未绕过审查。

提交 `de48df9e49866c5df54ed446f989b53b50473401` 已真实 index review/precommit 验证；PR [#28](https://github.com/supdger/sand-package/pull/28) 合并到 clean ref `c97266d8a6312117d132b7cf74682614c729c8b1`。Windows [native-path CI](https://github.com/supdger/sand-package/actions/runs/38068056570) 35 秒成功。86 载荷发行契约经不同上下文 Astra 从 clean ref 独立重建、逐文件 hash 和生产 validateContract 验收通过；公开 [0.2.8](https://github.com/supdger/sand-package/releases/tag/0.2.8) 与 tag ref 一致，公开附件 SHA `529f8d09bb37649116a3cc1ea654e2e86c62a3483b41b44ad6b744193e4dd934` 下载核对通过。任务证据：`/private/tmp/sandpackage-presql-recovery-20261011/`。原消费方脱敏材料仅用于复现需求，不代表本任务操作或验收其真实数据库。Wiki 对应页仅仓库外草稿，未推送。

公开消费：本任务 clean SandAdmin `a45cb75` 正常 hooks 安装后，经正常 Packagist 精确安装 0.2.7，再执行 `composer require supdger/sand-package:^0.2.8`，只更新 Package，其余 101 个依赖锁记录不变。86 vendor/63 部署后端文件摘要与公开契约匹配，实际 InstallLogic/ChangeFiles/LifecycleJournal/compat Server 反射路径指向本公开 vendor；公开副本的正式 Recover CLI 96 项中立录制回归通过。恢复检查确认缺失原状证明必须拒绝，不能用事后补证代替升级前证明。

权威源码仅 9 文件任务差异精确同步，保留本地版本比较、README VCS、更新日志文档与测试 wrapper 等先有候选；新长期恢复回归所需类均已存在，本地 96 CLI 检查及 7 项 lint 通过。Issue [#27 完工说明](https://github.com/supdger/sand-package/issues/27#issuecomment-6099784180) 已公开，仅包含公开中立结果及正式命令。Wiki `supdger/sandadmin.wiki/package-reference.md` 仅仓库外草稿，未推送；没有操作既有 SandAI 宿主、真实业务 PostgreSQL、密钥或共享服务。Windows 结果仅为 native-path CI，不将录制恢复与公开消费扩称其真实业务恢复闭环。专属测试临时现场由行为测试清理，consumer/checkout 与证据保留。


## 9. SandPackage 未开始升级候选撤回（2026-10-11，0.2.9 已公开消费验证）

- [x] 9.1 以中立上传候选现场复现 issue #29：仅上传后 WAIT_INSTALL、尚无生命周期记录的升级候选无法安全替换或经正式入口撤回；不得以 #27 的 SQL 前失败恢复证据代替本次复现。
- [x] 9.2 沿现有候选备份、归属与确认协议修复直接受影响入口，验证未开始候选安全撤回及替换；候选/备份不符、外部修改及已开始或已提交 SQL 等状态继续拒绝，保留冲突文件，不手改或删 journal。
- [x] 9.3 固定源码与正式中立回归独审通过后，准备补丁元数据、真实 index/precommit、feature PR/CI 合并和干净 ref 发行工件独验；公开 Release 与正常 Composer 0.2.8→新版本仅 Package 更新及实际载荷/入口验证分别记录。
- [x] 9.4 只更新受影响 README/CHANGELOG、既有 OpenSpec 与安全公开 issue 事实，Wiki 仅任务目录草稿；权威源码精确合入并保留先有差异，不操作现有消费宿主、真实数据库、密钥或共享服务。

Issue [#29](https://github.com/supdger/sand-package/issues/29) 已公开，基线 0.2.8 `c97266d8a6312117d132b7cf74682614c729c8b1`；正常公开基线的正式上传/恢复入口已中立复现本次缺口。第一轮 104 项回归后补修 raw INI 指纹及最终日志保存中断窗口，最终 fixed-source-004 的 119 项正式 CLI/API 录制回归、旧 #27 的 96 项回归及 CandidateRollback/Legacy/相关 lint 通过。3 个扩大检查夹具失败在公开 0.2.8 同样失败，保留原缺口，不把无关夹具修复混入本切片。最终源码与 8 文件元数据由不同上下文 Astra 独审通过。

提交 `3564b584189cf54e88f3ffdf8fea2bbda1c37a5a` 通过真实 index review/precommit；[PR #30](https://github.com/supdger/sand-package/pull/30) 合并到 clean ref `b26e782e19847816e90d3a556111dd209b818c35`，Windows [native-path CI](https://github.com/supdger/sand-package/actions/runs/38111168710) 34 秒成功。86 载荷发行契约经独立字节重建、生产 validateContract 与逐文件 SHA 验收；[0.2.9 Release](https://github.com/supdger/sand-package/releases/tag/0.2.9) 已公开，公开附件 SHA `6280308b0b5d3cd8e9b360596c2ec325611dec6d5d811f4626a5f167e2364b69` 与 frozen 一致，Packagist 版本/ref 和源码下载匹配。

专属 clean consumer 正常公开 Composer 从 0.2.8 升至 0.2.9，仅 Package 更新，其他 101 个依赖锁记录逐对象不变；原生 hooks、86 vendor/63 部署后端摘要与 InstallLogic/CandidateRollback/Recover/Controller/compat Server 实际公开 vendor 反射路径核对通过。公开安装代码的正式 CLI/API 119 项中立撤回回归通过；仅临时复制已提交测试，无 private 源码映射，测试副本随后精确清理。数据库只有录制连接，不代表原消费业务宿主恢复完成。

权威源码 8 文件必要差异经三方合并保留先有 dirty；README VCS 和文档候选保留，缺少公版既有 PluginVersion 类的本地新增门沿本地既有 upload 的 version_compare 规则，经过独立预览审查与本地 119 项回归/6 lint。该本地适配不宣称两种比较器全域等价，不扩大同步旧公版 feature；全源 before/after 清单确认仅目标 8 文件改变，最终实际 8 文件 SHA、154 文件全源清单和 applied diff 已由 Astra 不同上下文只读复验通过。Issue [#29 完工说明](https://github.com/supdger/sand-package/issues/29#issuecomment-6105405067) 仅公开产品事实与正式命令；已回读 body 一致，issue 由 PR 自动关闭。

Wiki `supdger/sandadmin.wiki/package-reference.md` 仅任务目录草稿，未推送；未向其他聊天发送消息，未操作既有消费宿主、真实数据库、密钥或共享服务。任务专属测试现场由有界 finally 清理，clone、消费者及证据保留。原始证据：`/private/tmp/sandpackage-upload-only-20261011/`。


## 10. SandPackage 依赖任务中断恢复（2026-10-11，0.2.10 已公开消费验证）

- [x] 10.1 公开 0.2.9 中立正式依赖入口复现 handler 中断、子进程退出但 marker/nonce 留存、新 begin 拒绝且无官方恢复的缺口（17 checks）；不把原消费机制分析当唯一归因或业务验收。
- [x] 10.2 官方 `inspect-dependency` / `recover-dependency` 与超级管理员 POST API 绑定原任务、候选、nonce、进程与持久检查点；仅证明旧进程退出后解除占用并保留依赖等待，不冒称命令成功，不重放依赖或 SQL；活进程、未知身份/权限、漂移与旧确认拒绝。
- [x] 10.3 state=4 延后原重载选择，最后依赖成功持久登记后处理；两个完成/marker 收尾窗口经正式恢复覆盖。fixed002 及集成10文件独审、实际索引 review/真实 precommit、PR32 与32秒 Windows native-path CI、clean merged ref 的87载荷发行契约独验均通过。
- [x] 10.4 0.2.10 Release 与正常 Packagist/Composer 0.2.9→0.2.10 公共消费验证完成；权威10文件精确同步保留原 VCS、版本比较和其他先有差异，实际123/NoPOSIX/8lint通过。Wiki 仅仓库外草稿，未操作原消费宿主、真实数据库、密钥或共享服务。

公开 [PR32](https://github.com/supdger/sand-package/pull/32) merge/ref `fb74978c40021357b79e4ebd5d9afa6ea39e6493`，tree `b72962a9467206263b67b86cad4f773a317f22aa`；[0.2.10 Release](https://github.com/supdger/sand-package/releases/tag/0.2.10) 的 system-update.json SHA256 `90b32ef53c38d10c417cea77362f37822c621e7b8a21f466e18d5471cc8cb725`，87公开源码载荷逐hash一致，host_min0.2.0无上限、schema/skeleton changes均false，Core0.2.4不变。[Windows native-path CI](https://github.com/supdger/sand-package/actions/runs/38128231538) 仅证明路径/包兼容，不证明 POSIX 恢复在 Windows 可用。正常独占 Composer consumer 从0.2.9升级，其他101完整lock记录不变、正常文件发布hook执行，87vendor/64部署plugin载荷hash和5实际类反射来自公开vendor。公开vendor正式123项CLI/API原生进程与录制生命周期检查、缺POSIX安全拒绝通过；录制SQL不是原PG业务恢复。

fixed001 独审发现 marker清理早于持久登记的窗口；fixed002 保留reaped/成功失败收尾证明，`finish-dependency-cleanup`只收尾原记录、保留原状态。故障注入两次unlink权限warning原生保留、相关断言通过，不归因原消费权限故障。最早隔离重试断言失败未保留frames、原因仍未知；后续12/12、debug40/40以及最终123通过不代替该原因证明，Release限项明示。旧历史专属目录缺失事实保留，未修改旧冻结证据。

[issue31公开完工事实](https://github.com/supdger/sand-package/issues/31#issuecomment-6107617529) 不声称原宿主已恢复。原始及集成/消费/同步证据在 `/private/tmp/sandpackage-dependency-interruption-20261011/`；authority-preview before/after 全源清单154→158仅10任务文件变化、task.diff SHA `b762e66a5867e1e2dd5850c7d00a9f703025bd9f4d8b5955bef4110fdd8e1a46`。同步实际10byte与预览、158全源清单和仅10目标差异经Astra最终独立PASS；3个临时复制回归文件已按committed hash精确删除，已观察自有fixture根及进程残留0，源码/证据/consumer保留。Wiki目标supdger/sandadmin.wiki/package-reference.md仅documentation-drafts草稿，未推送。

## 11. 提供方自身升级后的原依赖恢复（2026-10-11，0.2.11 已公开消费终态验证）

- [x] 11.1 中立正式入口复现旧依赖complete entry在sole provider要求变化后拒绝；源码夹具模拟Composer产物与下述真实公开Composer跨升级证据分别记录。
- [x] 11.2 恢复专用证明只允许require.supdger/sand-package单raw token变化，核旧备份/原事务/候选与实际锁/安装来源/代码一致、全当前依赖fresh绑定；不发明旧锁历史、不改变普通apply/restore严格门、不在恢复中写依赖文件/SQL或声明原命令成功。
- [x] 11.3 fixed003与集成8独审通过，provider128/ordinary36/existing123/projection6/foreign2/strict以及真实旧0210engine18检查分别留证；真实index-bound review与preservedprecommit、PR33/Windows CI、clean资产87files独立重建与公开消费门完成。
- [x] 11.4 权威8精确三方同步保留VCS/version_compare及其他先有dirty，实际128/36/6lint通过；受影响README/CHANGELOG/OpenSpec更新，Wiki仅外部草稿。未操作原SandAIhost/真实DB/服务/密钥、不重新开issue31或跨chat发送。

公开 [PR33](https://github.com/supdger/sand-package/pull/33) merge/ref `a0ca7d16332c215a7fee26d195e8993a68ffc7c9`，tree `29eadc40fcb7dfebebd88e34e257c910caee1d87`；[0.2.11 Release](https://github.com/supdger/sand-package/releases/tag/0.2.11) asset SHA `15469489e8c935886c0d1da35fbd688dd6acb4b330cdb3bbdd6f803fa357a0af`，87公开源载荷hash一致，host_min0.2.0无upper、schema/skeleton false、Core0.2.4不变。[Windows native-path CI](https://github.com/supdger/sand-package/actions/runs/38132972994) 成功32秒，仅路径/包兼容，不证明POSIX恢复Windows可用。

关键真实连接验收不是升级后的新夹具：在执行agent独占consumer实际公开0.2.10/同Composer根，经录制PDO正式两代upload/install(false)、实际TerminalRunner owner中断生成原state4/marker/nonce/complete dependencies journal，旧官方inspect先通过。随后正常公开Packagist Composer唯一provider0.2.10→0.2.11，101其他完整lock记录不变且正常hooks执行；同原现场使用actual public vendor官方inspect/recover，provider_requirement_update_verified=true/other_lock_history_verified=false，原journal字节不变、Composer产物不改、录制SQL0、保留state4等待。再明确执行同原waiting task的真实PATH `/opt/homebrew/bin/composer`，实际TerminalRunner原composer.update命令正常35依赖更新与hooks后exec-success/completed，state1/nonce及waitflags/marker清空；这35更新是明确的依赖重执行，不能混称之前唯一provider更新。完成proof核同owned nonce/type、durable SHA及8个完成时当前依赖inputs。普通runtime→dependency retirement helper实际归档原journal字节不变与transition闭合、SQL0；反射helper证明该普通guard解除，不等于原宿主重新上传业务整体验收。最终87vendor/64部署pluginhash与6类actualvendor路径同公版；不使用私有PSR映射替代公开消费。

001风险包括来源投影/指纹与恢复后普通归档堵塞，002transition绑定原closedcheckpoint/journal/candidate/currentprovider、新owned nonce/type与完成输入，补登记后持久证明失败及归档中断；003仅provider transition场景补runtimeSHA，保持普通0.2.10持久checkpoint旧shape和漂移拒绝。最早#31隔离retry缺原诊断、原因仍未知，Release限项保留。证据：`/private/tmp/sandpackage-provider-self-upgrade-20261011/`仅源码模拟/固定回归；`/private/tmp/sandpackage-provider-upgrade-recovery-20261011/`真实集成、发布、consumer原pending至完成、authority预览/实际清单。权威158→159仅8目标变化，无delete、先有dirty保留，task.diff SHA `3d42fe1ed3345fbb60a750637999af38a5e9e6ec09bbf01da88ab51283a709db`；Astra实际authority最终PASS：159全源SHA与预览一致，仅8目标变化、无删失或dirty丢失。清理前42原fixture文件逐hash备份到consumer-final-fixture-evidence，仅6个自有neutral fixture/app/payload/storage/ZIP路径移除；原记录进程0、active marker不存在，consumer lock/vendor与所有证据保留，本slice无vendor test复制。Wiki目标supdger/sandadmin.wiki/package-reference.md仅documentation-drafts草稿，未推送。

## 12. 依赖启动异常的安全诊断（2026-10-11，0.2.12 已公开消费验证）

- [x] 12.1 定位公共0211 TerminalRunner启动catch吞异常的诊断缺口；原消费start/error/completed无Composer的根因仍未知，不把故障注入当原根因复现。
- [x] 12.2 固定阶段、安全SSE分类与随机incidentID，白名单私有日志关联；不公开raw exception/command/env/nonce/trace args，logger失败不改变callback/finally。超级管理员HTTP只读预检仅报告本worker能力和配置提示，锁/写入/子进程/启动仍unknown。
- [x] 12.3 九文件整候选独审、真实索引review与preservedprecommit、PR34/30秒Windows路径CI、clean87payload资产独立重建与公开Release、正常onlyprovider消费及同pending正式续办/实际Runner完成通过。
- [x] 12.4 权威9三方精确同步已实际核159→160仅9targets，无delete、先有dirty保留、7lint通过，Astra实际全160SHA与9target最终独立PASS；Wiki仅外部草稿。54自有fixture原始文件逐hash保全后精确清理7路径及1临时test副本，残留0，consumerlock/vendor/所有checkout与证据保留，不操作原宿主/真实PG/服务/密钥或跨chat。

公开 [PR34](https://github.com/supdger/sand-package/pull/34) merge/ref `8a7bae08b8fbb330b41cf9e78dd2f79370c850c1`，tree `b58212828d00f2c0db51cb223252afeeb6249b47`；[0.2.12 Release](https://github.com/supdger/sand-package/releases/tag/0.2.12) asset SHA `b1f3afc87fb846f62b5c9f72a75cf6cbdac93d2d1f5b2f18f714d9303dced3ea`，87公开源码载荷hash与下载字节一致，host_min0.2.0无upper、schema/skeleton false、Core0.2.4不变。[Windows native-path CI](https://github.com/supdger/sand-package/actions/runs/38139150102)成功30秒，只证明路径/包兼容，不证明POSIX恢复Windows可用。平台首次tag/Release审核认为此前明确版本只0.2.9而拒，命令未执行；直接read_thread重新核原可信人类“处理完发布”不限版本原文后仅一次同入口新证据重审通过，无换入口或间接发布。

真实消费前置是本任务新建的中立场景，不能称原SandAI现场：同独占consumer实际public0211正式upload/install(录制PDO)、begin仅登记自己实际fork/reaped的job，正常Composer精确0.2.11→^0.2.11两次操作均零版本更新、onlyprovider单rawtoken变化、全package记录不变；旧正式inspect/reset生成state4/nojob/pending transition。该前置provider始终0211，未预演不同版本升级。随后正常PackagistComposer唯一provider0211→0212，其他101完整lock package记录不变、正常support Plugin安装hook执行，87vendor/64部署pluginhash与实际类反射均来自公开vendor。保留同一pending原journal/transition，0212官方fresh inspect/recover完成refresh-provider-binding，原字节不动、无新nonce/marker、录制SQL0；明确执行同waiting任务的真实PATH Composer update经actual TerminalRunner，0install/0update/0remove、exec-success/completed、state1/nonce/wait/marker清理。再正式upload3（未install）归档旧journal原字节，transition.original保留、SQL0。实际vendor诊断50故障注入/真实私有Webman日志关联/readonly Request及双logger失败检查通过，其中逻辑stub与原生Request检查不等于原HTTPworker或原宿主业务验收。原启动根因和此前#31无保存frames的retry原因仍unknown。

证据 `/private/tmp/sandpackage-dependency-start-diagnostics-20261011/`：integrated-candidate-001、release0212-freeze、public0211-pending-transition、real-public0212-refresh-result、actual-public-runner-result、authority-preview-manifest与authority-applied-report、cleanup-result。权威taskdiff SHA `8df7f300f71e2c863be8b867eae16df6754fd78244e820f0b16c897558dbb5ee`，VCS/localversion_compare/controller旧docs/CHANGELOG扩展等先有dirty完整保留；新长期TerminalRunnerDiagnosticsTest用途为安全诊断回归，不导入无关PluginVersion。Wiki目标仍supdger/sandadmin.wiki/package-reference.md，只wiki-draft-0212.md草稿，未推送；未向已closed31追加公开消息或跨chat发送。

## 13. 执行身份只读诊断与已安装插件依赖最低版本（2026-10-11，0.2.14已公开消费验证、权威同步最终独审通过）

- [x] 13.1 公开0.2.12已安装依赖的exact bundle version比较误拒较高版本；minimum002仅此兼容门改为有效版本>=声明最低版本，保持bundle ZIP/version/SHA及待安装候选的精确身份。28原生场景/190断言与独立新增leading-zero边界复审通过，普通目标升级及continue-fresh零SQL重放；不自动升级/降级依赖、不新增Policy字段。
- [x] 13.2 只读启动预检增加安全执行身份投影，不写恢复记录、不放宽身份/输入门、不猜原消费者异常原因；identity source003与8文件整个集成候选均已独审通过；当前worker身份提示不证明原HTTP或过去根因。
- [x] 13.3 README本次公开安装要求按人类明确下限规则使用>=0.2.13；实际发行version/ref/资产摘要仍精确身份，不混为兼容上限。整候选审查后才实际index/precommit→PR/CI→clean资产独验→公开正常Composer只更新provider，验actualpublic纯只读字段与本domain最低版本场景，不重旧pending全链。
- [x] 13.4 以当前权威160文件新before为三方同步基准保留VCS/localversion_compare及其他dirty；actual最终SHA独审、OpenSpec分层闭环，Wiki仅/tmp草稿、只本任务自有临时资源收尾，不原宿主/真实DB/服务/密钥/跨chat。

新独立release checkout基线公开0.2.12 `8a7bae08b8fbb330b41cf9e78dd2f79370c850c1`，0.2.13/v0.2.13准备时未占用。minimum002与identity003五源码文件已精确集成，连同三元数据组成8文件候选（integrated-candidate-003.json），whole8独审通过，实际index tree `4617639ff49b85def23ec286ae7430f5bcefc818` 与repository-review记录绑定，真实precommit通过；feature commit `3fa1a173eb2a4586bebcd7321f74901953e50e61` 经[PR35](https://github.com/supdger/sand-package/pull/35)合并到 `f37263d84d121ffffb6aff03f1c2a4f68dfa7d68`，Windows native-path CI通过32秒。clean merged87payload资产独立重建通过并公开0.2.13；标准Packagist版本/ref与下载SHA一致，正常quoted>=0.2.13命令唯一Package更新、其他101完整记录不变、87vendor/64部署hash一致。actualpublicvendor中立管理员Request返回13identity条件全true且输入/进程元数据不改，九个minimum域中立ZIP/录制PDO场景通过；不等于原HTTP或真实DB验收。authority尚未写，最终一次同步等待0.2.14。证据 `/private/tmp/sandpackage-provider-identity-minimum-20261011/`，最低版本固定实现/旧公开误拒与复审证据 `/private/tmp/sandpackage-plugin-minimum-20261011/`。原0.2.12 Release/已验consumer与旧冻结证据保持，不把新的预检投影称为原宿主HTTP诊断成功或原根因已闭合。


- [x] 13.5 写前独审发现PHP version_compare超大数字段误判相等；公开0.2.13 actualvendor正式install(false)独立复现低于最低版本仍执行录制SQL/部署（exit255预期拒绝断言失败）。0.2.14仅新installed minimum域私有helper按有效数字字符串长度/同长strcmp比较三core，不用int/float；同core预发布拒、build忽略，其他旧比较sites和诊断不变。35场景/236断言、whole5独审、真实index/precommit及PR36/37秒Windows路径CI通过；cleanmerge `9a38a0f170afdba7937ee036006d3ab1c2827430` 87payload资产独立重建通过并公开0.2.14。源码/真实public013失败/014资产证据 `/private/tmp/sandpackage-minimum-core-20261011/` 与 `/private/tmp/sandpackage-provider-identity-minimum-20261011/public-large-core-result.json`；最终权威三方160→161仅8目标已实际同步并通过独审，未采用PHP溢出的中间适配。


最终 [PR36](https://github.com/supdger/sand-package/pull/36) / [0.2.14 Release](https://github.com/supdger/sand-package/releases/tag/0.2.14) ref `9a38a0f170afdba7937ee036006d3ab1c2827430`、tree `2643177b114562b8af19946c910fbb8d19ab4d2d`；[Windows native-path CI](https://github.com/supdger/sand-package/actions/runs/38145131274)通过37秒，限路径兼容。公开asset SHA `b993bfe9fa5b97fb39bc5e65a4c62ca32918e0e4a70e448acdcb0f4e85b006e8` 与独立重建/下载一致，host_min0.2.0无upper、schema/skeleton false。标准Packagist准确ref，正常quoted `composer require 'supdger/sand-package:>=0.2.14'` 只Package013→014更新，其他101完整记录不变、正常hooks、87vendor/64部署hash匹配；actualpublicvendor八个超大core/等/高低/preview/build必要场景全部通过，记录PDO而非真实数据库。未重跑旧pending全链或声称全部旧上游依赖约束无upper。

最终权威一次三方同步160→161仅8目标/1长期test，153其余文件逐SHA保留、无delete/额外差异，helper与公共014一致；保留VCS/local旧version_compare/controller docs/CHANGELOG扩展，未导入旧PluginVersion生产类。写前及实际161全SHA均Astra独审PASS，6lint通过，taskdiff SHA `4a7862957987cb14f7e7c9fdb205901cd4b45355263f5139496a5eebffb3350a`。证据014taskroot：release0214-freeze、public-consumer-result、public-minimum-domain.log、authority-applied-report、cleanup-result；精确清理一份本任务vendor临时test副本并核无活跃自有PHP/Composer，测试仅自清受保护的临时前缀，保留消费lock/vendor/所有源码和失败成功证据。Wiki仍独立目标supdger/sandadmin.wiki/package-reference.md的/tmp草稿，未推送或跨chat发送。

原宿主此前只读HTTP诊断由消费方完成：实际worker运行元数据仍0.2.9、磁盘lock/installed为0.2.13，13条件中五个版本/ref/require匹配false、其他八个true；当前运行元数据错配已证实，但历史worker失败原因、master继承机制与原业务3.2仍未验。本供应方未触碰原宿主/DB/服务/密钥，未刷新InstalledVersions或放宽guard。消费方安全证据 RESULT.safe.json SHA `d9afc1f078dac102dafb453cca29b46d29edf045d63d4cb0189f43f13427cf0a` / DIAGNOSIS-HANDOFF.safe.json SHA `8505b9a8c3a198499c658dcf24bd6237b36e3fe1085ea61e0c0bd6b4659e8c7e`，仅记录消费方实测，不混为供应方执行结果。


消费方后续原宿主验收（主控已读核安全回执，本供应方未执行）：在新真人完整恢复授权后，标准restart一次形成fresh master40575；正式单次HTTP worker40585报告runtime/lock/installed均0.2.14/ref `9a38a0f170afdba7937ee036006d3ab1c2827430`，13conditions全true。当前worker陈旧元数据错配经fresh master消除已证实，不能倒推历史worker74222失败原因。证据 `/Users/code/project/sand_ai/tmp/sandai-owned-freshmaster0214-20261011/RESULT.safe.json` SHA `b5147d93aee5669aa81b29e48ddcde4334cbd4c766877ba3e7f819a64b8016e6`。

同一消费方随后官方027 inspect一次仅给出 `refresh-provider-binding`，按当次fingerprint正式recover一次成功，`command_started=false`、`sql_replayed=false`；027保持state4/wait1，nonce/marker均不存在，原journal/登记/环境保留，transition仅按正式协议刷新。安全回执 `BINDING-REFRESH-RESULT.safe.json` SHA `a8a540f7507ce0f52e4bfaf73539a98634b9be0f10e6756886053f3ae8e92050`。这证明当前身份与绑定续办，不证明正式SSE、029升级或原业务3.2完成；这些仍由消费方继续验收，不重新开启本供应方代码、构包、测试或发布。


消费方2026-10-12后续安装回执（仅读核证据，本供应方未执行宿主操作）：同一自有隔离验收宿主已按正式入口逐步029→030→031；本次回执明确030→031、登记版本0.2.31/state1，operation_pending与Composer/npm两wait均空、locks空，生命周期相关记录完成。载荷1370项逐摘要全部匹配、0不符；本次必要SSE一次耗时14.640308秒，official reset0、retry SSE0。SandPackage0.2.14/ref `9a38a0f170afdba7937ee036006d3ab1c2827430` 与Sand Core0.2.4/ref `7ea906743f9c912d03cc9f0fc1e68366c310c393` 的lock/installed前后保持，基础.env保留。

证据 `/Users/code/project/sand_ai/tmp/sandai-owned-upgrade0231-20261012/UPGRADE-RESULT.safe.json` SHA `081cbd274ee09b6358f9aecb76a00086b8ce6f3ba8a3f6919ef0a86894c57655`、`installed-payload.safe.json` SHA `0e1beefb5f91c9f81bf6aee3a417f7f0effa26b165873ffb2238b14f8c66a233`，本次只读核SHA及登记/载荷/依赖完成字段；031仍为本地标准固定候选，不是SandAI公开产品发布。消费方后续3.2最终独审已PASS，带明确历史证据复用及清理限项（如下）；不能将该有界结论记为全平台完成。未重开提供方代码、测试、构包或发布。


消费方最终判定回执已只读核SHA：`/Users/code/project/sand_ai/tmp/sandai-onboarding32-actual-independent-20261011/final031/FINAL-3.2-RESULT.safe.json` SHA `871c1436e063ead54522c67c93a226a11f89cafd267692426f4aa9e0d5a820f7`，decision `PASS_TASK_3_2_WITH_EXPLICIT_HISTORICAL_EVIDENCE_REUSE`。当前031原成功/405同key API与独立UI通过、六次same-wire缓存受控回归通过、最终browser非GET为0；部分历史positive路径当前不可用，明确依据既有SOT复用，不是本轮新付费positive save/verify/publish执行。主控已核22个当前证据path+SHA，本文仅记录最终回执，不代改消费方父任务3.2 checkbox。

cleanup回执SHA `6b6ebc85a8625ae532f8b7c302d1209e39d259cda86d7247de26e63cf45392b2`：stock container恢复、fixture queue为空、自有browser与临时auth logs清理。dummy4 draft因无正式删除入口保留；两份0600恢复证据因平台auto_review拒绝删除而保留，未retry或绕过，不能称all clean。SandAI031仍为本地标准固定候选，非公开产品发布；该3.2有界独审不代表全平台、全部32项/四场景、账单对账或新付费UI正向流程完成。本供应方此次只更新既有OpenSpec记录，未操作原宿主或共享资源。
