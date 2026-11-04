# 更新日志

仅记录本插件可核实的版本变化；上游历史不作为本插件逐版发行记录。

## 1.0.8（修正版，2026-11-03）

- 修正发行元数据的宿主兼容声明：根部与 `plugin/sandworkflow/` 两份 `info.ini` 的 `support` 由 `6.x` 改为 `">=0.1.0"`，`about` 文案同步为 SandAdmin 0.1.x；`config/app.php` 与 `info.ini` 版本号一致为 1.0.8。1.0.7 预览资产内的元数据仍是旧声明。
- `uninstall.sql`（根部与 `plugin/sandworkflow/` 两份）回流权威源：与 1.0.7 预览资产一致的收紧版，即事务包裹并收紧菜单归属与路径边界谓词；该修复此前只随预览资产发布，权威源码未同步。
- 首个包含管理端路由修复的发行包：路由组增加 `/app/sandworkflow` 前缀，`plugin/sandworkflow/app/functions.php` 注册 `plugin\sandworkflow\` PSR-4 自动加载回退（SandPackage 安装不重建宿主 Composer 映射）。
- 移除未使用的 `OrganDialog.deprecated` 备份文件。
- README 与独立 Wiki 的中文“版本更新”入口及版本变化、升级影响说明随本版公开发布。

### 升级影响

- 仅 PostgreSQL，不提供 MySQL 数据原地迁移。首次安装与卸载会删除对应工作流表；先备份，已有数据不要通过重装修复。
- 本版修正发行元数据并补齐随包清理脚本；真实宿主的安装、升级与最小工作流验收范围见 [版本与验收范围](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Versions)。

依据：标签 `sandworkflow-v1.0.8`，公开资产见 [Releases](https://github.com/supdger/sand-workflow/releases)。

## 1.0.7（预览版，2026-09-21）

- 从独立 SandWorkflow 仓库重新构建预览包，包内文档明确 `supdger/sand-workflow` 为唯一权威来源。
- 此 PostgreSQL 工作流包提供流程定义、发布、发起、审批、待办与抄送查询；这些能力是 1.0.7 的现有基线，不表示本次重构包新增所有能力。

### 升级影响

- 仅 PostgreSQL，不提供 MySQL 数据原地迁移。首次安装与卸载会删除对应工作流表；先备份，已有数据不要通过重装修复。
- Release 沿用此前安装与卸载证据；真实升级和完整工作流验收仍待完成。预览包或文档发布不等于目标宿主业务通过。

依据：[公开预览 Release](https://github.com/supdger/sand-workflow/releases/tag/sandworkflow-v1.0.7-preview-ddea7b6)，标签 `sandworkflow-v1.0.7-preview-ddea7b6`（`ddea7b609b55d4f7c9b62c23ea9f1970e6fe2607`）。完整已公开历史见 [Releases](https://github.com/supdger/sand-workflow/releases)。没有可靠依据的中间版本不补写变化或发布日期。
