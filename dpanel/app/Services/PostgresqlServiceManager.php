<?php

namespace App\Services;

/**
 * PostgreSQL and pgAdmin (config/postgresql.php, drust /api/v1/postgresql/service).
 */
class PostgresqlServiceManager extends SystemdStackServiceManager
{
    protected string $stack = 'postgresql';
}
