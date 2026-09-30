# 发布包校验

当前 `0.7.6-preview` 提供 `sand-iam-0.7.6.zip`、`SHA256SUMS` 和 `manifest.json`。这是 **unsigned preview**：摘要可以检测下载损坏和文件差异，不是独立信任的发行签名。完整正式发行签名和产品验收门槛尚未完成。

## 下载后检查

从项目 [GitHub Release](https://github.com/supdger/sand-iam/releases/tag/v0.7.6-preview) 下载同一版本的三个文件，放在同一个新目录。在该目录执行：

```sh
shasum -a 256 -c SHA256SUMS
unzip -t sand-iam-0.7.6.zip
unzip -l sand-iam-0.7.6.zip
```

第一条应显示 `sand-iam-0.7.6.zip: OK`，第二条应报告压缩文件无错误。第一条失败时停止，不要安装或修改校验文件使其通过。Windows 可用 PowerShell 的 `Get-FileHash .\sand-iam-0.7.6.zip -Algorithm SHA256`，逐字与 `SHA256SUMS` 对照；此处只提供对应入口，Windows 本机验收结果以 Release 说明为准。

ZIP 根目录应包含 `info.ini`、`config.json`、`install.sql`、`update.sql`、`uninstall.sql`、许可证与说明，以及 `plugin/sand-iam/`、`sandadmin-artd/src/views/plugin/sand-iam/`、SDK 和用户文档。元数据版本应为 `0.7.6`。

`manifest.json` 使用 `sand-iam.independent-release-manifest/v1`，记录独立仓库源码 commit/tree、包 SHA-256、条目数、逐文件 SHA-256、构建工具版本及两次构建是否一致。它不采用旧聚合工作区的 v8 签名证明格式，不应交给旧版 `verify-release-bundle.php` 当作正式签名包验证。

## 从可信源码重复构建

需要复核来源时，先通过可信渠道核对 Release 对应的源码 commit，再检出该固定 commit 的干净独立仓库。不要使用从待验证 ZIP 中解出的程序证明该 ZIP 自身可信。独立仓库中的构建入口是：

```sh
php tools/build-independent-release.php /absolute/path/to/new-artifacts
```

参数须替换为仓库外、父目录已存在且目标目录尚不存在的绝对路径。命令从已提交 Git blobs 分别生成 `primary` 和 `repeat` 两份包，固定条目顺序、时间和权限，并核对冻结依赖文件摘要与锁文件；任何不一致都会以非零状态码退出。结果中的 `SHA256SUMS`、`manifest.json` 与发布件逐项比较。构建环境 PHP、zip 扩展及 libzip 版本见 manifest，不同压缩工具版本可能影响 ZIP 字节。

`release-build-contract.json` 中的 `toolchain` 及 Composer/TypeScript 参数记录冻结依赖的原生成条件；本次重打包实际使用的 PHP、zip 和 libzip 版本以 `manifest.json` 的 `toolchain` 为准。本构建使用固定 Git 依赖文件，不声称执行了旧聚合工作区的 Composer/TypeScript 重新生成流程。源码仓库的工具不进入安装 ZIP；安装 ZIP 也不是完整开发仓库。

校验成功只证明相应字节一致，不证明目标 SandAdmin、PostgreSQL、身份提供方或业务系统已经兼容。安装前仍需备份，并按[安装与升级](installation-and-upgrade.md)检查精确来源版本及账本条件。管理端源码存在也不代表宿主已构建和部署前端。
