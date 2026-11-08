<?php

namespace App\Services\Wordpress;

/** Reads wp-cli's `info` answers into what the WordPress page shows (W1, W2). */
class WordpressInfo
{
    /**
     * @param  array<string, array{ok: bool, output: string, error: string}>  $results
     * @return array{version: string, core_update: ?string, plugins: list<array<string, mixed>>, themes: list<array<string, mixed>>}
     */
    public static function fromResults(array $results): array
    {
        $updates = self::json($results['core_updates']['output'] ?? '');
        $coreUpdate = null;
        foreach ($updates as $update) {
            $candidate = (string) ($update['version'] ?? '');
            if ($candidate !== '' && ($coreUpdate === null || version_compare($candidate, $coreUpdate, '>'))) {
                $coreUpdate = $candidate;
            }
        }

        return [
            'version' => trim($results['core_version']['output'] ?? ''),
            'core_update' => $coreUpdate,
            'plugins' => self::items($results['plugins']['output'] ?? ''),
            'themes' => self::items($results['themes']['output'] ?? ''),
        ];
    }

    /** @return list<array{name: string, title: string, status: string, version: string, update: ?string}> */
    private static function items(string $output): array
    {
        $items = [];
        foreach (self::json($output) as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $hasUpdate = ($item['update'] ?? 'none') === 'available';
            $items[] = [
                'name' => $name,
                'title' => html_entity_decode(strip_tags((string) ($item['title'] ?? $name))),
                'status' => (string) ($item['status'] ?? ''),
                'version' => (string) ($item['version'] ?? ''),
                'update' => $hasUpdate ? ((string) ($item['update_version'] ?? '') ?: 'available') : null,
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function json(string $output): array
    {
        // wp-cli can print a PHP notice before the JSON.
        $start = strpos($output, '[');
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
