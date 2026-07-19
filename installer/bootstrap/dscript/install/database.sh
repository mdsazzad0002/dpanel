#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../env.sh"

dscript_info "Configuring database..."

# Wait for MariaDB to be ready
wait_for_mysql() {
  local max_attempts=30
  local attempt=0
  while ! mysqladmin ping -h "$PANEL_DB_HOST" --silent 2>/dev/null; do
    attempt=$((attempt + 1))
    if [[ $attempt -ge $max_attempts ]]; then
      dscript_die "MariaDB did not start within ${max_attempts} seconds."
    fi
    sleep 1
  done
}

wait_for_mysql

# Create database and user
if command -v mysql >/dev/null 2>&1; then
  dscript_info "Creating panel database: ${PANEL_DB_NAME}..."

  mysql -h "$PANEL_DB_HOST" -u root <<EOSQL || true
CREATE DATABASE IF NOT EXISTS \`${PANEL_DB_NAME}\`
  CHARACTER SET ${PANEL_DB_CHARSET}
  COLLATE ${PANEL_DB_COLLATION};

CREATE USER IF NOT EXISTS '${PANEL_DB_USER}'@'${PANEL_DB_HOST}' IDENTIFIED BY '${PANEL_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${PANEL_DB_NAME}\`.* TO '${PANEL_DB_USER}'@'${PANEL_DB_HOST}';
FLUSH PRIVILEGES;
EOSQL

  dscript_info "Database configured successfully."
else
  dscript_warn "MySQL client not found, skipping database setup."
fi
