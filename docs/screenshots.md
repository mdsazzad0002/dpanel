<div align="center">

# 📸 dPanel Screenshots

**A visual tour of every admin page: websites, apps, Docker, DNS, email, databases, backups, security, users, and the AI Gateway.**

[Websites](#-websites) ·
[FTP](#-ftp) ·
[Apps &amp; PHP](#-apps--php) ·
[Docker](#-docker) ·
[DNS](#-dns) ·
[Email](#%EF%B8%8F-email) ·
[Rules &amp; SEO](#-rules--seo) ·
[Databases](#%EF%B8%8F-databases) ·
[Backups](#-data--backups) ·
[Security](#%EF%B8%8F-security) ·
[Users](#-users-roles--billing) ·
[AI Gateway](#-ai-gateway--mcp)

<sub>Captured on 2026-11-01 while signed in as an admin.</sub>

</div>

---

## 🏠 Dashboard

The first page after login. A banner warns when no system email is set. Below it, every
module is grouped into collapsible tool cards you can reorder with **Customize**. The
right column shows the signed-in account, hostname, server IP, OS and uptime, live CPU,
memory and disk load, the account's package usage, and the status of core services
(Drust Gateway, Drust API, MySQL, Redis, Postfix, Dovecot).

<details>
<summary><b>Show full dashboard</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/dashboard.png" alt="dPanel dashboard" width="100%">
</p>
</details>

---

## 🌐 Websites

### Website list

Every hosted site in one list, with its document root, PHP version, SSL state, owner, and
reseller. Aliases and child domains are grouped under their parent site. Each row has
Manage, Files, SSL, Disable, and Delete actions.

<details>
<summary><b>Show full-page screenshot</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/websites.png" alt="Website list" width="70%">
</p>
</details>

### Website management

The control page for one site. It shows status, the PHP version, SSL, the start
directory, project type and database connection, plus quick actions (file manager, link
storage, fix permissions, reconnect Laravel database, run migrations, Composer, npm build,
clear Laravel cache), queue workers, 19 service tools, 6 one-click installers (WordPress,
Laravel, Joomla, Drupal, CodeIgniter, WHMCS), local-development helpers, usage counters,
recent activity, and a delete zone.

<p align="center">
  <img src="images/website_manage.png" alt="Website management" width="100%">
</p>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>➕ Create website</h4>
      Add a new site: domain, owner, PHP version, and document root.
      <br><br>
      <img src="images/website_create.png" alt="Create website">
    </td>
    <td width="50%" valign="top">
      <h4>📁 File manager</h4>
      Browse files inside the account. Upload, create files and folders, search, view permissions, toggle hidden files, and restore from trash.
      <br><br>
      <img src="images/filemanager.png" alt="File manager">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>📥 Import &amp; Clone</h4>
      Upload site files and a database dump in chunks, clone another site on this server, or import from another server with a share link.
      <br><br>
      <img src="images/import_clone.png" alt="Import and Clone">
    </td>
    <td width="50%" valign="top">
      <h4>📤 Export &amp; Share</h4>
      Download the files as a ZIP and the database as <code>.sql</code>, or generate a one-time share link to clone the site onto a different server.
      <br><br>
      <img src="images/export_share.png" alt="Export and Share">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🐙 GitHub accounts</h4>
      An admin sets up the GitHub OAuth app once. Users can then connect one or more GitHub accounts and deploy repositories to their sites.
      <br><br>
      <img src="images/github_connections.png" alt="GitHub accounts">
    </td>
    <td width="50%"></td>
  </tr>
</table>

---

## 📂 FTP

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>FTP accounts</h4>
      All FTP accounts, filterable by website.
      <br><br>
      <img src="images/ftp.png" alt="FTP accounts">
    </td>
    <td width="50%" valign="top">
      <h4>Create FTP account</h4>
      Give a client FTP access limited to one website.
      <br><br>
      <img src="images/ftp_create.png" alt="Create FTP account">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>Connect &amp; clients</h4>
      Run a server connection test, copy client settings (explicit FTP over TLS, passive ports), check firewall requirements, and download FileZilla, WinSCP, Cyberduck or VS Code SFTP.
      <br><br>
      <img src="images/ftp_connect.png" alt="Connect and clients">
    </td>
    <td width="50%"></td>
  </tr>
</table>

---

## 🧩 Apps &amp; PHP

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>📊 Bandwidth</h4>
      Traffic and requests per website, by month.
      <br><br>
      <img src="images/bandwidth.png" alt="Bandwidth">
    </td>
    <td width="50%" valign="top">
      <h4>🐘 PHP Manager</h4>
      Every installed PHP version (5.6 to 8.5), as reported by the Rust API. Edit each version's <code>php.ini</code> and extensions separately.
      <br><br>
      <img src="images/phpmanagement.png" alt="PHP Manager">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🟢 Node.js apps</h4>
      Run Node.js apps from your home directory as managed services.
      <br><br>
      <img src="images/apps_node.png" alt="Node.js apps">
    </td>
    <td width="50%" valign="top">
      <h4>🐍 Python apps</h4>
      Run Python apps from your home directory as managed services.
      <br><br>
      <img src="images/apps_python.png" alt="Python apps">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🦀 Rust apps</h4>
      Build and run Rust apps from your home directory.
      <br><br>
      <img src="images/apps_rust.png" alt="Rust apps">
    </td>
    <td width="50%"></td>
  </tr>
</table>

---

## 🐳 Docker

Optional add-on, off by default. See the [Docker guide](docker.md).

### Overview

Running containers, stacks, images and server resources at a glance, with quick actions,
a disk-use table, one-click clean-up, and engine details.

<p align="center">
  <img src="images/docker_overview.png" alt="Docker overview" width="100%">
</p>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>Containers</h4>
      Run, inspect, open a console, and read logs.
      <br><br>
      <img src="images/docker_containers.png" alt="Containers">
    </td>
    <td width="50%" valign="top">
      <h4>Stacks</h4>
      Deploy and manage docker-compose apps.
      <br><br>
      <img src="images/docker_stacks.png" alt="Stacks">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>App templates</h4>
      One-click apps and stacks: databases, search, CMS, and tools.
      <br><br>
      <img src="images/docker_templates.png" alt="App templates">
    </td>
    <td width="50%" valign="top">
      <h4>Images</h4>
      Pull images from Docker Hub or any registry, and remove them.
      <br><br>
      <img src="images/docker_images.png" alt="Images">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>Networks</h4>
      Connect containers to each other by name.
      <br><br>
      <img src="images/docker_networks.png" alt="Networks">
    </td>
    <td width="50%" valign="top">
      <h4>Volumes</h4>
      Persistent data for containers.
      <br><br>
      <img src="images/docker_volumes.png" alt="Volumes">
    </td>
  </tr>
</table>

---

## 🧭 DNS

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>DNS zones</h4>
      Authoritative zones served through PowerDNS, with nameserver status, record count, and owner. Includes find &amp; replace across zones.
      <br><br>
      <img src="images/dnszone.png" alt="DNS zones">
    </td>
    <td width="50%" valign="top">
      <h4>Nameservers</h4>
      Active nameserver entries sync NS, A, and AAAA records into the selected zone. Disabled entries are kept for reference and are not published.
      <br><br>
      <img src="images/nameserver.png" alt="Nameservers">
    </td>
  </tr>
</table>

---

## ✉️ Email

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>📋 Mailboxes</h4>
      Every mailbox with its website, plan, quota, status, and Live/Sandbox mode. Actions: connect a device, open the mailbox, use as system email, edit, delete.
      <br><br>
      <img src="images/email_list.png" alt="Mailboxes">
    </td>
    <td width="50%" valign="top">
      <h4>➕ Create email</h4>
      Add a mailbox to one of your domains.
      <br><br>
      <img src="images/email_create.png" alt="Create email">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🧭 Mail DNS guide</h4>
      Configure and verify MX, SPF, DKIM and DMARC for each domain, and export the zone.
      <br><br>
      <img src="images/email_guide.png" alt="Mail DNS guide">
    </td>
    <td width="50%" valign="top">
      <h4>📨 System email</h4>
      The sender the panel uses for login codes, password resets and verification links.
      <br><br>
      <img src="images/system_mail.png" alt="System email">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🔐 Mail IPs &amp; SSL</h4>
      Each mail IP has one hostname, used as its HELO name, MX target, and PTR. Each hostname gets its own certificate for SMTP and IMAP, renewed automatically.
      <br><br>
      <img src="images/mail_ssl_ptr.png" alt="Mail IPs and SSL">
    </td>
    <td width="50%" valign="top">
      <h4>🩺 Mail health</h4>
      Delivery failures, the mail queue, and spam signals.
      <br><br>
      <img src="images/mail_health.png" alt="Mail health">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>📬 Webmail</h4>
      The built-in dPanel Mail client, with folders, search, compose, and a storage quota meter.
      <br><br>
      <img src="images/mailbox.png" alt="Webmail">
    </td>
    <td width="50%"></td>
  </tr>
</table>

---

## 🚦 Rules &amp; SEO

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>↪️ Redirect rules</h4>
      HTTPS, www and domain redirects.
      <br><br>
      <img src="images/redirects.png" alt="Redirect rules">
    </td>
    <td width="50%" valign="top">
      <h4>🔌 Port share</h4>
      Serve a website path from a Node.js/Python app or a local port.
      <br><br>
      <img src="images/port_shares.png" alt="Port share">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🗺️ Sitemap verify</h4>
      Check robots.txt, sitemaps, and the pages they list.
      <br><br>
      <img src="images/seo_sitemap.png" alt="Sitemap verify">
    </td>
    <td width="50%" valign="top">
      <h4>🔎 URL inspection</h4>
      Indexing, search previews, favicon, share cards, and PWA checks for one URL.
      <br><br>
      <img src="images/seo_url_inspection.png" alt="URL inspection">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🖼️ Icon generator</h4>
      Favicons, PWA icons, the web manifest, and a share image from one upload.
      <br><br>
      <img src="images/seo_icon_generator.png" alt="Icon generator">
    </td>
    <td width="50%" valign="top">
      <h4>💧 Image watermark</h4>
      Check images for watermarks and AI labels, and remove visible ones.
      <br><br>
      <img src="images/seo_watermark.png" alt="Image watermark">
    </td>
  </tr>
</table>

---

## 🗄️ Databases

All MariaDB/MySQL databases with their attached website, owner, engine, and database user.
Filter by name, website, or user. One-click links open phpMyAdmin, and each database has a
DB Login button.

<p align="center">
  <img src="images/database.png" alt="Database list" width="100%">
</p>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>Create database</h4>
      Create a database and user, optionally attached to a website.
      <br><br>
      <img src="images/database_create.png" alt="Create database">
    </td>
    <td width="50%" valign="top">
      <h4>Remote access</h4>
      Allow specific IPs to reach MySQL.
      <br><br>
      <img src="images/database_remote.png" alt="Remote access">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>PostgreSQL</h4>
      Turn PostgreSQL and pgAdmin on or off.
      <br><br>
      <img src="images/postgresql.png" alt="PostgreSQL">
    </td>
    <td width="50%" valign="top">
      <h4>MongoDB</h4>
      Turn MongoDB on or off and open Mongo Admin.
      <br><br>
      <img src="images/mongodb.png" alt="MongoDB">
    </td>
  </tr>
</table>

---

## 💾 Data &amp; Backups

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>☁️ Backups</h4>
      Scheduled snapshots, remote storage, and restore. See the <a href="backups.md">Backups guide</a>.
      <br><br>
      <img src="images/backups.png" alt="Backups">
    </td>
    <td width="50%" valign="top">
      <h4>🗑️ Trash backups</h4>
      Archives of deleted websites, ready to download or restore.
      <br><br>
      <img src="images/trash_backups.png" alt="Trash backups">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🚚 Migrations</h4>
      Import accounts from cPanel, DirectAdmin, Plesk, CyberPanel, WordPress, or another dPanel.
      <br><br>
      <img src="images/migrations.png" alt="Migrations">
    </td>
    <td width="50%" valign="top">
      <h4>🔗 Database &amp; Redis</h4>
      Point dPanel's own database and Redis at a remote host. Changes are tested first and rolled back automatically if they fail.
      <br><br>
      <img src="images/self_connection.png" alt="Database and Redis">
    </td>
  </tr>
</table>

---

## 🛡️ Security

### Security Center

A security score, recent scans and events, and the issues they found.

<details>
<summary><b>Show full-page screenshot</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/security_center.png" alt="Security Center" width="100%">
</p>
</details>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>Findings</h4>
      Every issue the scans found, with severity and the affected file or site.
      <br><br>
      <img src="images/security_findings.png" alt="Findings">
    </td>
    <td width="50%" valign="top">
      <h4>Scan history</h4>
      Every scan and its result.
      <br><br>
      <img src="images/security_scans.png" alt="Scan history">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>Detection rules</h4>
      Turn individual scanner rules on or off.
      <br><br>
      <img src="images/security_rules.png" alt="Detection rules">
    </td>
    <td width="50%" valign="top">
      <h4>Login users</h4>
      Server accounts that can open a shell over SSH (root and uid ≥ 1000), with sudo, locked and online badges. Manage lets you lock, unlock or change a password.
      <br><br>
      <img src="images/login_users.png" alt="Login users">
    </td>
  </tr>
</table>

### Security overview

Read-only live monitoring through drust. It shows the UFW firewall and SSH status and
every listening port, with its service, bind address, and whether UFW allows or
blocks it.

<details>
<summary><b>Show full-page screenshot</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/security.png" alt="Security overview" width="100%">
</p>
</details>

### SSH login history (Fail2ban)

Recent SSH logins and failed attempts from the system journal. You set how many failed
logins trigger a permanent block, and you can block or unblock IPs from the same page.

<p align="center">
  <img src="images/ssh_history.png" alt="SSH login history" width="100%">
</p>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>Whitelist</h4>
      IPs that are never blocked.
      <br><br>
      <img src="images/fail2ban_whitelist.png" alt="Whitelist">
    </td>
    <td width="50%" valign="top">
      <h4>Blocklist</h4>
      IPs blocked permanently.
      <br><br>
      <img src="images/fail2ban_blocklist.png" alt="Blocklist">
    </td>
  </tr>
</table>

---

## 📈 Monitoring

Live server metrics that refresh every second: CPU, memory, disk, counts of websites,
mailboxes and cron jobs, service status, and top processes.

<details>
<summary><b>Show full-page screenshot</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/monitoring.png" alt="Monitoring" width="100%">
</p>
</details>

---

## 👥 Users, Roles &amp; Billing

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>Manage users</h4>
      Users with their role, status, resource limits, package, and reseller. Admins can log in as a user, suspend them, or edit their limits.
      <br><br>
      <img src="images/users.png" alt="Manage users">
    </td>
    <td width="50%" valign="top">
      <h4>Manage roles</h4>
      Roles (admin, general, reseller) are built from a fixed list of system permissions, so each role gets only the modules it needs.
      <br><br>
      <img src="images/roles.png" alt="Manage roles">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>Packages</h4>
      Hosting plans with limits for websites, disk, databases, mailboxes and bandwidth.
      <br><br>
      <img src="images/packages.png" alt="Packages">
    </td>
    <td width="50%" valign="top">
      <h4>Billing</h4>
      Connect a billing system to provision accounts automatically.
      <br><br>
      <img src="images/billing.png" alt="Billing">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>WHMCS</h4>
      Configure the WHMCS integration: <code>.env</code> values, a one-command setup, available operations, and troubleshooting. See the <a href="../dpanel/integrations/whmcs/README.md">WHMCS guide</a>.
      <br><br>
      <img src="images/billing_whmcs.png" alt="WHMCS">
    </td>
    <td width="50%" valign="top">
      <h4>Profile</h4>
      Your name, email, password, and two-factor authentication.
      <br><br>
      <img src="images/profile.png" alt="Profile">
    </td>
  </tr>
</table>

---

## 🤖 AI Gateway &amp; MCP

See the [AI Gateway guide](ai-gateway.md) and [MCP Access](mcp.md).

<details>
<summary><b>Show AI Gateway overview</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/ai_gateway.png" alt="AI Gateway overview" width="100%">
</p>
</details>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>💬 Chat</h4>
      Chat with any connected model from the panel.
      <br><br>
      <img src="images/ai_chat.png" alt="Chat">
    </td>
    <td width="50%" valign="top">
      <h4>🔌 Providers</h4>
      Connect Claude, Codex, OpenAI, Gemini and local model endpoints, then test each one.
      <br><br>
      <img src="images/ai_providers.png" alt="Providers">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🧠 Models</h4>
      The models each provider exposes, and which ones are enabled.
      <br><br>
      <img src="images/ai_models.png" alt="Models">
    </td>
    <td width="50%" valign="top">
      <h4>🔑 API keys</h4>
      Issue keys for OpenAI/OpenRouter-compatible access to the gateway.
      <br><br>
      <img src="images/ai_api_keys.png" alt="API keys">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>📖 API docs</h4>
      Endpoint reference and example requests for the gateway API.
      <br><br>
      <img src="images/ai_docs.png" alt="API docs">
    </td>
    <td width="50%" valign="top">
      <h4>🛰️ MCP access</h4>
      Connect Claude, Cursor or any MCP client to the panel: create tokens (read-only or full), copy client snippets, and see the available tools.
      <br><br>
      <img src="images/mcp.png" alt="MCP access">
    </td>
  </tr>
</table>

---

<div align="center">

**Want to try it yourself?** Start with the [Installation guide](installation.md) ·
Back to [Documentation](README.md)

</div>
