<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\DatabaseController as PanelDatabaseController;
use App\Models\DatabaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Databases through the public API. Creating goes through the panel's own
 * DatabaseController::provision(): the database belongs to a website the
 * user can see, its name gets the site's prefix, and package limits apply.
 */
class DatabaseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $databases = DatabaseRequest::query()->visibleTo($request->user())->orderBy('database_name')->get();

        return response()->json(['data' => $databases->map(fn (DatabaseRequest $database) => $this->present($database))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'website' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:31', 'regex:/^[A-Za-z0-9_]+$/'],
            'user' => ['required', 'string', 'max:31', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'engine' => ['nullable', 'in:'.implode(',', [DatabaseRequest::ENGINE_MARIADB, DatabaseRequest::ENGINE_POSTGRESQL, DatabaseRequest::ENGINE_MONGODB])],
        ]);

        $result = app(PanelDatabaseController::class)->provision($this->panelRequest($request, [
            'domain' => strtolower(trim($validated['website'])),
            'database_name' => $validated['name'],
            'database_user' => $validated['user'],
            'database_password' => $validated['password'] ?? '',
            'engine' => $validated['engine'] ?? DatabaseRequest::ENGINE_MARIADB,
        ]));
        $record = $result['record'];
        $sync = $result['sync'];

        return response()->json([
            'message' => $sync['success'] ? 'Database created.' : ($sync['ran'] ? 'Database saved, but the server sync failed: '.$sync['output'] : 'Database saved as pending; the server sync did not run.'),
            // The password is only ever returned here.
            'data' => $this->present($record) + ['password' => (string) $record->database_password],
        ], 201);
    }

    public function destroy(Request $request, string $database): JsonResponse
    {
        // As on the Databases page: the panel's record goes, the server keeps the data.
        $deleted = DatabaseRequest::query()->visibleTo($request->user())->whereKey($database)->delete();

        return $deleted > 0 ? response()->json(['message' => 'Database record deleted.']) : $this->notFound('Database');
    }

    /** @return array<string, mixed> */
    private function present(DatabaseRequest $database): array
    {
        return [
            'id' => (string) $database->id,
            'engine' => (string) ($database->engine ?: DatabaseRequest::ENGINE_MARIADB),
            'website' => (string) $database->domain,
            'name' => (string) $database->database_name,
            'user' => (string) $database->database_user,
            'host' => (string) $database->database_host,
            'charset' => (string) $database->charset,
            'collation' => (string) $database->collation,
            'status' => (string) ($database->status ?: 'pending'),
            'owner_id' => $database->assigned_user_id ? (int) $database->assigned_user_id : null,
            'created_at' => $database->created_at?->toIso8601String(),
        ];
    }
}
