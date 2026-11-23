<?php

namespace App\Services\LoginAudit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The DB-IP "IP to City Lite" database (CC BY 4.0), kept on this server so
 * login IPs are never sent to a third party. DB-IP publishes a new file at
 * the start of each month; we fetch it once a month.
 */
class GeoIpDatabase
{
    private const URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    private const PROGRESS_KEY = 'login-audit:geoip-progress';

    /** A running stage with no update for this long means the worker died. */
    private const STALL_SECONDS = 120;

    private const ACTIVE_STAGES = ['queued', 'downloading', 'unpacking', 'locating'];

    public function path(): string
    {
        return storage_path('app/geoip/dbip-city-lite.mmdb');
    }

    public function exists(): bool
    {
        return is_file($this->path()) && filesize($this->path()) > 0;
    }

    /** @return array{installed: bool, updated_at: ?string, size: int} */
    public function info(): array
    {
        $exists = $this->exists();

        return [
            'installed' => $exists,
            'updated_at' => $exists ? date(DATE_ATOM, (int) filemtime($this->path())) : null,
            'size' => $exists ? (int) filesize($this->path()) : 0,
        ];
    }

    /**
     * Where the current (or last) download is, for the progress bar.
     *
     * @return array{stage: string, downloaded: int, total: int, month: ?string, message: ?string, updated_at: int}|null
     */
    public function progress(): ?array
    {
        $progress = Cache::get(self::PROGRESS_KEY);
        if (! is_array($progress)) {
            return null;
        }

        if (in_array($progress['stage'] ?? '', self::ACTIVE_STAGES, true)
            && now()->timestamp - (int) ($progress['updated_at'] ?? 0) > self::STALL_SECONDS) {
            $progress['stage'] = 'failed';
            $progress['message'] = 'The download stopped responding. Try again.';
        }

        return $progress;
    }

    public function isRunning(): bool
    {
        return in_array($this->progress()['stage'] ?? '', self::ACTIVE_STAGES, true);
    }

    public function setProgress(string $stage, array $extra = []): void
    {
        $previous = Cache::get(self::PROGRESS_KEY);
        $base = is_array($previous) && $stage !== 'queued' ? $previous : [];

        Cache::put(self::PROGRESS_KEY, [
            'downloaded' => 0,
            'total' => 0,
            'month' => null,
            'message' => null,
            ...$base,
            ...$extra,
            'stage' => $stage,
            'updated_at' => now()->timestamp,
        ], now()->addDay());
    }

    public function isStale(): bool
    {
        return ! $this->exists() || filemtime($this->path()) < strtotime('-32 days');
    }

    /**
     * Downloads this month's file, or last month's when this month's is not
     * out yet, and swaps it in only once it is complete.
     */
    public function update(): string
    {
        $dir = dirname($this->path());
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        $errors = [];
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $month) {
            $url = sprintf(self::URL, $month);
            $gz = $dir.'/download.mmdb.gz';
            $this->setProgress('downloading', ['month' => $month, 'downloaded' => 0, 'total' => 0, 'message' => null]);
            $lastWrite = 0.0;
            $onProgress = function ($total, $downloaded) use (&$lastWrite): void {
                $now = microtime(true);
                // About once a second is plenty for a progress bar.
                if ($now - $lastWrite < 0.75 && ! ($total > 0 && $downloaded >= $total)) {
                    return;
                }
                $lastWrite = $now;
                $this->setProgress('downloading', ['downloaded' => (int) $downloaded, 'total' => (int) $total]);
            };
            try {
                $response = Http::timeout(600)->withOptions(['sink' => $gz, 'progress' => $onProgress])->get($url);
                if (! $response->successful()) {
                    $errors[] = "{$month}: HTTP {$response->status()}";

                    continue;
                }
                $this->setProgress('unpacking');
                $this->unpack($gz, $dir.'/download.mmdb');
                rename($dir.'/download.mmdb', $this->path());

                return $month;
            } catch (\Throwable $e) {
                $errors[] = "{$month}: {$e->getMessage()}";
            } finally {
                @unlink($gz);
                @unlink($dir.'/download.mmdb');
            }
        }

        throw new RuntimeException('Could not download the DB-IP database ('.implode('; ', $errors).').');
    }

    private function unpack(string $gz, string $target): void
    {
        $in = gzopen($gz, 'rb');
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot unpack the downloaded database.');
        }
        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);

        // A truncated download still gunzips partway; make sure the reader accepts it.
        (new \MaxMind\Db\Reader($target))->close();
    }
}
