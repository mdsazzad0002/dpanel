<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteTerminalController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        return Inertia::render('Websites/Terminal', ['website' => $this->website($request, $id)]);
    }

    public function execute(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate(['command' => ['required', 'string', 'max:2000', 'not_regex:/[\r\n\x00]/']]);
        if (blank($website->site_owner) || blank($website->root_path)) {
            return response()->json(['message' => 'Website user or project root is unavailable.'], 422);
        }
        $client = Http::acceptJson()->asJson()->timeout(45);
        if ($apiToken = trim((string) config('serverpanel.execution_api_token', ''))) $client = $client->withToken($apiToken);
        $response = $client->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/website-terminal', [
            'site_owner' => (string) $website->site_owner,
            'project_root' => rtrim((string) ($website->project_root ?: $website->root_path), '/'),
            'command' => trim($validated['command']),
        ]);
        $json = $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) return response()->json(['message' => (string) ($json['message'] ?? 'Terminal command failed.')], 422);
        return response()->json($json['data'] ?? ['output' => '', 'exit_code' => 0])->header('Cache-Control', 'no-store, private');
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }
}
