# SandWorkflow

SandWorkflow 是 SandAdmin 的 PostgreSQL 工作流插件，提供流程分组、可视化流程定义、发布、发起、待办、已办、抄送和流程数据管理。

> **来源与边界**：这是面向 SandAdmin 的 PostgreSQL 适配包，不是 MySQL 到 PostgreSQL 的原地数据迁移工具。它依赖 SandAdmin 提供的用户、角色、部门、菜单和权限基础能力。它不应被描述为 SandAdmin 以外任何项目的官方发行包；单独分发时必须保留适用的上游、第三方和许可证声明。

> **权威源码**：本目录是 `sand-plugins` 工作区中 SandWorkflow 的唯一权威源码单元。`/Users/code/project/sand_plugins/sandadmin-demo-host` 的安装副本只用于受控同步与真实验收，不能作为并行开发源。`/Users/code/project/sandadmin` 是纯净通用宿主，只用于零插件基线。同步规则和迁移记录见 [SOURCE_OF_TRUTH.md](SOURCE_OF_TRUTH.md)。

## 兼容性

| 项目 | 要求 |
| --- | --- |
| 插件版本 | 以 `info.ini` 的当前版本为准（当前为 `1.0.7`） |
| 宿主 | SandAdmin 6.x |
| 数据库 | PostgreSQL；默认连接名为 `pgsql` |
| 前端依赖 | 见 `config.json` |

安装前请确认目标宿主已完成初始化，并在隔离环境验证插件版本与宿主版本的组合。历史 MySQL 数据不能直接通过本包原地转换。

## 安装、升级与卸载

1. 从与本 README 同一版本的发行包安装插件；安装器会使用包内 `install.sql`。
2. 安装后刷新后台菜单，为需要的角色授予 SandWorkflow 菜单和按钮权限。
3. 以有流程管理权限的账号登录，确认工作流菜单可见，并完成一条最小流程的发布、发起和审批验证。
4. 升级仅使用同一发行包提供的升级生命周期实现；升级前备份业务数据，并在隔离 PostgreSQL 环境验证。
5. 卸载会执行包内的卸载生命周期。卸载前必须明确数据保留要求并完成备份；不要把卸载当作恢复或迁移手段。

发布包中的 `install.sql`、`update.sql`、`uninstall.sql` 与 `plugin/sandworkflow/` 内同名文件必须保持一致。

## 使用说明

- 完整的产品操作流程见[用户使用文档](USER_GUIDE.md)。
- PostgreSQL 包约束和 SQL 再生成说明见[POSTGRESQL.md](POSTGRESQL.md)。
- 版本变更与已验证范围见[RELEASE_NOTES.md](RELEASE_NOTES.md)。
- 开发状态和验收门槛见[PROGRESS.md](PROGRESS.md)。
- 构建或上传候选包前运行 `tools/check-package-integrity.sh`，确认根/后端元数据、运行版本和生命周期 SQL 一致。
- 同步到默认演示验收宿主后，运行 `tools/check-sandadmin-host.sh`；该检查会同时核对菜单组件、发布包、后端和前端载荷，缺少任一部分都不得进入页面验收。

## 发布者检查单

- [ ] `info.ini`、README、发布说明和包版本一致。
- [ ] 后端载荷、前端载荷、生命周期 SQL 和文档都包含在发布包中。
- [ ] 在干净 PostgreSQL 宿主完成安装、权限授予和核心流程验证。
- [ ] 升级或卸载涉及数据时，已记录实际验证范围和备份策略。
- [ ] 随包附带适用的许可证、NOTICE 和第三方声明；不得删除继承文件中的归属信息。

## 已知限制

- 本包不提供 MySQL 到 PostgreSQL 的原地数据迁移。
- 公式仅支持数字、括号和四则运算，不执行任意 JavaScript。
- 静态检查或构建通过不等于目标宿主的安装、登录或业务回归已经验收。
