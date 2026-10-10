<div align="center">

# 📸 dPanel Screenshots

**A visual tour of the panel: websites, databases, email, DNS, security, and users.**

[Websites](#-websites) ·
[Databases](#-databases) ·
[Email](#-email) ·
[DNS](#-dns) ·
[Server](#-server--php) ·
[Security](#-security) ·
[Users](#-users--roles)

</div>

---

## 🏠 Dashboard

The first page after login. It shows live CPU, memory, and disk usage, shortcuts to
every module, the signed-in account's package usage, and the status of core services
(Drust Gateway, Drust API, MySQL, Redis, Postfix, Dovecot).

<p align="center">
  <img src="images/dashboard.png" alt="dPanel dashboard" width="100%">
</p>

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

The control page for one site. It shows status, the PHP version, SSL, and the start
directory, plus quick actions (fix permissions, run migrations, Composer, npm build,
clear Laravel cache), queue workers, 18 service tools, and 6 one-click app installers
(WordPress, Laravel, Joomla, Drupal, CodeIgniter, WHMCS).

<p align="center">
  <img src="images/website_manage.png" alt="Website management" width="100%">
</p>

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>📥 Import &amp; Clone</h4>
      Upload site files and a database dump in chunks, clone another site on this
      server, or import from another server with a share link.
      <br><br>
      <img src="images/import_clone.png" alt="Import and clone">
    </td>
    <td width="50%" valign="top">
      <h4>📤 Export &amp; Share</h4>
      Download the files as a ZIP and the database as <code>.sql</code>, or generate a
      one-time share link to clone the site onto a different server.
      <br><br>
      <img src="images/export_share.png" alt="Export and share">
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h4>🐙 GitHub accounts</h4>
      An admin sets up the GitHub OAuth app once. Users can then connect one or more
      GitHub accounts and deploy repositories to their sites.
      <br><br>
      <img src="images/github_connections.png" alt="GitHub connections">
    </td>
    <td width="50%" valign="top">
      <h4>📁 File manager</h4>
      Browse files inside the account. Upload, create files and folders, search, view
      permissions, toggle hidden files, and restore from trash.
      <br><br>
      <img src="images/filemanager.png" alt="File manager">
    </td>
  </tr>
</table>

---

## 🗄️ Databases

All MariaDB/MySQL and PostgreSQL databases with their attached website, owner, engine,
and database user. Filter by name, website, or user. One-click links open phpMyAdmin
or pgAdmin, and each database has a DB Login button.

<details>
<summary><b>Show full-page screenshot</b> (tall image)</summary>
<br>
<p align="center">
  <img src="images/database.png" alt="Database list" width="80%">
</p>
</details>

---

## ✉️ Email

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>🔐 Mail IPs &amp; SSL</h4>
      Each mail IP has one hostname, used as its HELO name, MX target, and PTR. Each
      hostname gets its own certificate for SMTP and IMAP, renewed automatically.
      "Detect &amp; apply best" picks the hostname that matches reverse DNS.
      <br><br>
      <img src="images/mail_ssl_ptr.png" alt="Mail IPs and SSL">
    </td>
    <td width="50%" valign="top">
      <h4>📬 Webmail</h4>
      The built-in dPanel Mail client, with folders, search, compose, and a storage
      quota meter.
      <br><br>
      <img src="images/mailbox.png" alt="dPanel Mail inbox">
    </td>
  </tr>
</table>

---

## 🧭 DNS

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>DNS zones</h4>
      Authoritative zones served through PowerDNS, with nameserver status, record
      count, and owner. Includes find &amp; replace across zones.
      <br><br>
      <img src="images/dnszone.png" alt="DNS zones">
    </td>
    <td width="50%" valign="top">
      <h4>Nameservers</h4>
      Active nameserver entries sync NS, A, and AAAA records into the selected zone.
      Disabled entries are kept for reference and are not published.
      <br><br>
      <img src="images/nameserver.png" alt="Nameservers">
    </td>
  </tr>
</table>

---

## ⚙️ Server &amp; PHP

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>🐘 PHP Manager</h4>
      Every installed PHP version (5.6 to 8.5), as reported by the Rust API. Edit
      each version's <code>php.ini</code> and extensions separately.
      <br><br>
      <img src="images/phpmanagement.png" alt="PHP Manager">
    </td>
    <td width="50%" valign="top">
      <h4>📈 Monitoring</h4>
      Live server metrics that refresh every second: CPU, memory, disk, counts of
      websites, mailboxes and cron jobs, service status, and top processes.
      <br><br>
      <img src="images/monitoring.png" alt="Monitoring">
    </td>
  </tr>
</table>

---

## 🛡️ Security

### Security overview

Read-only live monitoring through drust. It shows the UFW firewall and SSH status and
every listening port, with its service, bind address, and whether UFW allows or
blocks it.

<details>
<summary><b>Show full-page screenshot</b></summary>
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

---

## 👥 Users &amp; Roles

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>Manage users</h4>
      Users with their role, status, resource limits, package, and reseller. Admins
      can log in as a user, suspend them, or edit their limits.
      <br><br>
      <img src="images/users.png" alt="Manage users">
    </td>
    <td width="50%" valign="top">
      <h4>Manage roles</h4>
      Roles (admin, general, reseller) are built from a fixed list of system
      permissions, so each role gets only the modules it needs.
      <br><br>
      <img src="images/roles.png" alt="Manage roles">
    </td>
  </tr>
</table>

---

<div align="center">

**Want to try it yourself?** Start with the [Installation guide](installation.md) ·
Back to [Documentation](README.md)

</div>
