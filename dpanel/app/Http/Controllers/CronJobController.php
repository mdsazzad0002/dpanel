<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    public function index(string $id): Response
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $jobs = CronJob::query()
            ->where('website_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CronJob $job): array => [
                'id' => (string) $job->id,
                'website_id' => (string) $job->website_id,
                'domain' => (string) ($job->domain ?? ''),
                'name' => (string) $job->name,
                'expression' => (string) $job->expression,
                'command' => (string) $job->command,
                'status' => (string) $job->status,
                'description' => (string) ($job->description ?? ''),
                'created_at' => $job->created_at?->toIso8601String(),
                'updated_at' => $job->updated_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Websites/CronJobs', [
            'website' => [
                'id' => $website->id,
                'domain' => $website->domain ?? '',
            ],
            'cronJobs' => $jobs,
        ]);
    }

    public function store(Request $request, string $id): RedirectResponse
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $validated = $this->validatePayload($request);

        CronJob::query()->create([
            'id' => (string) str()->uuid(),
            'website_id' => $id,
            'domain' => (string) ($website->domain ?? ''),
            'name' => trim((string) $validated['name']),
            'expression' => trim((string) $validated['expression']),
            'command' => trim((string) $validated['command']),
            'status' => (string) $validated['status'],
            'description' => trim((string) ($validated['description'] ?? '')),
        ]);

        return redirect()->route('websites.cronjobs.index', $id)->with('success', 'Cron job created.');
    }

    public function update(Request $request, string $id, string $jobId): RedirectResponse
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $validated = $this->validatePayload($request);

        $job = CronJob::query()
            ->where('id', $jobId)
            ->where('website_id', $id)
            ->first();

        if ($job === null) {
            return redirect()->route('websites.cronjobs.index', $id)->with('error', 'Cron job not found.');
        }

        $job->update([
            'name' => trim((string) $validated['name']),
            'expression' => trim((string) $validated['expression']),
            'command' => trim((string) $validated['command']),
            'status' => (string) $validated['status'],
            'description' => trim((string) ($validated['description'] ?? '')),
        ]);

        return redirect()->route('websites.cronjobs.index', $id)->with('success', 'Cron job updated.');
    }

    public function destroy(string $id, string $jobId): RedirectResponse
    {
        $website = $this->findWebsite($id);
        abort_if($website === null, 404);

        $deleted = CronJob::query()
            ->where('id', $jobId)
            ->where('website_id', $id)
            ->delete();

        if (! $deleted) {
            return redirect()->route('websites.cronjobs.index', $id)->with('error', 'Cron job not found.');
        }

        return redirect()->route('websites.cronjobs.index', $id)->with('success', 'Cron job deleted.');
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

    private function findWebsite(string $id): ?Website
    {
        return Website::query()->find($id);
    }
}

