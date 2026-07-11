<?php

$secretSeed = trim((string) getenv('PMA_BLOWFISH_SECRET'));
if ($secretSeed === '') {
    $secretSeed = hash('sha256', __FILE__.'|ServerPanel|phpMyAdmin');
}
if (! preg_match('/^[0-9a-f]{64}$/i', $secretSeed)) {
    $secretSeed = hash('sha256', $secretSeed);
}

$scheme = (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$proxyBase = trim((string) ($_SERVER['HTTP_X_SERVERPANEL_PMA_BASE'] ?? ''));
if ($proxyBase !== '' && ! str_starts_with($proxyBase, '/')) {
    $proxyBase = '/'.$proxyBase;
}

$directBase = '/phpmyadmin';
$baseUri = $proxyBase !== '' ? rtrim($proxyBase, '/').'/' : $directBase.'/';

$signonUrl = $scheme.'://'.$host.$baseUri.'index.php';
$logoutUrl = $scheme.'://'.$host.$baseUri;

// Force a stable default language and clear stale/unsupported values early.
$_GET['lang'] = 'en';
$_POST['lang'] = 'en';
$_REQUEST['lang'] = 'en';
$_COOKIE['pma_lang'] = 'en';

$cfg['blowfish_secret'] = function_exists('hex2bin')
    ? hex2bin(substr($secretSeed, 0, 64))
    : $secretSeed;

$cfg['PMA_ABSOLUTE_URI'] = rtrim($scheme.'://'.$host.$baseUri, '/').'/';
$cfg['DefaultLang'] = 'en';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'PMA_single_signon';
$cfg['Servers'][$i]['SignonURL'] = $signonUrl;
$cfg['Servers'][$i]['LogoutURL'] = $logoutUrl;
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['ShowAll'] = true;
