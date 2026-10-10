<?php

namespace App\Services\Projects;

/**
 * Lists TCP ports listening on this host and the Linux user owning each
 * socket, straight from /proc/net/tcp{,6} (readable without root).
 */
class LocalPortScanner
{
    private const LISTEN_STATE = '0A';

    /** @return array<int, string> port => owner user name ('' when unknown) */
    public function listening(): array
    {
        $ports = [];
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_slice($lines ?: [], 1) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) < 8 || $cols[3] !== self::LISTEN_STATE) {
                    continue;
                }
                $port = hexdec(substr((string) strrchr($cols[1], ':'), 1));
                if ($port > 0 && ! isset($ports[$port])) {
                    $ports[$port] = $this->userName((int) $cols[7]);
                }
            }
        }
        ksort($ports);

        return $ports;
    }

    public function ownerOf(int $port): ?string
    {
        return $this->listening()[$port] ?? null;
    }

    private function userName(int $uid): string
    {
        static $names = [];
        if (! array_key_exists($uid, $names)) {
            $info = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
            $names[$uid] = is_array($info) ? (string) $info['name'] : (string) $uid;
        }

        return $names[$uid];
    }
}
