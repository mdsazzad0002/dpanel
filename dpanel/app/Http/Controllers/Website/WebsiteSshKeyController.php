<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteSshKeyController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        return Inertia::render('Websites/SshKeyGenerator', [
            'website' => $this->website($request, $id),
            'sshHost' => $request->getHost(),
            'sshPort' => 22,
        ]);
    }

    public function generate(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        if (blank($website->site_owner)) {
            return response()->json(['message' => 'This website does not have a system user.'], 422);
        }
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:120', 'regex:/^[^\r\n]+$/'],
        ]);

        $client = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($apiToken !== '') {
            $client = $client->withToken($apiToken);
        }
        $response = $client->post(
            rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/ssh-key/generate',
            [
                'site_owner' => (string) $website->site_owner,
                'comment' => trim((string) ($validated['comment'] ?? '')) ?: 'github-actions@'.$website->domain,
            ],
        );
        $json = $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) {
            return response()->json(['message' => (string) ($json['message'] ?? 'Unable to generate SSH key.')], 422);
        }

        return response()->json([
            'message' => 'SSH key generated and installed for '.$website->site_owner.'.',
            'key' => $json['data'] ?? [],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }
}
