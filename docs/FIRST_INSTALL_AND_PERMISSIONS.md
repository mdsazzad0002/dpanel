# First-Time Install And Permission Repair Guide

This guide helps new users install dPanel and fix the most common first-run problem: website files created by one Linux user while PHP-FPM runs as another user.

Typical error:

```text
file_put_contents(...): Failed to open stream: Permission denied
```

The quick repair command is:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

## Quick Fix: Can Open Folder But Cannot Create, Edit, Or Delete

Sometimes first-time setup lets you enter a website folder from the file manager,
but create, edit, upload, unzip, or delete actions fail. That means the panel can
read the directory, but the web/PHP user does not have write permission.

Run this from terminal:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

Then refresh the panel and try the file manager action again.

For one website only:

```bash
/var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
```

Replace `example` with the real website user. You can find website roots with:

```bash
find /home -maxdepth 2 -type d -name public_html -print
```

If the script cannot call the API, restart `drust` and run it again:

```bash
sudo systemctl restart drust
/var/www/dscript/scripts/fix-permissions.sh --all
```

Manual fallback for one website:

```bash
sudo chown -R example:www-data /home/example/public_html
sudo find /home/example/public_html -type d -exec chmod 2775 {} +
sudo find /home/example/public_html -type f -exec chmod 0664 {} +
sudo setfacl -R -m u:example:rwx,u:www-data:rwx,g:www-data:rwx /home/example/public_html
sudo setfacl -R -d -m u:example:rwx,u:www-data:rwx,g:www-data:rwx /home/example/public_html
```

Use the dPanel repair command first. The manual fallback is only for servers
where the API service is not available yet.

## 1. Recommended First Install

Start from a fresh Ubuntu/Debian server with sudo access.

```bash
cd /var/www
git clone https://github.com/mdsazzad0002/dpanel.git dpanel-source
cd dpanel-source
sudo ./installer.sh chain install
```

After installation, check the stack:

```bash
dpanel doctor
sudo systemctl status drust
sudo systemctl status nginx
sudo systemctl status apache2
```

If the server already has this repository at `/var/www`, run:

```bash
sudo /var/www/installer.sh chain install
```

## 2. Why Permission Issues Happen

Most hosted projects live under:

```text
/home/<site-user>/public_html
```

The project owner is usually the site user, for example:

```text
pos_localhost
example_com
client_site
```

But PHP-FPM and web server processes commonly run as:

```text
www-data
```

If files are owned by the site user only, PHP may not be able to write generated files, cache files, logs, uploads, sitemap files, or compiled Laravel views.

## 3. Standard Permission Model

dPanel expects this durable model:

- owner: the website Linux user
- group: `www-data`
- directories: group writable and setgid enabled
- files: owner/group writable where needed
- default ACL: future files inherit write access for the site user and `www-data`
- world/public write permission disabled

This means both the file manager user and PHP-FPM can work with the same project without using unsafe `777` permissions.

## 4. Fix All Projects

Run this after first install, after migrating projects, after extracting zip files, or after uploading files from another user:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

This scans:

```text
/home/*/public_html
```

and repairs every detected project.

## 5. Fix One Project

By path:

```bash
/var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
```

By username:

```bash
/var/www/dscript/scripts/fix-permissions.sh --user example
```

## 6. Verify Permission Repair

Replace `example` with your site user.

```bash
stat -c '%U:%G %A %n' /home/example/public_html
sudo -u www-data sh -c 'echo ok > /home/example/public_html/.permission-test && rm /home/example/public_html/.permission-test'
```

Expected result:

- owner is the site user
- group is `www-data`
- directory has group write access
- `www-data` write test succeeds

For Laravel projects:

```bash
cd /home/example/public_html
sudo -u www-data php artisan optimize:clear
```

## 7. Laravel Writable Paths

Laravel commonly needs write access to:

```text
storage/
bootstrap/cache/
public/
```

Examples of generated files:

```text
storage/logs/laravel.log
storage/framework/views/*
bootstrap/cache/*.php
public/sitemap.xml
public/rss.xml
public/uploads/*
```

The permission repair command handles these paths when they exist.

## 8. API-Based Permission Repair

The wrapper script calls the local `drust` API:

```text
POST /api/v1/filemanager/fix-permissions
```

Repair all projects:

```json
{
  "all": true
}
```

Repair one project:

```json
{
  "username": "example",
  "root_path": "/home/example/public_html"
}
```

The API must be called with:

```http
Authorization: Bearer <DRUST_API_TOKEN>
```

## 9. If The Command Fails

Check `drust` first:

```bash
sudo systemctl status drust
sudo journalctl -u drust -n 100 --no-pager
```

Check token config:

```bash
sudo cat /etc/drust/drust.env
grep SERVERPANEL_EXECUTION_API_TOKEN /var/www/dpanel/.env
```

The token in `/etc/drust/drust.env` must match the token Laravel uses.

Check if ACL tools exist:

```bash
command -v setfacl
command -v getfacl
```

If missing:

```bash
sudo apt update
sudo apt install acl
```

Then run:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

## 10. Do Not Use These As Permanent Fixes

Avoid:

```bash
chmod -R 777 /home/example/public_html
chown -R www-data:www-data /home/example/public_html
```

Why:

- `777` allows unnecessary public write access
- `www-data:www-data` can break file manager ownership
- future uploaded/generated files can repeat the same problem

Use the dPanel repair command instead:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

## 11. Good First-Run Checklist

After installation:

```bash
dpanel doctor
sudo systemctl status drust
/var/www/dscript/scripts/fix-permissions.sh --all
```

Then test one website:

```bash
stat -c '%U:%G %A %n' /home/example/public_html
sudo -u www-data sh -c 'echo ok > /home/example/public_html/.permission-test && rm /home/example/public_html/.permission-test'
```

If both commands pass, the project is ready for normal file manager and PHP runtime use.
