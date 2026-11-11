<?php

namespace App\Services\Extensions;

use App\Jobs\DeliverExtensionEvent;
use App\Models\Extension;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Turns panel changes into extension events (backlog U7). Each enabled
 * extension that subscribed and was granted the event's area gets its own
 * queued, signed webhook. Payloads carry ids and names, never secrets.
 */
class ExtensionEvents
{
    public static function listen(): void
    {
        Website::created(fn (Website $website) => app(self::class)->dispatch('website.created', self::website($website)));
        Website::deleted(fn (Website $website) => app(self::class)->dispatch('website.deleted', self::website($website)));
    }

    /** @param array<string, mixed> $data */
    public function dispatch(string $event, array $data): int
    {
        $sent = 0;
        try {
            $extensions = Extension::query()->where('enabled', true)->whereNotNull('webhook_url')->get();
        } catch (QueryException $e) {
            // An event must never break the change it reports, e.g. before
            // the extensions migration has run.
            report($e);

            return 0;
        }
        foreach ($extensions as $extension) {
            if ($extension->wantsEvent($event) && ExtensionManifest::allowsEvent($event, $extension->grantedScopes())) {
                DeliverExtensionEvent::dispatch($extension->id, self::envelope($event, $data))->afterCommit();
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, event: string, occurred_at: string, data: array<string, mixed>}
     */
    public static function envelope(string $event, array $data): array
    {
        return ['id' => (string) Str::uuid(), 'event' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $data];
    }

    /** @return array<string, mixed> */
    private static function website(Website $website): array
    {
        return [
            'id' => (string) $website->id,
            'domain' => (string) $website->domain,
            'type' => $website->type,
            'parent_id' => $website->parent_id,
            'owner_id' => $website->assigned_user_id,
        ];
    }
}
