param([Parameter(Mandatory = $true)][string]$WorkDirectory)
$ErrorActionPreference = 'Stop'
$source = Split-Path -Parent $PSScriptRoot
$work = [IO.Path]::GetFullPath($WorkDirectory)
if (Test-Path -LiteralPath $work) { throw 'Use a new isolated PostgreSQL verification directory.' }
New-Item -ItemType Directory -Path $work | Out-Null
function Download-Fixed([string]$Url, [string]$File, [string]$Hash) {
    Invoke-WebRequest -Uri $Url -OutFile $File
    if ((Get-FileHash -LiteralPath $File -Algorithm SHA256).Hash -ne $Hash) { throw "Fixed download differs: $File" }
    Write-Host "Downloaded and verified: $([IO.Path]::GetFileName($File))"
}
Write-Host 'Step 1: download fixed public SQL inputs and executor'
Download-Fixed 'https://github.com/supdger/sand-iam/releases/download/sand-iam-v0.7.3-preview-21cd4d6/sand-iam-0.7.3-v10-20260921T045559Z-release-unsigned.zip' (Join-Path $work 'baseline.zip') 'a3648de10a47c1de263a24c5db5885c0199ab76efd28a1f41fcb7a99e92954ed'
Download-Fixed 'https://github.com/supdger/sand-iam/releases/download/v0.7.6-preview/sand-iam-0.7.6.zip' (Join-Path $work 'candidate.zip') '7a04aff8a3fa6c9cd3ea537f459f40fc739cb7266c204f9aa46e37a6026814c5'
Download-Fixed 'https://api.github.com/repos/supdger/sand-package/zipball/3d5a25363ff75298b7e2fa4689984cb8ac89a287' (Join-Path $work 'executor.zip') '296e68542bcfe18b18cb62bf111744700db9e84a4c48edf8159b170ad6c66022'
Download-Fixed 'https://raw.githubusercontent.com/supdger/sand-core/43b547a5651ec8912ad28792a577c5ea7f05de38/server/plugin/sandadmin/db/sandadmin-pure.pgsql' (Join-Path $work 'host.pgsql') '428eed64a47cadff565e19bef5f3c41f7f924d0d1a9ccabbba2bf1817aa4fc57'
Add-Type -AssemblyName System.IO.Compression.FileSystem
foreach ($name in @('baseline', 'candidate', 'executor')) {
    [IO.Compression.ZipFile]::ExtractToDirectory((Join-Path $work "$name.zip"), (Join-Path $work $name))
}
$executor = @(Get-ChildItem -LiteralPath (Join-Path $work 'executor') -Recurse -File -Filter 'PostgresLifecycleSqlExecutor.php')
if ($executor.Count -ne 1) { throw 'Cannot uniquely identify public 0.1.9 executor.' }
Write-Host 'Step 2: locate runner-provided PostgreSQL and start this job''s isolated instance'
$installation = @(Get-ChildItem -LiteralPath 'C:\Program Files\PostgreSQL' -Directory |
    Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'bin\initdb.exe') } |
    Sort-Object -Property Name -Descending | Select-Object -First 1)
if ($installation.Count -ne 1) { throw 'Runner has no usable preinstalled PostgreSQL binaries.' }
$bin = Join-Path $installation[0].FullName 'bin'
$data = Join-Path $work 'pg-data'
$started = $false
try {
    & (Join-Path $bin 'initdb.exe') -D $data -U postgres -A trust --encoding=UTF8 --locale=C
    if ($LASTEXITCODE -ne 0) { throw 'Isolated initdb failed.' }
    & (Join-Path $bin 'pg_ctl.exe') -D $data -l (Join-Path $work 'postgres.log') -o '-h 127.0.0.1 -p 55476' -w start
    if ($LASTEXITCODE -ne 0) { throw 'Isolated PostgreSQL startup failed.' }
    $started = $true
    & (Join-Path $bin 'createdb.exe') -h 127.0.0.1 -p 55476 -U postgres iam076_cross_platform
    if ($LASTEXITCODE -ne 0) { throw 'Isolated database creation failed.' }
    Write-Host 'Step 3: run public executor and real PostgreSQL against fixed release SQL'
    & php (Join-Path $source 'tools/test-independent-upgrade.php') `
        "--host-schema=$(Join-Path $work 'host.pgsql')" `
        "--baseline=$(Join-Path $work 'baseline')" `
        "--candidate=$(Join-Path $work 'candidate')" `
        "--executor=$($executor[0].FullName)" `
        --database=iam076_cross_platform --host=127.0.0.1 --port=55476 --user=postgres `
        "--output=$(Join-Path $work 'sql-summary.json')"
    if ($LASTEXITCODE -ne 0) { throw 'Native PostgreSQL regression failed.' }
} finally {
    if ($started) {
        Write-Host 'Step 4: stop and remove this job''s temporary PostgreSQL instance'
        & (Join-Path $bin 'pg_ctl.exe') -D $data -w stop
        if ($LASTEXITCODE -ne 0) { throw 'Temporary PostgreSQL cleanup failed.' }
    }
    if (Test-Path -LiteralPath $data) { Remove-Item -LiteralPath $data -Recurse -Force }
}
Write-Host 'Success: native Windows public SQL regression, temporary PostgreSQL cleaned'
