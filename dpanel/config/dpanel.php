<?php

// Servers installed before the rename still have SERVERPANEL_* keys in .env;
// read them as the dPanel_* key of the same name unless that one is set.
foreach ([$_ENV, $_SERVER] as $source) {
    foreach ($source as $key => $value) {
        if (! is_string($key) || ! str_starts_with($key, 'SERVERPANEL_')) {
            continue;
        }
        $renamed = 'dPanel_'.substr($key, strlen('SERVERPANEL_'));
        if (! array_key_exists($renamed, $_ENV) && ! array_key_exists($renamed, $_SERVER)) {
            $_ENV[$renamed] = $_SERVER[$renamed] = $value;
        }
    }
}

return [
    'bandwidth_directory' => env('DRUST_BANDWIDTH_DIR', '/var/lib/drust/bandwidth'),
    'panel_domain' => env('PANEL_DOMAIN', ''),
    'panel_port' => (int) env('PANEL_PORT'),
    'mail' => [
        'server_ip' => env('dPanel_MAIL_SERVER_IP', ''),
        // Postfix HELO name. Empty = mail.<domain> of the first mailbox created.
        'hostname' => env('dPanel_MAIL_HOSTNAME', ''),
        'dkim_selector' => env('dPanel_DKIM_SELECTOR', 'default'),
        'dkim_domain' => env('dPanel_DKIM_DOMAIN', ''),
        'dkim_public_key' => env('dPanel_DKIM_PUBLIC_KEY', ''),
        'health_log_paths' => array_values(array_filter(array_map('trim', explode(',', env(
            'dPanel_MAIL_HEALTH_LOG_PATHS',
            '/var/log/mail.log,/var/log/maillog'
        ))))),
    ],
    'panel_cookie_name' => env('dPanel_PANEL_COOKIE', 'panel_session_proof'),
    // Safety-net absolute session age, independent of activity — forces a
    // fresh login eventually even if the panel is kept busy nonstop.
    'panel_token_lifetime' => (int) env('dPanel_PANEL_TOKEN_LIFETIME', 60),
    // Window granted at login / on each refresh before the session is
    // considered idle.
    'panel_inactivity_timeout' => (int) env('dPanel_PANEL_INACTIVITY_TIMEOUT', 10),
    // How close to expiry (minutes remaining) a request must be before it
    // triggers a refresh.
    'panel_token_refresh_threshold' => (int) env('dPanel_PANEL_TOKEN_REFRESH_THRESHOLD', 4),
    // How far a refresh pushes expires_at out from "now" once triggered.
    'panel_token_refresh_extension' => (int) env('dPanel_PANEL_TOKEN_REFRESH_EXTENSION', 15),
    'ssl_auto_renew_enabled' => (bool) env('dPanel_SSL_AUTO_RENEW_ENABLED', true),
    'ssl_auto_renew_days' => (int) env('dPanel_SSL_AUTO_RENEW_DAYS', 30),
    'ssl_auto_renew_cooldown_hours' => (int) env('dPanel_SSL_AUTO_RENEW_COOLDOWN_HOURS', 12),
    'compound_public_suffixes' => [
        'com.bd',
        'net.bd',
        'org.bd',
        'edu.bd',
        'gov.bd',
        'ac.bd',
        'com.au',
        'net.au',
        'org.au',
        'co.uk',
        'org.uk',
        'gov.uk',
        'ac.uk',
        'co.jp',
        'com.sg',
        'com.my',
        'co.nz',
    ],
    'ssh_timeout' => (int) env('dPanel_SSH_TIMEOUT', 20),
    'command_timeout' => (int) env('dPanel_COMMAND_TIMEOUT', 300),
    'allow_root_setup_mode' => (bool) env('dPanel_ALLOW_ROOT_SETUP_MODE', true),
    'auto_run_safe_commands' => (bool) env('dPanel_AUTO_RUN_SAFE_COMMANDS', true),
    'auto_run_safe_fixes' => (bool) env('dPanel_AUTO_RUN_SAFE_FIXES', false),
    'max_output_length' => (int) env('dPanel_MAX_OUTPUT_LENGTH', 120000),
    'report_base_path' => env('dPanel_REPORT_BASE_PATH', 'dpanel/reports'),
    'template_repository_dir' => env('dPanel_TEMPLATE_REPOSITORY_DIR', ''),
    'execution_api_base_url' => rtrim((string) env('dPanel_EXECUTION_API_BASE_URL', env('dPanel_API_BASE_URL', 'http://127.0.0.1:9500')), '/'),
    'edge_gateway_url' => rtrim((string) env('dPanel_EDGE_GATEWAY_URL', 'http://127.0.0.1'), '/'),
    'execution_api_url' => env('dPanel_EXECUTION_API_URL', rtrim((string) env('dPanel_EXECUTION_API_BASE_URL', env('dPanel_API_BASE_URL', 'http://127.0.0.1:9500')), '/').'/api/v1/script/run'),
    'database_api_url' => env('dPanel_DATABASE_API_URL', rtrim((string) env('dPanel_EXECUTION_API_BASE_URL', env('dPanel_API_BASE_URL', 'http://127.0.0.1:9500')), '/').'/api/v1/database-request'),
    'database_api_token' => env('dPanel_DATABASE_API_TOKEN', env('dPanel_EXECUTION_API_TOKEN', '')),
    'filemanager_api_url' => env('dPanel_FILEMANAGER_API_URL', rtrim((string) env('dPanel_EXECUTION_API_BASE_URL', env('dPanel_API_BASE_URL', 'http://127.0.0.1:9500')), '/').'/api/v1/filemanager'),
    'media_transcribe_api_url' => env('dPanel_MEDIA_TRANSCRIBE_API_URL', rtrim((string) env('dPanel_EXECUTION_API_BASE_URL', env('dPanel_API_BASE_URL', 'http://127.0.0.1:9500')), '/').'/api/v1/media/transcribe'),
    'media_ocr_api_url' => env('dPanel_MEDIA_OCR_API_URL', rtrim((string) env('dPanel_EXECUTION_API_BASE_URL', env('dPanel_API_BASE_URL', 'http://127.0.0.1:9500')), '/').'/api/v1/media/ocr'),
    'execution_api_token' => env('dPanel_EXECUTION_API_TOKEN', ''),
    'execution_api_timeout' => (int) env('dPanel_EXECUTION_API_TIMEOUT', 60),
    'edge_gateway_internal_url' => env('DRUST_EDGE_GATEWAY_INTERNAL_URL', 'http://127.0.0.1'),
    'edge_gateway_reload_channel' => env('DRUST_REDIS_RELOAD_CHANNEL', 'edge:reload'),
    'execution_api_upload_timeout' => (int) env('dPanel_EXECUTION_API_UPLOAD_TIMEOUT', 3600),
    'ftp' => [
        'port' => (int) env('dPanel_FTP_PORT', 21),
        'tls' => (bool) env('dPanel_FTP_TLS', true),
    ],
    'php_versions' => [
        '7.4',
        '8.0',
        '8.1',
        '8.2',
        '8.3',
        '8.4',
        '8.5',
    ],
    'website_php_defaults' => [
        'starter' => env('dPanel_WEBSITE_PHP_DEFAULT_STARTER', '8.3'),
        'wordpress' => env('dPanel_WEBSITE_PHP_DEFAULT_WORDPRESS', '8.3'),
    ],
    'installer_search_paths' => array_values(array_filter(array_map('trim', explode(',', env('dPanel_INSTALLER_SEARCH_PATHS', implode(',', [
        '/var/www/dscript',
    ])))))),
    'ai' => [
        'provider' => env('dPanel_AI_PROVIDER', 'heuristic'),
        'model' => env('dPanel_AI_MODEL', 'gpt-4.1-mini'),
        'temperature' => (float) env('dPanel_AI_TEMPERATURE', 0.2),
        'timeout' => (int) env('dPanel_AI_TIMEOUT', 45),
    ],
    'blocked_commands' => [
        'rm -rf /',
        'rm -rf /*',
        'mkfs',
        'dd if=',
        ':(){ :|:& };:',
        'chmod -R 777 /',
        'chown -R root /',
        'userdel',
        'passwd',
        'shutdown',
        'poweroff',
        'reboot',
        'iptables -F',
        'ufw disable',
        'truncate -s 0 /etc/passwd',
    ],
    'approval_required_patterns' => [
        'apt install',
        'apt upgrade',
        'apt remove',
        'systemctl restart',
        'systemctl stop',
        'service restart',
        'composer install',
        'composer update',
        'npm install',
        'npm run build',
        'php artisan migrate',
        'certbot',
        'crontab',
        'chmod',
        'chown',
        'rm ',
        'mv /etc',
        'mv /usr',
        'mv /var',
    ],
    'safe_patterns' => [
        'whoami',
        'pwd',
        'hostname',
        'hostnamectl',
        'uname -a',
        'lsb_release -a',
        'php -v',
        'php -m',
        'mysql --version',
        'mariadb --version',
        'composer --version',
        'node -v',
        'npm -v',
        'df -h',
        'free -m',
        'uptime',
        'systemctl status',
        'journalctl -u',
        'tail -n 100',
        'ls',
        'cat /var/log',
        'cat /etc/',
    ],
];
