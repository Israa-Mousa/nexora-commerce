<#
  Nexora Commerce - copy the local MySQL store database to a managed MySQL (Aiven).

  Safety:
    * Reads the LOCAL database settings from .env (password never printed).
    * Always writes a fresh dump to storage\app\nexora-backups first.
    * The dump is created WITHOUT "DROP TABLE" statements.
    * Refuses to import unless the target database is EMPTY (no tables).
    * The target password is asked here (hidden input) and passed via MYSQL_PWD only.
    * Nothing is deleted locally.

  Usage (PowerShell, from the project folder):
    powershell -ExecutionPolicy Bypass -File .\nexora-db-to-aiven.ps1
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

$mysqlBin = 'C:\Program Files\MySQL\MySQL Server 8.0\bin'
$mysql = Join-Path $mysqlBin 'mysql.exe'
$mysqldump = Join-Path $mysqlBin 'mysqldump.exe'
if (-not (Test-Path $mysql) -or -not (Test-Path $mysqldump)) { throw "MySQL client not found in $mysqlBin" }

function Read-DotEnv([string]$path) {
    $map = @{}
    foreach ($line in Get-Content -LiteralPath $path) {
        if ($line -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') {
            $v = $Matches[2].Trim()
            if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) { $v = $v.Substring(1, $v.Length - 2) }
            $map[$Matches[1]] = $v
        }
    }
    return $map
}

$envMap = Read-DotEnv (Join-Path $root '.env')
$src = @{
    Host = $envMap['DB_HOST']; Port = $envMap['DB_PORT']; Db = $envMap['DB_DATABASE']
    User = $envMap['DB_USERNAME']; Pass = $envMap['DB_PASSWORD']
}
if (-not $src.Port) { $src.Port = '3306' }
Write-Host "Local database: $($src.Db) on $($src.Host):$($src.Port) (user $($src.User))"

# ---------- 1. Fresh local dump ----------
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $root 'storage\app\nexora-backups'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$dumpData = Join-Path $backupDir "db-export-$stamp.sql"
$dumpSkel = Join-Path $backupDir "db-export-$stamp-volatile-schema.sql"

$volatile = @('cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs')
$common = @("--host=$($src.Host)", "--port=$($src.Port)", "--user=$($src.User)", '--single-transaction', '--routines', '--triggers',
            '--set-gtid-purged=OFF', '--no-tablespaces', '--skip-add-drop-table', '--default-character-set=utf8mb4', '--hex-blob', '--column-statistics=0')

$env:MYSQL_PWD = $src.Pass
try {
    $ignore = $volatile | ForEach-Object { "--ignore-table=$($src.Db).$_" }
    Write-Host 'Dumping store data (cache and session tables excluded)...'
    & $mysqldump @common @ignore "--result-file=$dumpData" $src.Db
    if ($LASTEXITCODE -ne 0) { throw 'mysqldump (data) failed' }

    $existing = & $mysql "--host=$($src.Host)" "--port=$($src.Port)" "--user=$($src.User)" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$($src.Db)' AND table_name IN ('$($volatile -join "','")')"
    if ($existing) {
        Write-Host 'Dumping empty structure of cache/session tables...'
        & $mysqldump @common --no-data "--result-file=$dumpSkel" $src.Db @($existing -split "`r?`n" | Where-Object { $_ })
        if ($LASTEXITCODE -ne 0) { throw 'mysqldump (schema) failed' }
    }

    $counts = @{}
    foreach ($t in 'products', 'product_flat', 'categories', 'product_images', 'attribute_options', 'channels', 'locales', 'admins') {
        $counts[$t] = (& $mysql "--host=$($src.Host)" "--port=$($src.Port)" "--user=$($src.User)" -N -B -e "SELECT COUNT(*) FROM ``$($src.Db)``.``$t``" 2>$null)
    }
} finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }

$sizeMb = [math]::Round((Get-Item $dumpData).Length / 1MB, 2)
Write-Host "Backup written: $dumpData ($sizeMb MB)"

# ---------- 2. Target (Aiven) ----------
Write-Host ''
Write-Host 'Enter the Aiven MySQL connection details (Service overview > Connection information).'
$dst = @{}
$dst.Host = Read-Host 'Host'
$dst.Port = Read-Host 'Port'
$dst.User = Read-Host 'User [avnadmin]'; if (-not $dst.User) { $dst.User = 'avnadmin' }
$dst.Db = Read-Host 'Database [defaultdb]'; if (-not $dst.Db) { $dst.Db = 'defaultdb' }
$ca = Read-Host 'Path to the downloaded CA certificate (ca.pem)'
if (-not (Test-Path $ca)) { throw "CA file not found: $ca" }
$secure = Read-Host 'Password (hidden)' -AsSecureString
$plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))

$tls = @("--host=$($dst.Host)", "--port=$($dst.Port)", "--user=$($dst.User)", '--ssl-mode=VERIFY_CA', "--ssl-ca=$ca", '--default-character-set=utf8mb4')

$env:MYSQL_PWD = $plain
try {
    $tables = & $mysql @tls -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$($dst.Db)'"
    if ($LASTEXITCODE -ne 0) { throw 'Could not connect to the target database (check host, port, user, password and CA).' }
    if ([int]$tables -gt 0) {
        throw "Target database '$($dst.Db)' already has $tables table(s). Nothing was imported. Use an empty database."
    }

    $initCmd = 'SET SESSION sql_require_primary_key=0, FOREIGN_KEY_CHECKS=0, UNIQUE_CHECKS=0'
    foreach ($file in @($dumpData, $dumpSkel)) {
        if (-not (Test-Path $file)) { continue }
        Write-Host "Importing $(Split-Path -Leaf $file) ..."
        $sourcePath = $file -replace '\\', '/'
        & $mysql @tls "--init-command=$initCmd" $dst.Db -e "source $sourcePath"
        if ($LASTEXITCODE -ne 0) {
            throw "Import failed. If the error mentions sql_require_primary_key, turn it off in Aiven (Service > Advanced configuration), empty the database and run again."
        }
    }

    Write-Host ''
    Write-Host 'Row counts (local -> Aiven):'
    $ok = $true
    foreach ($t in $counts.Keys | Sort-Object) {
        $remote = & $mysql @tls -N -B -e "SELECT COUNT(*) FROM ``$($dst.Db)``.``$t``" 2>$null
        $flag = if ("$remote" -eq "$($counts[$t])") { 'OK' } else { $ok = $false; 'DIFF' }
        Write-Host ("  {0,-18} {1,8} -> {2,8}  {3}" -f $t, $counts[$t], $remote, $flag)
    }
    if ($ok) { Write-Host 'Done: all checked tables match.' -ForegroundColor Green } else { Write-Host 'Some counts differ - do not deploy until this is checked.' -ForegroundColor Yellow }
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    $plain = $null
}
