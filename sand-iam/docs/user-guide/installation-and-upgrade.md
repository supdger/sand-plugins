# 安装与升级

本文面向 SandAdmin 运维人员。安装、升级和卸载都会改变数据库；开始前应取得环境授权并完成可恢复备份。

## 前置条件

- SandAdmin `6.x`，版本不低于 `6.0.11`；
- PostgreSQL 数据库和能够执行插件生命周期 SQL 的 SandPackage；
- 可写的插件目录、匹配的 PHP 运行环境，以及管理端构建/部署能力；
- 完整 SandIAM ZIP 和发布方提供的 SHA-256、签名、公钥或可信来源清单。

完整能力需要 PHP `>=8.2`，以及 `ctype`、`curl`、`dom`、`json`、`ldap`、`libxml`、
`mbstring`、`openssl`、`PDO`/`pdo_pgsql`、`sodium`、`zip` 和 `zlib`。这些分别承载身份字段校验、
HTTPS 对端、SAML、LDAPS、PostgreSQL、秘密加密和 SandPackage ZIP。安装前在解包目录运行：

```sh
php plugin/sand-iam/bin/check-runtime-requirements.php
```

自动化可追加 `--json`。失败表示当前 PHP 不能提供本发行版声明的完整能力；不要以关闭某个功能
来替代完整成品验收。命令只检查本机 PHP 能力，不连接数据库、不检查 schema，也不启动服务。

SandIAM 不负责创建数据库。请使用现有 SandAdmin 数据库；不要运行会隐式新建数据库的安装器或测试工具。

## 全新安装

1. 锁定 SandAdmin、SandPackage 和 SandIAM 版本，保存候选包摘要。
2. 校验 ZIP 摘要和签名，确认根目录包含 `info.ini`、生命周期 SQL、`plugin/sand-iam/`、管理端、SDK、文档和许可证。
3. 备份数据库、插件文件、管理端产物和部署配置；验证备份可以读取。
4. 在 SandPackage 中上传完整 ZIP，先阅读安装计划，再执行安装。
5. 按[配置参考](configuration-reference.md)从密钥系统注入基础密钥；可选功能和 worker 保持关闭，
   直到对应迁移、对端与运行验收完成。启动或重载服务前，在插件目录运行
   `php plugin/sand-iam/bin/check-runtime-configuration.php --profile=release`；预检失败时不要继续启动。
6. 重新登录，从 SandIAM 总览进入“第一次使用”；不要手工拼接内部路由。
7. 创建一个隔离的演示范围，完成允许、拒绝、撤销、审计和清理。

## 升级

1. 查阅根目录[变更日志](../../CHANGELOG.md)，确认当前版本属于声明支持的升级路径；不要跨过未声明的中间版本。
2. 锁定升级前数据库结构、插件版本和包摘要，完成备份。
3. 使用 SandPackage 执行升级。失败时保留错误阶段、候选摘要和恢复票据，不要手工标记成功。
4. 同一候选重复执行升级，结果应幂等；迁移账本不得重复或缺号。
5. 重新验证登录、授权、撤销、审计、worker 和外部协议，再开放业务流量。

## 失败恢复

只使用 SandPackage 对当前候选验证通过的恢复计划。恢复描述必须绑定候选载荷、`update.sql`
和失败阶段；摘要或版本不同就停止。恢复后再次核对已安装版本、迁移账本、权限、菜单和业务行为。

## 卸载

先撤销业务流量、凭证和外部回调，导出需保留的审计，并确认其他插件不再依赖 SandIAM。
通过 SandPackage 执行卸载后，检查 SandIAM 文件、菜单、权限和 `sand_iam_*` 表已按契约清理，
同时确认宿主及其他插件未受损。不要删除宿主自有表或审计备份。
