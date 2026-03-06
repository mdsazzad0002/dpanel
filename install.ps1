param(
    [switch]$SkipMigrate
)

$ErrorActionPreference = "Stop"

function Info([string]$msg) { Write-Host "[INFO] $msg" }
function Fail([string]$msg) { throw $msg }

function Require-Cmd([string]$name) {
    if (-not (Get-Command $name -ErrorAction SilentlyContinue)) {
        Fail "$name is required but not found in PATH."
    }
}

Set-Location $PSScriptRoot

$projectDir = $null
if ((Test-Path (Join-Path $PSScriptRoot "artisan")) -and (Test-Path (Join-Path $PSScriptRoot "composer.json"))) {
    $projectDir = $PSScriptRoot
} elseif ((Test-Path (Join-Path $PSScriptRoot "ServerPanel\\artisan")) -and (Test-Path (Join-Path $PSScriptRoot "ServerPanel\\composer.json"))) {
    $projectDir = Join-Path $PSScriptRoot "ServerPanel"
} else {
    Fail "Cannot find Laravel project. Expected artisan/composer.json in root or ServerPanel\\."
}

Set-Location $projectDir
Info "Running Windows local installer in $projectDir"

Require-Cmd "php"
Require-Cmd "composer"
Require-Cmd "npm"

if (-not (Test-Path ".env") -and (Test-Path ".env.example")) {
    Copy-Item ".env.example" ".env"
    Info "Created .env from .env.example"
}

Info "Installing Composer dependencies"
composer install --no-interaction

Info "Installing Node dependencies"
cmd /c npm install

Info "Generating application key"
php artisan key:generate --force

if (-not $SkipMigrate) {
    Info "Running migrations + seeders"
    php artisan migrate --seed
} else {
    Info "Skipping database migration (--SkipMigrate)"
}

Info "Building frontend assets"
cmd /c npm run build

Info "Done. Start app with: php artisan serve"
