# Sand Core

SandAdmin 的 Composer 核心包，包含：

- `server/plugin/sandadmin/`：Webman 后台核心后端源码；
- `sandadmin-artd/`：与后端同版本的管理端前端源码。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](CHANGELOG.md)保留历史记录。公开发行与下载见 [GitHub Releases](https://github.com/supdger/sand-core/releases)。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

安装后端：

```bash
composer config repositories.sand-core vcs https://github.com/supdger/sand-core
composer require supdger/sand-core:^0.2.4
```

公开 0.2.4 安装或更新时，Webman 的 Composer 插件钩子会将匹配版本的前端源码自动发布到宿主同级的 `sandadmin-artd/` 目录，不需要额外执行工具脚本，并拒绝覆盖未知修改。前端依赖安装、构建和部署仍需单独完成。

发布成功后，宿主应有 `server/plugin/sandadmin/`，前端目录应有 `.sand-core-source-manifest.json`。这只确认源码发布，不代表数据库初始化或登录通过。

升级至 0.2.4 后，确认已发布匹配的管理端源码，再重新构建并使用更新后的管理端。该版本修复插件安装后页面恢复但全屏加载遮罩不退出的问题，保留初始化暂态恢复；配套 SandPackage 0.2.4 可获得仓库读取的暂态恢复；只更新后端不会更新已部署的前端构建。

运行管理端还需要 Node.js 和 pnpm：前端声明下限为 Node.js 20.19.0、pnpm 8.8.0；当前 v9 锁文件应使用 pnpm 9，推荐按上述指南使用 Node.js 22（≥22.12.0）和 pnpm 9.15.9。环境文件、依赖安装、服务启动及首次登录步骤沿用该指南，以前端启动地址、登录后菜单和用户信息正常加载作为运行结果。

## 接入已存在的 PostgreSQL SandAdmin 数据库

只在**尚无 `server/.env`** 的宿主中，从宿主 `server/` 执行随包的独立 CLI。CLI 根据自身所在的 `server/plugin/sandadmin/tools/` 布局确定宿主绝对路径，不接受指定其他宿主路径的参数；隔离预检可使用独立的同构目录，至少包含 `server/config/database.php`、`server/config/think-orm.php`、`server/runtime/`、`server/plugin/sandadmin/db/sandadmin-6.0.pgsql` 和 `server/plugin/sandadmin/tools/attach-existing.php`。指定实际的 `--host`、`--port`、`--database`、`--user`；不提供默认数据库，也不从当前宿主配置猜测目标。当前 SandAI 测试宿主只能显式指定现有的 `sandai` 库。密码通过 `SAND_ATTACH_DB_PASSWORD` 环境变量提供，不写入参数或日志。

1. `preflight` 用只读事务检查本机配置、22 张核心表及列/主键/identity、ORM 辅助对象与权限、管理员与权限菜单，输出 SHA-256 指纹；此阶段不创建 `.env`。
2. 确认目标和指纹后，在同一宿主路径使用相同连接参数运行 `confirm`，并额外指定 `--fingerprint=<预检指纹> --confirm=ATTACH`。确认阶段在安装锁内重新检查；指纹变化则拒绝。成功时只原子创建 `server/.env`，不执行初始化 SQL、迁移、菜单写入、密码重置或服务重载。预检指纹不保证外部 DDL 在预检后保持不变；确认重检之后的数据库变更仍需另行协调。

命令格式：

```text
php plugin/sandadmin/tools/attach-existing.php preflight --host=HOST --port=PORT --database=DB --user=USER
php plugin/sandadmin/tools/attach-existing.php confirm --host=HOST --port=PORT --database=DB --user=USER --fingerprint=SHA256 --confirm=ATTACH
```

为避免密码进入 shell 历史，可在当前终端使用 `read -rs SAND_ATTACH_DB_PASSWORD` 后 `export SAND_ATTACH_DB_PASSWORD`；结束后 `unset SAND_ATTACH_DB_PASSWORD`。该 CLI 不代替新库安装；现有 Web 安装入口仍拒绝已存在的核心表。CLI 结构兼容检查不等于真实登录或业务验收。

若本机现有数据库角色确实使用空密码，须显式导出空值 `export SAND_ATTACH_DB_PASSWORD=''`，并在**预检和确认两次命令**都加 `--allow-empty-password=local`。此模式只接受字面 `127.0.0.1` 或 `::1`，还会核对 PostgreSQL 报告的客户端和服务端地址均为回环地址；Unix 环境下把 libpq 密码文件设为 `/dev/null`，且拒绝已有的 `PGPASSWORD`、`PGPASSFILE`、`PGSERVICE`、`PGSERVICEFILE` 和连接目标覆盖变量。连接认证失败时不会读取 `~/.pgpass` 或切换其他凭据；无法禁用密码文件的平台会拒绝此模式。成功写入的 `.env` 中密码为 `DB_PASSWORD = ''`。

后台核心更新由配套的 [SandPackage 0.2.1](https://github.com/supdger/sand-package) 提供，初次启用按其配置示例准备执行环境。0.2.0 不变更数据库结构或宿主骨架；更新契约与发行提交绑定，实际支持范围见对应版本说明。
