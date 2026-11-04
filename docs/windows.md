# dPanel on Windows

Run dPanel on a Windows 10 or 11 PC for local development, the way you would
use XAMPP or WampServer: start it, open `http://localhost`, create sites and
databases from the panel, stop it when you are done.

dPanel is a Linux control panel (systemd, PHP-FPM, MariaDB, the drust service),
so it does not run on Windows directly. `windows/dpanel.ps1` puts it in its own
WSL2 Linux distribution named **dPanel** (Ubuntu 24.04) and controls it from
Windows. Your other WSL distributions are not touched.

> This is for development and testing on your own PC. For a public website,
> install dPanel on a Linux server with `installer.sh`.

## Requirements

- Windows 10 version 2004 (build 19041) or newer, or Windows 11
- Virtualization turned on in the BIOS/UEFI (Intel VT-x or AMD-V)
- 8 GB RAM or more (WSL2 gets half by default; dPanel needs about 4 GB)
- About 15 GB of free disk space
- Ports 80 and 443 free on Windows. Stop IIS, XAMPP/WampServer Apache, or
  anything else that listens on them first

## Install

1. Download [`windows/dpanel.ps1`](../windows/dpanel.ps1) and
   [`windows/dpanel.cmd`](../windows/dpanel.cmd) into the same folder. In
   PowerShell:

   ```powershell
   $base = 'https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/windows'
   mkdir $HOME\dpanel-setup -Force | Out-Null; cd $HOME\dpanel-setup
   Invoke-WebRequest "$base/dpanel.ps1" -OutFile dpanel.ps1
   Invoke-WebRequest "$base/dpanel.cmd" -OutFile dpanel.cmd
   ```

2. Run the installer:

   ```powershell
   .\dpanel.cmd install
   ```

   It does the following:

   - Installs or updates WSL2 if needed. Windows asks for administrator rights.
     **If WSL was not installed before, restart Windows and run
     `.\dpanel.cmd install` again.**
   - Downloads Ubuntu 24.04 for WSL from `cloud-images.ubuntu.com`, checks its
     SHA-256, and imports it as the `dPanel` distro in
     `%LOCALAPPDATA%\dPanel`.
   - Turns on systemd in that distro.
   - Runs `installer.sh` inside it with local-PC settings: panel domain
     `localhost`, no Let's Encrypt certificate (`SKIP_SSL=true`), and no
     Linux firewall (`SKIP_FIREWALL=true`; Windows Firewall already protects
     the PC, and a firewall inside WSL breaks port forwarding). The first run
     takes 15-40 minutes.
   - Asks for your panel admin login and whether dPanel should start when you
     sign in to Windows.
   - Adds **dPanel** to the Start menu.

3. Open <http://localhost> and sign in.

To install a specific version, add `-Version`:

```powershell
.\dpanel.cmd install -Version v1.2.3
.\dpanel.cmd install -Version main
```

If the installer stops with an error, fix the cause and run
`.\dpanel.cmd install` again. It reuses the distro and continues.

## Daily use

Double-click `dpanel.cmd`, or open **Start menu > dPanel**, for the control
menu:

```text
================ dPanel for Windows ================
  Status: running  http://localhost
  1) Start           2) Stop            3) Restart
  4) Open panel      5) phpMyAdmin      6) Status
  7) Linux shell     8) Add site domain 9) Create admin login
 10) Update         11) Autostart on/off
 12) Install/Repair 13) Uninstall       0) Exit
```

The same actions as commands:

| Command | What it does |
| --- | --- |
| `.\dpanel.cmd start` | Start the distro and its services, wait until `http://localhost` answers |
| `.\dpanel.cmd stop` | Stop the distro; frees its RAM |
| `.\dpanel.cmd restart` | Stop, then start |
| `.\dpanel.cmd status` | Panel state, and each Linux service (drust, MariaDB, PHP-FPM, ...) |
| `.\dpanel.cmd open` | Open the panel in the browser |
| `.\dpanel.cmd phpmyadmin` | Open phpMyAdmin |
| `.\dpanel.cmd shell` | Root shell in the distro, in `/var/www`; `dpanel doctor` etc. work there |
| `.\dpanel.cmd admin` | Create a panel admin login, or reset the password of an existing one |
| `.\dpanel.cmd hosts add mysite.test` | Point a site domain at dPanel in the Windows hosts file |
| `.\dpanel.cmd hosts remove mysite.test` | Remove that entry |
| `.\dpanel.cmd hosts list` | Show the entries this script added |
| `.\dpanel.cmd autostart on` / `off` | Start dPanel when you sign in to Windows |
| `.\dpanel.cmd update` | Update dPanel to the latest release (`-Version` works here too) |
| `.\dpanel.cmd uninstall` | Delete the distro and everything in it |

## Websites

A site you create in the panel needs a domain that resolves to your PC:

1. `.\dpanel.cmd hosts add mysite.test` (asks for administrator rights; it
   adds `127.0.0.1 mysite.test` to `C:\Windows\System32\drivers\etc\hosts`).
2. In the panel, create the website with the domain `mysite.test`.
3. Open <http://mysite.test>.

Use a reserved suffix such as `.test` or `.localhost`. A real domain in the
hosts file hides the real site on this PC.

Site files live inside the distro, for example `/home/<user>/public_html`.
Edit them from Windows through `\\wsl$\dPanel\home\...` in Explorer, or open
the folder in VS Code with the WSL extension. Keep files inside the distro: a
site served from `/mnt/c/...` is much slower and has permission problems.

## How it works

- `wsl.exe` runs the `dPanel` distro. WSL forwards ports that Linux listens on
  to `localhost` on Windows, so the edge gateway on port 80 answers at
  `http://localhost`.
- WSL stops a distro shortly after its last Windows process exits. `start`
  runs a hidden `wsl.exe -d dPanel -e sleep infinity` that keeps it running;
  `stop` ends it with `wsl --terminate dPanel`.
- Autostart is a shortcut in your Startup folder that runs `dpanel.ps1 start`
  hidden.
- The script keeps its files in `%LOCALAPPDATA%\dPanel`: the distro disk
  (`disk\ext4.vhdx`), a copy of `dpanel.ps1` used by the shortcuts, and
  `wsl-helper.sh`, the Linux side of the commands.

Options for all commands: `-Distro <name>` (default `dPanel`),
`-InstallDir <path>`, `-Repo <owner/name>` for a fork, and `-RootfsUrl <url>`
to import a different Ubuntu image.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| `running scripts is disabled on this system` | Use `dpanel.cmd`, which runs the script with `-ExecutionPolicy Bypass` for that run only |
| `wsl --install failed` or `wsl --import failed` | Turn on virtualization in the BIOS. If WSL was just installed, restart Windows first |
| `Port 80 on Windows is used by System` | IIS or another http.sys service: `net stop W3SVC` (or remove IIS) |
| `Port 80 ... used by httpd` | XAMPP/WampServer Apache: stop it in its control panel |
| `http://localhost does not answer` | `.\dpanel.cmd status`, then in `.\dpanel.cmd shell`: `systemctl --failed` and `dpanel doctor` |
| `systemd: degraded` | A Linux service failed: `systemctl --failed` in the shell shows which |
| The PC is slow while dPanel runs | Limit WSL in `%USERPROFILE%\.wslconfig` (`[wsl2]` then `memory=4GB`), or `stop` dPanel when not in use |
| Login codes and reset emails never arrive | Expected on a PC: there is no public mail server. Create users and reset passwords with `.\dpanel.cmd admin` |

## Limits

- Let's Encrypt certificates, public DNS and outgoing mail need a public
  server. On a PC the panel runs over `http://`.
- Sites are reachable from this PC only. To reach them from your LAN, use WSL
  mirrored networking (`networkingMode=mirrored` in `.wslconfig`) and allow
  the ports in Windows Firewall.
- `uninstall` deletes all websites, databases and mail in the distro. Export
  what you need first, or back up the distro with
  `wsl --export dPanel dpanel-backup.tar`.
