<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteQueueWorker;
use App\Services\Website\LaravelQueueService;
use App\Services\Website\WebsiteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaravelQueueController extends Controller
{
    public function __construct(
        protected LaravelQueueService $queue,
        protected WebsiteAccessService $access,
    ) {}

    public function index(Request $request, string $token, string $id): JsonResponse
    {
        return response()->json(['success' => true, ...$this->state($this->website($id))]);
    }

    public function store(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($id);
        if ($website->queueWorkers()->count() >= WebsiteQueueWorker::MAX_PER_WEBSITE) {
            return response()->json([
                'success' => false,
                'message' => 'A website can have at most '.WebsiteQueueWorker::MAX_PER_WEBSITE.' queue workers.',
            ], 422);
        }

        $website->queueWorkers()->create($this->validated($request));

        return $this->syncAndRespond($website, 'Queue worker added and started. It will also start on boot.');
    }

    public function update(Request $request, string $token, string $id, string $worker): JsonResponse
    {
        $website = $this->website($id);
        $this->worker($website, $worker)->update($this->validated($request));

        return $this->syncAndRespond($website, 'Queue worker updated.');
    }

    public function destroy(Request $request, string $token, string $id, string $worker): JsonResponse
    {
        $website = $this->website($id);
        $this->worker($website, $worker)->delete();

        return $this->syncAndRespond($website, 'Queue worker removed and stopped.');
    }

    /** Re-applies every worker and restarts them, e.g. after a deploy or a failed start. */
    public function restart(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($id);
        try {
            $this->queue->sync($website);
            $this->queue->restart($website);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), ...$this->state($website)], 422);
        }

        return response()->json(['success' => true, 'message' => 'Queue workers restarted.', ...$this->state($website)]);
    }

    public function logs(Request $request, string $token, string $id, string $worker): JsonResponse
    {
        $website = $this->website($id);
        try {
            $output = $this->queue->logs($website, $this->worker($website, $worker));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'output' => $output]);
    }

    /** A failed start keeps the saved row so the user can fix the app and retry. */
    private function syncAndRespond(Website $website, string $message): JsonResponse
    {
        try {
            $this->queue->sync($website);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Saved, but the workers could not be started: '.$e->getMessage(),
                ...$this->state($website),
            ], 422);
        }

        return response()->json(['success' => true, 'message' => $message, ...$this->state($website)]);
    }

    /** @return array{workers: list<array<string, mixed>>, status_error: ?string} */
    private function state(Website $website): array
    {
        $statusError = null;
        try {
            $status = $this->queue->status($website);
        } catch (\Throwable $e) {
            $status = [];
            $statusError = $e->getMessage();
        }

        $workers = $website->queueWorkers()->orderBy('id')->get()
            ->map(fn (WebsiteQueueWorker $worker): array => [
                ...$worker->only(['id', 'connection', 'queue', 'processes', 'tries', 'timeout', 'sleep', 'memory', 'enabled']),
                'instances' => $status[(string) $worker->id] ?? [],
            ])
            ->values()
            ->all();

        return ['workers' => $workers, 'status_error' => $statusError];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'connection' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]*$/'],
            'queue' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9_.:-]+(,[A-Za-z0-9_.:-]+)*$/'],
            'processes' => ['required', 'integer', 'min:1', 'max:'.WebsiteQueueWorker::MAX_PROCESSES],
            'tries' => ['required', 'integer', 'min:1', 'max:100'],
            'timeout' => ['required', 'integer', 'min:0', 'max:3600'],
            'sleep' => ['required', 'integer', 'min:1', 'max:60'],
            'memory' => ['required', 'integer', 'min:64', 'max:4096'],
            'enabled' => ['required', 'boolean'],
        ], [
            'queue.regex' => 'Queue names may use letters, digits, _ - . : and commas between names.',
            'connection.regex' => 'The connection name may use letters, digits, _ - . and :.',
        ]);
        $validated['connection'] = trim((string) ($validated['connection'] ?? '')) ?: null;

        return $validated;
    }

    private function website(string $id): Website
    {
        $this->access->findAuthorizedWebsiteOrFail($id);

        return Website::query()->findOrFail($id);
    }

    private function worker(Website $website, string $worker): WebsiteQueueWorker
    {
        return $website->queueWorkers()->whereKey($worker)->firstOrFail();
    }
}
