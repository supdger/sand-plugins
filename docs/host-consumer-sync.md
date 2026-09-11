# SandAdmin 宿主消费与同步

`sand_plugins` 拥有插件源码和 `sandadmin-demo-host`，但不拥有 SandAdmin 核心。宿主核心的唯一权威来源是 `/Users/code/project/sandadmin`；同步只能由本工作区主动拉取，不能由 SandAdmin hook 主动写入，也不能把 demo 宿主的改动反向同步到 SandAdmin。

## 操作顺序

1. SandAdmin 发布 tag、release candidate 或明确 commit，并完成零业务插件检查。
2. 在本仓运行 `scripts/sync-sandadmin-host.sh --dry-run`，审查宿主核心差异和保留路径。
3. 取得本次写宿主授权后运行 `scripts/sync-sandadmin-host.sh --apply`。
4. 检查生成的 `sandadmin-host.lock`；正式验收要求 `source_state=clean`。
5. 从各插件权威目录构建发布包，在 demo 宿主或其可丢弃副本中完成安装、权限、业务链、升级和卸载验收。

脚本保护 `.env`、依赖、runtime、已安装业务插件目录，以及 demo 自有覆盖层：`server/plugin/sandadmin/config/saithink.php` 是历史配置键兼容层，`server/plugin/sandpackage/config/failed_upgrade_profiles.php` 是 SandIAM 失败升级验收画像。后者虽然位于 SandPackage 配置目录，但内容绑定 SandIAM 版本与迁移，不得回收到零业务插件的 SandAdmin 权威源。脚本只同步宿主 `server/` 与 `sandadmin-artd/` 核心；插件仍从 `sand-iam/`、`sand-ai/`、`sandworkflow/` 单向进入 demo 宿主。

`--allow-dirty` 只允许本地探索并记录 `dirty-local`，不得进入兼容矩阵、发布或正式 FLOW 分子。同步宿主文件不授权数据库创建/迁移、服务启停、插件安装、提交、推送或部署。

## 插件候选进入 demo

插件源码只在 `sand-ai/`、`sand-iam/`、`sandworkflow/` 中修改。使用 `scripts/sync-plugin-to-demo.sh <plugin> --dry-run` 审查导出差异，取得本次写 demo 授权后使用 `--apply`；脚本只更新 `sandadmin-demo-host/plugins/<plugin>` 发布副本并写入 `demo-plugin-locks/<plugin>.lock`，不会改已安装 runtime、数据库或服务。

正式安装和升级仍通过 SandPackage 执行。源码导出一致不等于安装、升级、卸载或业务验收通过。

## 防循环诊断

问题复现后先冻结宿主 revision、插件 revision、候选包 SHA、数据库/生命周期状态和复现证据。每轮只改变宿主或插件之一；禁止在 demo 中直接修复，也禁止由插件任务直接修改 SandAdmin。

疑似宿主问题按 [HOST/COMPAT 请求模板](host-requests/TEMPLATE.md)记录，并交由 SandAdmin 接受。连续三轮有效诊断不能缩小范围时标记 `BLOCKED_ROOT_CAUSE_UNKNOWN` 并停止修改，不能用继续往返改两个仓库代替根因证据。
