<?php
/**
 * ServerPanel phpMyAdmin Configuration
 * Optimized for PHP 8.3 and Custom Panel Autologin
 */

declare(strict_types=1);

/* Runtime installer should set a secure random value; fallback avoids empty secret. */
$cfg['blowfish_secret'] = '___blowfish_secret___';
$envBlowfishSecret = trim((string) getenv('PMA_BLOWFISH_SECRET'));
if ($envBlowfishSecret !== '') {
    $cfg['blowfish_secret'] = $envBlowfishSecret;
}
if ($cfg['blowfish_secret'] === '') {
    $machineId = '';
    foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $machineIdFile) {
        if (!is_readable($machineIdFile)) {
            continue;
        }
        $candidate = trim((string) @file_get_contents($machineIdFile));
        if ($candidate !== '') {
            $machineId = $candidate;
            break;
        }
    }
    $seed = $machineId !== '' ? $machineId : (php_uname('n') . '|' . __FILE__);
    $cfg['blowfish_secret'] = substr(hash('sha512', 'serverpanel-pma-' . $seed), 0, 64);
}

/**
 * Global Settings
 */
$cfg['ShowCreateDb'] = true;            // Show the "New" (+) database link
$cfg['SuggestAddress'] = false;        // Disables address suggestions
$cfg['AllowUserDropDatabase'] = true;  // Allows dropping databases for admin user
$cfg['MainPageIconic'] = false;        // Clean UI for the main page
$cfg['TempDir'] = '/var/lib/phpmyadmin/tmp';

/**
 * Server configuration
 */
$i = 0;
$i++;

/* Authentication type */
$cfg['Servers'][$i]['auth_type'] = 'signon';           // Use your custom signin script
$cfg['Servers'][$i]['SignonSession'] = 'SignonSession'; // Session name must match your bridge
$cfg['Servers'][$i]['SignonURL'] = '/phpmyadminsignin.php'; // The path to your script
/* Restrict database list only when a specific DB is requested by sign-in bridge. */
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['PMA_single_signon_db'])) {
    $cfg['Servers'][$i]['only_db'] = (string) $_SESSION['PMA_single_signon_db'];
}
$cfg['NavigationTreeDisplayDbFilter'] = false;
$cfg['NavigationTreeDefaultTabTable'] = 'browse';

/* Server connection */
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = false; // Security: Block root login via autologin

/* Database Visibility */
// Empty value means show all databases
$cfg['Servers'][$i]['hide_db'] = '';

/* Storage for phpMyAdmin features (Optional) */
$cfg['Servers'][$i]['pmadb'] = 'phpmyadmin';
$cfg['Servers'][$i]['controluser'] = 'pma';
$cfg['Servers'][$i]['controlpass'] = '___pma_password___';

/**
 * UI Tweaks to force hide the "New" button
 */
$cfg['NavigationTreeEnableGrouping'] = false;
$cfg['NavigationDisplayItemLoopback'] = false;
$cfg['NavigationTreeDbSeparator'] = '_';
$cfg['NavigationDisplayLogo'] = false;
