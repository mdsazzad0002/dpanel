<?php

namespace App\Services\Projects;

use App\Models\AppProject;
use App\Models\Website;
use App\Services\Website\WebsiteService;

/** Picks a free port for a new project in the runtime's panel range. */
class ProjectPortAllocator
{
    public function __construct(protected LocalPortScanner $scanner) {}

    public function allocate(string $runtime): int
    {
        [$start, $end] = $runtime === 'node'
            ? [WebsiteService::NODE_PORT_RANGE_START, WebsiteService::NODE_PORT_RANGE_END]
            : [WebsiteService::PYTHON_PORT_RANGE_START, WebsiteService::PYTHON_PORT_RANGE_END];

        $used = AppProject::query()->pluck('port')
            ->merge(Website::query()->whereNotNull('node_port')->pluck('node_port'))
            ->merge(Website::query()->whereNotNull('python_port')->pluck('python_port'))
            ->map(static fn ($port): int => (int) $port)
            ->flip()
            ->all();
        $used += $this->scanner->listening();

        for ($port = $start; $port <= $end; $port++) {
            if (! isset($used[$port])) {
                return $port;
            }
        }

        throw new \RuntimeException('No free ports are available in the project port range.');
    }
}
