<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    private const STORAGE_FILE = 'website-requests.json';

    /**
     * Show website creation page.
     */
    public function create(): Response
    {
        return Inertia::render('Websites/Create');
    }

    /**
     * List created website requests/commands.
     */
    public function index(): Response
    {
        $requests = collect($this->readRequests())
            ->map(function (array $item): array {
                if (empty($item['command'])) {
                    $item['command'] = $this->buildCommand([
                        'domain' => (string) ($item['domain'] ?? ''),
                        'root_path' => (string) ($item['root_path'] ?? ''),
                        'php_version' => (string) ($item['php_version'] ?? ''),
                        'enable_ssl' => (bool) ($item['enable_ssl'] ?? false),
                    ]);
                }

                return $item;
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();

        return Inertia::render('Websites/List', [
            'websiteRequests' => $requests,
        ]);
    }

    /**
     * Create a website command request.
     * Command execution is intentionally commented out.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        $command = $this->buildCommand($validated);

        // Intentionally disabled: command execution must be manually enabled later.
        // $output = [];
        // $exitCode = 0;
        // exec($command . ' 2>&1', $output, $exitCode);

        $requests = $this->readRequests();
        $requests[] = [
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'root_path' => $validated['root_path'],
            'php_version' => $validated['php_version'],
            'enable_ssl' => $validated['enable_ssl'],
            'command' => $command,
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ];
        $this->writeRequests($requests);

        return redirect()->route('websites.list')->with('success', 'Website command generated. Execution is currently disabled in controller (commented out).');
    }

    /**
     * Edit website request.
     */
    public function edit(string $id): Response
    {
        $requestItem = collect($this->readRequests())->firstWhere('id', $id);

        abort_if($requestItem === null, 404);

        return Inertia::render('Websites/Edit', [
            'websiteRequest' => $requestItem,
        ]);
    }

    /**
     * Show website management and usage history.
     */
    public function manage(string $id): Response
    {
        $website = collect($this->readRequests())->firstWhere('id', $id);
        abort_if($website === null, 404);

        $seed = abs(crc32((string) ($website['domain'] ?? $id)));
        $metrics = $this->buildMetrics($seed);
        $histories = $this->buildHistories($seed);

        $activities = [
            [
                'label' => 'Request Created',
                'value' => $website['created_at'] ?? null,
            ],
            [
                'label' => 'Request Updated',
                'value' => $website['updated_at'] ?? null,
            ],
            [
                'label' => 'Status',
                'value' => $website['status'] ?? 'pending',
            ],
            [
                'label' => 'Command',
                'value' => $website['command'] ?? null,
            ],
        ];

        return Inertia::render('Websites/Manage', [
            'website' => $website,
            'metrics' => $metrics,
            'histories' => $histories,
            'activities' => $activities,
        ]);
    }

    /**
     * Update website request and regenerate command.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        $requests = collect($this->readRequests())->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            $item['domain'] = $validated['domain'];
            $item['root_path'] = $validated['root_path'];
            $item['php_version'] = $validated['php_version'];
            $item['enable_ssl'] = $validated['enable_ssl'];
            $item['command'] = $this->buildCommand($validated);
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeRequests($requests);

        return redirect()->route('websites.list')->with('success', 'Website request updated successfully.');
    }

    /**
     * Delete website request.
     */
    public function destroy(string $id): RedirectResponse
    {
        $requests = collect($this->readRequests());
        $before = $requests->count();
        $filtered = $requests->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values();

        if ($filtered->count() === $before) {
            return redirect()->route('websites.list')->with('error', 'Website request not found.');
        }

        $this->writeRequests($filtered->all());

        return redirect()->route('websites.list')->with('success', 'Website request deleted successfully.');
    }

    /**
     * Build execution command from payload.
     *
     * @param array<string, mixed> $payload
     */
    private function buildCommand(array $payload): string
    {
        return sprintf(
            '/usr/local/bin/serverinstaller-site create --domain=%s --root=%s --php=%s%s',
            escapeshellarg((string) $payload['domain']),
            escapeshellarg((string) $payload['root_path']),
            escapeshellarg((string) $payload['php_version']),
            ! empty($payload['enable_ssl']) ? ' --ssl' : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'root_path' => ['required', 'string', 'max:255'],
            'php_version' => ['required', 'string', 'max:10'],
            'enable_ssl' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function buildMetrics(int $seed): array
    {
        return [
            'connections_current' => 10 + ($seed % 190),
            'jobs_pending' => $seed % 40,
            'databases_count' => 1 + ($seed % 12),
            'disk_used_mb' => 200 + ($seed % 50000),
            'disk_limit_mb' => 102400,
            'cpu_usage_percent' => 5 + ($seed % 75),
            'ram_usage_mb' => 256 + ($seed % 12000),
        ];
    }

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function buildHistories(int $seed): array
    {
        $points = [];

        for ($i = 11; $i >= 0; $i--) {
            $points[] = [
                'time' => now()->subHours($i)->format('H:i'),
                'connections' => 10 + (($seed + $i * 13) % 190),
                'jobs' => ($seed + $i * 7) % 40,
                'databases' => 1 + (($seed + $i * 3) % 12),
                'disk' => 200 + (($seed + $i * 101) % 50000),
                'cpu' => 5 + (($seed + $i * 5) % 75),
                'ram' => 256 + (($seed + $i * 31) % 12000),
            ];
        }

        return [
            'points' => $points,
        ];
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
}
