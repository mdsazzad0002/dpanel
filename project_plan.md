# ServerPanel / ServerInstall Master Plan

> **Purpose**
> This document is the single source of truth for architecture, ownership boundaries, implementation phases, safety rules, and long-term roadmap for the hosting control panel system.

---

## 1. Product Vision

Build a secure, multi-user hosting platform that can manage:

- Servers and SSH access
- Websites and application hosting
- Domains and DNS
- Mailboxes and mail domains
- Databases and database users
- SSL certificates and renewals
- Backups and restore operations
- Cron jobs and scheduled tasks
- File management
- Monitoring and alerts
- AI-assisted troubleshooting and remediation

The platform must be:

- Multi-server ready
- API-driven
- Queue-based for heavy work
- Secure by default
- Auditable end-to-end
- Extensible for future services

---

## 2. Current Product Shape

The current codebase is centered around `dpanel` as the control plane.

Current backend areas already visible in the repository include:

- Server management
- SSH connection testing
- Safe command execution with approval gating
- Server task orchestration
- Command history and event timeline
- AI-assisted error analysis
- Reports for command execution
- Website, mail, DNS, backup, security, monitoring, and cron related modules
- Role-based access control
- Panel session proof and tokenized panel routing

This plan covers both:

- What already exists and must remain stable
- What still needs to be implemented or hardened

---

## 3. Architecture Overview

```text
+--------------------+
|      dpanel        |
| Laravel Control    |
+---------+----------+
          |
          | internal API / queue jobs / secure adapter calls
          |
+---------v----------+
|       drust        |
| Rust Execution    |
+---------+----------+
          |
          | local system operations
          |
+---------v----------+
|      dscript       |
| Bootstrap Layer   |
+--------------------+
```

### Layer responsibilities

- `dpanel` owns identity, authorization, records, UI, audit, approvals, orchestration, and reporting.
- `drust` owns machine-level execution, system operations, and local service interactions.
- `dscript` owns first-time install, first-time configuration, and recovery/error-resolution scripts only.

If `drust` is not present in the repository yet, treat it as the future execution service that `dpanel` will target.

---

## 4. Layer Ownership Rules

### 4.1 dpanel

Location:

```text
/var/www/dpanel
```

Responsibilities:

- Authentication and session security
- Authorization and roles
- CRUD for panel resources
- Server inventory and configuration
- Queue dispatching
- Command lifecycle tracking
- AI analysis workflow
- Reports and audit history
- User-facing UI
- Integration with mail, DNS, websites, backups, and monitoring

Hard rule:

- Laravel application code must not directly run raw system commands for production operations.
- The panel should delegate heavy and sensitive operations to jobs or execution services.
- Laravel application code must not directly read, write, chmod, or `bash`/`sh` execute `.sh` files.
- Any shell-script work must go through an execution API or equivalent adapter boundary.
- If a script must run, `dpanel` should send an API request and let the execution layer perform the host-side work.
- Keep `dscript` configuration minimal and centralized.
- Do not duplicate installer config across Rust, shell, and Laravel layers.
- Prefer one source of truth for installer/runtime settings, with API-delivered values where possible.

### 4.2 drust

Location:

```text
/var/www/drust
```

Responsibilities:

- Linux user lifecycle
- Website provisioning
- Virtual host generation
- PHP pool management
- SSL issuance and renewal
- Mail service operations
- DNS updates
- Database provisioning
- Backup execution
- Monitoring collection
- File operations
- Service reloads and restarts

### 4.3 dscript

Location:

```text
/var/www/dscript
```

Responsibilities:

- Fresh OS bootstrap
- First-time stack installation
- First-time server configuration
- Recovery and error-resolution routines
- Initial PHP runtime readiness and version installation
- Dependency installation needed for initial setup only
- Template-based bootstrap provisioning

Important rule:

- `dscript` must not be used as the normal runtime execution layer.
- Ongoing control panel actions should go through `dpanel` and the execution layer, not back into `dscript`.
- After initial setup is complete, `dscript` should be treated as a bootstrap and repair utility only.
- During init, missing supported PHP versions must be installed before any panel automation depends on them.

Supported init PHP versions:

- 7.4
- 8.0
- 8.2
- 8.3
- 8.4
- 8.5

Bootstrap rule:

- If one or more supported versions are missing during first-time setup, `dscript` should install them automatically.
- After install, the versions must be available for later use by the panel and execution layer.
- Version selection should remain explicit and predictable, not implicit or hidden.

### 4.4 Installer Package Layout

Installer entrypoint:

```text
/var/www/installer.sh
```

Installer location:

```text
/var/www/installer
```

Installer responsibility:

- Act as the external entrypoint for first-time installation
- Start from the root-level `installer.sh` file
- Download the bootstrap package as a zip archive
- Retrieve the archive with `wget`
- Unzip the archive into a temporary working directory
- Invoke the `dscript` files inside the extracted package to complete installation and configuration

Installer flow:

```text
installer.sh
  ->
Fetch zip with wget
  ->
Unzip package
  ->
Run dscript bootstrap files
  ->
Install missing prerequisites
  ->
Apply first-time configuration
  ->
Finish bootstrap
```

Installer design rules:

- Keep the installer outside the main `dscript` folder
- Keep the installer structure simple
- Use `dscript` only after the archive has been extracted
- Let `installer.sh` maintain the bootstrap chain and delegate the actual setup steps to `dscript`
- Do not mix normal runtime logic into the installer package
- Keep the installer idempotent where possible

Exact installer folder tree:

```text
/var/www/
  installer.sh
  installer/
    bootstrap.zip
    bootstrap/
      dscript/
        init.sh
        env.sh
        install/
          php.sh
          packages.sh
          services.sh
          database.sh
        config/
          main.sh
          php.sh
          security.sh
        recovery/
          detect.sh
          fix.sh
          verify.sh
        templates/
          *.tpl
        shared/
          helpers.sh
          logs.sh
          paths.sh
```

Folder rules:

- `installer.sh` stays at `/var/www/installer.sh`
- The bootstrap archive is unpacked inside `/var/www/installer/bootstrap/`
- The real setup logic lives under `/var/www/installer/bootstrap/dscript/`
- `init.sh` should be the first file called inside `dscript`
- `env.sh` should hold bootstrap-time environment loading
- `install/` should handle first-time package and service installation
- `config/` should handle first-time configuration generation
- `recovery/` should handle error detection and repair flows
- `templates/` should keep bootstrap templates only
- `shared/` should keep reusable helper functions only

---

## 5. Source of Truth

The database is the source of truth for all user-facing resources.

Never treat these as authoritative on their own:

- Linux user existence
- Service state
- Config file content
- Cached state
- Temporary runtime output

Rules:

- Database records define intended state
- Execution layers reconcile the machine to match intent
- Post-execution verification must update the database
- Failed syncs must be visible in the UI and logs

---

## 6. Current Core Module

### 6.1 Server management

Current server records should store:

- Name
- Host
- Port
- Username
- Auth type
- Secret material in encrypted form
- Mode
- OS metadata
- Hardware metadata
- Connection status
- Notes
- Creator

### 6.2 SSH authentication

Supported auth modes:

- Password
- Private key

Security rules:

- Secrets must be encrypted at rest
- Secrets must not be serialized back to the browser unless explicitly required and safe
- Root login must be restricted by policy
- Connection tests should be logged

### 6.3 Command execution lifecycle

Command states:

- `draft`
- `pending_approval`
- `queued`
- `running`
- `success`
- `failed`
- `blocked`
- `cancelled`

Risk levels:

- `safe`
- `approval_required`
- `blocked`

Timeline events:

- Created
- Classified
- Approved
- Queued
- Started
- Output
- Failed
- Success
- Blocked
- AI analyzed
- Fix suggested
- Retried

### 6.4 AI error resolution

When a command fails:

- Generate an error signature
- Look for a known resolution
- If not found, request AI/heuristic guidance
- Store the suggested fix
- Optionally create child fix jobs
- Reclassify child commands through the same safety service

### 6.5 Reports

Every completed command should produce a human-readable report containing:

- Server details
- Command
- Risk level
- Status
- Output
- Error output
- AI summary
- AI fix suggestion
- Event timeline
- Approval metadata

---

## 7. Data Model

### 7.1 Existing server panel tables

- `servers`
- `ssh_connection_tests`
- `server_tasks`
- `server_task_steps`
- `command_jobs`
- `command_events`
- `ai_error_resolutions`
- `ssh_command_memories`

### 7.2 Platform tables that should exist or be kept aligned

- `users`
- `roles`
- `permissions`
- `panel_sessions`
- `websites`
- `managed_domains` (panel-owned domain intent and SSL state; do not reuse the PowerDNS `domains` table)
- `dns_zones`
- `dns_records`
- `mail_domains`
- `mailboxes`
- `databases`
- `database_users`
- `cron_jobs`
- `ssl_certificates`
- `backups`
- `activity_logs`
- `monitoring_metrics`
- `service_status`

### 7.3 Data design rules

- Use foreign keys where ownership is clear
- Index by status, owner, and timestamp for timeline-heavy tables
- Store secrets in encrypted columns or encrypted casts
- Store machine-generated text separately from user-authored intent
- Keep command history immutable once written, except for clearly bounded status updates

---

## 8. Security Model

### 8.1 Authentication

Current approach includes:

- Normal Laravel auth
- Panel-session proof token
- Cookie-backed session proof
- Tokenized panel routes

### 8.2 Authorization

Roles:

- Super Admin
- Admin
- Reseller
- User

Permission examples:

- `server.manage`
- `website.create`
- `website.delete`
- `domain.create`
- `domain.delete`
- `mail.create`
- `mail.delete`
- `database.create`
- `database.delete`
- `ssl.create`
- `ssl.delete`
- `backup.create`
- `backup.restore`

### 8.3 Command safety rules

Commands must be classified before execution.

Blocked examples include:

- Destructive filesystem wipes
- Boot-killing or system-killing operations
- Password file corruption
- Firewall disabling
- Unsafe recursive permission changes

Approval-required examples include:

- Package install/update/remove
- Service restart/stop
- Composer or npm installs
- Laravel migrations
- Certificate issuance
- Cron changes
- Ownership and permission changes

Safe examples include:

- Host inspection
- Version checks
- Status checks
- Log tailing
- Read-only directory listing

### 8.4 Secret handling

Required rules:

- Never store plaintext secrets
- Never display raw private keys or passwords in normal views
- Hide secret attributes from model serialization
- Avoid logging secrets in command output or reports

### 8.5 Least privilege

- Prefer per-server credentials instead of shared superuser access
- Use the smallest feasible Linux user rights
- Allow root only in explicitly gated bootstrap/setup flows

---

## 9. Command Flow

```text
User request
  ->
Create command job
  ->
Safety classification
  ->
Block / queue / approve
  ->
Execute through worker
  ->
Capture output and exit code
  ->
Write report
  ->
Analyze failures if needed
  ->
Suggest fix or create child jobs
```

### Command lifecycle requirements

- Every command must have a unique UUID
- Every status change should be traceable
- Output must be truncated safely if it exceeds configured limits
- Reports must be stored under a predictable path
- Failed commands should trigger analysis jobs

---

## 10. AI Workflow

### 10.1 AI responsibilities

AI is allowed to help with:

- Error summarization
- Failure classification
- Suggested fixes
- Pattern recognition
- Reusing known resolutions

AI must not be treated as final authority for unsafe operations.

### 10.2 AI provider strategy

Support a provider abstraction:

- Heuristic fallback
- Optional external AI provider
- Configurable model, timeout, and temperature

### 10.3 AI memory system

Persist learned knowledge in:

- `ai_error_resolutions`
- `ssh_command_memories`

Use cases:

- Reuse previously successful fixes
- Reduce repeated analysis
- Track success/failure history

### 10.4 AI safety rules

- AI suggestions should be reclassified before execution
- Unsafe AI output must be blocked or require approval
- AI-generated child commands must be audited like user commands
- Do not auto-run safe fixes unless the configuration explicitly allows it

---

## 11. Server Provisioning Flow

```text
User creates server
  ->
Save server metadata
  ->
Test SSH connection
  ->
Detect OS and hardware
  ->
Mark server online/offline/error
  ->
Allow inventory scan
  ->
Enable lifecycle operations
```

Required server fields:

- Name
- Host
- Port
- Username
- Authentication method
- Mode
- Notes
- Status

Inventory fields:

- OS name
- OS version
- Kernel
- Architecture
- CPU cores
- RAM total
- Disk total
- Last connected time
- Last scan time

---

## 12. Website Provisioning Flow

```text
Create website
  ->
Validate domain and ownership
  ->
Allocate server and system user
  ->
Create home and web root directories
  ->
Generate web server config
  ->
Create PHP pool
  ->
Set permissions and ownership
  ->
Issue SSL if requested
  ->
Reload services
  ->
Persist resulting state
```

Requirements:

- Support add, edit, delete, suspend, and restore
- Support multiple domains and subdomains
- Support template-based application setup
- Keep document root and system paths deterministic
- Keep web server sync idempotent

---

## 13. Domain and DNS Flow

```text
Create DNS zone
  ->
Write zone records
  ->
Validate syntax
  ->
Apply to resolver/backend
  ->
Reload DNS service
```

Supported record types:

- A
- AAAA
- MX
- TXT
- CNAME
- SRV
- CAA
- NS
- PTR

Requirements:

- Support apex and subdomain records
- Handle compound public suffixes correctly
- Keep DNS changes auditable
- Allow import/export later

---

## 14. Mail Flow

### 14.1 Mail domain flow

```text
Create mail domain
  ->
Generate required DNS values
  ->
Configure DKIM, SPF, DMARC
  ->
Provision mail stack entries
  ->
Reload services
```

### 14.2 Mailbox flow

```text
Create mailbox
  ->
Create maildir
  ->
Set password hash
  ->
Persist mailbox metadata
  ->
Reload or refresh mail services
```

Requirements:

- Mailboxes must map to domains deterministically
- Mail clients should open through secure panel entry points
- SSO or token-based mailbox entry should remain auditable
- Deletion should support soft delete or safe purge patterns

---

## 15. SSL Flow

Supported certificate modes:

- Let's Encrypt HTTP challenge
- Let's Encrypt DNS challenge
- Cloudflare-assisted DNS challenge
- Custom uploaded certificate

Renewal flow:

```text
Detect expiring cert
  ->
Queue renewal
  ->
Issue or renew
  ->
Deploy certificate
  ->
Reload web server
  ->
Log result
```

Runtime rule:

- `ssl_enabled` is intent only; it must never be treated as proof that a certificate exists.
- `drust` must inspect the installed certificate hostname and expiry before issue or renewal.
- Certbot runs only when the certificate is missing, invalid, expired, or inside the renewal threshold.
- `managed_domains` stores the latest checked status and expiry; `ssl_certificates` stores certificate metadata.
- Successful issuance or renewal must be followed by vhost reconciliation and graceful web-server reload.

Requirements:

- Renewal must be scheduled and cooldown-protected
- Certificate deployment must be atomic where possible
- Failures must be visible to admins immediately

---

## 16. Backup Architecture

Back up:

- Website files
- Databases
- Mail data
- DNS data
- Config files

Storage targets:

- Local disk
- S3-compatible storage
- Backblaze
- Remote SSH
- FTP/SFTP

Scheduling:

- Hourly
- Daily
- Weekly
- Monthly

Requirements:

- Backups must be restorable
- Each backup job must be logged
- Retention policies must be configurable
- Restore operations must be permission-gated

---

## 17. File Manager Rules

All file operations must go through the execution layer.

Supported actions:

- Read
- Write
- Rename
- Move
- Copy
- Delete
- Zip
- Unzip
- Change permissions
- Change ownership

Rules:

- Do not let the panel directly mutate user files on disk
- Keep access scoped to the owning account or authorized staff
- Prevent traversal and path escape attacks

---

## 18. Cron and Scheduled Tasks

Requirements:

- Users can create scheduled jobs safely
- Cron syntax must be validated
- Commands must be classified before activation
- Editing or deleting cron entries must update both DB and machine state
- Execution results should be logged when feasible

---

## 19. Database Management

Responsibilities:

- Create databases
- Create database users
- Assign privileges
- Rotate credentials
- Drop or archive resources safely

Rules:

- Never expose raw credentials in views
- Protect destructive actions behind authorization
- Keep host, port, username, and database name synchronized

---

## 20. Monitoring System

Metrics to collect:

- CPU
- RAM
- Load
- Disk
- IO
- Network
- Processes
- Services
- Uptime

Panel dashboard should present:

- Live metrics
- Service status
- Disk usage
- Load history
- Alerts

Requirements:

- Monitoring should be lightweight
- Historical data should be queryable
- Alerts should be actionable, not noisy

---

## 21. Queue Architecture

All heavy or slow work must run in queues.

Recommended queues:

- `default`
- `server-commands`
- `websites`
- `mail`
- `dns`
- `ssl`
- `backups`
- `monitoring`
- `system`
- `installer`

Queue rules:

- HTTP requests should return quickly
- Long operations should be asynchronous
- Retry strategy must be explicit
- Failed jobs should be observable and traceable

---

## 22. Panel Routing and UX Rules

Key current panel areas include:

- Servers
- Commands
- Server tasks
- Emails and webmail
- Mail plans
- Databases
- DNS
- Backups
- Security
- Monitoring
- Profiles
- Admin tools

UI rules:

- Show actual machine state and database state clearly
- Make approval actions obvious
- Warn before destructive operations
- Display timelines and reports for every command task
- Keep error messages actionable

---

## 23. Multi-Server Strategy

The system must support:

- One panel managing many servers
- Different server roles on different nodes
- Web, mail, DNS, and database split across nodes
- A future topology where services can be independently assigned

Principles:

- The panel chooses the target node
- The execution layer performs local actions
- Data should remain consistent across nodes
- Failures on one node should not corrupt global state

---

## 24. Directory and Hosting Layout

The hosting filesystem should stay predictable.

Example structure:

```text
/home/user1/
  domains/
    example.com/
      public_html/
      logs/
      ssl/
      backups/
      config/
  mail/
    example.com/
  databases/
    dumps/
  backups/
    daily/
    weekly/
    monthly/
  tmp/
  config/
    php/
    nginx/
    apache/
  stats/
```

Rules:

- Paths should be deterministic
- Different resource types should not overlap
- Generated configs should live in explicit config directories

---

## 25. Environment and Configuration

Important configuration areas should include:

- Panel domain and port
- Panel session cookie name
- Panel token lifetime
- Panel inactivity timeout
- SSH timeout
- Command timeout
- Root access setup policy
- Auto-run safe commands
- Auto-run safe fixes
- Maximum output length
- Report storage path
- AI provider and model
- Website PHP defaults
- Installer search paths

Configuration rules:

- Every environment toggle must have a safe default
- Dangerous automation must default to off unless explicitly intended
- Production settings should be documented in one place

---

## 26. Development Standards

1. Decide ownership layer first.
2. Keep UI state in `dpanel`.
3. Keep execution in `drust`.
4. Keep first-time install, first-time config, and recovery in `dscript`.
5. Never duplicate responsibility across layers.
6. Queue all heavy operations.
7. Treat the database as the source of truth.
8. Make security checks mandatory, not optional.
9. Preserve backward compatibility where possible.
10. Verify end-to-end behavior before merging.

Code quality rules:

- Favor idempotent operations
- Keep services small and testable
- Store audit events for meaningful state transitions
- Write clear failure paths as well as success paths

---

## 27. Testing Strategy

### 27.1 Backend tests

Must cover:

- Encrypted secret storage
- Secret non-exposure in responses
- Safety classification outcomes
- Approval workflow
- Queue dispatching
- Failure analysis dispatch
- Report generation
- Inventory scanning
- Role and permission gating

### 27.2 Integration tests

Must cover:

- Server create and test connection
- Command create, approve, execute, and report
- Website create and sync
- Mailbox create and open
- DNS record lifecycle
- SSL issuance and renewal paths
- Backup create and restore paths

### 27.3 Safety tests

Must ensure:

- Blocked commands never execute
- Dangerous commands require approval
- Secret values never leak to rendered HTML or logs
- Invalid job state transitions are rejected

---

## 28. Observability

Everything important should be observable.

Log categories:

- Authentication
- Authorization
- Server connectivity
- Command lifecycle
- Approval actions
- AI analysis
- Provisioning events
- Backup runs
- Monitoring alerts

Metrics to track:

- Queue latency
- Command success rate
- Connection failure rate
- Provisioning time
- Backup success rate
- SSL renewal success rate

---

## 29. Deployment and Operations

Deployment requirements:

- Migrations must be safe
- Queue workers must be supervised
- Frontend assets must be built
- Storage permissions must be correct
- Reports and logs must be writable

Operational checklist:

- Configure `.env`
- Run migrations
- Seed roles and baseline config
- Start queue workers
- Start scheduler
- Verify SSH connectivity
- Verify panel session behavior
- Verify report paths

---

## 30. Roadmap Phases

### Phase 1: Control Plane Hardening

- Finalize server management
- Finalize command lifecycle
- Harden security rules
- Stabilize reports and event timelines

### Phase 2: Core Hosting Modules

- Websites
- Domains and DNS
- Mail
- Databases
- Cron

### Phase 3: Automation and Reliability

- Backup and restore
- SSL automation
- Monitoring and alerting
- Better inventory and sync jobs

### Phase 4: Execution Layer Expansion

- `drust` API stabilization
- File manager
- Local system reconciliation
- Multi-node support

### Phase 5: Advanced Operations

- Migration tooling
- Import/export
- Template marketplace
- Audit exports
- Granular activity history

---

## 31. Definition of Done

A task is complete only when:

- The correct layer owns the feature
- Database state is updated correctly
- Queue processing succeeds
- Execution succeeds or fails gracefully
- The UI reflects the final state
- Logs and reports are generated
- Existing users are not broken
- Security checks pass
- The feature works end-to-end

---

## 32. Non-Goals

To keep the system maintainable, avoid these unless explicitly approved:

- Direct shell execution from request handlers
- Hidden background mutations without audit trail
- Storing plaintext secrets
- Unbounded automatic remediation
- Feature duplication across layers
- UI actions that do not map to durable state

---

## 33. Implementation Notes

- Keep this document updated when a module changes ownership
- When a new table is introduced, document its purpose here
- When a queue worker or event is added, document the lifecycle
- When a feature becomes production-ready, mark its phase accordingly
- When a feature is only planned, keep it under roadmap rather than current scope

---

## 34. Final Principle

If a future engineer reads only this file, they should understand:

- What the system is supposed to do
- Which layer owns each responsibility
- How data flows through the platform
- What is safe to automate
- What must remain approved
- What still needs to be built
