# Websites and Apps

How dPanel runs website apps: the one-click installers, the Quick Actions on a
website, Git deployments, and the build tools they use.

## Contents

- [How website commands run](#how-website-commands-run)
- [One-click app installers](#one-click-app-installers)
- [Quick Actions](#quick-actions)
- [Git deployments](#git-deployments)
- [Run a command by hand](#run-a-command-by-hand)
- [When something fails](#when-something-fails)

## How website commands run

Every website belongs to its own Linux account, and its files live under
`/home/<site-user>/`. When the panel installs an app or builds assets, drust
runs the command **as that site user**, never as root, so every file it creates
already has the right owner.

The tools are looked up in a fixed order:

| Tool | Used from | Installed by |
| --- | --- | --- |
| `composer` | `/usr/local/bin/composer`, then `/usr/bin/composer` | `sudo dpanel chain update` |
| `npm` / `node` | `/usr/local/bin/npm`, then `/usr/bin/npm` | `sudo dpanel chain update` (Node.js 20) |
| `php` | The website's own PHP version | The PHP module |

Distro packages in `/usr/bin` are only a fallback. Ubuntu 22.04 ships Node.js
12 without npm and Composer 2.2, which modern Laravel and Vite projects reject.
Check what a server has with:

```bash
node -v; ls -l /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/composer
```

## One-click app installers

| App | What the installer does |
| --- | --- |
| Laravel | `composer create-project` from the skeleton or a starter kit, artisan setup (`.env`, key, migrations), then Breeze (Laravel 10) or `npm` install and build |
| WordPress | Downloads WordPress from wordpress.org and sets it up |
| Drupal | `drupal/recommended-project`, Drush, then `drush site:install` |
| Joomla | Unpacks Joomla, then runs its `installation/joomla.php install` command |
| CodeIgniter | `composer create-project codeigniter4/appstarter` |
| WHMCS | Unpacks your licensed WHMCS files, then runs its CLI installer |

At the end, each installer sets ownership and makes the app's writable folders
(for example Laravel's `storage` and `bootstrap/cache`) usable by the site
user and the web server.

Composer and npm steps may run for up to 15 minutes. Other steps have a
10 minute limit. The panel shows the last part of the output when a step fails.

## Quick Actions

The website manage page has a **Quick Actions** panel for projects you
uploaded or cloned yourself.

| Button | What it runs |
| --- | --- |
| **Fix Permissions** | Resets ownership and permissions for the project. For a Laravel app it also recreates missing `storage` and `bootstrap/cache` folders, copies `.env.example` to `.env` when `.env` is missing, and builds Vite assets when `public/build/manifest.json` is missing (it also removes a stale `public/hot` file) |
| **Install Composer Dependencies** | `composer install --no-interaction --prefer-dist --optimize-autoloader`. Needs `composer.json` |
| **Install & Build NPM** | `npm ci` when `package-lock.json` exists, otherwise `npm install`, then `npm run build`. Needs `package.json` |

The project folder must be inside `/home/<site-user>/`.

## Git deployments

A website can be deployed from a Git repository. The clone runs as the site
user.

- Only HTTPS URLs from GitHub, GitLab, or Bitbucket are accepted
  (`https://github.com/...`, `https://gitlab.com/...`, `https://bitbucket.org/...`).
- Branch names may use letters, digits, `.`, `_`, `/`, and `-`.
- For a private repository, add an access token. It is used only for the Git
  request and is hidden in the output as `***`.
- A push that changes files in `.github/workflows` needs a token with the
  `workflow` scope.

After a deployment, use **Install Composer Dependencies** and
**Install & Build NPM** if the project needs them.

## Run a command by hand

To see the full output of a failing step, run it yourself as the site user,
with the same tools the panel uses:

```bash
sudo -u <site-user> -H env PATH=/usr/local/bin:/usr/bin:/bin \
  bash -c 'cd /home/<site-user>/public_html && npm ci && npm run build'

sudo -u <site-user> -H env PATH=/usr/local/bin:/usr/bin:/bin \
  bash -c 'cd /home/<site-user>/public_html && composer install'
```

Never run these as root inside a website folder. Root-owned files break later
panel actions. If it has already happened, run
`sudo dpanel script run fix-permissions --user <site-user>`.

## When something fails

| Message | Go to |
| --- | --- |
| `npm is not installed` / `composer is not installed` | [Troubleshooting](troubleshooting.md#npm-is-not-installed-on-this-server--composer-is-not-installed-on-this-server) |
| `runuser: failed to execute npm` | [Troubleshooting](troubleshooting.md#runuser-failed-to-execute-npm-no-such-file-or-directory) |
| `npm ERR!` / `vite build failed` | [Troubleshooting](troubleshooting.md#npm-err-or-vite-build-failed) |
| `Invalid project root.` | The folder is outside `/home/<site-user>/` or does not exist |
| Permission denied while writing files | [Operations → Files cannot be created](operations.md#files-cannot-be-created-edited-or-uploaded) |
