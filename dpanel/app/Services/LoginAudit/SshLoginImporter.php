<?php

namespace App\Services\LoginAudit;

use App\Models\LoginEvent;
use App\Services\Security\DrustSecurityClient;
use App\Support\Platform\LocalCommand;
use Illuminate\Support\Carbon;

/**
 * Copies SSH logins from the system journal (through drust) into
 * login_events, so they outlive journal rotation. The journal returns the
 * same lines on every run; the fingerprint keeps one row per line.
 */
class SshLoginImporter
{
    public function __construct(
        private readonly DrustSecurityClient $drust,
        private readonly LoginAuditRecorder $recorder,
        private readonly LocalCommand $local,
    ) {}

    /** SSH logins come from systemd's journal on Linux; nothing to read elsewhere. */
    public function supported(): bool
    {
        return $this->local->supports(LocalCommand::LINUX_TOOLS);
    }

    public function import(): int
    {
        if (! $this->supported()) {
            return 0;
        }

        $events = (array) ($this->drust->sshHistory()['events'] ?? []);
        $prints = [];
        foreach ($events as $event) {
            $prints[$this->fingerprint((array) $event)] = (array) $event;
        }
        $known = LoginEvent::query()->whereIn('fingerprint', array_keys($prints))->pluck('fingerprint')->flip();

        $added = 0;
        // Oldest first, so ids follow time.
        foreach (array_reverse($prints, true) as $print => $event) {
            if (isset($known[$print]) || ! isset($event['ip'], $event['time'])) {
                continue;
            }
            $accepted = ($event['result'] ?? '') === 'accepted';
            $method = (string) ($event['method'] ?? '');
            $saved = $this->recorder->record([
                'channel' => LoginEvent::CHANNEL_SSH,
                'result' => $accepted ? LoginEvent::RESULT_SUCCESS : LoginEvent::RESULT_FAILED,
                'method' => $method !== '' ? $method : null,
                'reason' => $accepted ? null : (str_contains($method, 'invalid user') ? 'No such Linux account' : 'Wrong password or key'),
                'username' => (string) ($event['user'] ?? ''),
                'ip_address' => (string) $event['ip'],
                'fingerprint' => $print,
                'occurred_at' => Carbon::createFromTimestamp((int) $event['time']),
            ]);
            if ($saved) {
                $added++;
            }
        }

        return $added;
    }

    /** @param  array<string, mixed>  $event */
    private function fingerprint(array $event): string
    {
        return sha1(implode('|', ['ssh', $event['time'] ?? '', $event['ip'] ?? '', $event['user'] ?? '', $event['result'] ?? '', $event['method'] ?? '']));
    }
}
