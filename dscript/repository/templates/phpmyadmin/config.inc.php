<?php

$cfg['blowfish_secret'] = '{{blowfish_secret}}';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'phpMyAdmin';
$cfg['Servers'][$i]['SignonURL'] = '{{phpmyadmin_signon_url}}';
$cfg['Servers'][$i]['LogoutURL'] = 'https://{{panel_domain}}:{{panel_port}}/logout';
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['ShowAll'] = true;
