<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\Docker\DrustDockerClient;
use App\Services\EdgeGatewayReloader;

/**
 * Runs a website as a Docker container, the way Python sites run under
 * gunicorn: the container publishes one port on 127.0.0.1 and the edge
 * gateway proxies the domain to it. The site's root folder is mounted into
 * the container, so its files are managed with the site's File Manager.
 */
class DockerSiteService
{
    public const ACTIONS = ['start', 'stop', 'restart', 'recreate', 'status', 'logs'];

    public function __construct(private readonly DrustDockerClient $drust) {}

    /** @return array<string, mixed> */
    public function control(Website $website, string $action): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown docker site action: {$action}");
        }
        if (! $website->isDockerRuntime()) {
            throw new \RuntimeException('This website does not use the Docker runtime.');
        }

        $name = $website->dockerContainerName();
        $container = $this->container($name);

        if ($action === 'status') {
            return ['container' => $container];
        }
        if ($action === 'logs') {
            if ($container === null) {
                throw new \RuntimeException('The container has not been created yet. Start it first.');
            }

            return $this->drust->logs($name, 300);
        }

        if ($action === 'stop') {
            if ($container !== null && $container['state'] === 'running') {
                $this->drust->action('stop', ['id' => $name]);
            }
            $this->markStatus($website, 'stopped');

            return ['container' => $this->container($name)];
        }

        // A missing container is created; recreate applies changed settings
        // (image, port, public, variables), which Docker cannot change in place.
        if ($container === null || $action === 'recreate') {
            $this->run($website, $container !== null);
        } elseif ($action === 'restart' || $container['state'] !== 'running') {
            $this->drust->action($action === 'restart' ? 'restart' : 'start', ['id' => $name]);
        }
        $this->markStatus($website, 'running');

        return ['container' => $this->container($name)];
    }

    /** Removes the site's container, if it has one; files in the mounted folder stay. */
    public function remove(Website $website): void
    {
        $name = $website->dockerContainerName();
        if ($this->container($name) !== null) {
            $this->drust->action('remove', ['id' => $name]);
        }
    }

    /** @return array<string, mixed> the `docker run` spec drust validates again */
    public function runSpec(Website $website): array
    {
        if (trim((string) $website->docker_image) === '') {
            throw new \RuntimeException('Set a Docker image in the runtime settings first.');
        }
        if (empty($website->docker_port) || empty($website->docker_container_port)) {
            throw new \RuntimeException('This website has no Docker port assigned.');
        }

        $volumes = [];
        $target = trim((string) $website->docker_mount_target);
        if ($target !== '') {
            $source = rtrim((string) $website->root_path, '/');
            // Only a site's own folder is mounted, never an arbitrary host path.
            if (! str_starts_with($source, '/home/') || str_contains($source, '..')) {
                throw new \RuntimeException('This website has no folder under /home to mount.');
            }
            $volumes[] = ['source' => $source, 'target' => $target, 'read_only' => false];
        }

        return [
            'image' => (string) $website->docker_image,
            'name' => $website->dockerContainerName(),
            'restart' => 'unless-stopped',
            'ports' => [[
                'host' => (int) $website->docker_port,
                'container' => (int) $website->docker_container_port,
                'protocol' => 'tcp',
                'public' => (bool) $website->docker_public,
            ]],
            'env' => array_values(array_map(static fn (array $env): array => [
                'key' => (string) ($env['key'] ?? ''),
                'value' => (string) ($env['value'] ?? ''),
            ], (array) ($website->docker_env ?? []))),
            'volumes' => $volumes,
        ];
    }

    private function run(Website $website, bool $replace): void
    {
        $spec = $this->runSpec($website);
        if ($replace) {
            $this->drust->action('remove', ['id' => $spec['name']]);
        }
        $this->drust->action('run', ['spec' => $spec]);
    }

    /** @return array<string, string>|null */
    private function container(string $name): ?array
    {
        $status = $this->drust->status();
        if (! ($status['running'] ?? false)) {
            throw new \RuntimeException(($status['installed'] ?? false)
                ? 'The Docker service is not running on this server.'
                : 'Docker is not installed on this server.');
        }
        foreach ((array) ($status['containers'] ?? []) as $container) {
            if (($container['name'] ?? '') === $name) {
                return array_map('strval', (array) $container);
            }
        }

        return null;
    }

    private function markStatus(Website $website, string $status): void
    {
        $website->forceFill(['docker_process_status' => $status])->saveQuietly();
        app(EdgeGatewayReloader::class)->reloadDomains([(string) $website->domain]);
    }
}
