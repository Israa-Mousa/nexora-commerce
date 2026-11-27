<#
    Nexora Beauty & Personal Care - safe setup script (Windows / PowerShell)

    What it does, in order:
      1. Finds PHP and checks the pdo_mysql extension.
      2. Prints a read-only status report of the current catalog.
      3. Takes a full mysqldump backup into storage\app\nexora-backups\  (stops if the backup fails).
      4. Runs the idempotent NexoraBeautySeeder (adds only what is missing - safe to re-run).
      5. Re-indexes products and clears caches.
      6. Optionally activates the Nexora theme on the default channel (-ActivateTheme).
         Optionally reorganizes the storefront (-Reorganize): hides non-Nexora categories and disables
         non-Nexora products on the storefront - reversible from the JSON undo log, nothing deleted.
      7. Prints the status report again.

    It never runs migrate:fresh, DROP, TRUNCATE or deletes anything. Passwords are never printed.

    Usage (from the project folder):
      powershell -ExecutionPolicy Bypass -File .\nexora-setup.ps1                 # status only (no writes)
      powershell -ExecutionPolicy Bypass -File .\nexora-setup.ps1 -Seed           # backup + seed + reindex
      powershell -ExecutionPolicy Bypass -File .\nexora-setup.ps1 -Seed -ActivateTheme -Reorganize
    Optional: -Php "C:\path\to\php.exe"  -MysqlDump "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe"
#>
param(
    [switch]$Seed,
    [switch]$ActivateTheme,
    [switch]$Reorganize,
    [string]$Php = "",
    [string]$MysqlDump = ""
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
Set-Location $root

function Find-Exe([string]$given, [string]$name, [string[]]$candidates) {
    if ($given -and (Test-Path $given)) { return $given }
    $cmd = Get-Command $name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($c in $candidates) { foreach ($m in (Get-Item $c -ErrorAction SilentlyContinue)) { return $m.FullName } }
    return $null
}

# Bagisto 2.5 needs PHP >= 8.4.1 - prefer an 8.4+ install over an older one that happens to be on PATH.
$phpCandidates = @()
if ($Php) { $phpCandidates += $Php }
$phpCandidates += @(Get-Item "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.5*\php.exe", "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4*\php.exe", 'C:\xampp\php\php.exe', 'C:\laragon\bin\php\*\php.exe', 'C:\php\php.exe' -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
$onPath = Get-Command php -ErrorAction SilentlyContinue
if ($onPath) { $phpCandidates += $onPath.Source }

$php = $null
foreach ($candidate in $phpCandidates) {
    if (-not (Test-Path $candidate)) { continue }
    $ver = & $candidate -r 'echo PHP_VERSION_ID;'
    if ([int]$ver -ge 80401) { $php = $candidate; break }
    Write-Host "Skipping $candidate (PHP $ver is older than 8.4.1, which this project requires)" -ForegroundColor DarkYellow
}
if (-not $php) { throw 'No PHP 8.4.1+ found. Install it (winget install PHP.PHP.8.4) or pass -Php "C:\path\to\php.exe".' }
Write-Host "PHP: $php" -ForegroundColor DarkGray
& $php -v | Select-Object -First 1

$ini = & $php -r 'echo php_ini_loaded_file() ?: "";'
if (-not $ini) {
    throw "This PHP has no php.ini loaded. In $(Split-Path $php) copy php.ini-development to php.ini, enable the extensions listed in NEXORA-SETUP notes (pdo_mysql, mbstring, intl, gd, fileinfo, openssl, curl, zip, sodium), set extension_dir = `"ext`", then run again."
}

$modules = & $php -m
$missing = @('pdo_mysql', 'mbstring', 'intl', 'gd', 'fileinfo', 'openssl', 'curl', 'zip') | Where-Object { -not ($modules -match "^$_$") }
if ($missing) {
    throw "Enable these extensions in $ini (remove the leading ';' from each 'extension=...' line), then run again: $($missing -join ', ')"
}

Write-Host "`n== Current status (read-only) ==" -ForegroundColor Cyan
& $php database\seeders\data\tools\nexora-status.php
if ($LASTEXITCODE -ne 0) { throw 'Database is not reachable. Start the MySQL80 service (services.msc) and check DB_* in .env.' }

if (-not $Seed) {
    Write-Host "`nStatus only. Re-run with -Seed to back up the database and add the Nexora demo catalog." -ForegroundColor Yellow
    exit 0
}

# ---- 1) Backup -------------------------------------------------------------
$envMap = @{}
Get-Content .env | Where-Object { $_ -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$' } | ForEach-Object {
    $envMap[$Matches[1]] = $Matches[2].Trim().Trim('"')
}
$dump = Find-Exe $MysqlDump 'mysqldump' @('C:\xampp\mysql\bin\mysqldump.exe', 'C:\laragon\bin\mysql\*\bin\mysqldump.exe', 'C:\wamp64\bin\mysql\*\bin\mysqldump.exe', 'C:\Program Files\MySQL\MySQL Server *\bin\mysqldump.exe', 'C:\Program Files\MariaDB *\bin\mysqldump.exe')
if (-not $dump) { throw 'mysqldump was not found - no changes were made. Pass -MysqlDump "C:\path\to\mysqldump.exe".' }

$backupDir = Join-Path $root 'storage\app\nexora-backups'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = Join-Path $backupDir "db-$($envMap['DB_DATABASE'])-$stamp.sql"

Write-Host "`n== Backing up database '$($envMap['DB_DATABASE'])' ==" -ForegroundColor Cyan
$env:MYSQL_PWD = $envMap['DB_PASSWORD']
try {
    $dbHost = if ($envMap['DB_HOST'] -eq 'localhost') { '127.0.0.1' } else { $envMap['DB_HOST'] }
    & $dump --host=$dbHost --port=$($envMap['DB_PORT']) --user=$($envMap['DB_USERNAME']) --single-transaction --routines --triggers --default-character-set=utf8mb4 "--result-file=$backup" $envMap['DB_DATABASE']
    if ($LASTEXITCODE -ne 0) { throw "mysqldump exited with code $LASTEXITCODE" }
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}
$size = (Get-Item $backup).Length
if ($size -lt 10240) { throw "Backup looks too small ($size bytes) - stopping before any write." }
Write-Host ("Backup OK: {0}  ({1:N1} MB)" -f $backup, ($size / 1MB)) -ForegroundColor Green

# ---- 2) Seed ---------------------------------------------------------------
Write-Host "`n== Adding Nexora Beauty & Personal Care demo catalog ==" -ForegroundColor Cyan
& $php artisan db:seed --class="Database\Seeders\NexoraBeautySeeder" --force
if ($LASTEXITCODE -ne 0) { throw 'Seeder failed - see the message above. The database backup is in storage\app\nexora-backups.' }

if ($Reorganize) {
    Write-Host "`n== Reorganizing storefront for Beauty & Personal Care (reversible) ==" -ForegroundColor Cyan
    & $php artisan db:seed --class="Database\Seeders\NexoraReorganizeSeeder" --force
    if ($LASTEXITCODE -ne 0) { throw 'Reorganize step failed - see the message above. Backup is in storage\app\nexora-backups.' }
}

# ---- 3) Index + caches -----------------------------------------------------
Write-Host "`n== Re-indexing and clearing caches ==" -ForegroundColor Cyan
& $php artisan indexer:index --type=inventory --type=price --type=flat
& $php artisan optimize:clear
& $php artisan responsecache:clear

if ($ActivateTheme) {
    & $php database\seeders\data\tools\nexora-status.php --activate-theme | Select-Object -First 1
    & $php artisan optimize:clear | Out-Null
}

Write-Host "`n== Status after setup ==" -ForegroundColor Cyan
& $php database\seeders\data\tools\nexora-status.php
Write-Host "`nDone. Open $($envMap['APP_URL'])" -ForegroundColor Green
