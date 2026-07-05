নিচের guideline অনুযায়ী বানালে তোমার custom panel cPanel-like behave করবে: same port 2083, token-based URL, secure cookie/session/IP/UA check, per-account Linux user, phpMyAdmin signon, MariaDB/PostgreSQL optional, mail, job/agent system, installer package—সব security-first.

1. Final target architecture

তোমার server structure হবে এমন:

VPS / Self-hosted Ubuntu Server
├── cp.yourdomain.com:2083          # Custom panel, cPanel-like port
├── Laravel Panel                   # UI, auth, permission, job create/status
├── Token Session System            # cpsessTOKEN + cookie + IP + UA
├── panel-agent.service             # external backend worker/agent
├── /usr/local/panel/scripts/       # root-owned safe scripts
├── /home/<account>/                # per hosting account Linux user
├── nginx                           # web server
├── php-fpm                         # per-account pool
├── MariaDB                         # primary database
├── PostgreSQL optional             # optional DB service
├── phpMyAdmin                      # signon auth, panel protected
├── Postfix/Dovecot                 # mail channel
├── SSL/Let's Encrypt               # panel + websites + mail
└── backup/restore/transfer system

High-level flow:

Browser
  ↓
https://cp.yourdomain.com:2083
  ↓
Laravel Panel
  ↓
token + cookie + IP + user-agent + no-cache middleware
  ↓
server_jobs table
  ↓
panel-agent.service
  ↓
whitelisted root-owned scripts
  ↓
nginx / php-fpm / MariaDB / PostgreSQL / mail / SSL / backup

Laravel web request কখনো direct root command run করবে না। Laravel শুধু job create করবে। Actual system কাজ করবে external panel-agent.service.

2. cPanel-like behavior

তোমার URL structure cPanel-এর মতো হতে পারে:

https://cp.yourdomain.com:2083/login
https://cp.yourdomain.com:2083/cpsessTOKEN/frontend/dashboard
https://cp.yourdomain.com:2083/cpsessTOKEN/3rdparty/phpMyAdmin/index.php
https://cp.yourdomain.com:2083/cpsessTOKEN/webmail
https://cp.yourdomain.com:2083/cpsessTOKEN/files

কিন্তু security rule হবে:

URL token alone = useless
Cookie alone = useless
DB session ছাড়া কিছুই valid না
Every request = full verification

Every request এ check হবে:

1. URL token exists
2. token hash DB তে valid
3. secure HttpOnly cookie exists
4. cookie hash DB session এর সাথে match
5. IP address match
6. User-Agent hash match
7. session expired কিনা
8. revoked/logout কিনা
9. route permission আছে কিনা
10. response no-store/no-cache header
3. Main server directory structure

Install complete হওয়ার পর server এ এভাবে থাকবে:

/var/www/panel/
└── Laravel panel app

/usr/local/panel/
├── agent/
│   └── panel_agent.py
├── scripts/
│   ├── create-account.sh
│   ├── delete-account.sh
│   ├── suspend-account.sh
│   ├── unsuspend-account.sh
│   ├── create-site.sh
│   ├── delete-site.sh
│   ├── create-database.sh
│   ├── delete-database.sh
│   ├── create-mailbox.sh
│   ├── delete-mailbox.sh
│   ├── issue-ssl.sh
│   ├── backup-account.sh
│   ├── restore-account.sh
│   └── health-check.sh
├── logs/
└── config/

/etc/systemd/system/
└── panel-agent.service

/home/
├── armetal/
│   └── public_html/
├── fujitsujp/
│   └── public_html/
└── client2/
    └── public_html/

Panel app কখনো /home/customer/public_html এর ভিতরে রাখবে না। Panel থাকবে /var/www/panel, agent/scripts থাকবে /usr/local/panel.

4. Installer package structure

Development/package zip এর ভিতরে সব একসাথে থাকবে:

panel-installer/
├── bootstrap.sh
├── config.env
├── manifest.json
├── payload/
│   ├── panel.tar.gz
│   ├── agent/
│   │   └── panel_agent.py
│   ├── scripts/
│   │   ├── create-account.sh
│   │   ├── delete-account.sh
│   │   ├── suspend-account.sh
│   │   ├── create-site.sh
│   │   ├── create-database.sh
│   │   ├── create-mailbox.sh
│   │   ├── issue-ssl.sh
│   │   ├── backup-account.sh
│   │   └── restore-account.sh
│   ├── nginx/
│   │   ├── panel.conf.tpl
│   │   └── site.conf.tpl
│   ├── php-fpm/
│   │   └── pool.conf.tpl
│   ├── systemd/
│   │   └── panel-agent.service.tpl
│   ├── phpmyadmin/
│   │   └── config.inc.php.tpl
│   └── mail/
│       ├── postfix/
│       └── dovecot/
└── lib/
    ├── functions.sh
    ├── install-system.sh
    ├── install-nginx.sh
    ├── install-php.sh
    ├── install-mariadb.sh
    ├── install-postgresql.sh
    ├── install-panel.sh
    ├── install-agent.sh
    ├── install-phpmyadmin.sh
    ├── install-mail.sh
    ├── install-security.sh
    └── health-check.sh

Run হবে:

sudo bash bootstrap.sh --env config.env

Installer run হওয়ার পর unzip folder আর runtime dependency হবে না। Installer সবকিছু permanent location এ copy করবে:

payload/panel.tar.gz       → /var/www/panel
payload/agent              → /usr/local/panel/agent
payload/scripts            → /usr/local/panel/scripts
payload/systemd            → /etc/systemd/system
payload/nginx templates    → /etc/nginx/sites-available
5. config.env example

প্রতিটা server আলাদা হবে, তাই hardcode না করে config based install করবে:

PANEL_DOMAIN=cp.yourdomain.com
PANEL_PORT=2083
SERVER_IP=YOUR_SERVER_IP

PHP_VERSION=8.3

DB_ENGINE=mariadb
DB_NAME=panel_db
DB_USER=panel_user
DB_PASSWORD=change_this_strong_password

ENABLE_PHPMYADMIN=true
ENABLE_POSTGRESQL=false
ENABLE_MAIL=true

MAIL_HOSTNAME=mail.yourdomain.com
MAIL_DOMAIN=yourdomain.com

ADMIN_EMAIL=admin@yourdomain.com
ADMIN_PASSWORD=change_this_admin_password

MariaDB-এর official quickstart Debian/Ubuntu-তে mariadb-server এবং mariadb-client install করার কথা বলে এবং secure installation step recommend করে। PostgreSQL optional রাখলে installer শুধু ENABLE_POSTGRESQL=true হলে install/config করবে।

6. bootstrap.sh design

bootstrap.sh হলো main entrypoint. এটা বাকি lib/module import করবে।

#!/bin/bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${BASE_DIR}/config.env"

if [[ "${1:-}" == "--env" && -n "${2:-}" ]]; then
  ENV_FILE="$2"
fi

if [[ $EUID -ne 0 ]]; then
  echo "Run as root"
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE"
  exit 1
fi

source "$ENV_FILE"

source "${BASE_DIR}/lib/functions.sh"
source "${BASE_DIR}/lib/install-system.sh"
source "${BASE_DIR}/lib/install-nginx.sh"
source "${BASE_DIR}/lib/install-php.sh"
source "${BASE_DIR}/lib/install-mariadb.sh"
source "${BASE_DIR}/lib/install-postgresql.sh"
source "${BASE_DIR}/lib/install-panel.sh"
source "${BASE_DIR}/lib/install-agent.sh"
source "${BASE_DIR}/lib/install-phpmyadmin.sh"
source "${BASE_DIR}/lib/install-mail.sh"
source "${BASE_DIR}/lib/install-security.sh"
source "${BASE_DIR}/lib/health-check.sh"

export BASE_DIR PANEL_DOMAIN PANEL_PORT SERVER_IP PHP_VERSION
export DB_NAME DB_USER DB_PASSWORD
export ENABLE_PHPMYADMIN ENABLE_POSTGRESQL ENABLE_MAIL
export MAIL_HOSTNAME MAIL_DOMAIN ADMIN_EMAIL ADMIN_PASSWORD

install_system
install_nginx
install_php
install_mariadb

if [[ "${ENABLE_POSTGRESQL}" == "true" ]]; then
  install_postgresql
fi

install_panel
install_agent

if [[ "${ENABLE_PHPMYADMIN}" == "true" ]]; then
  install_phpmyadmin
fi

if [[ "${ENABLE_MAIL}" == "true" ]]; then
  install_mail
fi

install_security
health_check

echo "Install complete: https://${PANEL_DOMAIN}:${PANEL_PORT}"
7. Laravel panel responsibilities

Laravel করবে:

- login/auth
- admin/customer/reseller role
- token session create
- token/cookie/IP/UA middleware
- dashboard
- hosting account management UI
- job create
- job status/output display
- phpMyAdmin signon session create
- permission/policy check
- audit log

Laravel করবে না:

- shell_exec
- sudo command
- systemctl directly
- raw terminal execution
- Linux user creation directly

Bad:

shell_exec("sudo useradd $username");

Good:

ServerJob::create([
    'user_id' => auth()->id(),
    'action' => 'create_account',
    'payload' => [
        'username' => $username,
        'domain' => $domain,
        'php_version' => $phpVersion,
    ],
    'status' => 'pending',
]);
8. Core database design

Panel DB structure:

users
- id
- name
- email
- password
- role: admin/reseller/customer
- status
- two_factor_enabled
- created_at

panel_sessions
- id
- user_id
- token_hash
- cookie_hash
- ip_address
- user_agent_hash
- expires_at
- last_seen_at
- revoked_at
- created_at

hosting_accounts
- id
- owner_user_id
- reseller_user_id nullable
- username
- system_user
- primary_domain
- home_path
- package_id
- status: active/suspended/deleted
- created_at

domains
- id
- hosting_account_id
- domain
- document_root
- type: primary/addon/subdomain
- ssl_status

databases
- id
- hosting_account_id
- engine: mariadb/postgresql
- db_name
- db_user
- encrypted_password
- created_at

mail_domains
- id
- hosting_account_id
- domain
- status

mailboxes
- id
- hosting_account_id
- email
- password_hash
- quota_mb
- status

server_jobs
- id
- user_id
- hosting_account_id nullable
- action
- payload json
- status: pending/running/success/failed/cancelled
- output longtext
- exit_code
- locked_at
- started_at
- finished_at
- created_at

audit_logs
- id
- user_id
- action
- ip_address
- user_agent
- metadata json
- created_at

Access model:

admin    = all accounts/resources
reseller = accounts created under reseller
customer = own hosting_accounts only
9. Token-based session system

Login success হলে:

1. URL token generate
2. cookie proof token generate
3. token hash DB তে save
4. cookie hash DB তে save
5. IP save
6. User-Agent hash save
7. expires_at save
8. redirect /cpsessTOKEN/frontend/dashboard

Laravel login example:

$urlToken = bin2hex(random_bytes(32));
$cookieToken = bin2hex(random_bytes(32));

PanelSession::create([
    'user_id' => $user->id,
    'token_hash' => hash('sha256', $urlToken),
    'cookie_hash' => hash('sha256', $cookieToken),
    'ip_address' => request()->ip(),
    'user_agent_hash' => hash('sha256', (string) request()->userAgent()),
    'expires_at' => now()->addHours(2),
    'last_seen_at' => now(),
]);

return redirect("/cpsess{$urlToken}/frontend/dashboard")
    ->withCookie(cookie(
        name: 'panel_session_proof',
        value: $cookieToken,
        minutes: 120,
        path: '/',
        domain: null,
        secure: true,
        httpOnly: true,
        raw: false,
        sameSite: 'Lax'
    ));

Every request middleware:

$urlToken = $request->route('token');
$cookieToken = $request->cookie('panel_session_proof');

if (!$urlToken || !$cookieToken) {
    abort(403);
}

$session = PanelSession::where('token_hash', hash('sha256', $urlToken))
    ->where('cookie_hash', hash('sha256', $cookieToken))
    ->whereNull('revoked_at')
    ->where('expires_at', '>', now())
    ->first();

if (!$session) {
    abort(403);
}

if (!hash_equals($session->ip_address, $request->ip())) {
    $session->update(['revoked_at' => now()]);
    abort(403);
}

$currentUaHash = hash('sha256', (string) $request->userAgent());

if (!hash_equals($session->user_agent_hash, $currentUaHash)) {
    $session->update(['revoked_at' => now()]);
    abort(403);
}

if ($session->last_seen_at && $session->last_seen_at->lt(now()->subMinutes(30))) {
    $session->update(['revoked_at' => now()]);
    abort(403);
}

$session->update(['last_seen_at' => now()]);
auth()->loginUsingId($session->user_id);

Response headers:

$response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
$response->headers->set('Pragma', 'no-cache');
$response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
$response->headers->set('Referrer-Policy', 'no-referrer');
$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('X-Robots-Tag', 'noindex, nofollow');
10. Nginx panel config

Panel port 2083:

server {
    listen 2083 ssl http2;
    server_name ${PANEL_DOMAIN};

    root /var/www/panel/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/${PANEL_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${PANEL_DOMAIN}/privkey.pem;

    add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0" always;
    add_header Pragma "no-cache" always;
    add_header Expires "Sat, 01 Jan 2000 00:00:00 GMT" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Robots-Tag "noindex, nofollow" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/cpsess[0-9a-fA-F]+/3rdparty/phpMyAdmin/(.+\.php)$ {
        alias /usr/share/phpmyadmin/$1;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /usr/share/phpmyadmin/$1;
    }

    location ~ ^/cpsess[0-9a-fA-F]+/3rdparty/phpMyAdmin/(.*)$ {
        alias /usr/share/phpmyadmin/$1;
        index index.php;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }
}

Note: Nginx path allow করলেই security complete না। phpMyAdmin entry অবশ্যই Laravel /cpsessTOKEN/pma-login route দিয়ে signon session create করে redirect করবে।

11. panel-agent / job system

তুমি চাইছো job Laravel দিয়ে না চলুক। তাই Laravel শুধু DB row insert করবে। External agent run করবে।

Flow:

Laravel Panel
    ↓
server_jobs table
    ↓
panel-agent.service
    ↓
action whitelist
    ↓
payload validation
    ↓
/usr/local/panel/scripts/*.sh
    ↓
status/output update

panel-agent.service:

[Unit]
Description=Custom Hosting Panel Agent
After=network.target mariadb.service

[Service]
Type=simple
User=root
Group=root
ExecStart=/usr/bin/python3 /usr/local/panel/agent/panel_agent.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target

First version root agent দিয়ে simple রাখা যায়। Production hardened version:

- panelagent Linux user
- sudoers restricted scripts only
- scripts root-owned
- Laravel user has no sudo

Sudoers example:

panelagent ALL=(root) NOPASSWD: /usr/local/panel/scripts/create-account.sh
panelagent ALL=(root) NOPASSWD: /usr/local/panel/scripts/delete-account.sh
panelagent ALL=(root) NOPASSWD: /usr/local/panel/scripts/issue-ssl.sh
panelagent ALL=(root) NOPASSWD: /usr/local/panel/scripts/create-database.sh
panelagent ALL=(root) NOPASSWD: /usr/local/panel/scripts/create-mailbox.sh

Laravel queue নিজে long-running worker support করে এবং docs অনুযায়ী queue worker long-running process হিসেবে চলে; কিন্তু তোমার root/server tasks Laravel queue দিয়ে না চালিয়ে external agent চালানো safer separation।

12. Script standard

প্রতিটা script এই convention follow করবে:

script.sh --check
script.sh --dry-run args...
script.sh args...

Example:

sudo /usr/local/panel/scripts/create-account.sh --check
sudo /usr/local/panel/scripts/create-account.sh --dry-run armetal armetalbd.com 8.3
sudo /usr/local/panel/scripts/create-account.sh armetal armetalbd.com 8.3

Every script এর ভিতরে:

set -euo pipefail

Must include:

- input validation
- allowed values only
- no raw shell interpolation
- no user-supplied command
- dry-run mode
- clear exit code
- output log
13. Hosting account creation

cPanel-like system এ প্রতিটা hosting account এর জন্য আলাদা Linux user রাখবে:

username: armetal
domain: armetalbd.com
home: /home/armetal
webroot: /home/armetal/public_html
php-fpm pool: armetal

create-account.sh করবে:

1. username validate
2. domain validate
3. Linux user create
4. /home/username/public_html create
5. permission set
6. php-fpm pool create
7. nginx vhost create
8. nginx -t
9. php-fpm reload
10. nginx reload
11. DB status update via agent

Basic permission:

useradd -m -d "/home/$USERNAME" -s /usr/sbin/nologin "$USERNAME"
mkdir -p "/home/$USERNAME/public_html"
chown -R "$USERNAME:$USERNAME" "/home/$USERNAME"
chmod 711 "/home/$USERNAME"
chmod 750 "/home/$USERNAME/public_html"

PHP-FPM pool:

[${USERNAME}]
user = ${USERNAME}
group = ${USERNAME}
listen = /run/php/panel-${USERNAME}.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10

Nginx website:

server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};

    root /home/${USERNAME}/public_html;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/panel-${USERNAME}.sock;
    }
}
14. MariaDB system

MariaDB primary DB হিসেবে রাখো। Installer করবে:

- mariadb-server install
- root local socket auth keep
- panel DB create
- panel DB user create
- app .env write
- secure config

MariaDB official guide Debian/Ubuntu-তে package install + secure installation recommend করে।

Per hosting account DB naming convention:

username_dbname
username_dbuser

Example:

armetal_wp
armetal_wpuser

DB password panel DB তে plain রাখবে না। Laravel encryption use করো:

Crypt::encryptString($password);
Crypt::decryptString($encrypted);
15. PostgreSQL optional

PostgreSQL optional রাখো:

ENABLE_POSTGRESQL=false

Enable হলে installer:

- postgresql install
- panel read-only/management role optional
- create DB script install
- firewall external access closed by default

PostgreSQL DB job:

action = create_postgresql_database
payload = account_id, db_name, db_user

Default:

PostgreSQL local only
No public port 5432 open
Remote access disabled unless admin explicitly enables
16. phpMyAdmin signon auth

phpMyAdmin direct password/config auth না। Use signon auth.

phpMyAdmin documentation lists auth modes including config, cookie, http, and signon; signon allows login from prepared PHP session data or supplied script, which matches your panel-protected auto-login design.

Flow:

User clicks phpMyAdmin
    ↓
/cpsessTOKEN/pma-login
    ↓
Laravel middleware verifies token/cookie/IP/UA
    ↓
permission check: user owns DB or admin
    ↓
create PMA signon session
    ↓
redirect /cpsessTOKEN/3rdparty/phpMyAdmin/index.php

phpMyAdmin config generated by installer:

<?php

$cfg['blowfish_secret'] = '${PMA_BLOWFISH_SECRET}';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'PMA_single_signon';
$cfg['Servers'][$i]['SignonURL'] = 'https://${PANEL_DOMAIN}:${PANEL_PORT}/login';
$cfg['Servers'][$i]['LogoutURL'] = 'https://${PANEL_DOMAIN}:${PANEL_PORT}/logout';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['AllowNoPassword'] = false;

blowfish_secret per server generate করবে:

PMA_BLOWFISH_SECRET="$(openssl rand -base64 48 | tr -d '\n' | cut -c1-48)"

Never copy same secret to every server.

17. Mail channel

Mail stack:

Postfix = SMTP receive/send
Dovecot = IMAP/POP3 + auth
MariaDB = virtual domains/mailboxes
OpenDKIM/Rspamd optional = DKIM/spam filter
Roundcube optional = webmail

Dovecot official docs include virtual user setups with Postfix, which is the model you need for hosting panel mailboxes not tied to Linux system users.

Mail data model:

mail_domains
- hosting_account_id
- domain

mailboxes
- hosting_account_id
- email
- password_hash
- quota_mb

Mail DNS requirements:

mail.yourdomain.com A SERVER_IP
clientdomain.com MX 10 mail.yourdomain.com
clientdomain.com TXT "v=spf1 mx a:mail.yourdomain.com ip4:SERVER_IP ~all"
_dmarc.clientdomain.com TXT "v=DMARC1; p=none; rua=mailto:postmaster@clientdomain.com"
default._domainkey.clientdomain.com TXT "v=DKIM1; k=rsa; p=PUBLIC_KEY"

Mail ports:

25   SMTP receive
587  SMTP submission
465  SMTPS optional
993  IMAPS
995  POP3S optional

Cloudflare mail records must be DNS-only, not proxied.

Mail installer should be optional because mail is the most DNS-sensitive part:

ENABLE_MAIL=true
MAIL_HOSTNAME=mail.yourdomain.com

Install order:

1. hostname check
2. DNS check
3. SSL cert for mail hostname
4. Postfix install
5. Dovecot install
6. virtual mailbox DB config
7. DKIM setup
8. firewall ports
9. send/receive test
18. Security-first checklist

Minimum security:

- HTTPS only
- Panel on 2083 with SSL
- URL token + secure cookie + DB session
- IP + User-Agent binding
- inactivity timeout
- logout revoke
- no browser cache
- no raw shell command
- external agent only
- script whitelist
- input validation in Laravel and agent and script
- root-owned scripts
- Laravel user no sudo
- logs/audit trail
- fail2ban
- firewall
- no public DB ports
- no exposed phpMyAdmin without panel session
- no token in query string
- no secret committed in package

Nginx should avoid logging token path or use masked logs:

location ~ ^/cpsess[0-9a-fA-F]+/ {
    access_log off;
    try_files $uri $uri/ /index.php?$query_string;
}

Better production: custom masked log, not total off.

Firewall:

ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 2083/tcp
ufw allow 25/tcp
ufw allow 587/tcp
ufw allow 993/tcp
ufw --force enable

If mail disabled, don’t open mail ports.

19. Backup/restore/transfer system

Account backup package:

account-backup-username.tar.gz
├── meta.json
├── homedir.tar.gz
├── databases/
│   ├── mariadb-db.sql.gz
│   └── postgres-db.sql.gz optional
├── mail/
│   └── mailboxes.tar.gz
├── ssl/
├── dns.json
└── configs/

meta.json:

{
  "username": "armetal",
  "primary_domain": "armetalbd.com",
  "php_version": "8.3",
  "home_path": "/home/armetal",
  "databases": [
    {
      "engine": "mariadb",
      "name": "armetal_wp",
      "user": "armetal_wpuser"
    }
  ],
  "mailboxes": [
    "info@armetalbd.com"
  ]
}

Same-server ownership transfer:

Just change hosting_accounts.owner_user_id
Linux user stays same
Files stay same

Server-to-server transfer:

source backup → copy archive → checksum verify → destination restore → assign owner
20. Testing workflow on Windows

Windows native full test হবে না, কিন্তু Docker/WSL দিয়ে partial test possible.

Recommended:

1. Windows Docker/WSL2:
   - bash syntax
   - dry-run script
   - config template generation
   - agent logic

2. Ubuntu VM:
   - systemd
   - nginx
   - php-fpm
   - MariaDB
   - Linux users
   - panel-agent

3. Fresh VPS:
   - real DNS
   - SSL
   - mail
   - production-like test

Every shell script:

bash -n script.sh
bash script.sh --dry-run args...

Add .gitattributes:

*.sh text eol=lf
*.py text eol=lf
*.service text eol=lf
*.conf text eol=lf

Windows CRLF হলে bash error হতে পারে:

bad interpreter: /bin/bash^M
21. Development phases

এভাবে phase করে বানালে stable হবে।

Phase 1: Core panel
- Laravel panel install
- login
- token/cookie/IP/UA middleware
- admin/customer roles
- server_jobs table
- panel-agent service
- dry-run scripts
Phase 2: Hosting account
- create account
- Linux user
- /home/user/public_html
- nginx vhost
- php-fpm pool
- suspend/unsuspend/delete
Phase 3: Database
- MariaDB install
- create/delete database
- create/delete DB user
- encrypted password storage
- phpMyAdmin signon
Phase 4: SSL and domain
- issue SSL
- renew SSL
- addon domain
- subdomain
- redirect
Phase 5: Mail
- Postfix/Dovecot
- virtual mailbox
- mailbox create/delete
- DKIM
- SPF/DMARC guide
- webmail optional
Phase 6: Backup/transfer
- account backup
- account restore
- remote transfer
- checksum verify
Phase 7: Hardening
- fail2ban
- audit logs
- rate limit
- 2FA
- masked logs
- malware scan optional
- resource limit
22. Most important rule

তোমার final model এইটা হওয়া উচিত:

Panel = brain/UI
Agent = executor
Scripts = root operations
DB = source of truth
Token session = secure access layer
Per-account Linux user = isolation

Final safe behavior:

Admin logs in
    ↓
gets /cpsessTOKEN/...
    ↓
every request token+cookie+IP+UA check
    ↓
admin creates hosting account
    ↓
Laravel creates job only
    ↓
panel-agent picks job
    ↓
agent validates action/payload
    ↓
root-owned script creates Linux user/nginx/php-fpm/db/mail
    ↓
job output shown in panel

এভাবে বানালে তোমার panel cPanel-এর মতো behave করবে, কিন্তু architecture তোমার control-এ থাকবে এবং security-first থাকবে।