<?php

namespace App\Http\Controllers;

use App\Models\CommandJob;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\SshCommandMemory;
use Inertia\Inertia;
use Inertia\Response;

class ServerPanelController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ServerPanel/ControlCenter', [
            'stats' => [
                'servers_total' => Server::query()->count(),
                'servers_online' => Server::query()->where('status', 'online')->count(),
                'commands_pending_approval' => CommandJob::query()->where('status', 'pending_approval')->count(),
                'commands_failed' => CommandJob::query()->where('status', 'failed')->count(),
                'tasks_running' => ServerTask::query()->where('status', 'running')->count(),
                'memories_total' => SshCommandMemory::query()->count(),
            ],
            'servers' => Server::query()->latest()->limit(8)->get(['id', 'name', 'host', 'status', 'mode', 'last_connected_at']),
            'commands' => CommandJob::query()->with('server:id,name')->latest()->limit(10)->get(['id', 'uuid', 'server_id', 'command', 'risk_level', 'status', 'created_at']),
            'tasks' => ServerTask::query()->with('server:id,name')->latest()->limit(10)->get(['id', 'uuid', 'server_id', 'title', 'status', 'priority', 'created_at']),
            'memories' => SshCommandMemory::query()->latest()->limit(10)->get(['id', 'title', 'category', 'success_count', 'fail_count', 'updated_at']),
        ]);
    }
}
