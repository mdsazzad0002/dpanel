<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('AiGateway/ApiKeys/Index', [
            'keys' => $this->serialisedKeys(),
            'apiBaseUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $generated = AiGatewayApiKey::generate();

        $key = AiGatewayApiKey::create([
            'name' => $data['name'],
            'key_prefix' => $generated['prefix'],
            'key_hash' => $generated['hash'],
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'key' => $this->serialiseKey($key->load('creator:id,name')),
            'plain_key' => $generated['plain'],
        ]);
    }

    private function serialisedKeys()
    {
        return AiGatewayApiKey::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AiGatewayApiKey $k) => $this->serialiseKey($k));
    }

    private function serialiseKey(AiGatewayApiKey $k): array
    {
        return [
            'id' => $k->id,
            'name' => $k->name,
            'key_prefix' => $k->key_prefix,
            'is_active' => $k->is_active,
            'last_used_at' => $k->last_used_at?->toDateTimeString(),
            'created_by' => $k->creator?->name,
            'created_at' => $k->created_at?->toDateTimeString(),
        ];
    }

    public function toggle(Request $request, $token, AiGatewayApiKey $apiKey): RedirectResponse
    {
        $apiKey->update(['is_active' => ! $apiKey->is_active]);

        return back();
    }

    /**
     * Issue a new secret for an existing key record (same id/name), since
     * the original plaintext can never be recovered once created — only
     * its hash is stored. The old secret stops working immediately.
     */
    public function regenerate(Request $request, $token, AiGatewayApiKey $apiKey): JsonResponse
    {
        $generated = AiGatewayApiKey::generate();

        $apiKey->update([
            'key_prefix' => $generated['prefix'],
            'key_hash' => $generated['hash'],
        ]);

        return response()->json([
            'key' => $this->serialiseKey($apiKey->load('creator:id,name')),
            'plain_key' => $generated['plain'],
        ]);
    }

    public function destroy(Request $request, $token, AiGatewayApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        return back();
    }
}
