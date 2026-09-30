# Troubleshooting

Find the message you see, then follow the fix. Messages are grouped by where
they appear. If yours is not listed, see [General checks](#general-checks).

## Contents

- [Install and update](#install-and-update)
- [Website app installs, Composer, and npm](#website-app-installs-composer-and-npm)
- [Panel and drust](#panel-and-drust)
- [Websites and SSL](#websites-and-ssl)
- [General checks](#general-checks)

## Install and update

### An update ran, but a fix merged to `main` is not on the server

`sudo dpanel chain update` only re-runs the code already in `/var/www/dscript`;
it does not download anything new. `sudo ./installer.sh update` downloads a
fresh copy, but by default it takes the **latest release tag**, which may be
older than `main`.

To get unreleased fixes from `main`:

```bash
curl -fsSL https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/installer.sh -o /tmp/installer.sh
sudo bash /tmp/installer.sh --version main update
```

Check what is installed:

```bash
sudo grep -E '^(APP_VERSION|DPANEL_RELEASE_)' /var/www/dpanel/.env
```

`APP_VERSION=main-<sha>` means a `main` build; compare the SHA with
`git log -1` on GitHub.

### `Module ... is installed. Update it now? [Y/n/skip]`

The chain asks before touching each module. Press Enter or `y` to go ahead,
`n` or `skip` to leave that module alone and continue with the next one. You
can also type `install`, `update`, `remove`, or `reinstall`.

Without a terminal (a pipe or a cron job) the default answer is used.

### `[ERROR] Missing path argument.` then `Module failed; chain stopped: filemanager`

The server is running a dscript from before this was fixed. The filemanager
helper was called without a path and stopped the chain, so every step after
it (Node.js, panel build, permissions) was skipped.

- Quick way through: answer `skip` when asked about `filemanager`.
- Permanent fix: update from `main` as shown
  [above](#an-update-ran-but-a-fix-merged-to-main-is-not-on-the-server).

### `Command "serverpanel:vhost-resync" is not defined`

Harmless. Older dscript still called a vhost command that was removed when
nginx was replaced by the drust edge gateway. The update continues normally.
Updating from `main` removes the step.

### `[WARN] Package not available: php8.5-opcache`

Harmless. From PHP 8.5, OPcache is built into PHP and has no separate package.
Confirm with `php8.5 -v`, which lists `Zend OPcache`.

### `[WAIT] apt/dpkg is busy (PID ...)`

Another package manager holds the lock, usually `unattended-upgrades` right
after boot. The installer waits up to 30 minutes (`APT_LOCK_TIMEOUT`, in
seconds). Let it finish, or stop the process it names with `kill <PID>`.

### `Errors were encountered while processing: linux-firmware` / `Unmet dependencies`

A system package (often `linux-firmware` or a kernel) failed to configure
earlier, and apt now refuses every install. The installer repairs this by
itself: it runs `dpkg --configure -a` and `apt-get -f install`, removes old
kernels when `/boot` is nearly full, and then retries. If it still stops, it
names the broken packages. Fix them by hand, then run the update again:

```bash
df -h /boot                         # a full /boot is the usual cause
sudo dpkg --configure -a
sudo apt --fix-broken install
sudo apt autoremove --purge         # frees /boot by removing old kernels
```

### `Command failed at line ...` / `Module failed; chain stopped: <module>`

The chain stops at the first failing module. Look at the lines just above the
error, then:

```bash
sudo dpanel doctor
sudo tail -n 200 /opt/dpanel/logs/install.log
```

Fix the cause and run the same command again. Modules that already finished
are detected as installed and skipped.

### The install is killed or freezes on a small VPS

Composer, the Vite build, and the Rust build need a lot of memory. On servers
under 2 GB the installer adds a swap file automatically. If it was killed
anyway, check `free -m` and `dmesg | grep -i oom`, then re-run the installer.

## Website app installs, Composer, and npm

### `npm is not installed on this server` / `composer is not installed on this server`

Website builds use `/usr/local/bin/npm` (Node.js 20) or `/usr/bin/npm`, and
the same for `composer`. Neither was found.

```bash
command -v node npm composer; node -v
ls -l /usr/local/bin/npm /usr/local/bin/node /usr/local/bin/composer
```

Run `sudo dpanel chain update` (from `main` if the chain stops early, see
[above](#an-update-ran-but-a-fix-merged-to-main-is-not-on-the-server)).
Afterwards `node -v` should print `v20.x` and `/usr/local/bin/npm` should exist.

### `runuser: failed to execute npm: No such file or directory`

drust looked for `npm` on its own `PATH` and found nothing. This happens with
drust builds from before the fix, and on servers where only the distro Node.js
(for example `v12` on Ubuntu 22.04, which has no npm) is installed.

Update from `main`, then check that `/usr/local/bin/npm` exists as above.

### `Unable to start /usr/local/bin/npm: No such file or directory (os error 2)`

A short-lived drust build could not start `runuser` itself. npm is fine; update
drust from `main`:

```bash
sudo bash /tmp/installer.sh --version main update
# or rebuild drust alone:
sudo /var/www/drust/deploy/install-service.sh
```

### `node -v` prints `v12...` (or another version below 20)

That is the distro Node.js, too old for Vite. The installer puts Node.js 20 in
`/opt/dpanel/node-v20.19.0` and links it into `/usr/local/bin`. If
`/usr/local/bin/node` is missing, the update did not reach that step; run the
update again. To link it by hand on an x64 server:

```bash
cd /opt/dpanel && curl -fsSL https://nodejs.org/dist/v20.19.0/node-v20.19.0-linux-x64.tar.xz | sudo tar -xJ
sudo mv node-v20.19.0-linux-x64 node-v20.19.0
sudo ln -sfn /opt/dpanel/node-v20.19.0/bin/{node,npm,npx} /usr/local/bin/
hash -r; node -v
```

Use `linux-arm64` instead of `linux-x64` on ARM servers.

### `npm ERR!` or `vite build failed`

npm ran; the project itself failed to build. Read the output shown in the
panel. Common causes: a `package-lock.json` that does not match
`package.json` (`npm ci` refuses it), or a build that needs more memory. Try
the build as the site owner to see the full error:

```bash
sudo -u <site-user> -H bash -c 'cd /home/<site-user>/public_html && npm run build'
```

## Panel and drust

### Panel actions fail with `Unauthorized`

The panel and drust use different tokens. `DRUST_API_TOKEN` in
`/etc/drust/drust.env` must equal `SERVERPANEL_EXECUTION_API_TOKEN` in
`/var/www/dpanel/.env`. Re-running `sudo /var/www/drust/deploy/install-service.sh`
aligns them.

### Panel actions fail or hang

```bash
systemctl is-active drust.service
journalctl -u drust.service -n 100 --no-pager
curl http://127.0.0.1:9500/health
```

### `error: rustup is not installed at '/root/.cargo'`

Seen on Ubuntu 24.04, which packages `rustup` in apt. Older drust installers
used that package, but the build runs with `CARGO_HOME=/root/.cargo`, where
the distro rustup refuses to work. Current installers always use the official
rustup in `/root/.cargo`. Update from `main`, or rebuild drust alone:

```bash
sudo /var/www/drust/deploy/install-service.sh
/root/.cargo/bin/cargo --version
```

### `drust rebuild failed; keeping the running binary`

The Rust build failed during an update, so the previous drust is still
running. New drust fixes are not active. Run the build on its own to see the
error:

```bash
sudo /var/www/drust/deploy/install-service.sh
```

## Websites and SSL

See [Operations → Troubleshooting](operations.md#troubleshooting) for
websites that do not load, SSL certificates that are not served, and file
permission errors.

### `phpMyAdmin unavailable: PHP front controller missing: /var/www/phpmyadmin/index.php`

`/var/www/phpmyadmin` holds only dPanel's sign-on config, not phpMyAdmin
itself. Older dscript copied phpMyAdmin only from the distro package in
`/usr/share/phpmyadmin`, which is usually not installed. Current dscript
downloads the official phpMyAdmin release (checksum-verified) when no copy is
found.

Update from `main` (see
[above](#an-update-ran-but-a-fix-merged-to-main-is-not-on-the-server)), or
re-run only the phpMyAdmin step:

```bash
sudo dpanel script run configure-phpmyadmin-signon
ls /var/www/phpmyadmin/index.php
```

### `Can not authenticate to IMAP server: [AUTHENTICATIONFAILED] Authentication failed.`

Dovecot rejected the mailbox login. It checks passwords against the panel's
`mailboxes` table, so there are two usual causes:

- Dovecot's copy of the panel DB password (`/etc/dovecot/dpanel-sql.conf.ext`)
  is out of date, for example after `chain install mariadb` created a new one.
- The mailbox's stored hash no longer matches its password. Older
  `mail:migrate-dovecot-sql` runs could hash an existing hash.

Every update now repairs both. To repair right away:

```bash
sudo dpanel chain update
# or only the mail part:
cd /var/www/dpanel && sudo php artisan mail:repair-dovecot-auth
```

If it still fails, see the exact reason:

```bash
echo "auth_verbose = yes" | sudo tee /etc/dovecot/conf.d/99-dpanel-debug.conf && sudo systemctl reload dovecot
sudo doveadm auth test user@example.com 'PASSWORD'
sudo grep -iE "auth|imap-login" /var/log/mail.log | tail -20
sudo rm /etc/dovecot/conf.d/99-dpanel-debug.conf && sudo systemctl reload dovecot
```

`unknown user` means the mailbox has no mail home yet; run
`sudo php artisan mail:migrate-dovecot-sql` in `/var/www/dpanel`. If the
command reports a password that cannot be decrypted, reset that mailbox's
password in the panel.

### `Relay access denied` or `mail for <domain> loops back to myself`

Postfix does not know the domain is hosted here. Other servers get
`Relay access denied`, and local mail bounces with `loops back to myself`.
Current dscript configures Postfix to look up hosted domains, mailboxes, and
forwarding in the panel database and deliver to Dovecot over LMTP. It also
enables port 587 for mail clients. Update from `main` (or run
`sudo dpanel chain update`), then check:

```bash
postconf -m | grep -x mysql                                # Postfix MySQL support
postconf -h virtual_mailbox_domains virtual_transport
sudo postmap -q example.com proxy:mysql:/etc/postfix/dpanel-virtual-domains.cf          # prints 1
sudo postmap -q user@example.com proxy:mysql:/etc/postfix/dpanel-virtual-mailboxes.cf   # prints 1
ls -l /var/spool/postfix/private/dovecot-lmtp /var/spool/postfix/private/auth
```

The domain's MX record must point to this server, and the mailbox must be
`active` in the panel.

### Gmail bounces with `550-5.7.26 ... sender is unauthenticated`

The sending domain's DNS does not vouch for this server: SPF and DKIM both
fail. Open **Email → Mail DNS Guide**, pick the domain, and publish every row
it shows at the domain's DNS provider:

| Record | Name | Value |
| --- | --- | --- |
| MX | `@` | the server's mail host, priority 10 |
| TXT (SPF) | `@` | `v=spf1 ip4:<server IP> mx ~all` |
| TXT (DKIM) | `default._domainkey` | `v=DKIM1; k=rsa; p=...` (click **Generate DKIM** first) |
| TXT (DMARC) | `_dmarc` | `v=DMARC1; p=none; ...` |

All domains share one mail host, so no per-domain `mail.` A record is needed.
The host is `SERVERPANEL_MAIL_HOSTNAME` in `/var/www/dpanel/.env` when set
(for example the panel domain), otherwise Postfix's current hostname. Setting
it also makes Postfix announce that name. The server IP comes from
`SERVERPANEL_MAIL_SERVER_IP`, or is detected when that is empty.

Check the records after they propagate:

```bash
dig +short MX example.com
dig +short TXT example.com
dig +short TXT default._domainkey.example.com
dig +short TXT _dmarc.example.com
```

Also ask your hosting provider to set the server IP's reverse DNS (PTR) to the
mail host (`postconf -h myhostname`).

## General checks

```bash
sudo dpanel doctor                    # what is broken
sudo dpanel doctor --fix              # try to repair it
sudo tail -n 200 /opt/dpanel/logs/install.log
journalctl -u drust.service -n 100 --no-pager
journalctl -u edge-gateway.service -n 100 --no-pager
```

When you open an issue, include the command you ran, the error lines, and the
output of `dpanel doctor`, with passwords and tokens removed. See
[Reporting issues](../CONTRIBUTING.md#reporting-issues).
