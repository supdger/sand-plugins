# SandAdmin demo

统一演示环境位于 `/Users/code/project/sand_demo`，不属于本迁移仓库。SandAdmin 的唯一权威来源是
`supdger/sandadmin`，插件源码分别属于各自独立仓库。演示环境只通过 Composer 和插件 Release
消费已发布版本，不能反向成为 SandAdmin 或插件的源码。

## 准备 SandAdmin

1. 选择已经完成零业务插件检查的 SandAdmin tag、候选版本或明确 commit。
2. 在 `/Users/code/project/sand_demo/server` 从 Packagist 安装明确版本，例如：

   ```bash
   composer require supdger/sandadmin:6.1.5-rc.1 --with-all-dependencies
   ```

3. 检查 `server/composer.lock` 中的版本和 source revision；正式验收只接受已发布版本。
4. 从插件独立仓库取得 Release ZIP，在 demo 或可丢弃副本中完成安装、权限、
   业务链、升级和卸载验收。

`sand_demo/sandadmin-artd` 是独立前端消费工程，不从 SandAdmin 源码仓库复制。更新 Composer
依赖不授权数据库创建或迁移、服务启停或插件生命周期操作。

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
