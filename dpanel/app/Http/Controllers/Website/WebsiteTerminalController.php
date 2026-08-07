<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteTerminalController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);
        $website->setAttribute('terminal_path', $this->terminalPath($website));

        return Inertia::render('Websites/Terminal', ['website' => $website]);
    }

    public function execute(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:2000', 'not_regex:/[\r\n\x00]/'],
            'working_directory' => ['nullable', 'string', 'max:1500'],
        ]);
        if (blank($website->site_owner) || blank($website->root_path)) {
            return response()->json(['message' => 'Website user or project root is unavailable.'], 422);
        }
        $client = Http::acceptJson()->asJson()->timeout(45);
        if ($apiToken = trim((string) config('serverpanel.execution_api_token', ''))) $client = $client->withToken($apiToken);
        $response = $client->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/website-terminal', [
            'site_owner' => (string) $website->site_owner,
            'project_root' => $this->terminalPath($website),
            'working_directory' => (string) ($validated['working_directory'] ?? $this->terminalPath($website)),
            'command' => trim($validated['command']),
        ]);
        $json = $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) return response()->json(['message' => (string) ($json['message'] ?? 'Terminal command failed.')], 422);
        return response()->json($json['data'] ?? ['output' => '', 'exit_code' => 0])->header('Cache-Control', 'no-store, private');
    }

    public function session(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        if (blank($website->site_owner)) {
            return response()->json(['message' => 'Website owner is unavailable.'], 422);
        }
        $ticket = Str::random(64);
        $client = Http::acceptJson()->asJson()->timeout(5);
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($apiToken !== '') $client = $client->withToken($apiToken);
        $edgeUrl = rtrim((string) config('serverpanel.edge_gateway_internal_url', 'http://127.0.0.1'), '/');
        $response = $client->post($edgeUrl.'/__admin/terminal-ticket', [
            'ticket' => $ticket,
            'site_owner' => (string) $website->site_owner,
            'project_root' => $this->terminalPath($website),
            'expires_at' => now()->addSeconds(60)->timestamp,
            'host' => $request->getHost(),
        ]);
        if (! $response->successful()) {
            return response()->json(['message' => 'Unable to open terminal session.'], 503);
        }
        return response()->json(['ticket' => $ticket, 'path' => '/__dpanel/terminal-ws']);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function terminalPath(Website $website): string
    {
        return '/home/'.trim((string) $website->site_owner, '/');
    }
}
