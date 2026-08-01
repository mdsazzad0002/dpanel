<?php

$cfg['blowfish_secret'] = '{{blowfish_secret}}';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'phpMyAdmin';
$cfg['Servers'][$i]['tracking'] = false;
$pmaScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$pmaHost = (string) ($_SERVER['HTTP_HOST'] ?? 'dpanel.localhost');
$cfg['Servers'][$i]['SignonURL'] = $pmaScheme . '://' . $pmaHost . '/phpmyadmin/phpmyadminsignin.php';
$cfg['Servers'][$i]['LogoutURL'] = 'https://{{panel_domain}}:{{panel_port}}/logout';
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['ShowAll'] = true;
