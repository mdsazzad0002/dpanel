<?php

namespace App\Services;

/**
 * MongoDB's mongod unit (config/mongodb.php, drust /api/v1/mongodb/service).
 */
class MongodbServiceManager extends SystemdStackServiceManager
{
    protected string $stack = 'mongodb';
}
