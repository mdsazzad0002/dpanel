<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    private const STORAGE_FILE = 'cron-jobs.json';
    private const WEBSITE_STORAGE_FILE = 'website-requests.json';

    public function index(string $id): Response
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $jobs = collect($this->readJobs())
            ->filter(fn (array $job) => (string) ($job['website_id'] ?? '') === $id)
            ->sortByDesc('created_at')
            ->values()
            ->all();

        return Inertia::render('Websites/CronJobs', [
            'website' => [
                'id' => $website['id'],
                'domain' => $website['domain'] ?? '',
            ],
            'cronJobs' => $jobs,
        ]);
    }

    public function store(Request $request, string $id): RedirectResponse
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $validated = $this->validatePayload($request);
        $jobs = $this->readJobs();
        $jobs[] = [
            'id' => (string) str()->uuid(),
            'website_id' => $id,
            'domain' => (string) ($website['domain'] ?? ''),
            'name' => trim((string) $validated['name']),
            'expression' => trim((string) $validated['expression']),
            'command' => trim((string) $validated['command']),
            'status' => (string) $validated['status'],
            'description' => trim((string) ($validated['description'] ?? '')),
            'created_at' => now()->toIso8601String(),
        ];

        $this->writeJobs($jobs);

        return redirect()->route('websites.cronjobs.index', $id)->with('success', 'Cron job created.');
    }

    public function update(Request $request, string $id, string $jobId): RedirectResponse
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $validated = $this->validatePayload($request);

        $jobs = collect($this->readJobs())->map(function (array $job) use ($id, $jobId, $validated) {
            if (($job['id'] ?? null) !== $jobId || (string) ($job['website_id'] ?? '') !== $id) {
                return $job;
            }

            $job['name'] = trim((string) $validated['name']);
            $job['expression'] = trim((string) $validated['expression']);
            $job['command'] = trim((string) $validated['command']);
            $job['status'] = (string) $validated['status'];
            $job['description'] = trim((string) ($validated['description'] ?? ''));
            $job['updated_at'] = now()->toIso8601String();

            return $job;
        })->values()->all();

        $this->writeJobs($jobs);

        return redirect()->route('websites.cronjobs.index', $id)->with('success', 'Cron job updated.');
    }

    public function destroy(string $id, string $jobId): RedirectResponse
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $jobs = collect($this->readJobs());
        $before = $jobs->count();
        $after = $jobs
            ->reject(fn (array $job) => ($job['id'] ?? null) === $jobId && (string) ($job['website_id'] ?? '') === $id)
            ->values()
            ->all();

        if (count($after) === $before) {
            return redirect()->route('websites.cronjobs.index', $id)->with('error', 'Cron job not found.');
        }

        $this->writeJobs($after);

        return redirect()->route('websites.cronjobs.index', $id)->with('success', 'Cron job deleted.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findWebsite(string $id): ?array
    {
        return collect($this->readWebsites())->firstWhere('id', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expression' => ['required', 'string', 'max:120', 'regex:/^(\S+\s+){4}\S+$/'],
            'command' => ['required', 'string', 'max:2000'],
            'status' => ['required', 'in:active,disabled'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJobs(): array
    {
        if (! Storage::exists(self::STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::STORAGE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    private function writeJobs(array $jobs): void
    {
        Storage::put(self::STORAGE_FILE, json_encode($jobs, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readWebsites(): array
    {
        if (! Storage::exists(self::WEBSITE_STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::WEBSITE_STORAGE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }
}

