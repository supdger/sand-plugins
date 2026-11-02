# Sand Core / Sand Package 宿主交接

## 包

| 包 | 开发路径 | Composer 名称 | 开发验证版本 |
| --- | --- | --- | --- |
| Sand Core | `/Users/code/project/sand_plugins/sand-core` | `supdger/sand-core` | `0.1.0` |
| Sand Package | `/Users/code/project/sand_plugins/sand-package` | `supdger/sand-package` | `0.1.0` |

## SandAdmin 开发期 path repository

SandAdmin `server/composer.json` 使用两个 `path` repository，并通过
`options.versions` 将开发副本固定为 `0.1.0`。安装后应存在：

```text
vendor/supdger/sand-core
vendor/supdger/sand-package
plugin/sandadmin
plugin/sandpackage
```

Composer 安装只发布后端运行源码，不创建数据库、不执行插件迁移、不启动或重启服务。

## 前端源码

```bash
php vendor/supdger/sand-core/tools/publish-frontend.php ../sandadmin-artd
php vendor/supdger/sand-package/tools/publish-frontend.php ../sandadmin-artd
```

Core 发布完整管理端基础源码，Package 随后叠加自己的页面；两个工具均记录版本与 SHA-256 基线并拒绝静默覆盖修改。工具不运行 pnpm、不编译。

## 候选归档

- `/private/tmp/sand-package-archives.cRJ6uZ/sand-core-0.1.0.zip`
  - SHA-256: `a6ce68e1091037ce61d71c0bf7aaa514e70fe647cee4b2a4945d85dcb9285ef8`
- `/private/tmp/sand-package-archives.cRJ6uZ/sand-package-0.1.0.zip`
  - SHA-256: `fe643f50f8a4c44fb20b13c5a050324aa0a58d73b032c7c871aef87e648ed1ac`

这些是本地候选，不是已发布版本。

## 回滚

若宿主验证失败，恢复迁移前 `server/composer.json`、`server/composer.lock` 和内置目录映射即可；不得以回滚包源码为由修改业务数据库。当前未提交、未推送、未发布。
