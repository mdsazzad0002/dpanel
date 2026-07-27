#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${PANEL_APP_DIR:-/var/www/dpanel}"

usage() {
  cat <<'EOF'
Usage: create-first-laravel-user.sh <name> <email> [password]

If password is omitted, a random one is generated.
The user is created in the Laravel users table and assigned the admin role.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 2 ]]; then
  usage
  [[ $# -ge 1 ]] && exit 0 || exit 64
fi

name="$(printf '%s' "${1:-}" | xargs)"
email="$(printf '%s' "${2:-}" | xargs | tr '[:upper:]' '[:lower:]')"
password="${3:-}"

if [[ -z "$name" || -z "$email" ]]; then
  echo "[ERROR] Name and email are required." >&2
  exit 64
fi

if [[ -z "$password" ]]; then
  if command -v openssl >/dev/null 2>&1; then
    password="$(openssl rand -base64 18 | tr -d '=+/[:space:]' | cut -c1-16)"
  else
    password="$(tr -dc 'a-zA-Z0-9' </dev/urandom | head -c 16)"
  fi
  echo "[INFO] Generated password for ${email}: ${password}"
fi

if [[ ! -x "${APP_DIR}/artisan" ]]; then
  echo "[ERROR] Laravel artisan not found at ${APP_DIR}/artisan" >&2
  exit 1
fi

cd "$APP_DIR"

php artisan tinker --execute="
use App\\Models\\User;
use Illuminate\\Support\\Facades\\Hash;
use Spatie\\Permission\\Models\\Role;

\$name = ${name@Q};
\$email = ${email@Q};
\$password = ${password@Q};

Role::findOrCreate('admin');
\$user = User::query()->where('email', \$email)->first();
if (\$user) {
    \$user->forceFill([
        'name' => \$name,
        'password' => Hash::make(\$password),
        'email_verified_at' => now(),
    ])->save();
    echo \"Updated existing admin user: {\$email}\\n\";
} else {
    \$user = User::create([
        'name' => \$name,
        'email' => \$email,
        'password' => Hash::make(\$password),
        'email_verified_at' => now(),
    ]);
    echo \"Created admin user: {\$email}\\n\";
}
\$user->syncRoles(['admin']);
echo \"Laravel admin user setup completed\\n\";
"
