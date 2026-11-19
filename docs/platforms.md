# Operating system support

Status on 2026-11-19. dPanel targets Linux, Windows and macOS, each for local
development and for live production. Only Linux is ready today.

## Summary

| OS | Production server | Local development | Installer |
| --- | --- | --- | --- |
| **Linux** | **Supported** | Supported | `installer.sh` |
| **Windows** | Not yet | Works from source, partly tested | None yet |
| **macOS** | Not yet | Not yet | None |

For a public server, use Linux.

## Linux: supported

The full panel runs on Linux: websites, PHP-FPM, databases, mail, DNS, SSL,
firewall, backups, WAF and per-site isolation.

- Install with `installer.sh` on Ubuntu, Debian, RHEL, Rocky, AlmaLinux,
  CentOS Stream or Fedora Server. See
  [Supported distributions](supported-distributions.md) and
  [Installation](installation.md).
- CI builds and tests drust and the Laravel panel on Linux for every push.

## Windows: local development, in progress

A Windows 10 or 11 PC can run the panel and serve PHP websites on
`127.0.0.1`, built from this repository. Setup:
[dPanel on Windows](windows.md).

| Part | State |
| --- | --- |
| drust build (MSVC) and test suite | Passes; CI runs it on `windows-2022` |
| Laravel test suite | Passes on Windows; CI job added |
| Supervisor for gateway, MariaDB and queue | Works |
| PHP websites through the gateway and a `php-cgi` pool | Works |
| PHP and MariaDB download with SHA-256 check (`drust runtime install`) | Works |
| Web terminal (PowerShell over ConPTY) | Works |
| Local CA and hosts file entries | Code done; not run in CI yet |
| Path edge cases (spaces, Unicode, long paths, `x.php::$DATA`) | Code done |
| End-to-end script (`dscript/tests/windows-e2e.sh`) | Exists |
| Tray app and MSI installer | Not started |
| Production profile: per-site users, quotas, mail, DNS, firewall | Not started |

Only creating and opening a website is tested end to end. Other features that
called Linux commands are being ported and can fail.

## macOS: not started

- drust compiles and its test suite passes on macOS in CI (`macos-latest`).
- The macOS local profile (paths, Homebrew PHP and MariaDB, ports 80/443,
  local CA, mail catcher) is not built yet.
- There is no setup guide and no package.

## Plans and specs

The roadmap, task backlog and technical specs are in [roadmap/](roadmap/):

| File | Contents |
| --- | --- |
| [windows-macos-native-plan.md](roadmap/windows-macos-native-plan.md) | Architecture, decisions and risks for native Windows and macOS |
| [backlog.md](roadmap/backlog.md) | Every task with its dependencies, done condition and status |
| [platform-spec.md](roadmap/platform-spec.md) | Platform interfaces, data model, API and security invariants |
| [feature-gap-plan.md](roadmap/feature-gap-plan.md) | Features missing compared with other panels |
| [extension-spec.md](roadmap/extension-spec.md) | Extension manifest, permissions and sandbox |
| [spikes/](roadmap/spikes/) | Results of go/no-go experiments |
