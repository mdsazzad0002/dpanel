<?php

namespace App\Services\Dns;

/**
 * Reads and writes RFC 1035 master files ("BIND zone files"), the format
 * Cloudflare, cPanel and most DNS hosts export. Parsing returns records in
 * the same shape the record form submits, so DnsRecordContent checks them.
 */
class BindZoneFile
{
    private const DEFAULT_TTL = 3600;

    /** Cloudflare exports TTL 1 for "Auto"; it means 300 seconds there. */
    private const AUTO_TTL = 300;

    /**
     * @param  iterable<object{name:string,type:string,content:string,ttl:int|null,prio:int|null}>  $records
     */
    public function export(string $zoneDomain, iterable $records): string
    {
        $lines = [
            "; Zone file for {$zoneDomain}",
            '; Exported by dPanel on '.now()->toRfc2822String(),
            "\$ORIGIN {$zoneDomain}.",
            '$TTL '.self::DEFAULT_TTL,
            '',
        ];
        $rows = collect($records)->sortBy(fn ($record) => [
            strtoupper((string) $record->type) === 'SOA' ? 0 : (strtoupper((string) $record->type) === 'NS' ? 1 : 2),
            (string) $record->name,
            (string) $record->type,
        ]);
        foreach ($rows as $record) {
            $type = strtoupper((string) $record->type);
            $owner = $this->absolute((string) $record->name);
            $ttl = (int) ($record->ttl ?: self::DEFAULT_TTL);
            $lines[] = implode("\t", [$owner, $ttl, 'IN', $type, $this->exportContent($type, (string) $record->content, (int) ($record->prio ?? 0))]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{records: list<array{name:string,type:string,content:string,ttl:int,priority:int|null}>, skipped: list<string>}
     */
    public function parse(string $text, string $zoneDomain): array
    {
        $origin = $zoneDomain;
        $defaultTtl = self::DEFAULT_TTL;
        $lastOwner = $zoneDomain;
        $records = [];
        $skipped = [];

        foreach ($this->logicalLines($text) as [$lineNumber, $startsBlank, $tokens]) {
            if ($tokens === []) {
                continue;
            }
            $directive = strtoupper($tokens[0]);
            if ($directive === '$ORIGIN' && isset($tokens[1])) {
                $origin = $this->resolveName($tokens[1], $origin);

                continue;
            }
            if ($directive === '$TTL' && isset($tokens[1])) {
                $defaultTtl = $this->ttl($tokens[1]) ?? $defaultTtl;

                continue;
            }
            if (str_starts_with($directive, '$')) {
                $skipped[] = "Line {$lineNumber}: {$tokens[0]} is not supported.";

                continue;
            }

            $owner = $startsBlank ? $lastOwner : $this->resolveName(array_shift($tokens), $origin);
            $lastOwner = $owner;
            $ttl = null;
            // TTL and class may come in either order before the type.
            while ($tokens !== []) {
                if ($ttl === null && ($parsed = $this->ttl($tokens[0])) !== null) {
                    $ttl = $parsed;
                    array_shift($tokens);
                } elseif (in_array(strtoupper($tokens[0]), ['IN', 'CH', 'HS'], true)) {
                    array_shift($tokens);
                } else {
                    break;
                }
            }
            $type = strtoupper((string) array_shift($tokens));
            if ($type === '' || $tokens === []) {
                $skipped[] = "Line {$lineNumber}: incomplete record.";

                continue;
            }
            if ($owner !== $zoneDomain && ! str_ends_with($owner, '.'.$zoneDomain)) {
                $skipped[] = "Line {$lineNumber}: {$owner} is outside {$zoneDomain}.";

                continue;
            }
            if ($type === 'SOA') {
                continue;
            }
            if ($type === 'NS' && $owner === $zoneDomain) {
                // The old provider's nameservers; this server publishes its own.
                $skipped[] = "Line {$lineNumber}: apex NS {$tokens[0]} belongs to the previous DNS host.";

                continue;
            }
            if (! in_array($type, DnsRecordContent::TYPES, true)) {
                $skipped[] = "Line {$lineNumber}: {$type} records are not supported.";

                continue;
            }

            $ttl ??= $defaultTtl;
            $records[] = [
                'name' => $owner,
                'type' => $type,
                'ttl' => $ttl < 60 ? self::AUTO_TTL : min($ttl, 86400),
            ] + $this->rdata($type, $tokens, $origin);
        }

        return ['records' => $records, 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $tokens
     * @return array{content:string, priority:int|null}
     */
    private function rdata(string $type, array $tokens, string $origin): array
    {
        $host = fn (string $value): string => $value === '.' ? '.' : $this->resolveName($value, $origin);

        return match ($type) {
            'CNAME', 'NS', 'PTR' => ['content' => $host($tokens[0]), 'priority' => null],
            'MX' => count($tokens) >= 2
                ? ['content' => $host($tokens[1]), 'priority' => (int) $tokens[0]]
                : ['content' => $host($tokens[0]), 'priority' => null],
            'SRV' => count($tokens) >= 4
                ? ['content' => "{$tokens[1]} {$tokens[2]} ".$host($tokens[3]), 'priority' => (int) $tokens[0]]
                : ['content' => implode(' ', $tokens), 'priority' => null],
            // Bare words become one quoted string each, as BIND reads them.
            'TXT' => ['content' => implode(' ', array_map(
                fn (string $token): string => str_starts_with($token, '"') ? $token : '"'.addcslashes($token, '\\"').'"',
                $tokens,
            )), 'priority' => null],
            default => ['content' => implode(' ', $tokens), 'priority' => null],
        };
    }

    private function exportContent(string $type, string $content, int $priority): string
    {
        return match ($type) {
            'CNAME', 'NS', 'PTR' => $this->absolute($content),
            'MX' => $priority.' '.$this->absolute($content),
            'SRV' => (function () use ($content, $priority): string {
                $parts = preg_split('/\s+/', trim($content)) ?: [];
                if (count($parts) === 3) {
                    $parts[2] = $parts[2] === '.' ? '.' : $this->absolute($parts[2]);
                }

                return $priority.' '.implode(' ', $parts);
            })(),
            'SOA' => (function () use ($content): string {
                $parts = preg_split('/\s+/', trim($content)) ?: [];
                foreach ([0, 1] as $index) {
                    if (isset($parts[$index])) {
                        $parts[$index] = $this->absolute($parts[$index]);
                    }
                }

                return implode(' ', $parts);
            })(),
            default => $content,
        };
    }

    private function absolute(string $name): string
    {
        return rtrim(strtolower(trim($name)), '.').'.';
    }

    private function resolveName(string $name, string $origin): string
    {
        $name = strtolower(trim($name));
        if ($name === '@') {
            return $origin;
        }
        if (str_ends_with($name, '.')) {
            return rtrim($name, '.');
        }

        return "{$name}.{$origin}";
    }

    /** Seconds from "3600" or BIND units such as "1h30m" or "2d". */
    private function ttl(string $value): ?int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }
        if (preg_match('/^(?:\d+[smhdw])+$/i', $value) !== 1) {
            return null;
        }
        preg_match_all('/(\d+)([smhdw])/i', $value, $parts, PREG_SET_ORDER);
        $units = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

        return array_sum(array_map(fn (array $part): int => (int) $part[1] * $units[strtolower($part[2])], $parts));
    }

    /**
     * Splits the file into records: comments removed, parenthesised records
     * joined onto one line, quoted strings kept as single tokens.
     *
     * @return list<array{0:int, 1:bool, 2:list<string>}> [line number, starts with blank owner, tokens]
     */
    private function logicalLines(string $text): array
    {
        $lines = [];
        $tokens = [];
        $depth = 0;
        $startLine = 1;
        $startsBlank = false;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $raw) {
            if ($depth === 0) {
                $tokens = [];
                $startLine = $index + 1;
                $startsBlank = $raw !== '' && ctype_space($raw[0]);
            }
            $length = strlen($raw);
            $token = null;
            for ($i = 0; $i < $length; $i++) {
                $char = $raw[$i];
                if ($char === '"') {
                    if ($token !== null) {
                        $tokens[] = $token;
                        $token = null;
                    }
                    $end = $i + 1;
                    while ($end < $length && $raw[$end] !== '"') {
                        $end += $raw[$end] === '\\' ? 2 : 1;
                    }
                    $tokens[] = substr($raw, $i, $end - $i + 1);
                    $i = $end;

                    continue;
                }
                if ($char === ';') {
                    break;
                }
                if ($char === '(' || $char === ')') {
                    $depth = max(0, $depth + ($char === '(' ? 1 : -1));
                    if ($token !== null) {
                        $tokens[] = $token;
                        $token = null;
                    }

                    continue;
                }
                if (ctype_space($char)) {
                    if ($token !== null) {
                        $tokens[] = $token;
                        $token = null;
                    }

                    continue;
                }
                $token .= $char;
            }
            if ($token !== null) {
                $tokens[] = $token;
            }
            if ($depth === 0) {
                $lines[] = [$startLine, $startsBlank, $tokens];
            }
        }

        return $lines;
    }
}
