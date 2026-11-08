<?php

namespace App\Services\Wordpress;

/**
 * The WordPress security check (backlog W4): wp-cli's answers turned into
 * pass / warn / fail lines, each with the fix the panel can apply, if any.
 */
class WordpressSecurityReport
{
    /** The fixes drust knows; the controller takes nothing else. */
    public const FIXES = ['disallow_file_edit', 'disable_debug', 'close_registration', 'reinstall_core'];

    /**
     * @param  array<string, array{ok: bool, output: string, error: string}>  $results
     * @return list<array{key: string, status: string, detail: string, fix: ?string}>
     */
    public static function fromResults(array $results): array
    {
        $checks = [];
        $add = function (string $key, string $status, string $detail = '', ?string $fix = null) use (&$checks): void {
            $checks[] = ['key' => $key, 'status' => $status, 'detail' => $detail, 'fix' => $status === 'pass' ? null : $fix];
        };

        $core = $results['core_checksums'] ?? null;
        if ($core !== null) {
            $changed = self::changedCoreFiles($core['output']."\n".$core['error']);
            $add('core_files', $core['ok'] ? 'pass' : 'fail', implode(', ', array_slice($changed, 0, 10)), 'reinstall_core');
        }

        $plugins = $results['plugin_checksums'] ?? null;
        if ($plugins !== null) {
            $bad = self::changedPlugins($plugins['output']);
            $add('plugin_files', $bad === [] ? 'pass' : 'warn', implode(', ', array_slice($bad, 0, 10)));
        }

        $admins = array_values(array_filter(array_map('trim', explode("\n", $results['admins']['output'] ?? ''))));
        if (isset($results['admins'])) {
            $weak = array_values(array_intersect(array_map('strtolower', $admins), ['admin', 'administrator', 'root', 'test']));
            $add('admin_names', $weak === [] ? 'pass' : 'warn', implode(', ', $weak));
        }

        if (isset($results['registration'])) {
            $open = trim($results['registration']['output']) === '1';
            $add('registration', $open ? 'warn' : 'pass', '', 'close_registration');
        }

        if (isset($results['debug'])) {
            $on = $results['debug']['ok'] && self::truthy($results['debug']['output']);
            $add('debug', $on ? 'warn' : 'pass', '', 'disable_debug');
        }

        if (isset($results['file_edit'])) {
            $blocked = $results['file_edit']['ok'] && self::truthy($results['file_edit']['output']);
            $add('file_edit', $blocked ? 'pass' : 'warn', '', 'disallow_file_edit');
        }

        if (isset($results['core_updates'])) {
            $info = WordpressInfo::fromResults($results);
            $add('core_update', $info['core_update'] === null ? 'pass' : 'warn', (string) $info['core_update']);
            $outdated = array_values(array_map(fn ($p) => $p['name'], array_filter($info['plugins'], fn ($p) => $p['update'] !== null)));
            $add('plugin_updates', $outdated === [] ? 'pass' : 'warn', implode(', ', array_slice($outdated, 0, 10)));
        }

        return $checks;
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true'], true);
    }

    /** @return list<string> */
    private static function changedCoreFiles(string $text): array
    {
        preg_match_all('/File (?:doesn\'t verify against checksum|should not exist): (\S+)/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    private static function changedPlugins(string $output): array
    {
        $start = strpos($output, '[');
        $rows = $start === false ? [] : (json_decode(substr($output, $start), true) ?: []);
        $plugins = [];
        foreach ((array) $rows as $row) {
            $message = strtolower((string) ($row['message'] ?? ''));
            // A plugin not on wordpress.org has no checksums: that is not a change.
            if (is_array($row) && isset($row['plugin_name']) && ! str_contains($message, 'could not retrieve the checksums')) {
                $plugins[] = (string) $row['plugin_name'];
            }
        }

        return array_values(array_unique($plugins));
    }
}
