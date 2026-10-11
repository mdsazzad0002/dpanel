<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest;
use App\Services\MongoAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MongoAdminController extends Controller
{
    public function __construct(
        private readonly MongoAdminClient $mongo,
    ) {
    }

    public function index(Request $request): Response
    {
        $database = $this->scopedDatabase($request);

        return Inertia::render('Databases/MongoAdmin', [
            'initialDb' => $database?->database_name ?? (string) $request->query('db', ''),
            'initialCollection' => (string) $request->query('collection', ''),
            'scope' => $database ? ['id' => (string) $database->id, 'name' => (string) $database->database_name] : null,
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'op' => ['required', 'array'],
            'op.action' => ['required', 'string', Rule::in(array_diff(MongoAdminClient::ACTIONS, MongoAdminClient::INTERNAL_ACTIONS))],
        ]);
        $database = $this->scopedDatabase($request);

        // Forward `op` exactly as sent: decoding it into PHP arrays would turn
        // every empty object ({} filters, {} fields in documents) into [].
        $body = json_decode($request->getContent());
        $op = is_object($body) && is_object($body->op ?? null) ? $body->op : (array) $request->input('op');
        $result = $this->mongo->run($op, $database);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Download a collection (optionally filtered) as a JSON array of
     * Extended JSON documents, the format Insert/Import reads back.
     */
    public function export(Request $request): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'db' => ['required', 'string', 'max:64'],
            'collection' => ['required', 'string', 'max:255'],
            'filter' => ['nullable', 'string', 'max:65535'],
        ]);
        $database = $this->scopedDatabase($request);

        $filter = null;
        if (($data['filter'] ?? '') !== '') {
            // Decoded as objects so an empty {} stays an object for drust.
            $filter = json_decode($data['filter']);
            if (! is_object($filter)) {
                return response()->json(['success' => false, 'message' => 'The filter is not valid JSON.'], 422);
            }
        }

        $result = $this->mongo->run([
            'action' => 'export',
            'db' => $data['db'],
            'collection' => $data['collection'],
            'filter' => $filter,
        ], $database);
        if (! $result['success']) {
            return response()->json($result, 422);
        }

        $documents = $result['data']['documents'] ?? [];
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $data['db'].'.'.$data['collection']).'.json';

        return response()->streamDownload(function () use ($documents) {
            echo json_encode($documents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * The MongoDB database a `scope` (database list id) opens with its own
     * login. Without a scope the page runs as the MongoDB admin, which only
     * panel admins may do.
     */
    private function scopedDatabase(Request $request): ?DatabaseRequest
    {
        $scope = trim((string) $request->input('scope', ''));
        if ($scope === '') {
            abort_unless($request->user()?->hasRole('admin'), 403, 'Only admins can open every MongoDB database.');

            return null;
        }

        $database = DatabaseRequest::query()
            ->visibleTo($request->user())
            ->where('engine', DatabaseRequest::ENGINE_MONGODB)
            ->find($scope);
        abort_if($database === null, 404);

        return $database;
    }
}
