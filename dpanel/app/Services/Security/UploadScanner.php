<?php

namespace App\Services\Security;

use App\Models\SecurityRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Antivirus check for uploaded files, switched on and off from Security
 * Center. While it is off nothing is scanned and the ClamAV daemon is
 * stopped, so it costs no memory. While it is on, an upload that cannot be
 * checked is refused rather than let through unscanned.
 */
class UploadScanner
{
    private const TABLE = 'security_settings';

    private const KEY = 'upload_scan';

    public function __construct(private readonly DrustSecurityClient $drust) {}

    public function enabled(): bool
    {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return false;
            }
            $raw = DB::table(self::TABLE)->where('setting_key', self::KEY)->value('setting_value');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            return (bool) ($decoded['enabled'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Turning on starts the ClamAV daemon through drust first and only then
     * saves the setting, so scanning is never on without a daemon behind it.
     * Turning off saves the setting first, so it takes effect at once even
     * when drust or ClamAV is broken; stopping the daemon is then best effort.
     *
     * @return string|null a warning when the daemon could not be stopped
     */
    public function setEnabled(bool $enabled): ?string
    {
        if ($enabled) {
            $this->drust->setUploadScan(true);
            $this->store(true);

            return null;
        }

        $this->store(false);
        try {
            $this->drust->setUploadScan(false);
        } catch (\Throwable $e) {
            Log::warning('Upload scan was turned off but the ClamAV daemon could not be stopped.', ['error' => $e->getMessage()]);

            return 'Upload scanning is off, but the ClamAV daemon could not be stopped: '.$e->getMessage();
        }

        return null;
    }

    private function store(bool $enabled): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::KEY],
            ['setting_value' => json_encode(['enabled' => $enabled]), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        try {
            $daemon = $this->drust->uploadScanStatus();
        } catch (\Throwable $e) {
            $daemon = ['error' => $e->getMessage()];
        }

        return ['enabled' => $this->enabled(), 'daemon' => $daemon];
    }

    /**
     * Throw if upload scanning is on and the file is infected or cannot be
     * checked. Does nothing while upload scanning is off.
     *
     * @param  string  $name  the file's real name; staged uploads often have none
     */
    public function assertClean(string $path, string $name): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $report = $this->drust->scanUploadedFile($path, $name);
        } catch (\Throwable $e) {
            Log::warning('Upload scan could not check a file.', ['name' => $name, 'error' => $e->getMessage()]);

            throw new UploadRejectedException('The upload could not be checked for viruses, so it was not saved. An admin can turn upload scanning off under Security Center → Detection Rules. '.$e->getMessage(), previous: $e);
        }

        $threats = array_values(array_filter((array) ($report['threats'] ?? []), 'is_string'));
        if (! ($report['clean'] ?? true) || $threats !== []) {
            Log::warning('Upload scan blocked an infected file.', ['name' => $name, 'threats' => $threats]);

            throw new UploadRejectedException('Virus detected in '.$name.' ('.implode(', ', $threats ?: ['unknown threat']).'). The file was not saved.');
        }

        // Only critical PHP findings block an upload: eval of decoded data,
        // shell commands fed by request input and known webshells.
        $findings = array_filter((array) ($report['findings'] ?? []), fn ($finding) => ($finding['severity'] ?? null) === 'critical');
        $disabled = SecurityRule::query()->where('enabled', false)->pluck('rule_id')->all();
        $findings = array_filter($findings, fn ($finding) => ! in_array($finding['rule_id'] ?? '', $disabled, true));
        if ($findings !== []) {
            $titles = array_unique(array_map(fn ($finding) => (string) ($finding['title'] ?? $finding['rule_id'] ?? 'Malicious code'), $findings));
            Log::warning('Upload scan blocked malicious PHP.', ['name' => $name, 'findings' => array_column($findings, 'rule_id')]);

            throw new UploadRejectedException('Malicious code detected in '.$name.' ('.implode(', ', $titles).'). The file was not saved.');
        }
    }
}
