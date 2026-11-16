<?php

namespace App\Services\LoginAudit;

use MaxMind\Db\Reader;

/** Turns an IP into a country, region, city and coordinates from the local DB-IP file. */
class GeoIpLocator
{
    private ?Reader $reader = null;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly GeoIpDatabase $database) {}

    /**
     * @return array{geo_status: string, country_code: ?string, country: ?string, region: ?string, city: ?string, latitude: ?float, longitude: ?float}
     */
    public function locate(string $ip): array
    {
        return $this->cache[$ip] ??= $this->lookup($ip);
    }

    private function lookup(string $ip): array
    {
        $empty = ['country_code' => null, 'country' => null, 'region' => null, 'city' => null, 'latitude' => null, 'longitude' => null];

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['geo_status' => 'unknown'] + $empty;
        }
        // Loopback, LAN and other reserved ranges have no place on a map.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['geo_status' => 'private'] + $empty;
        }

        $reader = $this->reader();
        if ($reader === null) {
            return ['geo_status' => 'pending'] + $empty;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable) {
            $record = null;
        }
        if (! is_array($record)) {
            return ['geo_status' => 'unknown'] + $empty;
        }

        $name = fn (?array $node) => is_array($node['names'] ?? null) ? ($node['names']['en'] ?? reset($node['names']) ?: null) : null;
        $lat = $record['location']['latitude'] ?? null;
        $lng = $record['location']['longitude'] ?? null;

        return [
            'geo_status' => 'located',
            'country_code' => isset($record['country']['iso_code']) ? substr((string) $record['country']['iso_code'], 0, 2) : null,
            'country' => $name($record['country'] ?? null),
            'region' => $name($record['subdivisions'][0] ?? null),
            'city' => $name($record['city'] ?? null),
            'latitude' => is_numeric($lat) ? (float) $lat : null,
            'longitude' => is_numeric($lng) ? (float) $lng : null,
        ];
    }

    private function reader(): ?Reader
    {
        if ($this->reader === null && $this->database->exists()) {
            try {
                $this->reader = new Reader($this->database->path());
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->reader;
    }
}
