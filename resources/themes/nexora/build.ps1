$ErrorActionPreference = 'Stop'
$source = Join-Path $PSScriptRoot 'assets\nexora.css'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$targetDir = Join-Path $projectRoot 'public\themes\shop\nexora'
$target = Join-Path $targetDir 'nexora.css'

if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
    throw "Nexora stylesheet source not found: $source"
}

if (-not (Test-Path -LiteralPath $targetDir -PathType Container)) {
    throw "Nexora public asset directory not found: $targetDir"
}

Copy-Item -LiteralPath $source -Destination $target -Force
$sourceHash = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
$targetHash = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash
if ($sourceHash -ne $targetHash) {
    throw 'Nexora stylesheet copy verification failed.'
}
Write-Output 'Nexora stylesheet built and verified.'