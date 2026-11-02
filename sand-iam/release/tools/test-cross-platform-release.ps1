param([Parameter(Mandatory = $true)][string]$WorkDirectory)
$ErrorActionPreference = 'Stop'
$source = Split-Path -Parent $PSScriptRoot
$work = [IO.Path]::GetFullPath($WorkDirectory)
if (Test-Path -LiteralPath $work) { throw 'Use a new isolated verification directory.' }
New-Item -ItemType Directory -Path $work | Out-Null
$download = Join-Path $work '公开下载 中文 % !'
New-Item -ItemType Directory -Path $download | Out-Null
$release = 'https://github.com/supdger/sand-iam/releases/download/v0.7.6-preview'
Write-Host 'Step 1: download fixed public 0.7.6 preview assets'
foreach ($file in @('sand-iam-0.7.6.zip', 'SHA256SUMS', 'manifest.json')) {
    Invoke-WebRequest -Uri "$release/$file" -OutFile (Join-Path $download $file)
    Write-Host "Downloaded: $file"
}
$fixedHash = '7a04aff8a3fa6c9cd3ea537f459f40fc739cb7266c204f9aa46e37a6026814c5'
if ((Get-FileHash -LiteralPath (Join-Path $download 'sand-iam-0.7.6.zip') -Algorithm SHA256).Hash -ne $fixedHash) {
    throw 'Public preview differs from the fixed approved artifact.'
}
$verificationDoc = [IO.File]::ReadAllText((Join-Path $source 'docs/user-guide/release-package-verification.md'))
$installDoc = [IO.File]::ReadAllText((Join-Path $source 'docs/user-guide/installation-and-upgrade.md'))
$verifyBlocks = [regex]::Matches($verificationDoc, '(?s)```powershell\r?\n(.*?)\r?\n {0,3}```')
$installBlocks = [regex]::Matches($installDoc, '(?s)```powershell\r?\n(.*?)\r?\n {0,3}```')
if ($verifyBlocks.Count -ne 2 -or $installBlocks.Count -ne 1) { throw 'Documented command blocks changed; review the runner.' }
Write-Host 'Step 2: execute the actual documented PowerShell verification and frontend commands'
Push-Location -LiteralPath $download
try {
    & ([scriptblock]::Create($verifyBlocks[0].Groups[1].Value))
    & ([scriptblock]::Create($installBlocks[0].Groups[1].Value))
    $originalSum = [IO.File]::ReadAllBytes((Join-Path $download 'SHA256SUMS'))
    try {
        [IO.File]::WriteAllText((Join-Path $download 'SHA256SUMS'), ('0' * 64) + '  sand-iam-0.7.6.zip')
        $rejected = $false
        try { & ([scriptblock]::Create($verifyBlocks[0].Groups[1].Value)) } catch { $rejected = $true }
        if (!$rejected) { throw 'Documented verification accepted a bad checksum.' }
        Write-Host 'PASS: documented checksum failure stops verification'
    } finally { [IO.File]::WriteAllBytes((Join-Path $download 'SHA256SUMS'), $originalSum) }
} finally { Pop-Location }
Write-Host 'Step 3: reproduce fixed public payload from Git blobs in native Unicode/space/%/! path'
$nativeSource = Join-Path $work '源码 clone % !'
& git clone --no-hardlinks $source $nativeSource
if ($LASTEXITCODE -ne 0) { throw 'Cannot create isolated native checkout.' }
& git -C $nativeSource checkout --detach '5c5e19c06ba39d45e670d9d5b80e5d0dd965e8bf'
if ($LASTEXITCODE -ne 0) { throw 'Cannot check out fixed public source.' }
Copy-Item -LiteralPath (Join-Path $source 'tools/build-independent-release.php') -Destination (Join-Path $nativeSource 'tools/build-independent-release.php')
& git -C $nativeSource add tools/build-independent-release.php
if ($LASTEXITCODE -ne 0) { throw 'Cannot stage isolated builder patch.' }
& git -C $nativeSource -c 'user.name=Cross-platform regression' -c 'user.email=regression@example.invalid' commit -m 'Isolated builder regression against fixed public payload'
if ($LASTEXITCODE -ne 0) { throw 'Cannot commit isolated builder fixture.' }
Push-Location -LiteralPath $nativeSource
try {
    if ($IsWindows) {
        # The actual Windows documentation block creates an outside-source TEMP path.
        . ([scriptblock]::Create($verifyBlocks[1].Groups[1].Value))
    } else {
        $artifacts = Join-Path $work 'macos-artifacts'
        & php tools/build-independent-release.php $artifacts
        if ($LASTEXITCODE -ne 0) { throw 'Native macOS rebuild failed.' }
    }
    $rebuilt = Get-Content -LiteralPath (Join-Path $artifacts 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $public = Get-Content -LiteralPath (Join-Path $download 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($rebuilt.entries -ne $public.entries -or !$rebuilt.repeat_bit_identical) {
        throw 'Rebuild count or repeat identity differs.'
    }
    foreach ($property in $public.files.PSObject.Properties) {
        $actual = $rebuilt.files.PSObject.Properties[$property.Name]
        if ($null -eq $actual -or $actual.Value -ne $property.Value) {
            throw "Rebuilt public payload differs: $($property.Name)"
        }
    }
    Copy-Item -LiteralPath (Join-Path $artifacts 'manifest.json') -Destination (Join-Path $work 'rebuilt-manifest.json')
    Write-Host "PASS: $($rebuilt.entries) committed payload entries match the fixed public release"
    Write-Host "Native archive SHA-256: $($rebuilt.sha256); public SHA-256: $($public.sha256)"
} finally {
    Pop-Location
    if ($artifacts -and (Test-Path -LiteralPath $artifacts)) { Remove-Item -LiteralPath $artifacts -Recurse -Force }
}
Write-Host 'Step 4: verify native positive and negative builder fixtures'
& php (Join-Path $source 'tools/test-independent-release.php')
if ($LASTEXITCODE -ne 0) { throw 'Native builder regression failed.' }
Write-Host 'Success: public downloads, documented commands, committed payload and native path regressions'
