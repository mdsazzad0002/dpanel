<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Jobs\ScanServerInventoryJob;
use App\Models\Server;
use App\Models\SshConnectionTest;
use App\Services\ServerPanel\ServerInventoryService;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(): Response
    {
        $servers = Server::query()
            ->latest()
            ->get()
            ->map(fn (Server $server): array => $this->serverPayload($server));

        return Inertia::render('ServerPanel/Servers/Index', [
            'servers' => $servers,
            'statusCounts' => [
                'online' => Server::query()->where('status', 'online')->count(),
                'offline' => Server::query()->where('status', 'offline')->count(),
                'error' => Server::query()->where('status', 'error')->count(),
                'needs_approval' => \App\Models\CommandJob::query()->where('status', 'pending_approval')->count(),
                'failed' => \App\Models\CommandJob::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ServerPanel/Servers/Create');
    }

    public function store(StoreServerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $server = Server::query()->create([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'port' => (int) ($validated['port'] ?? 22),
            'username' => $validated['username'],
            'auth_type' => $validated['auth_type'],
            'encrypted_password' => $validated['auth_type'] === 'password' ? ($validated['password'] ?? null) : null,
            'encrypted_private_key' => $validated['auth_type'] === 'key' ? ($validated['private_key'] ?? null) : null,
            'encrypted_private_key_passphrase' => $validated['auth_type'] === 'key' ? ($validated['private_key_passphrase'] ?? null) : null,
            'mode' => $validated['mode'] ?? 'setup',
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->route('servers.show', $server)->with('success', 'Server added. Credentials are encrypted and never shown again.');
    }

    public function show(Server $server): Response
    {
        $server->load([
            'connectionTests' => fn ($query) => $query->latest('tested_at')->limit(10),
            'commandJobs' => fn ($query) => $query->latest()->limit(20),
        ]);

        return Inertia::render('ServerPanel/Servers/Show', [
            'server' => $this->serverPayload($server),
            'recentConnectionTests' => $server->connectionTests,
            'recentCommands' => $server->commandJobs->map(fn ($job) => $job->only([
                'id', 'uuid', 'command', 'risk_level', 'status', 'created_at', 'started_at', 'finished_at',
            ])),
        ]);
    }

    public function edit(Server $server): Response
    {
        return Inertia::render('ServerPanel/Servers/Create', [
            'server' => $this->serverPayload($server),
            'editing' => true,
        ]);
    }

    public function update(UpdateServerRequest $request, Server $server): RedirectResponse
    {
        $validated = $request->validated();

        $server->forceFill([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'port' => (int) ($validated['port'] ?? 22),
            'username' => $validated['username'],
            'auth_type' => $validated['auth_type'],
            'mode' => $validated['mode'] ?? $server->mode,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (($validated['auth_type'] ?? '') === 'password' && ! empty($validated['password'])) {
            $server->encrypted_password = $validated['password'];
            $server->encrypted_private_key = null;
            $server->encrypted_private_key_passphrase = null;
        }

        if (($validated['auth_type'] ?? '') === 'key' && ! empty($validated['private_key'])) {
            $server->encrypted_private_key = $validated['private_key'];
            $server->encrypted_private_key_passphrase = $validated['private_key_passphrase'] ?? null;
            $server->encrypted_password = null;
        }

        $server->save();

        return redirect()->route('servers.show', $server)->with('success', 'Server updated.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $server->delete();

        return redirect()->route('servers.index')->with('success', 'Server removed.');
    }

    public function testConnection(Server $server, SshClientService $sshClient): RedirectResponse
    {
        $result = $sshClient->testConnection($server);
        SshConnectionTest::query()->create([
            'server_id' => $server->id,
            'status' => $result['status'],
            'output' => $result['output'],
            'error_output' => $result['error_output'],
            'latency_ms' => $result['latency_ms'],
            'tested_at' => now(),
        ]);

        $server->forceFill([
            'status' => $result['status'] === 'success' ? 'online' : 'error',
            'last_connected_at' => $result['status'] === 'success' ? now() : $server->last_connected_at,
            'error_message' => $result['status'] === 'failed' ? $result['error_output'] : null,
        ])->save();

        if ($result['status'] === 'success') {
            ScanServerInventoryJob::dispatch($server->id)->onQueue('server-commands');
        }

        return redirect()->route('servers.show', $server)->with(
            $result['status'] === 'success' ? 'success' : 'error',
            $result['status'] === 'success' ? 'SSH connection succeeded.' : 'SSH connection failed: '.$result['error_output'],
        );
    }

    public function scanInventory(Server $server, ServerInventoryService $inventoryService): RedirectResponse
    {
        try {
            $inventoryService->scan($server);

            return redirect()->route('servers.show', $server)->with('success', 'Inventory scan completed.');
        } catch (\Throwable $exception) {
            $server->forceFill([
                'status' => 'error',
                'error_message' => $exception->getMessage(),
            ])->save();

            return redirect()->route('servers.show', $server)->with('error', 'Inventory scan failed: '.$exception->getMessage());
        }
    }

    public function terminal(Server $server): RedirectResponse
    {
        return redirect()->route('servers.commands', $server);
    }

    private function serverPayload(Server $server): array
    {
        return $server->only([
            'id',
            'name',
            'host',
            'port',
            'username',
            'auth_type',
            'mode',
            'status',
            'os_name',
            'os_version',
            'kernel',
            'architecture',
            'cpu_cores',
            'ram_total_mb',
            'disk_total_gb',
            'last_connected_at',
            'last_scan_at',
            'error_message',
            'notes',
            'created_at',
            'updated_at',
        ]);
    }
}
