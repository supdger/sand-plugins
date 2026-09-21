# SandAdmin demo

`sandadmin-demo` 是这个迁移仓库保留的本地演示环境。SandAdmin 的唯一权威来源是
`supdger/sandadmin`，插件源码分别属于各自独立仓库。演示环境只消费已提交版本，
不能反向成为 SandAdmin 或插件的源码。

## 准备 SandAdmin

1. 选择已经完成零业务插件检查的 SandAdmin tag、候选版本或明确 commit。
2. 运行 `scripts/sync-sandadmin-demo.sh --dry-run`，审查差异和保留路径。
3. 取得本次写演示环境授权后运行 `scripts/sync-sandadmin-demo.sh --apply`。
4. 检查生成的 `sandadmin-demo.lock`；正式验收要求 `source_state=clean`。
5. 从插件独立仓库取得 Release ZIP，在 demo 或可丢弃副本中完成安装、权限、
   业务链、升级和卸载验收。

脚本保护 `.env`、依赖、runtime、已安装插件和 demo 自有覆盖层。它只准备
SandAdmin 的 `server/` 与 `sandadmin-artd/`；插件通过插件市场或明确的迁移期
同步进入 demo。

`--allow-dirty` 只用于本地探索并记录 `dirty-local`，不能进入兼容矩阵、发布或
正式验收。准备 demo 文件不授权数据库创建或迁移、服务启停、插件安装、提交、
推送或部署。

## 插件候选

插件源码只在 `supdger/sand-ai`、`supdger/sand-iam` 和
`supdger/sand-workflow` 中修改。发布包通过 SandAdmin 插件市场进入 demo；
本仓不再提供源码目录同步脚本。

正式安装和升级仍通过 SandPackage 执行。文件一致不等于安装、升级、卸载或
业务验收通过。

## 问题归属

复现问题时记录 SandAdmin revision、插件 revision、候选包 SHA、数据库和生命周期
状态。每轮只改变 SandAdmin 或插件之一；不要在 demo 中直接修复。

单插件问题回到对应插件仓库。只有零插件基线、中立扩展或多个独立插件均可复现的
通用缺陷，才按 [SandAdmin 请求模板](sandadmin-requests/TEMPLATE.md)交给
SandAdmin。
