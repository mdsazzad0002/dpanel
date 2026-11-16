<?php

namespace App\Services\LoginAudit;

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
            try {
                $response = Http::timeout(600)->withOptions(['sink' => $gz])->get($url);
                if (! $response->successful()) {
                    $errors[] = "{$month}: HTTP {$response->status()}";

                    continue;
                }
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
