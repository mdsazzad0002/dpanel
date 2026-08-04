# First Install and Permissions

This guide covers the initial dPanel installation and the supported way to
repair website filesystem permissions.

## First installation

Run the installer on a supported server as a user with `sudo` access:

```bash
curl -fsSL https://installer.dengrweb.com/installer.sh -o installer.sh
chmod +x installer.sh
sudo ./installer.sh
```

Install or refresh the Rust services, then verify that they are running:

```bash
sudo /var/www/drust/deploy/install-service.sh
sudo systemctl status drust.service edge-gateway.service
```

The standard installation paths are:

```text
/var/www/dpanel       Laravel/Vue panel
/var/www/drust        Rust API and edge gateway
/var/www/dscript      installer and recovery runtime
/var/www/phpmyadmin   bundled phpMyAdmin
/etc/drust/drust.env
/etc/drust/edge-gateway.env
```

After changing either environment file, restart the services:

```bash
sudo systemctl restart drust.service edge-gateway.service
```

## Website ownership and permissions

Website roots normally use `/home/<site-user>/public_html` and should be owned
by the corresponding site account. Do not use broad permissions such as
`chmod -R 777`.

Repair every managed website:

```bash
sudo dpanel script run fix-permissions --all
```

Repair one website account:

```bash
sudo dpanel script run fix-permissions --user <site-user>
```

Repair one managed path for that account:

```bash
sudo dpanel script run fix-permissions --user <site-user> --path /home/<site-user>/public_html
```

Inspect ownership, traversal permissions, and ACLs when diagnosing access
problems:

```bash
namei -l /home/<site-user>/public_html
getfacl /home/<site-user>/public_html
```

Shared PHP fallback may require ACL or group access for `www-data`. Use the
provided permission-repair command so ownership and ACLs stay consistent with
dPanel's website records.

## Verification

Check the services and their recent logs:

```bash
sudo systemctl status drust.service edge-gateway.service
sudo journalctl -u drust.service -n 100 --no-pager
sudo journalctl -u edge-gateway.service -n 100 --no-pager
```

Test a configured hostname locally without changing DNS:

```bash
curl -H 'Host: example.com' http://127.0.0.1/
```

For the complete production and recovery reference, see the repository
[`README.md`](../README.md).
