# 安装与升级

本文面向 SandAdmin 运维人员。安装、升级和卸载都会改变数据库；开始前应取得环境授权并完成可恢复备份。

## 前置条件

- SandAdmin `6.x`，版本不低于 `6.0.11`。`info.ini` 的宿主声明是 `6.x`；`6.0.11` 是包完整性检查使用的最低比较值；
- PostgreSQL 数据库和 SandPackage。仓库中有 SandPackage `6.1.4` 的宿主协作记录；它只是**已观察的宿主基线**，不是支持矩阵、兼容性承诺或本候选已经真实安装的证据；
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

1. 锁定 SandAdmin、SandPackage 和 SandIAM 版本，保存候选包摘要。记录宿主实际版本，而不是只记录 `6.x`。
2. 校验 ZIP 摘要和签名，确认根目录包含 `info.ini`、生命周期 SQL、`plugin/sand-iam/`、管理端、SDK、文档和许可证。
3. 备份数据库、插件文件、管理端产物和部署配置；验证备份可以读取。
4. 登录 SandAdmin 后进入“插件市场”（菜单路由为 `/plugin/sandpackage/install/index`），上传**完整** SandIAM ZIP；不要上传解开的子目录或只上传 `plugin/sand-iam/`。在安装候选详情中先阅读计划、版本和摘要，再选择“安装”。
5. 等待 SandPackage 的安装结果明确结束。若界面报告失败，停止在该阶段并保留候选摘要、阶段、错误码和恢复票据；不要改迁移账本、复制文件或手工导入 SQL。
6. 按[配置参考](configuration-reference.md)从密钥系统注入基础密钥；可选功能和 worker 保持关闭，
   直到对应迁移、对端与运行验收完成。启动或重载服务前，在插件目录运行
   `php plugin/sand-iam/bin/check-runtime-configuration.php --profile=release`；预检失败时不要继续启动。
7. 发布管理端载荷：SandIAM 包内管理端源码的实际目录是
   `sandadmin-artd/src/views/plugin/sand-iam/`。上传前可仅检查 ZIP 是否携带它：

   ```sh
   unzip -l "$SAND_IAM_ZIP" | rg 'sandadmin-artd/src/views/plugin/sand-iam/'
   ```

   本仓没有 SandAdmin 宿主的管理端构建或发布命令，不能据此虚构一条命令；使用宿主既定流程前先由
   运维方确认其构建入口。仓内可运行的只读包内检查是
   `php sand-iam/tools/check-package-integrity.php`，其 `admin UI payload` 通过只证明源码载荷关系完整。
   宿主前端激活正式契约未提供，已作为发布阻塞记录。
   成功标志依次是 SandPackage 对完整 ZIP 明确报告安装成功、已部署管理端实际显示 SandIAM 菜单，并能打开
   总览；任一缺失都不是“管理端已发布”。不要把源码目录存在当成页面已经发布。
8. 重新登录。确认左侧出现 SandIAM 菜单、能打开总览并从总览进入“第一次使用”；再打开应用用户入口 `/app/sand-iam/account/`，确认返回的是门户而不是 SandAdmin 后台登录页。
9. 创建一个隔离的演示范围，完成允许、拒绝、撤销、审计和清理。上述检查是安装后应执行的操作清单，不是本源码候选已完成真实安装的声明。

## 升级

1. 查阅根目录[变更日志](../../CHANGELOG.md)，确认当前版本属于声明支持的升级路径；不要跨过未声明的中间版本。
2. 锁定升级前数据库结构、插件版本和包摘要，完成备份。
3. 在 SandAdmin“插件市场”中上传完整升级 ZIP，在候选详情核对“当前版本 → 候选版本”、候选摘要和升级计划后，再选择升级。失败时保留错误阶段、候选摘要和恢复票据，不要手工标记成功。
4. 若 SandPackage 明确报告数据库阶段尚未开始或尚未提交当前候选的新迁移，只能由 SandPackage 以正常升级路径重试；
   不要手工执行或重复导入 `update.sql`。
5. 若数据库阶段已提交，账本已有当前候选的新 revision，则 `update.sql` 不是可重复执行的幂等脚本；保留阶段、
   错误和候选摘要，按 SandPackage 的“数据库已提交后”阶段恢复/完成流程处理，不得伪造 FAILED 或改账本。
6. 重新验证登录、授权、撤销、审计、worker 和外部协议，再开放业务流量。

### 0.7.0 升级到 0.7.1

此正常升级只适用于账本已精确完成 `001–037` 的 0.7.0 安装。SandPackage 会先核验账本身份、
账本结构和 86 张 SandIAM 表，再执行原始迁移 `038`；成功后账本必须有 39 行且最大修订号为 38。
038 仍记录为 `package_version=0.7.0`，这是已发布迁移身份的一部分，不是版本标记错误。
生成的 `update.sql` 将这项 preflight 与原始 `038` 主体组合为同一个显式 PostgreSQL 事务：任一
语句失败时，该生命周期事务会回滚。此事务组合不改变 SandPackage 对数据库阶段之后失败的恢复流程。

不要把恢复记录改为 FAILED，也不要手工补账本或执行 SQL。0.7.1 正常包不声明 `0.6.0 → 0.7.1`
直升，也不携带历史 recovery descriptor；若环境属于历史 0.6.0 失败升级，必须使用当时候选和宿主
已验收的恢复程序，而不是此包。

### 0.7.1 升级到 0.7.2

此正常升级只适用于账本已精确完成 `001–038` 的 0.7.1 安装。SandPackage 会先核验 39 条账本
身份、账本结构、`sand_iam_service_grant.data_class` 的既有非空结构和 revision 038 的保留索引，
再依次执行迁移 `039`、`040`。039 只解除该列的非空约束，保留 `varchar(32)`、`internal`
默认值、既有数据和检查约束；040 允许匿名 Passkey challenge 在验证成功后绑定已解析身份，
其他 challenge 仍必须绑定身份。成功后账本必须有 41 行且最大修订号为 40。

生成的 `update.sql` 将 0.7.2 preflight 与 039、040 组合在同一个显式 PostgreSQL 事务中。
缺失、多余、摘要冲突或结构不匹配均在 039 前关闭失败；任一语句失败时整段事务回滚。不要从
0.7.0 跨版本直接升级到 0.7.2，也不要手工清空 `data_class`、解除约束、补写 039/040 账本或
重复执行 `update.sql`。

## 失败恢复

先回到 SandAdmin“插件市场”中该候选的失败详情，保存阶段、候选摘要、错误码和 SandPackage 显示的下一步。

- 数据库阶段尚未提交：仅使用该候选提供的正常“重试”路径；不要手工执行或重复导入 `update.sql`。
- 数据库阶段已提交：先核对迁移账本是否已有当前候选的新 revision，再只按 SandPackage 显示的“数据库已提交后”恢复/完成路径处理。不要删除候选、伪造 FAILED 状态、改账本或用旧包覆盖文件。
- 无法判定阶段、没有可用的 SandPackage 恢复操作，或恢复操作报告失败：停止操作，保持备份和现场，向宿主/SandPackage 维护者提交版本、候选摘要、阶段、错误码、请求 ID 与脱敏日志。

0.7.2 正常包没有旧 `0.6.0 → 0.7.0` recovery descriptor，不能借用历史描述器或手工重放 `update.sql`。
恢复或完成后，重新核对已安装版本、迁移账本、菜单/权限、管理端载荷和应用用户门户入口，再按本页的隔离范围执行允许、拒绝、撤销和审计检查。

## 卸载

先撤销业务流量、凭证和外部回调，导出需保留的审计，并确认其他插件不再依赖 SandIAM。
通过 SandPackage 执行卸载后，检查 SandIAM 文件、菜单、权限和 `sand_iam_*` 表已按契约清理，
同时确认宿主及其他插件未受损。不要删除宿主自有表或审计备份。
