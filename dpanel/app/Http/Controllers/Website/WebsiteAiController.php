<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\AiGateway\PanelAi;
use App\Services\Platform\SiteLogClient;
use App\Services\Website\CronAi;
use App\Services\Website\SiteLogAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AI helpers on website pages: a cron job from plain words and an
 * explanation of the site's logs. Both only suggest; nothing is changed.
 */
class WebsiteAiController extends Controller
{
    public function __construct(private readonly PanelAi $ai) {}

    public function cron(Request $request, CronAi $cronAi, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'request' => ['required', 'string', 'max:1000'],
            'command' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $suggestion = $cronAi->suggest($website, (string) $validated['request'], (string) ($validated['command'] ?? ''), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, ...$suggestion]);
    }

    /**
     * The log is read again here instead of taken from the browser, so this
     * endpoint only ever explains the site's own log.
     */
    public function logs(Request $request, SiteLogAi $logAi, SiteLogClient $logs, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'kind' => ['required', 'in:access,php,waf'],
            'lines' => ['nullable', 'integer', 'between:10,500'],
            'filter' => ['nullable', 'string', 'max:200'],
            'language' => ['nullable', 'in:en,bn'],
        ]);

        $owner = (string) $website->site_owner;
        if ($validated['kind'] === 'php' && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $owner) !== 1) {
            return response()->json(['ok' => false, 'message' => 'This website has no PHP error log.'], 422);
        }

        try {
            $result = $logs->tail($validated['kind'], (string) $website->domain, $owner, (int) ($validated['lines'] ?? 200));
            $lines = array_values(array_filter((array) ($result['lines'] ?? []), 'is_string'));
            $needle = mb_strtolower(trim((string) ($validated['filter'] ?? '')));
            if ($needle !== '') {
                $lines = array_values(array_filter($lines, fn (string $line): bool => str_contains(mb_strtolower($line), $needle)));
            }

            $result = $logAi->explain($validated['kind'], (string) $website->domain, $lines, (string) ($validated['language'] ?? 'en'), $request->user()?->id, (string) $website->id, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        // Each action is applied by the user through websites.ip-rules.store.
        return response()->json(['ok' => true, ...$result]);
    }

    private function website(Request $request, string $id): Website
    {
        $website = Website::query()->visibleTo($request->user())->findOrFail($id);
        // The buttons are hidden without a model; a direct call gets a clear answer.
        abort_unless($this->ai->available(), 404, 'AI Gateway has no active model.');

        return $website;
    }
}
