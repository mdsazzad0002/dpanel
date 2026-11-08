<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\Platform\FirewallClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Firewall rules from the panel (backlog S3). drust writes them to UFW or
 * firewalld and undoes each change that is not confirmed within its window,
 * so a rule that cuts the admin off comes back out on its own.
 */
class FirewallRuleController extends Controller
{
    public function __construct(
        private readonly FirewallClient $firewall,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Security/Firewall/Index');
    }

    public function list(): JsonResponse
    {
        try {
            return response()->json(['ok' => true, ...$this->firewall->list()]);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:allow,deny'],
            'port_from' => ['nullable', 'integer', 'between:1,65535', 'required_without:source'],
            'port_to' => ['nullable', 'integer', 'between:1,65535', 'gt:port_from'],
            'protocol' => ['nullable', 'in:tcp,udp'],
            'source' => ['nullable', 'string', 'max:64', 'required_without:port_from', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! self::isAddress((string) $value)) {
                    $fail('The source must be an IPv4 or IPv6 address, optionally with a /prefix.');
                }
            }],
        ]);

        $rule = [
            'action' => $validated['action'],
            'port' => isset($validated['port_from']) ? [
                'from' => (int) $validated['port_from'],
                'to' => isset($validated['port_to']) ? (int) $validated['port_to'] : null,
                'protocol' => $validated['protocol'] ?? 'tcp',
            ] : null,
            'source' => ($validated['source'] ?? '') !== '' ? trim($validated['source']) : null,
        ];

        try {
            $result = $this->firewall->add($rule);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->activity->log('firewall.rule_added', null, ['rule' => $rule, 'id' => $result['id']], $request);

        return response()->json(['ok' => true, ...$result]);
    }

    public function destroy(Request $request, string $token, string $rule): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f]{12}$/', $rule) === 1, 404);

        try {
            $result = $this->firewall->remove($rule);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->activity->log('firewall.rule_removed', null, ['id' => $rule], $request);

        return response()->json(['ok' => true, ...$result]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'regex:/^[0-9a-f]{24}$/']]);

        try {
            $kept = $this->firewall->confirm($validated['token']);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        if (! $kept) {
            return response()->json(['ok' => false, 'message' => 'The confirm came too late; the change has been undone.'], 409);
        }

        $this->activity->log('firewall.change_confirmed', null, [], $request);

        return response()->json(['ok' => true, 'message' => 'Change kept.']);
    }

    private static function isAddress(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }
        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }
}
