<?php

$cfg['blowfish_secret'] = '{{blowfish_secret}}';

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'PMA_single_signon';
$cfg['Servers'][$i]['SignonURL'] = 'https://{{panel_domain}}:{{panel_port}}/databases';
$cfg['Servers'][$i]['LogoutURL'] = 'https://{{panel_domain}}:{{panel_port}}/logout';
$cfg['Servers'][$i]['host'] = '127.0.0.1';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['ShowAll'] = true;
