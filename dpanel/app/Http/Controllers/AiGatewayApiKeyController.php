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
            'apiBaseUrl' => rtrim($request->getSchemeAndHttpHost(), '/').'/api/v1',
            'limitDefaults' => [
                'ip_per_minute' => (int) config('aigateway.rate_limit.ip_per_minute', 120),
                'key_per_minute' => (int) config('aigateway.rate_limit.key_per_minute', 600),
                'key_ip_per_minute' => (int) config('aigateway.rate_limit.key_ip_per_minute', 60),
                'bad_key_attempts' => (int) config('aigateway.rate_limit.bad_key_attempts', 10),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->limitRules(),
        ]);

        $generated = AiGatewayApiKey::generate();

        $key = AiGatewayApiKey::create([
            ...$this->limitAttributes($data),
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
            'rate_limit_per_minute' => $k->rate_limit_per_minute,
            'ip_rate_limit_per_minute' => $k->ip_rate_limit_per_minute,
            'allowed_ips' => implode("\n", $k->allowedIpList()),
            'last_used_at' => $k->last_used_at?->toDateTimeString(),
            'created_by' => $k->creator?->name,
            'created_at' => $k->created_at?->toDateTimeString(),
        ];
    }

    /**
     * Per-key request limits and IP allowlist. Empty limit = config default,
     * 0 = unlimited; empty allowlist = any IP.
     */
    public function updateLimits(Request $request, $token, AiGatewayApiKey $apiKey): JsonResponse
    {
        $data = $request->validate($this->limitRules());

        $apiKey->update($this->limitAttributes($data));

        return response()->json(['key' => $this->serialiseKey($apiKey->load('creator:id,name'))]);
    }

    private function limitRules(): array
    {
        return [
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'ip_rate_limit_per_minute' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'allowed_ips' => ['nullable', 'string', 'max:5000', function (string $attr, $value, \Closure $fail): void {
                foreach (AiGatewayApiKey::parseIpList((string) $value) as $entry) {
                    if (! AiGatewayApiKey::isValidIpOrCidr($entry)) {
                        $fail(__('":entry" is not a valid IP address or CIDR range.', ['entry' => $entry]));

                        return;
                    }
                }
            }],
        ];
    }

    private function limitAttributes(array $data): array
    {
        $ips = AiGatewayApiKey::parseIpList((string) ($data['allowed_ips'] ?? ''));

        return [
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? null,
            'ip_rate_limit_per_minute' => $data['ip_rate_limit_per_minute'] ?? null,
            'allowed_ips' => $ips === [] ? null : implode("\n", $ips),
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
