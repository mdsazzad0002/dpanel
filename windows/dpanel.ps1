<#
.SYNOPSIS
  dPanel for Windows: run the dPanel hosting control panel on a Windows PC,
  the way XAMPP or WampServer is used for local development.

.DESCRIPTION
  dPanel is a Linux control panel (systemd, nginx/PHP-FPM, MariaDB, the drust
  service). Windows cannot run it natively, so this script runs it inside its
  own WSL2 Linux distribution named "dPanel" (Ubuntu 24.04). Your other WSL
  distributions are not touched.

  The panel is reachable from Windows at http://localhost, and websites you
  create in the panel at http://<site-domain> after adding the domain to the
  Windows hosts file (the "hosts add" command does that).

  Run without arguments for an interactive menu.

.EXAMPLE
  .\dpanel.ps1 install              # set up WSL2, the dPanel distro and the panel
  .\dpanel.ps1 install -Version main
  .\dpanel.ps1 start | stop | restart | status
  .\dpanel.ps1 open                 # open the panel in the browser
  .\dpanel.ps1 phpmyadmin
  .\dpanel.ps1 shell                # root shell inside the dPanel distro
  .\dpanel.ps1 admin                # create or reset a panel admin login
  .\dpanel.ps1 hosts add mysite.test
  .\dpanel.ps1 hosts remove mysite.test
  .\dpanel.ps1 hosts list
  .\dpanel.ps1 autostart on|off     # start dPanel when you sign in to Windows
  .\dpanel.ps1 update [-Version v1.2.3]
  .\dpanel.ps1 uninstall            # deletes the dPanel distro and all its data

  See docs/windows.md.
#>
[CmdletBinding()]
param(
  [Parameter(Position = 0)]
  [string]$Command = 'menu',

  [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
  [string[]]$Rest = @(),

  # Release tag, branch or commit to install. Empty = latest release.
  [string]$Version = '',

  # Name of the WSL distribution dPanel lives in.
  [string]$Distro = 'dPanel',

  # Where the distro's virtual disk, the downloaded image and this script's copy are kept.
  [string]$InstallDir = (Join-Path $env:LOCALAPPDATA 'dPanel'),

  # GitHub repository to install from (owner/name), for forks.
  [string]$Repo = 'mdsazzad0002/dpanel',

  # Ubuntu WSL root filesystem to import. Empty = Ubuntu 24.04 for this CPU.
  [string]$RootfsUrl = '',

  # Answer "yes" to confirmations (uninstall).
  [switch]$Yes
)

Set-StrictMode -Version 1
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
# Newer WSL prints its own messages as UTF-8 with this set, instead of UTF-16.
$env:WSL_UTF8 = '1'

$PanelUrl = 'http://localhost'
$HostsFile = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$StartupLink = Join-Path ([Environment]::GetFolderPath('Startup')) 'dPanel.lnk'
$StartMenuLink = Join-Path ([Environment]::GetFolderPath('Programs')) 'dPanel.lnk'
$InstalledScript = Join-Path $InstallDir 'dpanel.ps1'

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
function Write-Info($msg) { Write-Host "[INFO] $msg" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "[ OK ] $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "[WARN] $msg" -ForegroundColor Yellow }
function Fail($msg)       { throw "[ERROR] $msg" }

function Test-Admin {
  $id = [Security.Principal.WindowsIdentity]::GetCurrent()
  return (New-Object Security.Principal.WindowsPrincipal $id).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Re-run this script elevated with the given arguments and wait for it.
function Invoke-Elevated([string[]]$Arguments) {
  if (-not $PSCommandPath) {
    Fail 'Administrator rights are needed. Save dpanel.ps1 to a file and run it from there.'
  }
  $argLine = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"") +
    ($Arguments | ForEach-Object { if ($_ -match '\s') { "`"$_`"" } else { $_ } }) +
    @('-Distro', $Distro, '-InstallDir', "`"$InstallDir`"")
  Write-Info 'Asking for administrator rights...'
  $p = Start-Process -FilePath 'powershell.exe' -ArgumentList ($argLine -join ' ') -Verb RunAs -Wait -PassThru
  return $p.ExitCode
}

function Confirm-Action([string]$Question) {
  if ($Yes) { return $true }
  $answer = Read-Host "$Question [y/N]"
  return ($answer -match '^(y|yes)$')
}

# ---------------------------------------------------------------------------
# WSL helpers
# ---------------------------------------------------------------------------
# Only the Microsoft Store build of WSL (1.0+) has "wsl --version", and it is
# the one with systemd support. The inbox wsl.exe stub fails this check.
function Test-WslAvailable {
  if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) { return $false }
  # Windows PowerShell turns redirected native stderr into a terminating error
  # under 'Stop'; this scope only needs the exit code.
  $ErrorActionPreference = 'Continue'
  & wsl.exe --version *> $null
  return ($LASTEXITCODE -eq 0)
}

# Quote arguments for a Windows command line (Start-Process joins them as-is).
function ConvertTo-ArgLine([string[]]$Arguments) {
  return (@($Arguments | ForEach-Object {
    if ($_ -eq '') { '""' }
    elseif ($_ -match '"') { Fail "Arguments cannot contain double quotes: $_" }
    elseif ($_ -match '\s') { "`"$_`"" }
    else { $_ }
  }) -join ' ')
}

function Get-WslList([switch]$Running) {
  $listArgs = @('--list', '--quiet')
  if ($Running) { $listArgs += '--running' }
  $ErrorActionPreference = 'Continue'
  $out = & wsl.exe @listArgs 2>$null
  if ($LASTEXITCODE -ne 0 -or -not $out) { return @() }
  return @($out | ForEach-Object { ($_ -replace "`0", '').Trim() } | Where-Object { $_ })
}

function Test-DistroInstalled { return (Get-WslList) -contains $Distro }
function Test-DistroRunning   { return (Get-WslList -Running) -contains $Distro }

function Assert-DistroInstalled {
  if (-not (Test-DistroInstalled)) {
    Fail "The '$Distro' WSL distribution is not installed. Run: .\dpanel.ps1 install"
  }
}

# Run a command inside the distro as root, without a Linux shell in between.
# Start-Process keeps it on this console (prompts and colours keep working)
# instead of turning its output into this function's return value.
function Invoke-Distro([string[]]$CommandArgs) {
  $argLine = ConvertTo-ArgLine (@('-d', $Distro, '-u', 'root', '-e') + $CommandArgs)
  $p = Start-Process -FilePath 'wsl.exe' -ArgumentList $argLine -NoNewWindow -Wait -PassThru
  return $p.ExitCode
}

# The Linux side of this script. Written to a file with LF line endings and run
# with bash, so nothing has to survive PowerShell -> wsl.exe argument quoting.
$HelperScript = @'
#!/usr/bin/env bash
set -Eeuo pipefail
action="${1:-}"; shift || true

case "$action" in
  configure)
    # systemd is required by dPanel. Root is the default user because the
    # distro exists only to run the panel.
    cat > /etc/wsl.conf <<'EOF'
[boot]
systemd=true

[user]
default=root

[network]
hostname=dpanel
generateHosts=true
generateResolvConf=true

[interop]
appendWindowsPath=false
EOF
    echo "[INFO] /etc/wsl.conf written (systemd on)."
    ;;

  wait-systemd)
    for _ in $(seq 1 90); do
      state="$(systemctl is-system-running 2>/dev/null || true)"
      case "$state" in
        running|degraded) echo "$state"; exit 0 ;;
      esac
      sleep 1
    done
    echo "${state:-unknown}"
    exit 1
    ;;

  install|update)
    repo="${1:?repo}"; version="${2:-}"
    export DEBIAN_FRONTEND=noninteractive
    if ! command -v curl >/dev/null 2>&1; then
      apt-get update -qq && apt-get install -y -qq curl ca-certificates
    fi
    ref="main"
    [[ -n "$version" && "$version" != "latest" ]] && ref="$version"
    curl -fsSL --retry 3 "https://raw.githubusercontent.com/${repo}/${ref}/installer.sh" -o /root/dpanel-installer.sh \
      || curl -fsSL --retry 3 "https://raw.githubusercontent.com/${repo}/main/installer.sh" -o /root/dpanel-installer.sh
    args=()
    if [[ -n "$version" ]]; then args+=(--version "$version"); else args+=(--latest); fi
    [[ "$action" == "update" ]] && args+=(update)
    # Local-PC defaults: the panel answers on http://localhost, there is no
    # public domain for a certificate, and Windows already has a firewall
    # (a Linux firewall inside WSL only breaks localhost forwarding).
    export DPANEL_REPO="$repo"
    export PANEL_DOMAIN="${PANEL_DOMAIN:-localhost}" PANEL_PORT="${PANEL_PORT:-80}"
    export PANEL_SKIP_FIRST_INSTALL_PROMPTS=true SKIP_FIREWALL=true SKIP_SSL=true
    exec bash /root/dpanel-installer.sh "${args[@]}"
    ;;

  admin)
    name="${1:?name}"; email="${2:?email}"
    script=/var/www/dscript/scripts/create-first-laravel-user.sh
    [[ -x "$script" ]] || { echo "[ERROR] dPanel is not installed in this distro." >&2; exit 1; }
    # The password arrives through WSLENV, never on a command line.
    exec bash "$script" "$name" "$email" "${DPANEL_ADMIN_PW:-}"
    ;;

  status)
    printf 'systemd: %s\n' "$(systemctl is-system-running 2>/dev/null || true)"
    for unit in drust edge-gateway mariadb redis-server postgresql supervisor postfix dovecot pdns; do
      systemctl cat "${unit}.service" >/dev/null 2>&1 || continue
      printf '  %-14s %s\n' "$unit" "$(systemctl is-active "${unit}.service" 2>/dev/null || true)"
    done
    for unit in $(systemctl list-units --type=service --all --no-legend --plain 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
      printf '  %-14s %s\n' "${unit%.service}" "$(systemctl is-active "$unit" 2>/dev/null || true)"
    done
    ;;

  *)
    echo "unknown helper action: $action" >&2
    exit 64
    ;;
esac
'@

# Write the helper next to the distro disk and return its path inside Linux.
function Get-HelperPath {
  New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
  $helperPath = Join-Path $InstallDir 'wsl-helper.sh'
  [IO.File]::WriteAllText($helperPath, ($HelperScript -replace "`r`n", "`n"), (New-Object Text.UTF8Encoding $false))
  $linuxPath = (& wsl.exe -d $Distro -u root -e wslpath -a $helperPath | Select-Object -First 1)
  if ($LASTEXITCODE -ne 0 -or -not $linuxPath) { Fail "Cannot reach the '$Distro' distro (wslpath failed)." }
  return $linuxPath.Trim()
}

function Invoke-Helper([string[]]$HelperArgs) {
  return Invoke-Distro (@('bash', (Get-HelperPath)) + $HelperArgs)
}

# ---------------------------------------------------------------------------
# Windows-side checks
# ---------------------------------------------------------------------------
function Get-PortOwners([int[]]$Ports) {
  $owners = @()
  $conns = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $Ports -contains $_.LocalPort }
  foreach ($c in $conns) {
    $proc = Get-Process -Id $c.OwningProcess -ErrorAction SilentlyContinue
    $name = if ($proc) { $proc.ProcessName } else { 'unknown' }
    # WSL forwards the distro's ports through these; that is dPanel itself.
    if ($name -in @('wslrelay', 'wslservice', 'wsl', 'vmmem', 'vmmemWSL')) { continue }
    $owners += [pscustomobject]@{ Port = $c.LocalPort; Process = $name; Pid = $c.OwningProcess }
  }
  return @($owners | Sort-Object Port, Pid -Unique)
}

# Checked only while the distro is stopped: once it runs, WSL's own port
# forwarder holds 80/443 and would look like a conflict.
function Test-PortsFree {
  if ((Test-DistroInstalled) -and (Test-DistroRunning)) { return $true }
  $owners = Get-PortOwners @(80, 443)
  if ($owners.Count -eq 0) { return $true }
  foreach ($o in $owners) {
    $hint = switch -Regex ($o.Process) {
      '^System$' { 'IIS / http.sys (stop it: net stop W3SVC  or  net stop http)' }
      'httpd|apache' { 'Apache, for example XAMPP or WampServer - stop it first' }
      'nginx' { 'another nginx - stop it first' }
      'Skype' { 'Skype - turn off "use port 80 and 443" in its settings' }
      default { 'stop this program, or dPanel will not be reachable on that port' }
    }
    Write-Warn ("Port {0} on Windows is used by {1} (PID {2}): {3}" -f $o.Port, $o.Process, $o.Pid, $hint)
  }
  return $false
}

function Test-PanelHttp {
  try {
    $r = Invoke-WebRequest -Uri $PanelUrl -UseBasicParsing -TimeoutSec 5 -MaximumRedirection 0 -ErrorAction Stop
    return [int]$r.StatusCode
  } catch {
    $resp = $_.Exception.Response
    if ($resp) { return [int]$resp.StatusCode }
    return 0
  }
}

function Get-RootfsUrl {
  if ($RootfsUrl) { return $RootfsUrl }
  $arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } else { 'amd64' }
  return "https://cloud-images.ubuntu.com/wsl/releases/24.04/current/ubuntu-noble-wsl-$arch-wsl.rootfs.tar.gz"
}

function Save-Download([string]$Url, [string]$Destination) {
  if (Get-Command curl.exe -ErrorAction SilentlyContinue) {
    & curl.exe -fL --retry 3 --progress-bar -o $Destination $Url
    if ($LASTEXITCODE -ne 0) { Fail "Download failed: $Url" }
  } else {
    Invoke-WebRequest -Uri $Url -OutFile $Destination -UseBasicParsing
  }
}

# ---------------------------------------------------------------------------
# Commands
# ---------------------------------------------------------------------------
function Install-Wsl {
  $build = [Environment]::OSVersion.Version.Build
  if ($build -lt 19041) {
    Fail "Windows 10 version 2004 (build 19041) or newer is required for WSL2. This PC is build $build."
  }

  if (Test-WslAvailable) { return }

  # The WSL feature is on but the old inbox build is installed: updating
  # brings the Store build (with systemd) and needs no restart.
  if (Get-Command wsl.exe -ErrorAction SilentlyContinue) {
    Write-Info 'Updating WSL to a version with systemd support...'
    & wsl.exe --update
    if (Test-WslAvailable) { return }
  }

  if (-not (Test-Admin)) {
    $code = Invoke-Elevated @('install-wsl')
    if ($code -ne 0 -and $code -ne 3010) {
      Fail "Installing WSL failed (exit code $code). Turn on virtualization (VT-x/AMD-V) in the BIOS, or run 'wsl --install --no-distribution' as administrator to see the error."
    }
    if ((Test-WslAvailable) -and $code -ne 3010) { return }
    Write-Warn 'WSL was installed. Restart Windows, then run this command again: .\dpanel.ps1 install'
    exit 3010
  }

  Write-Info 'Installing WSL2 (Windows Subsystem for Linux)...'
  & wsl.exe --install --no-distribution
  if ($LASTEXITCODE -ne 0) {
    Fail 'wsl --install failed. Turn on virtualization (VT-x/AMD-V) in the BIOS and try again.'
  }
  exit 3010
}

function Install-Distro {
  if (Test-DistroInstalled) {
    Write-Info "WSL distro '$Distro' already exists; reusing it."
    return
  }

  New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
  $url = Get-RootfsUrl
  $tarball = Join-Path $InstallDir 'ubuntu-rootfs.tar.gz'

  Write-Info "Downloading Ubuntu 24.04 for WSL from $url"
  Save-Download $url $tarball

  if (-not $RootfsUrl) {
    $sumsFile = Join-Path $InstallDir 'SHA256SUMS'
    Save-Download ($url.Substring(0, $url.LastIndexOf('/')) + '/SHA256SUMS') $sumsFile
    $leaf = $url.Substring($url.LastIndexOf('/') + 1)
    $line = Get-Content $sumsFile | Where-Object { $_ -match [regex]::Escape($leaf) + '$' } | Select-Object -First 1
    if (-not $line) { Fail "No checksum for $leaf in SHA256SUMS." }
    $expected = ($line -split '\s+')[0].ToLower()
    $actual = (Get-FileHash -Algorithm SHA256 $tarball).Hash.ToLower()
    if ($expected -ne $actual) {
      Remove-Item $tarball -Force
      Fail "Checksum mismatch for the Ubuntu image (expected $expected, got $actual). Run install again."
    }
    Write-Ok 'Ubuntu image checksum verified.'
  }

  $diskDir = Join-Path $InstallDir 'disk'
  New-Item -ItemType Directory -Force -Path $diskDir | Out-Null
  Write-Info "Creating WSL distro '$Distro' in $diskDir"
  & wsl.exe --import $Distro $diskDir $tarball --version 2
  if ($LASTEXITCODE -ne 0) {
    Fail 'wsl --import failed. If WSL was installed just now, restart Windows first; also check that virtualization is on in the BIOS.'
  }
  Remove-Item $tarball -Force -ErrorAction SilentlyContinue
}

function Start-Keeper {
  # WSL stops a distro a few seconds after its last wsl.exe process exits. A
  # hidden "sleep infinity" keeps dPanel's services running like XAMPP's.
  $keeper = Get-CimInstance Win32_Process -Filter "Name='wsl.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match "-d\s+$([regex]::Escape($Distro))\b" -and $_.CommandLine -match 'sleep\s+infinity' }
  if ($keeper) { return }
  Start-Process -FilePath 'wsl.exe' -ArgumentList @('-d', $Distro, '-u', 'root', '-e', 'sleep', 'infinity') -WindowStyle Hidden | Out-Null
}

function Wait-Systemd {
  $state = (& wsl.exe -d $Distro -u root -e bash (Get-HelperPath) wait-systemd | Select-Object -Last 1)
  if ($LASTEXITCODE -ne 0) {
    Write-Warn "systemd did not finish starting (state: $state). Check with: .\dpanel.ps1 shell  then  systemctl --failed"
    return $false
  }
  if ($state -eq 'degraded') { Write-Warn 'Some Linux services failed to start (systemd: degraded). See: systemctl --failed' }
  return $true
}

function Start-DPanel([switch]$Quiet) {
  Assert-DistroInstalled
  if (-not $Quiet) { [void](Test-PortsFree) }
  Write-Info "Starting '$Distro'..."
  Start-Keeper
  [void](Wait-Systemd)
  $code = 0
  for ($i = 0; $i -lt 30; $i++) {
    $code = Test-PanelHttp
    if ($code -gt 0) { break }
    Start-Sleep -Seconds 2
  }
  if ($code -gt 0) { Write-Ok "dPanel is running: $PanelUrl" }
  else { Write-Warn "The distro is up, but $PanelUrl does not answer yet. Check: .\dpanel.ps1 status" }
}

function Stop-DPanel {
  Assert-DistroInstalled
  Write-Info "Stopping '$Distro'..."
  & wsl.exe --terminate $Distro | Out-Null
  Write-Ok 'dPanel stopped.'
}

function Show-Status {
  if (-not (Test-WslAvailable)) { Write-Warn 'WSL is not installed.'; return }
  if (-not (Test-DistroInstalled)) { Write-Warn "dPanel is not installed (no '$Distro' WSL distro)."; return }
  if (-not (Test-DistroRunning)) {
    Write-Host "dPanel: stopped    (start it with: .\dpanel.ps1 start)" -ForegroundColor Yellow
    [void](Test-PortsFree)
    return
  }
  $code = Test-PanelHttp
  if ($code -gt 0) { Write-Host "dPanel: running    $PanelUrl  (HTTP $code)" -ForegroundColor Green }
  else { Write-Host "dPanel: distro running, but $PanelUrl does not answer" -ForegroundColor Yellow }
  [void](Invoke-Helper @('status'))
}

function New-Shortcut([string]$Path, [string]$ScriptArgs, [switch]$Hidden) {
  $psOptions = '-NoProfile -ExecutionPolicy Bypass'
  if ($Hidden) { $psOptions += ' -WindowStyle Hidden' }
  $shell = New-Object -ComObject WScript.Shell
  $lnk = $shell.CreateShortcut($Path)
  $lnk.TargetPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
  $lnk.Arguments = "$psOptions -File `"$InstalledScript`" $ScriptArgs -Distro $Distro -InstallDir `"$InstallDir`""
  $lnk.WorkingDirectory = $InstallDir
  $lnk.WindowStyle = $(if ($Hidden) { 7 } else { 1 })
  $lnk.Description = 'dPanel hosting control panel (WSL2)'
  $lnk.Save()
}

function Copy-SelfToInstallDir {
  if (-not $PSCommandPath) { return }
  New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
  if ((Resolve-Path $PSCommandPath).Path -ne $InstalledScript) {
    Copy-Item -Force $PSCommandPath $InstalledScript
  }
  New-Shortcut $StartMenuLink 'menu'
}

function Set-Autostart([string]$State) {
  switch ($State) {
    'on' {
      if (-not (Test-Path $InstalledScript)) { Copy-SelfToInstallDir }
      New-Shortcut $StartupLink 'start' -Hidden
      Write-Ok 'dPanel will start when you sign in to Windows.'
    }
    'off' {
      Remove-Item $StartupLink -Force -ErrorAction SilentlyContinue
      Write-Ok 'Autostart turned off.'
    }
    default {
      if (Test-Path $StartupLink) { Write-Host 'Autostart: on' } else { Write-Host 'Autostart: off' }
    }
  }
}

function New-AdminUser {
  Assert-DistroInstalled
  Start-Keeper
  Write-Host 'Create a dPanel login (admin role).'
  $name = Read-Host 'Name'
  $email = Read-Host 'Email'
  if (-not $name -or -not $email) { Fail 'Name and email are required.' }
  $secure = Read-Host 'Password (blank = generate one)' -AsSecureString
  $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
  try {
    $env:DPANEL_ADMIN_PW = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    $env:WSLENV = (@('DPANEL_ADMIN_PW/u') + @(($env:WSLENV -split ':') | Where-Object { $_ -and $_ -notlike 'DPANEL_ADMIN_PW*' })) -join ':'
    $code = Invoke-Helper @('admin', $name, $email)
  } finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    Remove-Item Env:DPANEL_ADMIN_PW -ErrorAction SilentlyContinue
  }
  if ($code -ne 0) { Fail 'Creating the admin user failed.' }
  Write-Ok "Sign in at $PanelUrl with $email"
}

function Update-Hosts([string]$Action, [string]$Domain) {
  if ($Action -eq 'list' -or -not $Action) {
    Get-Content $HostsFile | Where-Object { $_ -match '#\s*dpanel\s*$' }
    return
  }
  if ($Domain -notmatch '^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,62}$') {
    Fail "Not a valid domain name: '$Domain'"
  }
  if (-not (Test-Admin)) {
    $code = Invoke-Elevated @('hosts', $Action, $Domain)
    if ($code -ne 0) { Fail 'Editing the hosts file failed.' }
    Write-Ok "hosts file updated ($Action $Domain)."
    return
  }
  $lines = @(Get-Content $HostsFile)
  $pattern = '^\s*127\.0\.0\.1\s+' + [regex]::Escape($Domain) + '\s+#\s*dpanel\s*$'
  switch ($Action) {
    'add' {
      if ($lines -match $pattern) { Write-Info "$Domain is already in the hosts file."; return }
      Add-Content -Path $HostsFile -Value "127.0.0.1 $Domain # dpanel" -Encoding ASCII
      Write-Ok "$Domain now points to dPanel. Create the site with this domain in the panel, then open http://$Domain"
    }
    'remove' {
      $kept = $lines | Where-Object { $_ -notmatch $pattern }
      Set-Content -Path $HostsFile -Value $kept -Encoding ASCII
      Write-Ok "$Domain removed from the hosts file."
    }
    default { Fail "Use: hosts add|remove|list <domain>" }
  }
  & ipconfig.exe /flushdns | Out-Null
}

function Install-DPanel {
  Install-Wsl

  $ramGb = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB)
  if ($ramGb -lt 8) {
    Write-Warn "This PC has $ramGb GB RAM. WSL2 gets half of it by default and dPanel needs about 4 GB."
    Write-Warn "Close other programs, or raise the limit in $env:USERPROFILE\.wslconfig ([wsl2] memory=4GB)."
  }

  [void](Test-PortsFree)
  Install-Distro

  Write-Info 'Turning on systemd in the distro...'
  if ((Invoke-Helper @('configure')) -ne 0) { Fail 'Could not write /etc/wsl.conf.' }
  & wsl.exe --terminate $Distro | Out-Null
  Start-Keeper
  if (-not (Wait-Systemd)) { Fail 'systemd is not running in the distro, and dPanel needs it.' }
  Write-Ok 'systemd is running.'

  Write-Info 'Running the dPanel installer inside WSL. This takes 15-40 minutes on the first run.'
  $code = Invoke-Helper @('install', $Repo, $Version)
  if ($code -ne 0) {
    Fail "The dPanel installer stopped with exit code $code. Fix the error above, then run install again (it resumes)."
  }

  Copy-SelfToInstallDir
  Write-Ok 'dPanel is installed.'

  if (Confirm-Action 'Create your dPanel admin login now?') { New-AdminUser }
  if (Confirm-Action 'Start dPanel automatically when you sign in to Windows?') { Set-Autostart 'on' }

  Start-DPanel -Quiet
  Write-Host ''
  Write-Host "  Panel:       $PanelUrl" -ForegroundColor Green
  Write-Host "  phpMyAdmin:  $PanelUrl/phpmyadmin/"
  Write-Host "  Control:     Start menu > dPanel, or .\dpanel.ps1"
  Write-Host "  New site:    .\dpanel.ps1 hosts add mysite.test, then create mysite.test in the panel"
}

function Update-DPanel {
  Assert-DistroInstalled
  Start-Keeper
  [void](Wait-Systemd)
  $code = Invoke-Helper @('update', $Repo, $Version)
  if ($code -ne 0) { Fail "Update failed with exit code $code." }
  Copy-SelfToInstallDir
  Write-Ok 'dPanel updated.'
}

function Uninstall-DPanel {
  if (-not (Test-DistroInstalled)) { Write-Info "No '$Distro' distro to remove." }
  else {
    Write-Warn "This deletes the '$Distro' WSL distro: every website, database, mailbox and file inside it."
    if (-not (Confirm-Action 'Delete dPanel and all of its data?')) { Write-Info 'Cancelled.'; return }
    & wsl.exe --terminate $Distro | Out-Null
    & wsl.exe --unregister $Distro
    if ($LASTEXITCODE -ne 0) { Fail 'wsl --unregister failed.' }
  }
  Remove-Item $StartupLink, $StartMenuLink -Force -ErrorAction SilentlyContinue
  # Only what this script created: -InstallDir may point at a folder with other files.
  foreach ($item in @('disk', 'dpanel.ps1', 'wsl-helper.sh', 'SHA256SUMS', 'ubuntu-rootfs.tar.gz')) {
    Remove-Item (Join-Path $InstallDir $item) -Recurse -Force -ErrorAction SilentlyContinue
  }
  if ((Test-Path $InstallDir) -and -not (Get-ChildItem -Force $InstallDir)) { Remove-Item -Force $InstallDir }
  $count = @(Get-Content $HostsFile | Where-Object { $_ -match '#\s*dpanel\s*$' }).Count
  if ($count -gt 0) { Write-Info "$count dPanel entries remain in $HostsFile; remove them with: .\dpanel.ps1 hosts remove <domain>" }
  Write-Ok 'dPanel removed. WSL itself is left installed.'
}

function Show-Menu {
  while ($true) {
    $state = 'not installed'
    if ((Test-WslAvailable) -and (Test-DistroInstalled)) {
      $state = if (Test-DistroRunning) { "running  $PanelUrl" } else { 'stopped' }
    }
    Write-Host ''
    Write-Host '================ dPanel for Windows ================' -ForegroundColor Cyan
    Write-Host "  Status: $state"
    Write-Host '  1) Start           2) Stop            3) Restart'
    Write-Host '  4) Open panel      5) phpMyAdmin      6) Status'
    Write-Host '  7) Linux shell     8) Add site domain 9) Create admin login'
    Write-Host ' 10) Update         11) Autostart on/off'
    Write-Host ' 12) Install/Repair 13) Uninstall       0) Exit'
    $choice = Read-Host 'Select'
    try {
      switch ($choice) {
        '1'  { Start-DPanel }
        '2'  { Stop-DPanel }
        '3'  { Stop-DPanel; Start-DPanel }
        '4'  { Start-Process $PanelUrl }
        '5'  { Start-Process "$PanelUrl/phpmyadmin/" }
        '6'  { Show-Status }
        '7'  { Assert-DistroInstalled; & wsl.exe -d $Distro -u root --cd /var/www }
        '8'  { $d = Read-Host 'Domain (for example mysite.test)'; Update-Hosts 'add' $d }
        '9'  { New-AdminUser }
        '10' { Update-DPanel }
        '11' { if (Test-Path $StartupLink) { Set-Autostart 'off' } else { Set-Autostart 'on' } }
        '12' { Install-DPanel }
        '13' { Uninstall-DPanel }
        '0'  { return }
        default { Write-Warn 'Unknown choice.' }
      }
    } catch {
      Write-Host $_.Exception.Message -ForegroundColor Red
    }
  }
}

# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
try {
  switch ($Command.ToLower()) {
    'menu'        { Show-Menu }
    'install'     { Install-DPanel }
    'install-wsl' { Install-Wsl }
    'start'       { Start-DPanel }
    'stop'        { Stop-DPanel }
    'restart'     { Stop-DPanel; Start-DPanel }
    'status'      { Show-Status }
    'open'        { Start-Process $PanelUrl }
    'phpmyadmin'  { Start-Process "$PanelUrl/phpmyadmin/" }
    'shell'       { Assert-DistroInstalled; & wsl.exe -d $Distro -u root --cd /var/www }
    'admin'       { New-AdminUser }
    'hosts'       { Update-Hosts ($Rest | Select-Object -First 1) ($Rest | Select-Object -Skip 1 -First 1) }
    'autostart'   { Set-Autostart ($Rest | Select-Object -First 1) }
    'update'      { Update-DPanel }
    'uninstall'   { Uninstall-DPanel }
    { $_ -in 'help', '-h', '--help', '/?' } { Get-Help $PSCommandPath -Detailed }
    default       { Fail "Unknown command '$Command'. Run: .\dpanel.ps1 help" }
  }
} catch {
  Write-Host $_.Exception.Message -ForegroundColor Red
  exit 1
}
