<?php

namespace App\Jobs;

use App\Models\Extension;
use App\Services\Extensions\ExtensionManifest;
use App\Services\Extensions\ExtensionSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends one event to one extension's webhook (backlog U7), signed with its
 * secret. Checked again at send time: an extension disabled or removed after
 * the event was queued gets nothing. No redirects are followed.
 */
class DeliverExtensionEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param array{id: string, event: string, occurred_at: string, data: array<string, mixed>} $envelope */
    public function __construct(public int $extensionId, public array $envelope) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $extension = Extension::query()->find($this->extensionId);
        if (! $extension || ! self::shouldSend($extension, $this->envelope['event'])) {
            return;
        }

        // A failure is retried later, but never thrown: on the sync queue that
        // would fail the panel change that raised the event.
        $result = self::send($extension, $this->envelope);
        if (! $result['ok'] && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
        }
    }

    public static function shouldSend(Extension $extension, string $event): bool
    {
        if (! $extension->enabled || ! $extension->webhook_url) {
            return false;
        }

        return $event === 'ping' || ($extension->wantsEvent($event) && ExtensionManifest::allowsEvent($event, $extension->grantedScopes()));
    }

    /**
     * @param  array{id: string, event: string, occurred_at: string, data: array<string, mixed>}  $envelope
     * @return array{ok: bool, status: ?int, error: ?string}
     */
    public static function send(Extension $extension, array $envelope): array
    {
        $body = (string) json_encode(['extension' => $extension->identifier] + $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        $status = null;
        $error = null;
        try {
            $response = Http::withoutRedirecting()
                ->timeout(10)
                ->connectTimeout(5)
                ->withUserAgent('dPanel-Extensions/1')
                ->withHeaders([
                    'X-Dpanel-Event' => $envelope['event'],
                    'X-Dpanel-Delivery' => $envelope['id'],
                    'X-Dpanel-Timestamp' => (string) $timestamp,
                    'X-Dpanel-Signature' => ExtensionSigner::signature((string) $extension->webhook_secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post((string) $extension->webhook_url);
            $status = $response->status();
            if (! $response->successful()) {
                $error = "HTTP {$status}";
            }
        } catch (ConnectionException $e) {
            $error = Str::limit($e->getMessage(), 300);
        }

        $extension->forceFill([
            'last_delivery_at' => now(),
            'last_delivery_status' => $status,
            'last_delivery_error' => $error,
        ])->save();

        return ['ok' => $error === null, 'status' => $status, 'error' => $error];
    }
}
