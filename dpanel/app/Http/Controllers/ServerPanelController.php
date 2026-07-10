<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerTask;
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
                'tasks_running' => ServerTask::query()->where('status', 'running')->count(),
                'tasks_failed' => ServerTask::query()->where('status', 'failed')->count(),
            ],
            'servers' => Server::query()->latest()->limit(8)->get(['id', 'name', 'host', 'status', 'mode', 'last_connected_at']),
            'tasks' => ServerTask::query()->with('server:id,name')->latest()->limit(10)->get(['id', 'uuid', 'server_id', 'title', 'status', 'priority', 'created_at']),
        ]);
    }
}
