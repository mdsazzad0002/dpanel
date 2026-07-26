<?php

namespace App\Jobs;

use App\Models\DnsZone;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDnsZoneJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public string $dnsZoneId,
        public string $action = 'sync',
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $zone = DnsZone::with('records')->find($this->zoneId ?? $this->dnsZoneId);
        if (! $zone) {
            return;
        }

        $records = $zone->records->where('is_active', true);
        $zoneFile = $this->generateZoneFile($zone, $records);

        // Write zone file and reload DNS service via execution layer
        // This would call drust API or use SSH to apply changes
    }

    private function generateZoneFile(DnsZone $zone, $records): string
    {
        $lines = [];
        $lines[] = "; Zone: {$zone->domain}";
        $lines[] = "; Generated: " . now()->toRfc3339String();
        $lines[] = "";

        foreach ($records as $record) {
            $ttl = $record->ttl ?? 3600;
            $type = $record->type;
            $name = $record->name === '@' ? '' : $record->name . '.';
            $content = $record->content;
            $priority = $record->priority ? " {$record->priority}" : '';
            $lines[] = "{$name}\t{$ttl}\tIN\t{$type}{$priority}\t{$content}";
        }

        return implode("\n", $lines) . "\n";
    }
}
