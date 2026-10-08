<?php

namespace App\Services\Docker;

/**
 * Validation rules for a `docker run` spec and the shape drust expects, shared
 * by "Run a container" and "Edit and recreate". drust checks every value again.
 */
class RunSpecInput
{
    public const NAME = 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

    public const IMAGE = 'regex:/^[A-Za-z0-9][A-Za-z0-9_.\/:@-]{0,254}$/';

    /** @return array<string, array<int, string>> */
    public static function rules(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : $prefix.'.';

        return [
            $p.'image' => ['required', 'string', self::IMAGE],
            $p.'name' => ['nullable', 'string', self::NAME],
            $p.'restart' => ['nullable', 'in:no,always,unless-stopped,on-failure'],
            $p.'ports' => ['array', 'max:20'],
            $p.'ports.*.host' => ['required', 'integer', 'between:1,65535'],
            $p.'ports.*.container' => ['required', 'integer', 'between:1,65535'],
            $p.'ports.*.protocol' => ['nullable', 'in:tcp,udp'],
            $p.'ports.*.public' => ['boolean'],
            $p.'env' => ['array', 'max:100'],
            $p.'env.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/'],
            $p.'env.*.value' => ['nullable', 'string', 'max:4096'],
            $p.'volumes' => ['array', 'max:20'],
            $p.'volumes.*.source' => ['required', 'string', 'max:512'],
            $p.'volumes.*.target' => ['required', 'string', 'max:512', 'starts_with:/'],
            $p.'volumes.*.read_only' => ['boolean'],
            $p.'network' => ['nullable', 'string', self::NAME],
            $p.'aliases' => ['array', 'max:10'],
            $p.'aliases.*' => ['nullable', 'string', self::NAME],
            $p.'hostname' => ['nullable', 'string', self::NAME],
            $p.'memory' => ['nullable', 'string', 'regex:/^\d+(\.\d+)?[bkmgBKMG]?$/'],
            $p.'cpus' => ['nullable', 'string', 'regex:/^\d+(\.\d+)?$/'],
            $p.'entrypoint' => ['nullable', 'string', 'max:1024'],
            $p.'command' => ['array', 'max:64'],
            $p.'command.*' => ['nullable', 'string', 'max:4096'],
            $p.'pull' => ['boolean'],
        ];
    }

    /**
     * drust reads missing fields as defaults but rejects nulls, so every field is filled in.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public static function normalize(array $spec): array
    {
        return [
            'image' => (string) $spec['image'],
            'name' => (string) ($spec['name'] ?? ''),
            'restart' => (string) ($spec['restart'] ?? ''),
            'ports' => array_values(array_map(fn ($port) => [
                'host' => (int) $port['host'],
                'container' => (int) $port['container'],
                'protocol' => (string) ($port['protocol'] ?? 'tcp'),
                'public' => (bool) ($port['public'] ?? false),
            ], $spec['ports'] ?? [])),
            'env' => array_values(array_map(fn ($env) => ['key' => (string) $env['key'], 'value' => (string) ($env['value'] ?? '')], $spec['env'] ?? [])),
            'volumes' => array_values(array_map(fn ($volume) => [
                'source' => (string) $volume['source'],
                'target' => (string) $volume['target'],
                'read_only' => (bool) ($volume['read_only'] ?? false),
            ], $spec['volumes'] ?? [])),
            'network' => (string) ($spec['network'] ?? ''),
            'aliases' => array_values(array_filter(array_map('strval', $spec['aliases'] ?? []), fn ($a) => trim($a) !== '')),
            'hostname' => (string) ($spec['hostname'] ?? ''),
            'memory' => (string) ($spec['memory'] ?? ''),
            'cpus' => (string) ($spec['cpus'] ?? ''),
            'entrypoint' => (string) ($spec['entrypoint'] ?? ''),
            'command' => array_values(array_map(fn ($arg) => (string) $arg, $spec['command'] ?? [])),
            'pull' => (bool) ($spec['pull'] ?? false),
        ];
    }

    /**
     * Variable values can hold secrets, so only their names are logged.
     *
     * @param  array<string, mixed>  $spec  a normalized spec
     * @return array<string, mixed>
     */
    public static function forLog(array $spec): array
    {
        return [
            'image' => $spec['image'],
            'name' => $spec['name'],
            'ports' => $spec['ports'],
            'env' => array_column($spec['env'], 'key'),
            'volumes' => $spec['volumes'],
            'network' => $spec['network'],
        ];
    }
}
