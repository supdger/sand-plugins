# 发布包校验

当前 `0.7.6-preview` 提供 `sand-iam-0.7.6.zip`、`SHA256SUMS` 和 `manifest.json`。这是 **unsigned preview**：摘要可以检测下载损坏和文件差异，不是独立信任的发行签名。完整正式发行签名和产品验收门槛尚未完成。

## 下载后检查

从项目 [GitHub Release](https://github.com/supdger/sand-iam/releases/tag/v0.7.6-preview) 下载同一版本的三个文件，放在同一个新目录。在该目录执行：

macOS 的 Terminal：

```sh
shasum -a 256 -c SHA256SUMS
unzip -t sand-iam-0.7.6.zip
unzip -l sand-iam-0.7.6.zip
```

第一条应显示 `sand-iam-0.7.6.zip: OK`，第二条应报告压缩文件无错误。第一条失败时停止，不要安装或修改校验文件使其通过。

Windows 的 PowerShell（在三个下载文件所在目录完整粘贴执行）：

```powershell
$ErrorActionPreference = 'Stop'
$zipPath = (Resolve-Path -LiteralPath '.\sand-iam-0.7.6.zip').Path
$sumLine = [IO.File]::ReadAllText((Resolve-Path -LiteralPath '.\SHA256SUMS').Path).Trim()
if ($sumLine -notmatch '^([0-9a-fA-F]{64})  sand-iam-0[.]7[.]6[.]zip$') {
    throw 'SHA256SUMS 格式或包名不符，请重新下载同一 Release 的文件。'
}
$expected = $Matches[1]
if ((Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash -ne $expected) {
    throw 'ZIP 摘要不符，停止安装并重新下载。'
}
Write-Host 'ZIP SHA-256：通过'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $manifest = Get-Content -LiteralPath '.\manifest.json' -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($manifest.schema -ne 'sand-iam.independent-release-manifest/v1' -or
        $manifest.sha256 -ne $expected -or $manifest.entries -ne $archive.Entries.Count) {
        throw '构建清单与 ZIP 不符，停止安装。'
    }
    $seen = @{}
    foreach ($entry in $archive.Entries) {
        if ($seen.ContainsKey($entry.FullName)) { throw 'ZIP 含重复条目，停止安装。' }
        $seen[$entry.FullName] = $true
        $property = $manifest.files.PSObject.Properties[$entry.FullName]
        if ($null -eq $property) { throw "清单缺少条目：$($entry.FullName)" }
        $stream = $entry.Open()
        $hasher = [Security.Cryptography.SHA256]::Create()
        try {
            $digest = [BitConverter]::ToString($hasher.ComputeHash($stream)).Replace('-', '').ToLowerInvariant()
            if ($digest -ne $property.Value) { throw "条目摘要不符：$($entry.FullName)" }
        } finally { $hasher.Dispose(); $stream.Dispose() }
    }
    if (@($manifest.files.PSObject.Properties).Count -ne $seen.Count) {
        throw '构建清单含有 ZIP 中不存在的条目。'
    }
    $archive.Entries | Select-Object -ExpandProperty FullName
    Write-Host "ZIP 条目校验：通过（$($seen.Count) 个条目）"
} finally { $archive.Dispose() }
```

此命令读取全部条目并与构建清单逐项比对，不解包、不安装。任一步失败都应停止；无需安装 `shasum`、`unzip` 或 `rg`。这些摘要仍不是发行签名。各系统的实际验收结果以 Release 或对应回归记录为准，不以提供命令代替实测。

ZIP 根目录应包含 `info.ini`、`config.json`、`install.sql`、`update.sql`、`uninstall.sql`、许可证与说明，以及 `plugin/sand-iam/`、`sandadmin-artd/src/views/plugin/sand-iam/`、SDK 和用户文档。元数据版本应为 `0.7.6`。

`manifest.json` 使用 `sand-iam.independent-release-manifest/v1`，记录独立仓库源码 commit/tree、包 SHA-256、条目数、逐文件 SHA-256、构建工具版本及两次构建是否一致。它不采用旧聚合工作区的 v8 签名证明格式，不应交给旧版 `verify-release-bundle.php` 当作正式签名包验证。

## 从可信源码重复构建

需要复核来源时，先通过可信渠道核对源码 commit，再检出固定 commit 的干净独立仓库。不要使用从待验证 ZIP 中解出的程序证明该 ZIP 自身可信。

公开 `v0.7.6-preview` 对应源码 `5c5e19c06ba39d45e670d9d5b80e5d0dd965e8bf` 的构建器只接受 Unix 绝对路径，不能原样在 Windows 上运行。下面的 Windows 命令需要包含跨平台修复的可信源码 revision；当前修复候选见 [`codex/iam076-cross-platform-regression`](https://github.com/supdger/sand-iam/tree/codex/iam076-cross-platform-regression)，使用前须核对并固定该候选的具体 commit。该候选不是已发布 0.7.6 的原源码，其用户文档也已改变，不能要求整包摘要与旧 ZIP 相同。回归中的旧包复核固定原源码，只替换构建工具并提交隔离夹具，再将全部载荷文件摘要与原发布清单逐项比较；它不改写已发布资产。

独立仓库中的构建入口是：

需要 Git、PHP `>=8.2` 与 PHP `zip` 扩展。在干净独立源码仓库根目录执行；命令和路径须使用代码块中的英文半角符号，中文标点可保留在业务数据中。

macOS：

```sh
php tools/build-independent-release.php /absolute/path/to/new-artifacts
```

Windows PowerShell（使用已存在的系统临时目录作为父目录）：

```powershell
$artifacts = Join-Path $env:TEMP ('sand-iam-build-' + [Guid]::NewGuid().ToString('N'))
php .\tools\build-independent-release.php $artifacts
if ($LASTEXITCODE -ne 0) { throw '重复构建失败，请查看上一条错误。' }
Write-Host "构建产物：$artifacts"
```

macOS 示例的参数须替换为仓库外、父目录已存在且目标目录尚不存在的绝对路径；Windows 入口接受完整盘符路径，也可使用具有写权限的完整 UNC 共享路径，不接受 `C:目录` 或 `\目录` 这类依赖当前驱动器的路径。路径含空格时必须作为单个参数传递，手写字面路径请加引号。

命令从已提交 Git blobs 分别生成 `primary` 和 `repeat` 两份包，固定条目顺序、时间和权限，并核对冻结依赖文件摘要与锁文件；任何不一致都会以非零状态码退出。Git 的工作区 CRLF 转换不改变所读取的已提交字节；程序不替换 SQL、业务数据中的标点或反斜杠。结果中的 `SHA256SUMS`、`manifest.json` 与发布件逐项比较。构建环境 PHP、zip 扩展及 libzip 版本见 manifest，不同压缩工具版本可能影响 ZIP 字节，跨环境应同时比对清单中逐文件摘要。

`release-build-contract.json` 中的 `toolchain` 及 Composer/TypeScript 参数记录冻结依赖的原生成条件；本次重打包实际使用的 PHP、zip 和 libzip 版本以 `manifest.json` 的 `toolchain` 为准。本构建使用固定 Git 依赖文件，不声称执行了旧聚合工作区的 Composer/TypeScript 重新生成流程。源码仓库的工具不进入安装 ZIP；安装 ZIP 也不是完整开发仓库。

校验成功只证明相应字节一致，不证明目标 SandAdmin、PostgreSQL、身份提供方或业务系统已经兼容。安装前仍需备份，并按[安装与升级](installation-and-upgrade.md)检查精确来源版本及账本条件。管理端源码存在也不代表宿主已构建和部署前端。
