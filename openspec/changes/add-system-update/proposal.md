## Why
业务插件已有仓库升级，但宿主核心与插件管理器自身仍需手工 Composer 更新和前端发布。管理员需要一个具有预检、可观察执行过程和失败恢复的后台入口。

## What Changes
- SandPackage 增加超级管理员系统更新入口，升级 Sand Core 运行包、匹配前端和 SandPackage；不覆盖用户应用或 Git 宿主骨架。
- 官方 Release 通过 system-update.json 声明固定版本、源码 revision、宿主兼容范围及无数据库/骨架变更契约。
- 独立原生 PHP 执行器在 runtime 内保存任务、锁和备份，自更新时不依赖正在替换的 vendor。
- 自动执行依赖求解、备份、精确版本安装、受管理源码发布、前端构建、静态部署、宿主配置的服务重载、健康检查及可核验恢复。

## Capabilities
### New Capabilities
- `system-update`: 基础运行包的后台更新和失败恢复。
### Modified Capabilities
无。

## Impact
权威源码 sand-package 的后端、管理页面、发布工具与文档。无新增数据库表，不执行数据库迁移；涉及数据库或宿主骨架的版本明确拒绝。实现授权不等于当前宿主执行升级、服务操作、提交或发布授权。
