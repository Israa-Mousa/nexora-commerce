<#
  Nexora Commerce - copy the local MySQL store data into the Render PostgreSQL database.

  1. Deploy once on Render first (the deploy creates the tables).
  2. In Render: Dashboard > nexora-db > Connect > copy the "External Database URL".
  3. Run from the project folder:
       powershell -ExecutionPolicy Bypass -File .\nexora-db-to-render.ps1
     The URL is read from the clipboard or entered as hidden input.

  The local MySQL database is only read. Render data is backed up locally before replacement.
  Restore a saved backup with -RestoreBackup <path>.
#>
param(
    [string]$RestoreBackup = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

$php = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
if (-not (Test-Path $php)) { $php = (Get-Command php -ErrorAction Stop).Source }
$extDir = Join-Path (Split-Path -Parent $php) 'ext'
if (-not (Test-Path (Join-Path $extDir 'php_pdo_pgsql.dll'))) { throw "php_pdo_pgsql.dll was not found in $extDir" }

# Takes the URL from the clipboard (click the copy icon next to "External Database URL" in Render first).
$url = "$(Get-Clipboard -Raw)".Trim()
if ($url.StartsWith('postgres')) {
    Write-Host 'Using the Render database URL from the clipboard.'
    Set-Clipboard -Value ' '   # clear it so the password does not stay in the clipboard
} else {
    $secure = Read-Host 'Paste the External Database URL from Render (hidden)' -AsSecureString
    $url = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
}
if (-not $url.StartsWith('postgres')) { throw 'That does not look like a PostgreSQL URL (it should start with postgresql://).' }

$env:NEXORA_PG_URL = $url
try {
    $phpArgs = @(
        '-d', "extension_dir=$extDir",
        '-d', 'extension=pdo_mysql',
        '-d', 'extension=pdo_pgsql',
        '-d', 'extension=mbstring',
        (Join-Path $root 'tools\nexora-mysql-to-pgsql.php')
    )
    if ($RestoreBackup) {
        if (-not (Test-Path -LiteralPath $RestoreBackup -PathType Leaf)) { throw "Backup file not found: $RestoreBackup" }
        $phpArgs += "--restore=$RestoreBackup"
    }

    & $php @phpArgs
    if ($LASTEXITCODE -ne 0) { throw 'The database operation did not finish - see the messages above.' }
    Write-Host ''
    Write-Host 'Done. In Render, open nexora-commerce and press "Manual Deploy > Restart" (or tell Claude) so the store reloads.' -ForegroundColor Green
} finally {
    Remove-Item Env:\NEXORA_PG_URL -ErrorAction SilentlyContinue
    $url = $null
}
