<?php

namespace App\Http\Controllers;

use App\Services\MongodbServiceManager;
use App\Services\MongoExpressSsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MongodbController extends Controller
{
    public function __construct(
        private readonly MongodbServiceManager $services,
        private readonly MongoExpressSsoService $mongoExpress,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Databases/Mongodb', $this->state());
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

    /**
     * Open mongo-express, signed in through dPanel.
     */
    public function openMongoExpress(): RedirectResponse
    {
        try {
            return redirect()->to($this->mongoExpress->url());
        } catch (\RuntimeException $e) {
            return redirect()->route('databases.mongodb')->with('error', 'Could not open mongo-express: '.$e->getMessage());
        }
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
                'host' => (string) config('mongodb.host'),
                'port' => (int) config('mongodb.port'),
                'username' => (string) config('mongodb.admin_username'),
                'password' => (string) config('mongodb.admin_password'),
            ],
            'mongoExpress' => [
                'path' => (string) config('mongodb.mongo_express_path'),
            ],
        ];
    }
}
