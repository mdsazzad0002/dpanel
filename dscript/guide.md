# dscript guide

This repo provides the shell entrypoint used by `dpanel` for installer and maintenance tasks.

## Quick Start

From the project root:

```bash
./dpanel info
./dpanel install
./dpanel update
./dpanel php versions
```

If `dpanel` is on your `PATH`, you can use:

```bash
dpanel info
dpanel php versions
```

## Top-Level Commands

### `install`

Installs the full panel stack when no module is provided.

Usage:

```bash
dpanel install
dpanel install <module>
```

Examples:

```bash
dpanel install
dpanel install php
dpanel install site:create example.com bdsoft yes nginx /home/bdsoft/public_html
```

### `update`

Updates the full panel stack when no module is provided.

Usage:

```bash
dpanel update
dpanel update <module>
```

Examples:

```bash
dpanel update
dpanel update php
```

### `remove`

Removes a module.

Usage:

```bash
dpanel remove <module> [version]
```

Examples:

```bash
dpanel remove php 8.3
```

### `info`

Prints server metadata and installed module state.

Usage:

```bash
dpanel info
```

### `site:create`

Scaffolds a site configuration.

Usage:

```bash
dpanel site:create <domain> <username> [php_version] [ssl] [web_server] [root_path]
```

Example:

```bash
dpanel site:create example.com bdsoft 8.3 yes nginx /home/bdsoft/public_html
```

### `php`

PHP management commands.

Usage:

```bash
dpanel php <install|update|reinstall|default|versions|list|remove> [version|all]
```

Commands:

```bash
dpanel php versions
dpanel php list
dpanel php install
dpanel php install 8.3
dpanel php update
dpanel php update 8.3
dpanel php reinstall
dpanel php reinstall 8.3
dpanel php default 8.3
dpanel php remove 8.3
```

Behavior:

- `versions` shows the configured PHP versions together with current server install/default status.
- `list` behaves the same as `versions`.
- `install` without a version checks every PHP version listed in `repository/modules/php/php.json` and installs only the versions not already installed.
- `reinstall` behaves like `install` but forces a fresh install pass for the selected version(s).
- `update` without a version updates every PHP version listed there.
- `default <version>` switches the system CLI default and records it in `server.json`.

### `user:create`

Creates an admin user.

Usage:

```bash
dpanel user:create
```

### `ssh:disable-root`

Disables root SSH login.

Usage:

```bash
dpanel ssh:disable-root
```

### `filemanager`

File manager helper commands.

Usage:

```bash
dpanel filemanager <create|remove|exists|file-exists|user> <path>...
```

Examples:

```bash
dpanel filemanager create /var/www/example
dpanel filemanager remove /var/www/example
dpanel filemanager exists /var/www/example
dpanel filemanager file-exists /var/www/example/.env
dpanel filemanager user create bdsoft
```

## PHP Version Source

The available PHP versions are loaded from:

```bash
repository/modules/php/php.json
```

Current versions:

```text
7.4
8.0
8.1
8.2
8.3
8.4
8.5
```

## Useful Environment Variables

- `PANEL_BOOTSTRAP_MODE`: `install`, `update`, `info`, or `site:create`
- `PANEL_MODULES`: comma-separated module list for bootstrap install
- `PANEL_APP_DIR`: application path used for `.env` lookup
- `PANEL_APP_ENV_FILE`: explicit `.env` file path
- `PANEL_DB_NAME`: database name for bootstrap provisioning
- `PANEL_DB_USER`: database user for bootstrap provisioning
- `PANEL_DB_PASSWORD`: database password override
- `PHP_VERSION`: preferred PHP version when a single version is needed
- `LIKESOFT_BASE_URL`: remote base URL for downloading modules

## Notes

- `install` and `update` without a module run the bootstrap flow.
- The PHP shell commands are the preferred way to manage PHP versions now.
- `php versions` is the safest way to inspect what the shell considers valid.
