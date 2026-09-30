# Quick Reference

The commands you need most, by task. Run them on the server as a user with
`sudo`. For every option, see the [dscript CLI guide](dscript.md). For errors,
see [Troubleshooting](troubleshooting.md).

## Install and update

| I want to… | Run |
| --- | --- |
| Install dPanel on a new server | `curl -fsSL https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/installer.sh -o installer.sh && sudo bash installer.sh` |
| Update to the latest release | `sudo bash installer.sh update` |
| Update to unreleased fixes on `main` | `sudo bash installer.sh --version main update` |
| Re-run the update steps with the code already on the server (no download) | `sudo dpanel chain update` |
| See what an update would do, without changing anything | `sudo dpanel --dry-run chain update` |
| See which version is installed | `sudo grep -E '^(APP_VERSION\|DPANEL_RELEASE_)' /var/www/dpanel/.env` |
| Install one module | `sudo dpanel chain install php` (several: `php,mariadb`) |
| Skip a module during an update | Answer `skip` when it asks `[Y/n/skip]` |

`installer.sh update` downloads new code; `dpanel chain update` does not. Use
the installer when you want a fix that was just released or merged.

## Check health

| I want to… | Run |
| --- | --- |
| Find what is broken | `sudo dpanel doctor` |
| Try to repair it | `sudo dpanel doctor --fix` |
| See the install/update log | `sudo dpanel logs install` or `sudo tail -n 200 /opt/dpanel/logs/install.log` |
| See server and module info | `dpanel info` |
| Check the Rust services | `sudo systemctl status drust.service edge-gateway.service` |
| Check the drust API | `curl http://127.0.0.1:9500/health` |
| Read drust logs | `journalctl -u drust.service -n 100 --no-pager` |
| Read gateway logs | `journalctl -u edge-gateway.service -n 100 --no-pager` |

## PHP, Node.js, and build tools

| I want to… | Run |
| --- | --- |
| List installed PHP versions | `dpanel php versions` |
| Install a PHP version | `sudo dpanel php install 8.3` |
| Check a PHP-FPM config and reload it | `sudo php-fpm8.3 -t && sudo systemctl reload-or-restart php8.3-fpm` |
| Check Node.js and npm for website builds | `node -v; ls -l /usr/local/bin/npm` (expect `v20.x`) |
| Install or relink Node.js 20, npm, and composer | `sudo dpanel chain update` |

## Websites and files

| I want to… | Run |
| --- | --- |
| Fix ownership of every website | `sudo dpanel script run fix-permissions --all` |
| Fix one account | `sudo dpanel script run fix-permissions --user <site-user>` |
| Test a site without DNS | `curl -H 'Host: example.com' http://127.0.0.1/` |
| Issue SSL for a site | `sudo dpanel script run issue-ssl example.com /home/<site-user>/public_html` |
| See the certificate the gateway serves | `openssl s_client -connect 127.0.0.1:443 -servername example.com </dev/null \| openssl x509 -noout -subject -enddate` |
| Reload gateway certificates and config | `redis-cli PUBLISH edge:reload '{}'` |

## Panel

| I want to… | Run |
| --- | --- |
| Change the panel domain | `sudo dpanel script run set-panel-domain panel.example.com` |
| Request SSL for the panel | `cd /var/www/dpanel && sudo php artisan serverpanel:panel-ssl` |
| Clear Laravel caches after a config change | `cd /var/www/dpanel && sudo -u www-data php artisan optimize:clear` |
| Run new migrations | `cd /var/www/dpanel && sudo -u www-data php artisan migrate --force` |
| Rebuild the panel UI | `cd /var/www/dpanel && npm run build` |
| Rebuild and restart drust | `sudo /var/www/drust/deploy/install-service.sh` |

## Server accounts and SSH

| I want to… | Run |
| --- | --- |
| Unblock an IP banned by fail2ban | Panel → **Fail2ban**, or `sudo fail2ban-client unban <ip>` |
| See which IPs fail2ban blocks | `sudo fail2ban-client status sshd` |
| Create an admin (sudo) user | `sudo dpanel script run create-admin-user <username>` |
| Set a system user's password | `sudo dpanel script run set-system-user-password <username>` |
| List every maintenance script | `dpanel script list` |
| Show help for one script | `dpanel script help <name>` |

## Important paths

| Path | What it is |
| --- | --- |
| `/var/www/dpanel` | Laravel/Vue panel (`.env` holds the version and drust token) |
| `/var/www/drust` | Rust API and edge gateway source |
| `/var/www/dscript` | Installer and recovery toolkit that `dpanel` runs |
| `/etc/drust/drust.env` | drust API settings and token |
| `/etc/drust/edge-gateway.env` | Gateway ports, panel domain, PHP pool settings |
| `/opt/dpanel/logs/install.log` | Install and update log |
| `/opt/dpanel/node-v20.19.0` | Node.js 20 used for builds, linked into `/usr/local/bin` |
| `/home/<site-user>/public_html` | A website's files |
