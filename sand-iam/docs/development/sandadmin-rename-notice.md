# SandAdmin 宿主更名通知

> 状态：**源码已按本通知切换到 SandAdmin 引用**；兼容加载器保留到宿主正式标签发布。本文不改变 SandIAM 的领域边界、路由、权限码或 `sand_iam_*` 表结构。

## 变更摘要

原 PostgreSQL 宿主 **SaiAdmin-PG** 已更名为 **SandAdmin**。这是一项核心包、目录、命名空间和发布仓库的更名；已有核心 `sa_*` 表为存量兼容对象，**不迁移、不改名**。

## SandIAM 必须调整的引用

| 旧引用 | 新引用 |
| --- | --- |
| `plugin\\saiadmin\\...` | `plugin\\sandadmin\\...` |
| `plugin.saiadmin...` | `plugin.sandadmin...` |
| `saiadmin-artd/` | `sandadmin-artd/` |
| `FRONTEND_DIR=saiadmin-artd` | `FRONTEND_DIR=sandadmin-artd` |
| `SAIADMIN_*` | `SANDADMIN_*` |
| `sai:*` 开发命令 | `sand:*` 开发命令 |

源码 28 个业务 PHP 文件已改为 `plugin\\sandadmin`（异常处理器、`BaseController`、`BaseModel`、鉴权中间件和权限服务）。`app/functions.php` 仍保留过渡期双向类别名，供旧宿主或残留引用解析；正式标签发布后删除，不要长期靠别名运行。

## 不变项

- 插件显示名仍为 `SandIAM`，目录/路由仍为 `sand-iam`，PHP 命名空间仍为 `plugin\\SandIam`；
- 所有 `sand_iam_*` 表、SandIAM API 契约、权限码和业务领域归属不变；
- 宿主的既有 `sa_system_*` 等核心表继续保留，以保证已安装实例和历史数据兼容。

## 发布门槛

1. 在 SandAdmin 的 PostgreSQL 宿主中安装本插件，并执行 Composer 自动加载刷新；
2. 完成 PHP 静态检查及受影响的插件测试；
3. 用已登录的后台会话验证一个管理端受保护路由与一个运行时身份上下文路由；
4. 插件包前端载荷路径已改为 `sandadmin-artd/src/views/plugin/sand-iam/`。2026-08-14 已同步到验收宿主 `server/plugin/sand-iam`，PHP 语法检查通过；需重载 Webman 后做登录态冒烟。

## 发布协调

SandAdmin 首版发布标签、GitHub 新仓库地址和最终的兼容窗口以宿主发布说明为准。不要在该标签发布前删除当前的兼容加载逻辑。
