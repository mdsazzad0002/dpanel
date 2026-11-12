<?php

namespace App\Services\Website;

use App\Support\Platform\LocalCommand;

/**
 * Best-effort Linux account and chmod isolation of a site's home, shared by
 * the website and WordPress flows. Each step is its own argv; a failed step
 * does not stop the flow, as before.
 */
class SiteOwnerIsolation
{
    public function __construct(private readonly LocalCommand $local) {}

    /** Linux accounts and modes: useradd, chown, chmod. */
    public function supported(): bool
    {
        return $this->local->supports(LocalCommand::LINUX_TOOLS);
    }

    public function apply(string $owner, string $homePath, string $projectRoot, string $publicRoot, string $rootPath): void
    {
        if (! $this->supported()) {
            return;
        }

        $this->step(['getent', 'group', $owner]) || $this->step(['groupadd', $owner]);
        $this->step(['id', '-u', $owner]) || $this->step(['useradd', '-m', '-d', $homePath, '-s', '/usr/sbin/nologin', '-g', $owner, $owner]);
        $this->step(['mkdir', '-p', $homePath]);
        $this->lockHome($homePath);
        $this->step(['mkdir', '-p', $projectRoot]);
        $this->step(['chown', '-R', $owner.':www-data', $projectRoot]);
        $this->step(['find', $projectRoot, '-type', 'd', '-exec', 'chmod', '750', '{}', ';']);
        $this->step(['find', $projectRoot, '-type', 'f', '-exec', 'chmod', '640', '{}', ';']);
        $this->step(['mkdir', '-p', $publicRoot]);
        $this->step(['mkdir', '-p', $rootPath]);
    }

    /** root:root 711, so the site user can enter its home but not list or replace it. */
    public function lockHome(string $homePath): void
    {
        if (! $this->supported()) {
            return;
        }

        $this->step(['chown', 'root:root', $homePath]);
        $this->step(['chmod', '711', $homePath]);
    }

    /** @param  list<string>  $argv */
    private function step(array $argv): bool
    {
        return $this->local->succeeds($argv, 600);
    }
}
