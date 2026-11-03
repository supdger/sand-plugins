# SandAdmin demo

`/Users/code/project/sand_demo` 是统一演示和验收环境，不是 SandAdmin 或插件源码。SandAdmin 的唯一权威来源是 `/Users/code/project/sandadmin`；插件源码位于 `/Users/code/project/sand_plugins` 的对应权威目录。

## 操作顺序

1. SandAdmin 发布 tag、release candidate 或明确 commit，并完成零业务插件检查。
2. 在本仓运行 `scripts/sync-sandadmin-demo.sh --dry-run`，审查 SandAdmin 差异和保留路径。
3. 取得本次写 demo 授权后运行 `scripts/sync-sandadmin-demo.sh --apply`。
4. 检查 demo 根目录生成的 `sandadmin-host.lock`；正式验收要求 `source_state=clean`。
5. 从各插件权威目录构建发布包，在 demo 宿主或其可丢弃副本中完成安装、权限、业务链、升级和卸载验收。

脚本保护 `.env`、依赖、runtime、已安装业务插件目录，以及 demo 自有覆盖层：`server/config/plugin/tinywan/jwt/` 保存宿主独立密钥，不从 SandAdmin 工作树复制，也不因源文件缺失而删除；`server/plugin/sandadmin/config/saithink.php` 是历史配置键兼容层；`server/plugin/sandpackage/config/failed_upgrade_profiles.php` 是 SandIAM 失败升级验收画像。后者虽然位于 SandPackage 配置目录，但内容绑定 SandIAM 版本与迁移，不得回收到零业务插件的 SandAdmin 权威源。脚本只同步宿主 `server/` 与 `sandadmin-artd/` 核心；插件仍从 `sand-iam/`、`sand-ai/`、`sandworkflow/` 单向进入 demo 宿主。

同步结束时脚本检查 demo JWT 运行配置（`available`、`missing`、`unreadable` 或 `placeholder`），不输出密钥，也不把运行配置状态写入 `sandadmin-host.lock`。`available` 仅表示文件可读且未检测到已知占位符，不能证明 JWT 配置有效或后台可以登录。状态不是 `available` 时脚本以退出码 4 拒绝继续，源码同步不会完成；必须另行取得密钥操作和服务启停授权，在 demo 执行 Tinywan JWT 初始化后重新运行同步。应用启动（SecurityBootstrap 自检）、登录与鉴权同样拒绝空密钥、`__JWT_*__` 占位符或短于 32 字节的密钥。SandAdmin revision 和登录运行就绪是两项独立证据。

`--allow-dirty` 只允许本地探索并记录 `dirty-local`，不得进入兼容矩阵、发布或正式 FLOW 分子。同步宿主文件不授权数据库创建/迁移、服务启停、插件安装、提交、推送或部署。

SandPackage 持久数据根 `server/storage/sandpackage/` 与旧 `server/runtime/sandpackage/` 均由消费宿主独立维护。同步以 `server/` 为相对根，通过 `/storage/sandpackage/***` 和原有 `runtime/` 排除规则保护：既不从源宿主复制，也不因 `--delete-after` 删除目标已有数据；其他 `storage/` 内容仍正常同步。该保护不执行旧记录迁移，迁移须等待宿主冻结接口与 revision 后按单独授权执行。
本机 openrsync（rsync 2.6.9 兼容）在源 `storage/` 父目录缺失时使用 delete-delay 仍会将已排除的持久根列入递归删除，而 delete-after 已经独立临时夹具和真实宿主 dry-run 验证根及子项保留、其他 storage 内容照常同步。

复用验收工具通过 `scripts/sandpackage-storage-root.php` 调用被验宿主的 `PluginStorage::root()`，不自行选择新旧目录，不启动宿主或执行迁移。宿主尚未提供该接口或双根冲突时拒绝继续，不回退到硬编码旧路径。版本绑定的历史批次脚本和故障证据保留原状，不直接用于新 revision 验收。当前消费预检见 [4bf5b23 消费记录](host-requests/HOST-202609-4bf5b23-consumer-preflight.md)。

## 插件候选进入 demo

插件源码只在各自权威目录中修改。使用 `scripts/sync-plugin-to-demo.sh <plugin> --dry-run` 审查导出差异；脚本只更新 `/Users/code/project/sand_demo/plugins/<plugin>` 发布副本，不会改已安装 runtime、数据库或服务。

导出前必须实际取得 Git 状态；Git 命令失败时脚本以状态 6 关闭，不能把空输出解释成 `clean`。脚本会从 `PATH` 选择第一个能执行 `--version` 的绝对 Git 程序，因此系统 Git 被本机工具链许可阻断时可以使用已配置的后备 Git；也可用绝对路径 `SAND_GIT_BIN` 固定本次 Git，指定程序不可用时同样失败关闭。`--allow-dirty` 只改变已确认 dirty 状态的探索许可，不能绕过 Git 状态读取失败。

SandPackage 虽以 Webman 插件形态存在，但属于 SandAdmin 内置基础设施，其权威源码、修复、版本和发布均由 SandAdmin 维护。`sand_plugins` 不实现或发布 SandPackage，只从 clean SandAdmin revision 拉取宿主内置版本，并用它执行 Sand 插件的正式安装和升级。源码导出一致不等于安装、升级、卸载或业务验收通过。

## 防循环诊断

问题复现后先冻结宿主 revision、插件 revision、候选包 SHA、数据库/生命周期状态和复现证据。每轮只改变宿主或插件之一；禁止在 demo 中直接修复，也禁止由插件任务直接修改 SandAdmin。

单插件或插件特有组合的复现默认在插件权威源码解决。只有零插件基线、中立扩展或多个独立插件复现的通用缺陷，才按 [HOST/COMPAT 请求模板](host-requests/TEMPLATE.md)交由 SandAdmin；宿主方案必须说明如何尽量保持 SaiAdmin 上游结构和升级路径，优先公开扩展契约、适配器或兼容层。

第 1、2 轮单变量诊断仍不能缩小范围时，第 3 轮使用 `gpt-6-astra/high` 做有界根因审查，并记录请求配置、实际任务模型证据和新增信息。第 3 轮仍无根因或下一项可区分实验时标记 `BLOCKED_ROOT_CAUSE_UNKNOWN` 并停止修改，不自动开始第 4 轮。
