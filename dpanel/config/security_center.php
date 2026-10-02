<?php

/*
|--------------------------------------------------------------------------
| Security Center
|--------------------------------------------------------------------------
|
| Rule catalog, score weights and scan coverage. Website rules (DP-PHP-*,
| DP-PERM-*, DP-INT-*, DP-MAL-*) are detected by the drust scanner; server
| rules (DP-SSH-*, DP-FW-*, DP-NET-*, DP-SSL-*, DP-BAK-*) by the panel's
| posture check. The catalog is synced into the security_rules table, where
| an admin can disable a rule.
|
*/

return [

    // Weighted share of the overall score. Categories that were never
    // measured are left out and the remaining weights are rescaled, so an
    // unscanned area can neither raise nor lower the score.
    'weights' => [
        'firewall' => 15,
        'waf' => 15,
        'malware' => 20,
        'integrity' => 10,
        'php' => 10,
        'ssh' => 10,
        'ssl' => 10,
        'updates' => 5,
        'backup' => 5,
    ],

    'category_labels' => [
        'firewall' => 'Firewall',
        'waf' => 'WAF',
        'malware' => 'Malware',
        'integrity' => 'File Integrity',
        'php' => 'PHP & Files',
        'ssh' => 'SSH',
        'ssl' => 'SSL/TLS',
        'updates' => 'Updates',
        'backup' => 'Backup',
    ],

    // Not measured before Phase 2; shown on the dashboard as "not measured".
    'unmeasured_categories' => ['waf', 'updates'],

    // What the dashboard tells the user about each category: the scan that
    // measures it, what it checks and where to fix it (an admin|reseller
    // route, shown to admins only).
    'category_guides' => [
        'firewall' => ['scan' => 'configuration', 'checks' => 'UFW is active and no database or cache port is reachable from outside.', 'fix_route' => 'security.firewall', 'fix_label' => 'Firewall settings'],
        'waf' => ['scan' => null, 'checks' => 'Web application firewall rules. Not built yet, so it does not affect the score.', 'fix_route' => null, 'fix_label' => null],
        'malware' => ['scan' => 'full', 'checks' => 'Webshells, obfuscated code and ClamAV signatures in website files. Only Full and Malware scans measure it.', 'fix_route' => null, 'fix_label' => null],
        'integrity' => ['scan' => 'quick', 'checks' => 'Code files added, changed or removed since the previous scan. The first scan only records hashes.', 'fix_route' => null, 'fix_label' => null],
        'php' => ['scan' => 'quick', 'checks' => 'PHP in upload folders, odd PHP extensions, shell functions and unsafe file permissions.', 'fix_route' => null, 'fix_label' => null],
        'ssh' => ['scan' => 'configuration', 'checks' => 'Root login and password login in sshd.', 'fix_route' => 'security.ssh', 'fix_label' => 'SSH settings'],
        'ssl' => ['scan' => 'configuration', 'checks' => 'Every website has HTTPS and no certificate is expired or about to expire.', 'fix_route' => 'websites.list', 'fix_label' => 'Websites'],
        'updates' => ['scan' => null, 'checks' => 'Outdated packages and CMS versions. Not built yet, so it does not affect the score.', 'fix_route' => null, 'fix_label' => null],
        'backup' => ['scan' => 'configuration', 'checks' => 'A backup has completed in the last :days days.', 'fix_route' => 'backups.index', 'fix_label' => 'Backups'],
    ],

    // Finding categories that roll up into a score category.
    'score_category_map' => [
        'permissions' => 'php',
    ],

    // Points one open finding takes off its category score (floored at 0).
    'severity_penalties' => [
        'critical' => 40,
        'high' => 20,
        'medium' => 8,
        'low' => 3,
        'info' => 0,
    ],

    'scan_types' => [
        'quick' => 'Quick (code, uploads, permissions, integrity)',
        'full' => 'Full (quick + ClamAV)',
        'malware' => 'Malware (code + ClamAV)',
        'integrity' => 'File integrity only',
    ],

    // Rules a scan type checks. Open findings for these rules that the scan no
    // longer reports are closed automatically. Integrity changes are reported
    // once and stay open until reviewed, so they are never auto-closed.
    'scan_coverage' => [
        'quick' => ['DP-PHP-001', 'DP-PHP-002', 'DP-PHP-003', 'DP-PHP-004', 'DP-PHP-005', 'DP-PHP-006', 'DP-PHP-007', 'DP-PHP-008', 'DP-PERM-001', 'DP-PERM-002'],
        'full' => ['DP-PHP-001', 'DP-PHP-002', 'DP-PHP-003', 'DP-PHP-004', 'DP-PHP-005', 'DP-PHP-006', 'DP-PHP-007', 'DP-PHP-008', 'DP-PERM-001', 'DP-PERM-002', 'DP-MAL-001'],
        'malware' => ['DP-PHP-001', 'DP-PHP-002', 'DP-PHP-003', 'DP-PHP-004', 'DP-PHP-005', 'DP-PHP-006', 'DP-PHP-007', 'DP-PHP-008', 'DP-MAL-001'],
        'integrity' => [],
        'configuration' => ['DP-SSH-001', 'DP-SSH-002', 'DP-FW-001', 'DP-NET-001', 'DP-SSL-001', 'DP-SSL-002', 'DP-SSL-003', 'DP-BAK-001', 'DP-BAK-002'],
    ],

    // Score categories each scan type measures.
    'scan_measures' => [
        'quick' => ['php', 'integrity'],
        'full' => ['php', 'integrity', 'malware'],
        'malware' => ['php', 'malware'],
        'integrity' => ['integrity'],
        'configuration' => ['firewall', 'ssh', 'ssl', 'backup'],
    ],

    'backup_max_age_days' => 7,

    'ssl_expiry_warning_days' => 14,

    // Database and cache ports that should never be reachable from outside.
    'private_ports' => [3306, 5432, 6379, 11211, 27017, 9200],

    'rules' => [
        'DP-PHP-001' => ['name' => 'Executable PHP in upload directory', 'category' => 'php', 'severity' => 'high', 'detection_type' => 'path', 'description' => 'A PHP file inside an uploads directory can be executed by anyone who knows its URL.', 'remediation' => 'Remove unknown files and disable PHP execution in upload directories.'],
        'DP-PHP-002' => ['name' => 'Obfuscated code execution', 'category' => 'malware', 'severity' => 'critical', 'detection_type' => 'content', 'description' => 'eval()/assert() on base64 or gzip decoded data.', 'remediation' => 'Quarantine the file and restore a clean copy.'],
        'DP-PHP-003' => ['name' => 'Request input executed', 'category' => 'malware', 'severity' => 'critical', 'detection_type' => 'content', 'description' => 'User-controlled input passed straight to eval() or a shell function.', 'remediation' => 'Delete the file and review access logs.'],
        'DP-PHP-004' => ['name' => 'Large encoded blob', 'category' => 'malware', 'severity' => 'medium', 'detection_type' => 'content', 'description' => 'Very long base64 or hex-escaped string inside a PHP file.', 'remediation' => 'Check what the data is used for.'],
        'DP-PHP-005' => ['name' => 'Shell command function', 'category' => 'php', 'severity' => 'low', 'detection_type' => 'content', 'description' => 'Application code calls system(), exec(), shell_exec() or similar.', 'remediation' => 'Make sure no user input reaches the call.'],
        'DP-PHP-006' => ['name' => 'Known webshell signature', 'category' => 'malware', 'severity' => 'critical', 'detection_type' => 'signature', 'description' => 'Marker of a publicly known PHP webshell.', 'remediation' => 'Delete the file and rotate site passwords.'],
        'DP-PHP-007' => ['name' => 'PHP code in image file', 'category' => 'malware', 'severity' => 'high', 'detection_type' => 'content', 'description' => 'An image file contains a PHP open tag.', 'remediation' => 'Delete the file.'],
        'DP-PHP-008' => ['name' => 'Unusual PHP extension', 'category' => 'php', 'severity' => 'medium', 'detection_type' => 'path', 'description' => 'File uses .phtml, .php5, .phar or a similar extension.', 'remediation' => 'Remove it unless your application needs it.'],
        'DP-PERM-001' => ['name' => 'World-writable file or directory', 'category' => 'permissions', 'severity' => 'medium', 'detection_type' => 'permission', 'description' => 'Every user on the server can modify it.', 'remediation' => 'chmod o-w; use 755 for directories and 644 for files.'],
        'DP-PERM-002' => ['name' => 'Secrets file readable by everyone', 'category' => 'permissions', 'severity' => 'medium', 'detection_type' => 'permission', 'description' => '.env or a config file with passwords is world-readable.', 'remediation' => 'chmod 640.'],
        'DP-INT-001' => ['name' => 'Code file modified', 'category' => 'integrity', 'severity' => 'medium', 'detection_type' => 'integrity', 'description' => 'SHA-256 changed since the previous scan.', 'remediation' => 'Confirm the change was yours.'],
        'DP-INT-002' => ['name' => 'New code file', 'category' => 'integrity', 'severity' => 'medium', 'detection_type' => 'integrity', 'description' => 'Code file appeared since the previous scan.', 'remediation' => 'Confirm the file belongs to your application.'],
        'DP-INT-003' => ['name' => 'Code file removed', 'category' => 'integrity', 'severity' => 'low', 'detection_type' => 'integrity', 'description' => 'Code file disappeared since the previous scan.', 'remediation' => 'Confirm it was removed on purpose.'],
        'DP-INT-004' => ['name' => 'Large code change', 'category' => 'integrity', 'severity' => 'low', 'detection_type' => 'integrity', 'description' => 'Hundreds of code files changed at once, usually a deploy.', 'remediation' => 'Run a full scan if you did not deploy.'],
        'DP-MAL-001' => ['name' => 'ClamAV detection', 'category' => 'malware', 'severity' => 'critical', 'detection_type' => 'signature', 'description' => 'ClamAV matched a malware signature.', 'remediation' => 'Quarantine the file and restore a clean copy.'],
        'DP-SSH-001' => ['name' => 'Root login allowed', 'category' => 'ssh', 'severity' => 'high', 'detection_type' => 'configuration', 'description' => 'PermitRootLogin allows root to log in with a password.', 'remediation' => 'Set PermitRootLogin to no or prohibit-password.'],
        'DP-SSH-002' => ['name' => 'SSH password login enabled', 'category' => 'ssh', 'severity' => 'medium', 'detection_type' => 'configuration', 'description' => 'Passwords can be brute-forced; keys cannot.', 'remediation' => 'Add SSH keys, then disable PasswordAuthentication.'],
        'DP-FW-001' => ['name' => 'Firewall disabled', 'category' => 'firewall', 'severity' => 'high', 'detection_type' => 'configuration', 'description' => 'UFW is not active.', 'remediation' => 'Enable the firewall from Security > Firewall.'],
        'DP-NET-001' => ['name' => 'Private service exposed', 'category' => 'firewall', 'severity' => 'high', 'detection_type' => 'configuration', 'description' => 'A database or cache port listens publicly and the firewall does not block it.', 'remediation' => 'Bind the service to 127.0.0.1 or restrict the port to trusted IPs.'],
        'DP-SSL-001' => ['name' => 'Certificate expired', 'category' => 'ssl', 'severity' => 'high', 'detection_type' => 'configuration', 'description' => 'Visitors see a browser warning.', 'remediation' => 'Renew the certificate.'],
        'DP-SSL-002' => ['name' => 'Certificate expiring soon', 'category' => 'ssl', 'severity' => 'medium', 'detection_type' => 'configuration', 'description' => 'The certificate expires within the warning window.', 'remediation' => 'Renew it or check that auto-renew works.'],
        'DP-SSL-003' => ['name' => 'Website without HTTPS', 'category' => 'ssl', 'severity' => 'low', 'detection_type' => 'configuration', 'description' => 'SSL is not enabled for this website.', 'remediation' => 'Issue a certificate from the website settings.'],
        'DP-BAK-001' => ['name' => 'No completed backup', 'category' => 'backup', 'severity' => 'medium', 'detection_type' => 'configuration', 'description' => 'No backup has ever completed.', 'remediation' => 'Create a backup schedule.'],
        'DP-BAK-002' => ['name' => 'Backup is old', 'category' => 'backup', 'severity' => 'low', 'detection_type' => 'configuration', 'description' => 'The newest completed backup is older than the allowed age.', 'remediation' => 'Check that scheduled backups run.'],
    ],

];
