<?php

namespace App\Services;

/**
 * The local model runner (config/aigateway.php local_llm, drust /api/v1/llm/service).
 */
class LocalLlmServiceManager extends SystemdStackServiceManager
{
    protected string $stack = 'llm';

    protected function services(): array
    {
        return (array) config('aigateway.local_llm.services');
    }
}
