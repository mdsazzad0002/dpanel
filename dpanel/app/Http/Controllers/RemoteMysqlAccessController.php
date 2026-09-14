<?php

namespace App\Http\Controllers;

use App\Models\RemoteMysqlAccessRule;
use App\Services\RemoteMysqlAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RemoteMysqlAccessController extends Controller
{
    public function __construct(private readonly RemoteMysqlAccessService $service)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Databases/RemoteAccess', $this->state());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', (string) $value)) {
                    $fail('Enter a valid IP address or CIDR range.');
                }
            }],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($data['ip_address']);

        if (RemoteMysqlAccessRule::query()->where('ip_address', $ip)->exists()) {
            return response()->json(['success' => false, 'message' => 'This IP is already allowed.'], 422);
        }

        $wasExternalEnabled = $this->service->isExternalBindEnabled();

        if (! $wasExternalEnabled) {
            $bindResult = $this->service->setBindAddress((string) config('remotemysql.external_bind_address'));
            if (! $bindResult['success']) {
                return response()->json(['success' => false, 'message' => 'Could not enable external MySQL access: '.($bindResult['error'] ?? 'unknown error')], 422);
            }
        }

        $firewallResult = $this->service->addFirewallRule($ip);
        if (! $firewallResult['success']) {
            if (! $wasExternalEnabled) {
                $this->service->setBindAddress((string) config('remotemysql.loopback_bind_address'));
            }

            return response()->json(['success' => false, 'message' => 'Could not add the firewall rule: '.($firewallResult['error'] ?? 'unknown error')], 422);
        }

        RemoteMysqlAccessRule::query()->create([
            'id' => (string) Str::uuid(),
            'ip_address' => $ip,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(array_merge(['success' => true], $this->state()));
    }

    public function destroy(string $id): JsonResponse
    {
        $rule = RemoteMysqlAccessRule::query()->findOrFail($id);

        $firewallResult = $this->service->removeFirewallRule($rule->ip_address);
        $rule->delete();

        if (RemoteMysqlAccessRule::query()->count() === 0) {
            $this->service->setBindAddress((string) config('remotemysql.loopback_bind_address'));
        }

        return response()->json(array_merge([
            'success' => true,
            'firewall_warning' => $firewallResult['success'] ? null : $firewallResult['error'],
        ], $this->state()));
    }

    public function restrictWildcard(): JsonResponse
    {
        $result = $this->service->restrictWildcard();

        return response()->json(array_merge([
            'success' => $result['success'],
            'message' => $result['error'] ?? null,
        ], $this->state()));
    }

    public function refresh(): JsonResponse
    {
        return response()->json(array_merge(['success' => true], $this->state()));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'rules' => RemoteMysqlAccessRule::query()->latest()->get(['id', 'ip_address', 'note', 'created_by', 'created_at']),
            'bindAddress' => $this->service->currentBindAddress(),
            'externalEnabled' => $this->service->isExternalBindEnabled(),
            'liveFirewallLines' => $this->service->liveFirewallLines(),
            'port' => (int) config('remotemysql.port'),
        ];
    }
}
