# 安装与升级

本文面向 SandAdmin 运维人员。安装和卸载会改变数据库；本页当前版本为 `0.7.6-preview`，其受支持升级只读核验 SandIAM 业务结构和数据。插件管理器仍会更新自身安装登记及文件；开始前应取得环境授权并完成可恢复备份。

## 前置条件

- SandAdmin `0.1.x` 是元数据声明范围；本次生命周期验收以公开 SandPackage `0.1.9` 为目标，已实测版本与结果见该版本 Release，不能据此推定所有宿主版本均已验收；
- PostgreSQL 数据库和与该宿主匹配的 SandPackage。安装前核对锁定的 SandPackage 版本和候选包所需的安装能力；
- 可写的插件目录、匹配的 PHP 运行环境，以及管理端构建/部署能力；
- 完整 `sand-iam-0.7.6.zip`、同一 Release 的 `SHA256SUMS` 和 `manifest.json`；本次为 unsigned preview，没有正式发行签名，见[包校验](release-package-verification.md)。

完整能力需要 PHP `>=8.2`，以及 `ctype`、`curl`、`dom`、`json`、`ldap`、`libxml`、
`mbstring`、`openssl`、`PDO`/`pdo_pgsql`、`sodium`、`zip` 和 `zlib`。这些分别承载身份字段校验、
HTTPS 对端、SAML、LDAPS、PostgreSQL、秘密加密和 SandPackage ZIP。安装前在解包目录运行：

```sh
php plugin/sand-iam/bin/check-runtime-requirements.php
```

自动化可追加 `--json`。失败表示当前 PHP 不能提供本发行版声明的完整能力；不要以关闭某个功能
来替代完整成品验收。命令只检查本机 PHP 能力，不连接数据库、不检查 schema，也不启动服务。

SandIAM 不负责创建数据库。请使用现有 SandAdmin 数据库；不要运行会隐式新建数据库的安装器或测试工具。

生命周期 SQL 使用包内原始 UTF-8 字节，不要用 Windows 编辑器打开后另存为 UTF-8 BOM 或手工替换分号、引号、反斜杠。公开 SandPackage `0.1.9` 不会剥除 SQL 的 UTF-8 BOM；带 BOM 的脚本会在 PostgreSQL 以 `42601` 语法错误安全拒绝，不能视为升级成功。应重新取得并校验原包，不要通过编辑 SQL 绕过核验。正常 CRLF 换行和字符串中的中文标点属于不同情况，不应因此改写业务数据。

## 全新安装

1. 锁定 SandAdmin、SandPackage 和 SandIAM 版本，保存候选包摘要并记录宿主实际版本。
2. 按本次预发布的[包校验步骤](release-package-verification.md)校验 ZIP 摘要和条目，确认根目录包含 `info.ini`、生命周期 SQL、`plugin/sand-iam/`、管理端、SDK、文档和许可证。
3. 备份数据库、插件文件、管理端产物和部署配置；验证备份可以读取。
4. 登录 SandAdmin 后进入“插件市场”（菜单路由为 `/plugin/sandpackage/install/index`），上传**完整** SandIAM ZIP；不要上传解开的子目录或只上传 `plugin/sand-iam/`。在安装候选详情中先阅读计划、版本和摘要，再选择“安装”。
5. 等待 SandPackage 的安装结果明确结束。若界面报告失败，停止在该阶段并保留候选摘要、阶段、错误码和恢复票据；不要改迁移账本、复制文件或手工导入 SQL。
6. 按[配置参考](configuration-reference.md)从密钥系统注入基础密钥；可选功能和 worker 保持关闭，
   直到对应迁移、对端与运行验收完成。启动或重载服务前，在插件目录运行
   `php plugin/sand-iam/bin/check-runtime-configuration.php --profile=release`；预检失败时不要继续启动。
7. 发布管理端载荷：SandIAM 包内管理端源码的实际目录是
   `sandadmin-artd/src/views/plugin/sand-iam/`。上传前可仅检查 ZIP 是否携带它：

   macOS，在 ZIP 所在目录执行：

   ```sh
   unzip -l sand-iam-0.7.6.zip | grep 'sandadmin-artd/src/views/plugin/sand-iam/'
   ```

   Windows PowerShell，在 ZIP 所在目录执行：

   ```powershell
   $ErrorActionPreference = 'Stop'
   Add-Type -AssemblyName System.IO.Compression.FileSystem
   $archive = [IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath '.\sand-iam-0.7.6.zip').Path)
   try {
       $frontend = @($archive.Entries | Where-Object {
           $_.FullName.StartsWith('sandadmin-artd/src/views/plugin/sand-iam/')
       })
       if ($frontend.Count -eq 0) { throw '包中缺少 SandIAM 管理端源码，停止安装。' }
       $frontend | Select-Object -ExpandProperty FullName
       Write-Host "管理端源码：已包含（$($frontend.Count) 个条目）"
   } finally { $archive.Dispose() }
   ```

   本仓没有 SandAdmin 宿主的管理端构建或发布命令，不能据此虚构一条命令；使用宿主既定流程前先由
   运维方确认其构建入口。上述 ZIP 列表只证明管理端源码已包含，不能替代宿主构建、部署与浏览器检查。
   公开 SandPackage `0.1.9` 普通升级会复制管理端源码，但没有自动构建并激活前端的步骤。
   成功标志依次是 SandPackage 对完整 ZIP 明确报告安装成功、已部署管理端实际显示 SandIAM 菜单，并能打开
   总览；任一缺失都不是“管理端已发布”。不要把源码目录存在当成页面已经发布。
8. 重新登录。确认左侧出现 SandIAM 菜单、能打开总览并从总览进入“第一次使用”；再打开应用用户入口 `/app/sand-iam/account/`，确认返回的是门户而不是 SandAdmin 后台登录页。
9. 创建一个隔离的演示范围，完成允许、拒绝、撤销、审计和清理。上述检查是安装后应执行的操作清单，不是本源码候选已完成真实安装的声明。

## 0.7.3 / 0.7.5 升级到 0.7.6

本包只支持来源版本为 `0.7.3` 或 `0.7.5`，且数据库已经具备**已发布 0.7.3 的完整 43 条迁移账本**。版本号相同不代表来源相同：账本必须包含 `042_permission_menu_hierarchy.pgsql`，每行修订、摘要和历史包版本均须匹配；只有 42 行、包含冲突的 `042_schema_semantics.pgsql`、多余/缺失记录或结构不符时都会拒绝。不要补写账本来绕过核验。

1. 查阅[变更日志](../../CHANGELOG.md)和当前 Release 说明，确认来源版本、包摘要、宿主及管理器版本。
2. 分别备份数据库、插件文件、管理端产物和配置，并确认能恢复。管理器自动制作的插件文件备份**不包含数据库备份**。
3. 可以从“插件仓库”选择已入目录的更高版本并直接升级；版本尚未进入目录时，下载本次完整 ZIP，上传后在插件管理中核对“当前版本 → 0.7.6”并确认升级。发布 Release 本身不会自动更新仓库目录。
4. SandPackage 执行本包 `update.sql`：在 PostgreSQL `READ ONLY` 事务内严格核对账本、归属约束和菜单根，不执行 SandIAM 业务表 DDL/DML，也不新增迁移记录。管理器自身的版本登记和文件部署属于另外的步骤。
5. 数据库核验通过后仍须等待管理器确认整个升级结束；核对已安装版本和文件，再按宿主已有流程构建、部署管理端。
6. 重新登录并检查页面，随后在隔离业务范围验证允许、拒绝、撤销和审计，再决定是否开放流量。页面成功不等于完整业务链已验收。

`0.7.5` 曾作为其他插件的锁定依赖包分发；其旧 `sand_platform` 扩展元数据会被公开 SandPackage `0.1.9` 的上传入口拒绝，不能将那个包的全新安装当作已支持入口。0.7.6 已去除此旧扩展。已安装 0.7.5 的升级仍须满足上面的精确账本要求；其他插件若锁定旧版本，还须先完成依赖兼容评估。本次发布不自动修改 SandAI 的依赖锁。

更早版本、42 行的历史 0.7.3 候选和失败升级恢复不属于本包升级入口；应使用与其来源相符、已有验收证据的历史方案。不要把旧版 0.7.2 → 0.7.3 的迁移 SQL 与本包拼接执行。

## 失败恢复

先回到 SandAdmin“插件市场”中该候选的失败详情，保存阶段、候选摘要、错误码和 SandPackage 显示的下一步。

- 数据库阶段尚未提交：仅使用该候选提供的正常“重试”路径；不要手工执行或重复导入 `update.sql`。
- 0.7.6 只读核验已提交但文件部署/登记失败：本包不会新增 revision，不能用“出现新账本行”判断完成。保留管理器阶段和文件备份，仅按该宿主提供的恢复/完成路径处理；不要伪造 FAILED 状态、改账本或手工覆盖旧包。
- 无法判定阶段、没有可用的 SandPackage 恢复操作，或恢复操作报告失败：停止操作，保持备份和现场，向宿主/SandPackage 维护者提交版本、候选摘要、阶段、错误码、请求 ID 与脱敏日志。

0.7.6 正常包没有旧 `0.6.0 → 0.7.0` recovery descriptor，不能借用历史描述器或手工重放 `update.sql`。
恢复或完成后，重新核对已安装版本、迁移账本、菜单/权限、管理端载荷和应用用户门户入口，再按本页的隔离范围执行允许、拒绝、撤销和审计检查。

## 卸载

先撤销业务流量、凭证和外部回调，导出需保留的审计，并确认其他插件不再依赖 SandIAM。
通过 SandPackage 执行卸载后，检查 SandIAM 文件、菜单、权限和 `sand_iam_*` 表已按契约清理，
同时确认宿主及其他插件未受损。不要删除宿主自有表或审计备份。
