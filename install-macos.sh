#!/usr/bin/env bash
set -euo pipefail

SKIP_MIGRATE="false"
for arg in "$@"; do
    case "$arg" in
        --skip-migrate)
            SKIP_MIGRATE="true"
            ;;
        *)
            echo "[ERROR] Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR=""

if [[ -f "${SCRIPT_DIR}/artisan" && -f "${SCRIPT_DIR}/composer.json" ]]; then
    PROJECT_DIR="${SCRIPT_DIR}"
elif [[ -f "${SCRIPT_DIR}/ServerPanel/artisan" && -f "${SCRIPT_DIR}/ServerPanel/composer.json" ]]; then
    PROJECT_DIR="${SCRIPT_DIR}/ServerPanel"
else
    fail "Cannot find Laravel project. Expected artisan/composer.json in root or ServerPanel/."
fi

cd "$PROJECT_DIR"

info() { echo "[INFO] $*"; }
fail() { echo "[ERROR] $*" >&2; exit 1; }
has_cmd() { command -v "$1" >/dev/null 2>&1; }

info "Running macOS local installer in $PROJECT_DIR"

has_cmd php || fail "php is required but not found in PATH."
has_cmd composer || fail "composer is required but not found in PATH."
has_cmd npm || fail "npm is required but not found in PATH."

if [[ ! -f .env && -f .env.example ]]; then
    cp .env.example .env
    info "Created .env from .env.example"
fi

info "Installing Composer dependencies"
composer install --no-interaction

info "Installing Node dependencies"
npm install

info "Generating application key"
php artisan key:generate --force

if [[ "$SKIP_MIGRATE" != "true" ]]; then
    info "Running migrations + seeders"
    php artisan migrate --seed
else
    info "Skipping database migration (--skip-migrate)"
fi

info "Building frontend assets"
npm run build

info "Done. Start app with: php artisan serve"
