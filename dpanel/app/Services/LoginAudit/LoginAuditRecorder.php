<?php

namespace App\Services\LoginAudit;

use App\Models\LoginEvent;
use Illuminate\Support\Facades\Log;

/** Writes login events. Recording must never break a login, so errors are only logged. */
class LoginAuditRecorder
{
    public function __construct(private readonly GeoIpLocator $locator) {}

    /** @param  array<string, mixed>  $attributes */
    public function record(array $attributes): ?LoginEvent
    {
        try {
            $ip = (string) ($attributes['ip_address'] ?? '');
            $attributes['user_agent'] = isset($attributes['user_agent'])
                ? mb_substr((string) $attributes['user_agent'], 0, 512)
                : null;
            $attributes['username'] = isset($attributes['username'])
                ? mb_substr((string) $attributes['username'], 0, 191)
                : null;
            $attributes['occurred_at'] ??= now();

            return LoginEvent::query()->create($attributes + $this->locator->locate($ip));
        } catch (\Throwable $e) {
            Log::warning('Login audit: could not record event', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Fills in places for rows saved before the DB-IP file was there. */
    public function locatePending(int $limit = 5000): int
    {
        $done = 0;
        LoginEvent::query()
            ->where('geo_status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (LoginEvent $event) use (&$done): void {
                $geo = $this->locator->locate($event->ip_address);
                if ($geo['geo_status'] !== 'pending') {
                    $event->update($geo);
                    $done++;
                }
            });

        return $done;
    }
}
