<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    private const STORAGE_FILE = 'database-requests.json';
    private const WEBSITE_STORAGE_FILE = 'website-requests.json';

    /**
     * Show database create form.
     */
    public function create(): Response
    {
        return Inertia::render('Databases/Create', [
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    /**
     * List created database requests.
     */
    public function index(): Response
    {
        $requests = collect($this->readRequests())
            ->sortByDesc('created_at')
            ->values()
            ->all();

        return Inertia::render('Databases/List', [
            'databaseRequests' => $requests,
        ]);
    }

    /**
     * Store database request. Command execution is intentionally disabled.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $command = $this->buildCommand($validated);

        // Intentionally disabled: command execution must be manually enabled later.
        // $output = [];
        // $exitCode = 0;
        // exec($command . ' 2>&1', $output, $exitCode);

        $requests = $this->readRequests();
        $requests[] = [
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'database_name' => $validated['database_name'],
            'database_user' => $validated['database_user'],
            'database_password' => $validated['database_password'],
            'database_host' => $validated['database_host'],
            'charset' => $validated['charset'],
            'collation' => $validated['collation'],
            'command' => $command,
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ];

        $this->writeRequests($requests);

        return redirect()->route('databases.list')->with('success', 'Database request created. Command execution is currently disabled in controller.');
    }

    /**
     * Show edit form.
     */
    public function edit(string $id): Response
    {
        $requestItem = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($requestItem === null, 404);

        return Inertia::render('Databases/Edit', [
            'databaseRequest' => $requestItem,
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    /**
     * Update a database request.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $requests = collect($this->readRequests())->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            $item['database_name'] = $validated['database_name'];
            $item['database_user'] = $validated['database_user'];
            $item['database_password'] = $validated['database_password'];
            $item['database_host'] = $validated['database_host'];
            $item['domain'] = $validated['domain'];
            $item['charset'] = $validated['charset'];
            $item['collation'] = $validated['collation'];
            $item['command'] = $this->buildCommand($validated);
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($requests);

        return redirect()->route('databases.list')->with('success', 'Database request updated successfully.');
    }

    /**
     * Delete a database request.
     */
    public function destroy(string $id): RedirectResponse
    {
        $requests = collect($this->readRequests());
        $before = $requests->count();
        $filtered = $requests->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values();

        if ($filtered->count() === $before) {
            return redirect()->route('databases.list')->with('error', 'Database request not found.');
        }

        $this->writeRequests($filtered->all());

        return redirect()->route('databases.list')->with('success', 'Database request deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'database_name' => ['required', 'string', 'max:64'],
            'database_user' => ['required', 'string', 'max:64'],
            'database_password' => ['required', 'string', 'max:255'],
            'database_host' => ['required', 'string', 'max:255'],
            'charset' => ['required', 'string', 'max:32'],
            'collation' => ['required', 'string', 'max:64'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildCommand(array $payload): string
    {
        return sprintf(
            '/usr/local/bin/serverinstaller-db create --domain=%s --name=%s --user=%s --password=%s --host=%s --charset=%s --collation=%s',
            escapeshellarg((string) $payload['domain']),
            escapeshellarg((string) $payload['database_name']),
            escapeshellarg((string) $payload['database_user']),
            escapeshellarg((string) $payload['database_password']),
            escapeshellarg((string) $payload['database_host']),
            escapeshellarg((string) $payload['charset']),
            escapeshellarg((string) $payload['collation']),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRequests(): array
    {
        if (! Storage::exists(self::STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::STORAGE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private function writeRequests(array $requests): void
    {
        Storage::put(self::STORAGE_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<int, string>
     */
    private function readWebsiteDomains(): array
    {
        if (! Storage::exists(self::WEBSITE_STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::WEBSITE_STORAGE_FILE), true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->pluck('domain')
            ->filter(fn ($domain) => is_string($domain) && $domain !== '')
            ->unique()
            ->values()
            ->all();
    }
}
