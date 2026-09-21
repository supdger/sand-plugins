# SandAdmin demo

统一演示环境位于 `/Users/code/project/sand_demo`，不属于本迁移仓库。SandAdmin 的唯一权威来源是
`supdger/sandadmin`，插件源码分别属于各自独立仓库。演示环境只从 SandAdmin 的 clean revision
单向同步完整前后端源码，并通过插件 Release 消费业务插件，不能反向成为 SandAdmin 或插件的源码。

## 准备 SandAdmin

1. 选择已经完成零业务插件检查的 SandAdmin tag、候选版本或明确 commit。
2. 将该 revision 的 `server/` 与 `sandadmin-artd/` 单向同步到
   `/Users/code/project/sand_demo`，保护 demo 的环境、数据库/缓存配置、依赖、运行数据、
   JWT 配置、候选目录和业务插件副本。
3. 检查 `sandadmin-host.lock` 中的 source revision 和 clean 状态；正式验收只接受经过
   源码与构建验证的 clean revision。
4. 从插件独立仓库取得 Release ZIP，在 demo 或可丢弃副本中完成安装、权限、
   业务链、升级和卸载验收。

在 demo 中分别对 `server/composer.lock` 和 `sandadmin-artd/pnpm-lock.yaml` 安装依赖并做
命令发现、类型检查或生产构建。源码同步和依赖安装不授权数据库创建或迁移、服务启停或插件生命周期操作。

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
