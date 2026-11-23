<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Services\LoginAudit\GeoIpDatabase;
use App\Services\LoginAudit\LoginLocationSetting;
use App\Services\LoginAudit\SshLoginImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Panel and SSH login history with where each login came from. Admins only. */
class LoginAuditController extends Controller
{
    public function __construct(
        private readonly GeoIpDatabase $geoip,
        private readonly SshLoginImporter $ssh,
        private readonly LoginLocationSetting $location,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Security/LoginAudit/Index', [
            'clientIp' => (string) $request->ip(),
            'sshSupported' => $this->ssh->supported(),
            'geoip' => $this->geoip->info(),
            'geoipProgress' => $this->geoip->progress(),
            'retentionDays' => (int) config('dpanel.login_audit_retention_days', 365),
            'locationModes' => $this->location->all(),
            'scheme' => $request->secure() ? 'https' : 'http',
        ]);
    }

    /**
     * Saves the login location mode for one scheme. Any mode can be set on
     * any scheme; the page warns and asks before "required" without a
     * working test, and `login-audit:location off` undoes a lock-out.
     */
    public function location(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scheme' => ['required', 'in:'.implode(',', LoginLocationSetting::SCHEMES)],
            'mode' => ['required', 'in:'.implode(',', LoginLocationSetting::MODES)],
        ]);
        $label = strtoupper($validated['scheme']);

        $this->location->set($validated['scheme'], $validated['mode']);

        return response()->json(['success' => true, 'modes' => $this->location->all(), 'message' => match ($validated['mode']) {
            'off' => "{$label}: the login form no longer asks for a location.",
            'ask' => "{$label}: the login form asks for a location; signing in works without it.",
            'required' => "{$label}: signing in now needs the device location. If a browser gives none, run `php artisan login-audit:location off` on the server.",
        }]);
    }

    public function events(Request $request): JsonResponse
    {
        $page = $this->filtered($request)
            ->with(['user:id,name,email,role', 'actor:id,name,email'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min(200, max(10, (int) $request->integer('per_page', 50))));

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'stats' => $this->stats($request),
        ]);
    }

    /** One point per place, with how many logins of each kind came from there. */
    public function map(Request $request): JsonResponse
    {
        // The browser's own position when it was shared, otherwise the IP's place.
        $lat = 'COALESCE(device_latitude, latitude)';
        $lng = 'COALESCE(device_longitude, longitude)';
        $points = $this->filtered($request)
            ->whereRaw("{$lat} IS NOT NULL")
            ->whereRaw("{$lng} IS NOT NULL")
            ->select([
                DB::raw("{$lat} as latitude"),
                DB::raw("{$lng} as longitude"),
                DB::raw('MAX(device_latitude IS NOT NULL) as device'),
                'city', 'region', 'country', 'country_code',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(result = 'success') as success"),
                DB::raw("SUM(result = 'failed') as failed"),
                DB::raw("SUM(channel = 'ssh') as ssh"),
                DB::raw("SUM(channel = 'panel') as panel"),
                DB::raw('COUNT(DISTINCT ip_address) as ips'),
                DB::raw('MAX(occurred_at) as last_seen'),
            ])
            ->groupBy(DB::raw($lat), DB::raw($lng), 'city', 'region', 'country', 'country_code')
            ->orderByDesc('total')
            ->limit(2000)
            ->get();

        return response()->json(['success' => true, 'data' => $points]);
    }

    /** Pulls new SSH lines from the journal now instead of waiting for the 5-minute job. */
    public function sync(): JsonResponse
    {
        if (! $this->ssh->supported()) {
            return response()->json(['success' => true, 'message' => 'Panel logins are recorded as they happen.']);
        }

        try {
            $added = $this->ssh->import();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'SSH import failed: '.$e->getMessage()], 502);
        }

        return response()->json(['success' => true, 'message' => "{$added} new SSH ".($added === 1 ? 'event' : 'events').' saved.']);
    }

    /** Starts the DB-IP download after the response, since it takes a minute or two. */
    public function geoip(): JsonResponse
    {
        if ($this->geoip->isRunning()) {
            return response()->json([
                'success' => true,
                'message' => 'The DB-IP database is already downloading.',
                'progress' => $this->geoip->progress(),
            ]);
        }

        // Shown straight away, before the worker picks it up after the response.
        $this->geoip->setProgress('queued');

        dispatch(function (): void {
            Artisan::call('login-audit:geoip-update', ['--force' => true]);
        })->afterResponse();

        return response()->json([
            'success' => true,
            'message' => 'Downloading the DB-IP database (about 130 MB). Places fill in once it is done.',
            'progress' => $this->geoip->progress(),
        ]);
    }

    /** Polled by the map card's progress bar while the download runs. */
    public function geoipStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'progress' => $this->geoip->progress(),
            'geoip' => $this->geoip->info(),
        ]);
    }

    private function filtered(Request $request): Builder
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'in:panel,ssh'],
            'result' => ['nullable', 'in:success,failed,logout'],
            'search' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'size:2'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return LoginEvent::query()
            ->when($validated['channel'] ?? null, fn (Builder $q, $v) => $q->where('channel', $v))
            ->when($validated['result'] ?? null, fn (Builder $q, $v) => $q->where('result', $v))
            ->when($validated['country'] ?? null, fn (Builder $q, $v) => $q->where('country_code', strtoupper($v)))
            ->when($validated['from'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '>=', \Illuminate\Support\Carbon::parse($v)->startOfDay()))
            ->when($validated['to'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '<=', \Illuminate\Support\Carbon::parse($v)->endOfDay()))
            ->when(trim((string) ($validated['search'] ?? '')), function (Builder $q, string $needle): void {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $q->where(fn (Builder $w) => $w
                    ->where('ip_address', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('country', 'like', $like)
                    ->orWhere('user_agent', 'like', $like));
            });
    }

    /** @return array<string, mixed> */
    private function stats(Request $request): array
    {
        $base = fn () => $this->filtered($request);

        return [
            'total' => $base()->count(),
            'success' => $base()->where('result', 'success')->count(),
            'failed' => $base()->where('result', 'failed')->count(),
            'failed_24h' => $base()->where('result', 'failed')->where('occurred_at', '>=', now()->subDay())->count(),
            'unique_ips' => $base()->distinct()->count('ip_address'),
            'countries' => $base()->whereNotNull('country_code')
                ->select('country_code', 'country', DB::raw('COUNT(*) as total'))
                ->groupBy('country_code', 'country')
                ->orderByDesc('total')
                ->limit(8)
                ->get(),
        ];
    }
}
