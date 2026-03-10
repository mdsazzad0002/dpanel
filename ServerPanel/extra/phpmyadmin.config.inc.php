<?php
/**
 * ServerPanel phpMyAdmin Configuration
 * Optimized for PHP 8.3 + Custom Panel Autologin
 */

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Blowfish Secret
|--------------------------------------------------------------------------
*/

$cfg['blowfish_secret'] = getenv('PMA_BLOWFISH_SECRET') ?: 'serverpanel_secure_secret_key_2026_change_this';


/*
|--------------------------------------------------------------------------
| Temp Directory
|--------------------------------------------------------------------------
*/

$cfg['TempDir'] = '/tmp';


/*
|--------------------------------------------------------------------------
| Global Settings
|--------------------------------------------------------------------------
*/

$cfg['ShowCreateDb'] = true;
$cfg['SuggestAddress'] = false;
$cfg['AllowUserDropDatabase'] = true;
$cfg['MainPageIconic'] = false;
$cfg['NavigationTreeDisplayDbFilter'] = false;
$cfg['NavigationTreeDefaultTabTable'] = 'browse';


/*
|--------------------------------------------------------------------------
| Server Configuration
|--------------------------------------------------------------------------
*/

$i = 0;
$i++;


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$cfg['Servers'][$i]['auth_type'] = 'signon';

/* MUST match phpmyadminsignin.php */
$cfg['Servers'][$i]['SignonSession'] = 'SignonSession';

/* bridge file */
$cfg['Servers'][$i]['SignonURL'] = 'phpmyadminsignin.php';

/* logout handler */
$cfg['Servers'][$i]['LogoutURL'] = 'phpmyadminsignin.php';

/*
|--------------------------------------------------------------------------
| ServerPanel Token Issuing (optional but recommended)
|--------------------------------------------------------------------------
|
| To avoid sending DB passwords through the browser, ServerPanel can request
| a one-time signon token from phpmyadminsignin.php?action=issue.
|
| Set this env var for the webserver running phpMyAdmin:
| - PMA_SIGNON_ISSUE_SECRET=... (must match ServerPanel PHPMYADMIN_SIGNON_SECRET)
*/


/*
|--------------------------------------------------------------------------
| MySQL Server Connection
|--------------------------------------------------------------------------
*/

$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['compress'] = false;

$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = false;


/*
|--------------------------------------------------------------------------
| Restrict database from panel
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['PMA_single_signon_db'])) {
    $cfg['Servers'][$i]['only_db'] = $_SESSION['PMA_single_signon_db'];
}


/*
|--------------------------------------------------------------------------
| phpMyAdmin Storage
|--------------------------------------------------------------------------
*/

$cfg['Servers'][$i]['pmadb'] = 'phpmyadmin';

$cfg['Servers'][$i]['controluser'] = 'pma';
$cfg['Servers'][$i]['controlpass'] = 'pmapass';


$cfg['Servers'][$i]['bookmarktable'] = 'pma__bookmark';
$cfg['Servers'][$i]['relation'] = 'pma__relation';
$cfg['Servers'][$i]['table_info'] = 'pma__table_info';
$cfg['Servers'][$i]['table_coords'] = 'pma__table_coords';
$cfg['Servers'][$i]['pdf_pages'] = 'pma__pdf_pages';
$cfg['Servers'][$i]['column_info'] = 'pma__column_info';
$cfg['Servers'][$i]['history'] = 'pma__history';
$cfg['Servers'][$i]['table_uiprefs'] = 'pma__table_uiprefs';
$cfg['Servers'][$i]['tracking'] = 'pma__tracking';
$cfg['Servers'][$i]['userconfig'] = 'pma__userconfig';
$cfg['Servers'][$i]['recent'] = 'pma__recent';
$cfg['Servers'][$i]['favorite'] = 'pma__favorite';
$cfg['Servers'][$i]['users'] = 'pma__users';
$cfg['Servers'][$i]['usergroups'] = 'pma__usergroups';
$cfg['Servers'][$i]['navigationhiding'] = 'pma__navigationhiding';
$cfg['Servers'][$i]['savedsearches'] = 'pma__savedsearches';
$cfg['Servers'][$i]['central_columns'] = 'pma__central_columns';
$cfg['Servers'][$i]['designer_settings'] = 'pma__designer_settings';
$cfg['Servers'][$i]['export_templates'] = 'pma__export_templates';


/*
|--------------------------------------------------------------------------
| Security Hardening
|--------------------------------------------------------------------------
*/

$cfg['LoginCookieValidity'] = 1800;
$cfg['CheckConfigurationPermissions'] = true;
$cfg['AllowArbitraryServer'] = false;


/*
|--------------------------------------------------------------------------
| UI
|--------------------------------------------------------------------------
*/

$cfg['MaxRows'] = 50;
$cfg['RowActionType'] = 'icons';
$cfg['DefaultLang'] = 'en';
$cfg['ThemeDefault'] = 'pmahomme';
