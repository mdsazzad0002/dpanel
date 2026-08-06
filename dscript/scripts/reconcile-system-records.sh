#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${PANEL_APP_DIR:-/var/www/dpanel}"
NON_INTERACTIVE=false
PANEL_DOMAIN_INPUT="${PANEL_DOMAIN:-}"
PANEL_DOMAIN_PROVIDED=false
[[ -n "$PANEL_DOMAIN_INPUT" ]] && PANEL_DOMAIN_PROVIDED=true
PANEL_ROOT_INPUT="${PANEL_ROOT:-$APP_DIR}"
SYSTEM_USER_NAME="${PANEL_SYSTEM_USER_NAME:-dpanel}"
SYSTEM_USER_EMAIL="${PANEL_SYSTEM_USER_EMAIL:-system@dpanel.localhost}"
SYSTEM_USER_PASSWORD="${PANEL_SYSTEM_USER_PASSWORD:-}"
ALIASES=()
ALIASES_PROVIDED=false

usage() {
  cat <<'EOF'
Usage: reconcile-system-records.sh [options]

  --domain DOMAIN        Panel's reserved main domain (website id 1)
  --alias DOMAIN         Panel alias; repeat for multiple aliases
  --root PATH            Panel project root
  --user-name NAME       Reserved user id 1 name
  --user-email EMAIL     Reserved user id 1 email
  --user-password PASS   Reserved user id 1 password
  --non-interactive      Apply supplied values/defaults without prompts
EOF
}

while (($#)); do
  case "$1" in
    --domain) PANEL_DOMAIN_INPUT="${2:-}"; PANEL_DOMAIN_PROVIDED=true; shift 2 ;;
    --alias) ALIASES+=("${2:-}"); ALIASES_PROVIDED=true; shift 2 ;;
    --root) PANEL_ROOT_INPUT="${2:-}"; shift 2 ;;
    --user-name) SYSTEM_USER_NAME="${2:-}"; shift 2 ;;
    --user-email) SYSTEM_USER_EMAIL="${2:-}"; shift 2 ;;
    --user-password) SYSTEM_USER_PASSWORD="${2:-}"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE=true; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf '[ERROR] Unknown option: %s\n' "$1" >&2; usage; exit 64 ;;
  esac
done

[[ -x "$APP_DIR/artisan" ]] || {
  printf '[ERROR] Laravel artisan not found: %s/artisan\n' "$APP_DIR" >&2
  exit 1
}

normalize_domain() {
  local value="${1,,}"
  value="${value#http://}"
  value="${value#https://}"
  value="${value%%/*}"
  value="${value%%:*}"
  printf '%s' "${value%.}"
}

valid_domain() {
  [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]]
}

confirm() {
  local prompt="$1" default="${2:-n}" answer
  if [[ "$NON_INTERACTIVE" == true || ! -t 0 ]]; then
    return 0
  fi
  read -rp "${prompt} [$([[ "$default" == y ]] && printf 'Y/n' || printf 'y/N')]: " answer
  answer="${answer,,}"
  [[ -z "$answer" ]] && answer="$default"
  [[ "$answer" == y || "$answer" == yes ]]
}

artisan_eval() {
  local code="$1"
  (cd "$APP_DIR" && php artisan tinker --execute="$code")
}

read_env_domain() {
  local app_url
  app_url="$(sed -n 's/^APP_URL=//p' "$APP_DIR/.env" 2>/dev/null | tail -n 1 | tr -d $'\r"'\')"
  normalize_domain "$app_url"
}

printf '\nExisting website/domain configuration\n'
printf '=====================================\n'
artisan_eval '
App\Models\Website::query()->orderByRaw("CASE WHEN id = '\''1'\'' THEN 0 ELSE 1 END")->orderBy("domain")
    ->get(["id","domain","root_path","start_directory","status","type"])
    ->each(fn ($w) => printf("%-38s %-30s root=%s start=%s status=%s type=%s\n",
        $w->id, $w->domain, $w->root_path ?: "-", $w->start_directory ?: "-", $w->status, $w->type));
'

existing_domain="$(artisan_eval 'echo (string) optional(App\Models\Website::query()->find("1"))->domain;' | tail -n 1)"
[[ -n "$PANEL_DOMAIN_INPUT" ]] || PANEL_DOMAIN_INPUT="$existing_domain"
[[ -n "$PANEL_DOMAIN_INPUT" ]] || PANEL_DOMAIN_INPUT="$(read_env_domain)"
PANEL_DOMAIN_INPUT="$(normalize_domain "$PANEL_DOMAIN_INPUT")"

configure_website=false
if [[ -n "$existing_domain" ]]; then
  if [[ "$PANEL_DOMAIN_PROVIDED" == true ]]; then
    confirm "Update reserved panel website id 1: ${existing_domain} -> ${PANEL_DOMAIN_INPUT}?" n && configure_website=true
  else
    confirm "Update reserved panel website id 1 (${existing_domain})?" n && configure_website=true
  fi
else
  confirm "Reserved panel website id 1 is missing. Create it?" y && configure_website=true
fi

if [[ "$configure_website" == true ]]; then
  if [[ "$NON_INTERACTIVE" != true && -t 0 ]]; then
    if [[ "$PANEL_DOMAIN_PROVIDED" != true ]]; then
      read -rp "Panel domain [${PANEL_DOMAIN_INPUT}]: " answer
      PANEL_DOMAIN_INPUT="$(normalize_domain "${answer:-$PANEL_DOMAIN_INPUT}")"
    fi
    read -rp "Aliases (comma/space separated, blank for none): " aliases_raw
    read -ra ALIASES <<< "${aliases_raw//,/ }"
    ALIASES_PROVIDED=true
  elif [[ "$ALIASES_PROVIDED" != true ]]; then
    existing_aliases="$(artisan_eval 'echo App\Models\Website::query()->whereIn("type", ["alis", "alias"])->where("assigned_user_id", 1)->orderBy("domain")->pluck("domain")->implode(",");' | tail -n 1)"
    IFS=',' read -ra ALIASES <<< "$existing_aliases"
  fi

  valid_domain "$PANEL_DOMAIN_INPUT" || {
    printf '[ERROR] Invalid panel domain: %s\n' "$PANEL_DOMAIN_INPUT" >&2
    exit 64
  }

  normalized_aliases=()
  for alias in "${ALIASES[@]}"; do
    alias="$(normalize_domain "$alias")"
    [[ -z "$alias" || "$alias" == "$PANEL_DOMAIN_INPUT" ]] && continue
    valid_domain "$alias" || { printf '[ERROR] Invalid alias: %s\n' "$alias" >&2; exit 64; }
    normalized_aliases+=("$alias")
  done
  aliases_csv="$(IFS=,; printf '%s' "${normalized_aliases[*]:-}")"

  export DPANEL_SYSTEM_DOMAIN="$PANEL_DOMAIN_INPUT"
  export DPANEL_SYSTEM_ALIASES="$aliases_csv"
  export DPANEL_SYSTEM_ROOT="${PANEL_ROOT_INPUT%/}"
  artisan_eval '
use App\Models\Website;
use Illuminate\Support\Facades\DB;

$domain = getenv("DPANEL_SYSTEM_DOMAIN");
$root = getenv("DPANEL_SYSTEM_ROOT");
$aliases = array_values(array_filter(explode(",", (string) getenv("DPANEL_SYSTEM_ALIASES"))));
$conflict = Website::query()->where("domain", $domain)->where("id", "!=", "1")->first();
if ($conflict) {
    throw new RuntimeException("Panel domain belongs to website {$conflict->id}; refusing to overwrite it.");
}
foreach ($aliases as $alias) {
    $conflict = Website::query()->where("domain", $alias)->whereNotIn("type", ["alis", "alias"])->first();
    if ($conflict) {
        throw new RuntimeException("Alias {$alias} belongs to website {$conflict->id}; refusing to overwrite it.");
    }
}
$values = fn (string $name, string $type) => [
    "domain" => $name, "root_path" => $root, "project_root" => $root,
    "start_directory" => "public", "site_owner" => "system",
    "php_version" => (string) env("PANEL_PHP_VERSION", "8.3"),
    "enable_ssl" => str_starts_with((string) config("app.url"), "https://"),
    "filemanager_show_hidden" => false, "assigned_user_id" => 1,
    "assigned_reseller_id" => null, "status" => "live", "type" => $type,
];
DB::transaction(function () use ($domain, $root, $aliases, $values) {
Website::query()->updateOrCreate(["id" => "1"], $values($domain, "primary"));
    $keep = [];
    foreach ($aliases as $alias) {
        $id = "1-alias-".substr(hash("sha256", $alias), 0, 24);
        $keep[] = $id;
        Website::query()->updateOrCreate(["id" => $id], array_merge($values($alias, "alias"), ["parent_id" => "1"]));
    }
    $query = Website::query()->whereIn("type", ["alis", "alias"])->where("assigned_user_id", 1)
        ->where(fn ($query) => $query->where("parent_id", "1")->orWhere("id", "like", "1-alias-%"));
    $keep === [] ? $query->delete() : $query->whereNotIn("id", $keep)->delete();
});
echo "Reserved panel website configured: {$domain} (id 1)\n";
'
fi

printf '\nExisting panel users\n'
printf '====================\n'
artisan_eval '
$user = App\Models\User::query()->find(1);
if ($user) {
    printf("%-6s %-30s %s\n", $user->id, $user->name, $user->email);
}
'

system_user_email="$(artisan_eval 'echo (string) optional(App\Models\User::query()->find(1))->email;' | tail -n 1)"
if [[ -n "$system_user_email" ]]; then
  [[ "$SYSTEM_USER_EMAIL" == "system@dpanel.localhost" ]] && SYSTEM_USER_EMAIL="$system_user_email"
fi
configure_user=false
if [[ -n "$system_user_email" ]]; then
  confirm "Update reserved system user id 1 (${system_user_email})?" n && configure_user=true
else
  confirm "Reserved system user id 1 is missing. Create it?" y && configure_user=true
fi

if [[ "$configure_user" == true ]]; then
  if [[ "$NON_INTERACTIVE" != true && -t 0 ]]; then
    read -rp "System user email [${SYSTEM_USER_EMAIL}]: " answer
    SYSTEM_USER_EMAIL="${answer:-$SYSTEM_USER_EMAIL}"
    read -rsp "Password (blank keeps existing or generates for a new user): " SYSTEM_USER_PASSWORD
    printf '\n'
  fi
  export DPANEL_SYSTEM_USER_NAME="$SYSTEM_USER_NAME"
  export DPANEL_SYSTEM_USER_EMAIL="${SYSTEM_USER_EMAIL,,}"
  export DPANEL_SYSTEM_USER_PASSWORD="$SYSTEM_USER_PASSWORD"
  artisan_eval '
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

$name = trim((string) getenv("DPANEL_SYSTEM_USER_NAME"));
$email = strtolower(trim((string) getenv("DPANEL_SYSTEM_USER_EMAIL")));
$password = (string) getenv("DPANEL_SYSTEM_USER_PASSWORD");
if ($name === "" || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException("A valid system user name and email are required.");
}
$conflict = User::query()->where("email", $email)->where("id", "!=", 1)->first();
if ($conflict) {
    throw new RuntimeException("System email belongs to user {$conflict->id}; refusing to overwrite it.");
}
DB::transaction(function () use ($name, $email, $password) {
    $user = User::query()->find(1) ?? new User();
    $values = ["id" => 1, "name" => $name, "email" => $email, "email_verified_at" => now()];
    if ($password !== "" || ! $user->exists) {
        $values["password"] = Hash::make($password !== "" ? $password : Str::password(32));
    }
    $user->forceFill($values)->save();
    Role::findOrCreate("admin");
    $user->syncRoles(["admin"]);
});
echo "Reserved system user configured: {$email} (id 1)\n";
'
fi

printf '[INFO] Reconciliation complete. user_id=1 and website/domain id=1 are reserved for the panel system.\n'
