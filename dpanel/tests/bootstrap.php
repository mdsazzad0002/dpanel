<?php

declare(strict_types=1);

/*
 * PHPUnit must never load Laravel's production config cache. A cached
 * bootstrap/cache/config.php contains resolved DB credentials and overrides
 * phpunit.xml, which would make RefreshDatabase destructive.
 */
$testingConfigCache = dirname(__DIR__).'/storage/framework/testing/phpunit-config.php';

foreach ([
    'APP_ENV' => 'testing',
    'APP_CONFIG_CACHE' => $testingConfigCache,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
