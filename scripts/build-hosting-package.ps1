# Build OphthaMind hosting upload package (zip + SQL dump)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$deployDir = Join-Path $root 'deploy'
$outZip = Join-Path $deployDir 'ophthamind-hosting.zip'
$sqlOut = Join-Path $deployDir 'eye_system_hosting.sql'
$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null

Write-Host 'Exporting database...'
if (-not (Test-Path $mysqldump)) {
    throw "mysqldump not found at $mysqldump"
}

& $mysqldump -h 127.0.0.1 -P 3307 -u root eye_system `
    --single-transaction --routines --triggers `
    --default-character-set=utf8mb4 `
    --result-file=$sqlOut

$sqlSize = [math]::Round((Get-Item $sqlOut).Length / 1MB, 1)
Write-Host "SQL dump: $sqlOut ($sqlSize MB)"

if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    Write-Host 'Running composer install...'
    Push-Location $root
    composer install --no-dev --optimize-autoloader
    Pop-Location
}

$excludeDirs = @('venv', '.git', 'node_modules', 'deploy', '__pycache__')
$excludeNames = @('ophthamind-hosting.zip', '.env', '.env.local', '.hosting-deploy')

$staging = Join-Path $env:TEMP ("ophthamind_staging_" + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Force -Path $staging | Out-Null

Write-Host "Staging files to $staging ..."

Get-ChildItem -Path $root -Force | ForEach-Object {
    if ($excludeDirs -contains $_.Name) { return }
    if ($excludeNames -contains $_.Name) { return }
  Copy-Item -Path $_.FullName -Destination (Join-Path $staging $_.Name) -Recurse -Force
}

# Hosting env template -> .env (empty DB; hosting_setup.php fills in)
Copy-Item (Join-Path $root 'deploy\.env.hosting') (Join-Path $staging '.env') -Force
New-Item -ItemType File -Force -Path (Join-Path $staging '.hosting-deploy') | Out-Null

# SQL dump for one-click import via hosting_setup.php
Copy-Item $sqlOut (Join-Path $staging 'eye_system_hosting.sql') -Force
$deploySqlDir = Join-Path $staging 'deploy'
New-Item -ItemType Directory -Force -Path $deploySqlDir | Out-Null
Copy-Item $sqlOut (Join-Path $deploySqlDir 'eye_system_hosting.sql') -Force

# Ensure writable dirs
@('upload', 'storage\logs') | ForEach-Object {
    $p = Join-Path $staging $_
    New-Item -ItemType Directory -Force -Path $p | Out-Null
    if (-not (Test-Path (Join-Path $p '.gitkeep'))) {
        New-Item -ItemType File -Force -Path (Join-Path $p '.gitkeep') | Out-Null
    }
}

if (Test-Path $outZip) { Remove-Item $outZip -Force }
Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $outZip -CompressionLevel Optimal
Remove-Item $staging -Recurse -Force

$zipSize = [math]::Round((Get-Item $outZip).Length / 1MB, 1)
Write-Host ''
Write-Host 'DONE'
Write-Host "Upload zip: $outZip ($zipSize MB)"
Write-Host 'Steps:'
Write-Host '  1. Upload ophthamind-hosting.zip to 076790.unisza.work file manager'
Write-Host '  2. Extract into domain root (index.php must be at root)'
Write-Host '  3. Open https://076790.unisza.work/hosting_setup.php'
Write-Host '  4. Enter MySQL credentials from hosting panel + finish setup'
