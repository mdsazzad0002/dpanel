<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Jobs\QuickExportJob;
use App\Models\DatabaseRequest;
use App\Models\Website;
use App\Services\Backup\QuickExportJobStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Queues the existing quick-export job for several websites at once. Each
 * site's files and each of its databases become their own download; the
 * list page polls the per-site quick-export status route for every item.
 */
class WebsiteBulkExportController extends Controller
{
    private const MAX_WEBSITES = 50;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'website_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_WEBSITES],
            'website_ids.*' => ['required', 'string', 'max:64'],
            'files' => ['boolean'],
            'databases' => ['boolean'],
        ]);
        $withFiles = (bool) ($validated['files'] ?? true);
        $withDatabases = (bool) ($validated['databases'] ?? false);
        if (! $withFiles && ! $withDatabases) {
            return response()->json(['ok' => false, 'message' => 'Choose files, databases or both.'], 422);
        }

        if (trim((string) config('serverpanel.execution_api_base_url', '')) === ''
            || trim((string) config('serverpanel.execution_api_token', '')) === '') {
            return response()->json(['ok' => false, 'message' => 'dRust backup API is not configured.'], 503);
        }

        $user = $request->user();
        // The system website is the panel itself; quick export is hidden for it elsewhere too.
        $websites = Website::query()->visibleTo($user)
            ->whereIn('id', array_unique($validated['website_ids']))
            ->where('id', '!=', 1)
            ->get();

        $items = [];
        foreach ($websites as $website) {
            if ($withFiles) {
                $items[] = $this->queue($user->id, $website, 'files', null, 'Files');
            }
            if ($withDatabases) {
                $databases = DatabaseRequest::query()->visibleTo($user)
                    ->where('domain', $website->domain)
                    ->where('status', 'active')
                    ->get();
                foreach ($databases as $database) {
                    $items[] = $this->queue($user->id, $website, 'database', (string) $database->id, 'Database '.$database->database_name);
                }
            }
        }

        if ($items === []) {
            return response()->json(['ok' => false, 'message' => 'Nothing to export for the selected websites.'], 422);
        }

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * @return array{website_id: string, domain: string, label: string, export_id: string}
     */
    private function queue(int $userId, Website $website, string $content, ?string $databaseId, string $label): array
    {
        $exportId = (string) Str::uuid();
        QuickExportJobStatus::set($exportId, ['stage' => 'queued']);
        QuickExportJob::dispatch($exportId, (string) $website->id, $userId, $content, $databaseId);

        return [
            'website_id' => (string) $website->id,
            'domain' => (string) $website->domain,
            'label' => $label,
            'export_id' => $exportId,
        ];
    }
}
