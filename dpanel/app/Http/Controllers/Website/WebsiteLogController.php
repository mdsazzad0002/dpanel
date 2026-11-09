<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Platform\SiteLogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A website's access log, its firewall matches (S2) and its account's PHP error log (S4). */
class WebsiteLogController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);

        return Inertia::render('Websites/Logs/Index', [
            'website' => ['id' => (string) $website->id, 'domain' => (string) $website->domain],
        ]);
    }

    public function lines(Request $request, string $token, string $id, SiteLogClient $logs): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'kind' => ['required', 'in:access,php,waf'],
            'lines' => ['nullable', 'integer', 'between:10,2000'],
        ]);

        $owner = (string) $website->site_owner;
        if ($validated['kind'] === 'php' && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $owner) !== 1) {
            return response()->json(['ok' => true, 'exists' => false, 'lines' => []]);
        }

        try {
            $result = $logs->tail($validated['kind'], (string) $website->domain, $owner, (int) ($validated['lines'] ?? 200));
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true, ...$result]);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }
}
