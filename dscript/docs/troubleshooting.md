# Troubleshooting, recovery and repair

## First response

```bash
dpanel doctor
dpanel info
dpanel logs install
dpanel logs update
systemctl status nginx apache2 mariadb drust --no-pager
journalctl -u drust -n 100 --no-pager
```

## Failed module

```bash
dpanel module nginx info
sudo dpanel module nginx update
```

## Old runtime error

If `dpanel` says `Unknown command: script`, refresh the installed runtime:

```bash
sudo /var/www/dscript/dpanel runtime refresh
/usr/local/bin/dpanel --version
/usr/local/bin/dpanel script list
```

## Migrate from the old `/opt/likesoft` runtime

The current default is `/opt/dpanel`. Refresh explicitly from the checkout so
the launcher, local module repository and runtime all move to the new location:

```bash
sudo env DPANEL_BASE_DIR=/opt/dpanel /var/www/dscript/dpanel runtime refresh
hash -r
sudo env DPANEL_BASE_DIR=/opt/dpanel dpanel --version
sudo env DPANEL_BASE_DIR=/opt/dpanel dpanel chain install
```

After refresh, existing modules are read from `/opt/dpanel/repository`; a
network request is only a fallback when a requested local asset is absent.

## Safe repair

```bash
sudo dpanel doctor --fix
sudo dpanel chain repair
```

## Permission repair

```bash
sudo find /var/www/dscript -type f -name '*.sh' -exec chmod 0755 {} +
dpanel script list
```
