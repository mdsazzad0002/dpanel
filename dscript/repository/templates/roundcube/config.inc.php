<?php

$config['db_dsnw'] = 'mysql://{{roundcube_db_user}}:{{roundcube_db_password}}@{{roundcube_db_host}}:{{roundcube_db_port}}/{{roundcube_db_name}}';
$config['default_host'] = 'tls://127.0.0.1';
$config['default_port'] = 993;
$config['smtp_server'] = 'tls://127.0.0.1';
$config['smtp_port'] = 587;
$config['des_key'] = '{{roundcube_des_key}}';
$config['force_https'] = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$config['login_autocomplete'] = 2;
$config['ip_check'] = true;
$config['session_lifetime'] = 120;
$config['product_name'] = 'ServerPanel Webmail';
$config['plugins'] = ['serverpanel_sso'];
$config['skin'] = 'elastic';
