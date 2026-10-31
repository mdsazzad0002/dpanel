<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Private phpinfo() for one website. drust runs it through the site's own
 * PHP-FPM pool from a file outside the document root, so nothing is ever
 * published on the website itself; only signed-in panel users can see it.
 */
class WebsitePhpInfoController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);

        return Inertia::render('Websites/PhpInfo', [
            'website' => $website->only(['id', 'domain', 'php_version', 'runtime', 'site_owner']),
        ]);
    }

    public function render(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $runtime = strtolower(trim((string) ($website->runtime ?: 'php')));
        if ($runtime !== 'php') {
            return $this->private(response()->json(['message' => 'This website does not run on PHP.'], 422));
        }

        $client = Http::acceptJson()->asJson()->timeout(45);
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($apiToken !== '') {
            $client = $client->withToken($apiToken);
        }

        try {
            $response = $client->post(
                rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/php/info',
                [
                    'php_version' => trim((string) $website->php_version) ?: null,
                    'site_owner' => trim((string) $website->site_owner) ?: null,
                ],
            );
        } catch (\Throwable) {
            return $this->private(response()->json(['message' => 'dRust API is unreachable.'], 502));
        }

        $json = $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) {
            return $this->private(response()->json(['message' => (string) ($json['message'] ?? 'Unable to load phpinfo().')], 422));
        }

        $html = (string) ($json['data']['html'] ?? '');
        preg_match('/PHP Version\s*(?:<\/[^>]+>\s*)*(?:<[^>]+>\s*)*(\d+\.\d+\.\d+[^<\s]*)/i', $html, $matches);

        return $this->private(response()->json([
            'html' => $html,
            'version' => $matches[1] ?? null,
        ]));
    }

    private function private(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }
}
