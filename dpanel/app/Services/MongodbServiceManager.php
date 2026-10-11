<?php

namespace App\Services;

/**
 * Same on/off handling as PostgreSQL, for the mongod unit (config/mongodb.php,
 * drust /api/v1/mongodb/service).
 */
class MongodbServiceManager extends PostgresqlServiceManager
{
    protected string $stack = 'mongodb';
}
