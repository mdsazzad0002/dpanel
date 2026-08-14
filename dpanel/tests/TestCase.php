<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to start any Laravel test unless it is using an isolated,
     * in-memory SQLite database. This guard runs before RefreshDatabase.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new LogicException(sprintf(
                'Unsafe test database blocked (environment=%s, connection=%s, database=%s). Tests require testing + sqlite + :memory:.',
                $app->environment(),
                $connection,
                $database,
            ));
        }

        return $app;
    }
}
