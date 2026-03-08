<?php
/**
 * ServerPanel phpMyAdmin Configuration
 * Optimized for PHP 8.3 and Custom Panel Autologin
 */

declare(strict_types=1);

/* Runtime installer sets secure value */
$cfg['blowfish_secret'] = '';

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
/* This tells phpMyAdmin to only show databases the user has access to */
$cfg['Servers'][$i]['only_db'] = $_SESSION['PMA_single_signon_user'] . '%';
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
$cfg['Servers'][$i]['controlpass'] = '';

/**
 * UI Tweaks to force hide the "New" button
 */
$cfg['NavigationTreeEnableGrouping'] = false;
$cfg['NavigationDisplayItemLoopback'] = false;
$cfg['NavigationTreeDbSeparator'] = '_';
$cfg['NavigationDisplayLogo'] = false;
