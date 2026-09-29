<?php

namespace App\Http\Controllers;

use App\Services\PostgresqlServiceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostgresqlController extends Controller
{
    public function __construct(private readonly PostgresqlServiceManager $services)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Databases/Postgresql', $this->state());
    }

    public function toggle(Request $request, string $token, string $service): JsonResponse
    {
        if (! $this->services->isKnownService($service)) {
            abort(404);
        }

        $data = $request->validate([
            'running' => ['required', 'boolean'],
        ]);

        $result = $this->services->setRunning($service, (bool) $data['running']);

        return response()->json(array_merge([
            'success' => $result['success'],
            'message' => $result['error'] ?? null,
        ], $this->state()), $result['success'] ? 200 : 422);
    }

    public function refresh(): JsonResponse
    {
        return response()->json(array_merge(['success' => true], $this->state()));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'services' => array_values($this->services->statuses()),
            'connection' => [
                'host' => (string) config('postgresql.host'),
                'port' => (int) config('postgresql.port'),
                'username' => (string) config('postgresql.admin_username'),
                'password' => (string) config('postgresql.admin_password'),
            ],
            'pgadmin' => [
                'path' => (string) config('postgresql.pgadmin_path'),
                'email' => (string) config('postgresql.pgadmin_email'),
                'password' => (string) config('postgresql.pgadmin_password'),
            ],
        ];
    }
}
