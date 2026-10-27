# Supported distributions

dPanel supports fresh, systemd-based installations from these distribution
families:

| Distribution | Package stack | Status |
| --- | --- | --- |
| Ubuntu | APT / DEB | Supported |
| Debian | APT / DEB | Supported |
| Red Hat Enterprise Linux | DNF / RPM | Supported |
| Rocky Linux | DNF / RPM | Supported |
| AlmaLinux | DNF / RPM | Supported |
| CentOS Stream | DNF / RPM | Supported |
| Fedora Server | DNF / RPM | Supported |

Use a currently maintained release with `systemd`, a working package
repository, root access, and at least 4 GB RAM. Minimal cloud/server images are
recommended. Derivatives are not accepted merely because they provide `apt`
or `dnf`; the installer checks `/etc/os-release` and stops before modifying an
unsupported host.

## Support boundaries

- Ubuntu and Debian use their native repositories plus the Sury/Ubuntu PHP
  repository when the requested PHP version is not in the base repository.
- Enterprise Linux and Fedora use DNF. Enterprise Linux enables EPEL when it
  is available because several mail, security, and DNS packages live there.
- Package versions depend on the enabled repositories. MongoDB is installed
  only when MongoDB publishes a compatible vendor repository for the host.
- SELinux may remain enabled. Site paths, ports, and custom policies should be
  validated before moving an existing production workload.
- Arch Linux, Alpine Linux, openSUSE, Amazon Linux, Oracle Linux, and other
  derivatives are not supported by the native installer.

## Before installation

Use a fresh server. The installer changes packages, services, firewall rules,
users, `/var/www`, and configuration under `/etc`. Do not use it to convert an
existing hosting server in place.

```bash
cat /etc/os-release
systemctl --version
sudo ./installer.sh --latest
sudo dpanel doctor
```

An OS appearing in this table means the installer contains the appropriate
package-manager and service mappings. A release should still pass the smoke
test in `dscript/tests/cli-smoke.sh` and a clean-VM installation test before a
specific version is advertised as certified.
