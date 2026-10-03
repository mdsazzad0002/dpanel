<?php

namespace App\Services\Security;

use App\Models\SecurityScan;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a running scan into steps and a percentage the panel can draw.
 *
 * drust counts the website's files before it checks them and reports how many
 * it has done, for its own checks and for ClamAV, so the bar follows real work
 * instead of a timer. The weights below only say how the phases share the bar.
 */
class ScanProgress
{
    private const WEIGHTS = [
        'counting' => 3,
        'files' => 40,
        'integrity' => 4,
        'clamav' => 48,
        'saving' => 5,
    ];

    private const LABELS = [
        'queued' => 'Waiting for a worker',
        'counting' => 'Counting files',
        'files' => 'Checking files',
        'integrity' => 'Comparing file hashes',
        'signatures' => 'Updating virus signatures',
        'clamav' => 'ClamAV malware scan',
        'saving' => 'Saving results',
        'checking' => 'Checking server configuration',
    ];

    public function __construct(private readonly DrustSecurityClient $drust)
    {
    }

    public static function key(SecurityScan $scan): string
    {
        return 'scan-'.$scan->id;
    }

    /** drust has returned; the panel is storing the findings. */
    public static function markSaving(SecurityScan $scan): void
    {
        Cache::put(self::key($scan).'-saving', true, now()->addHour());
    }

    public static function forget(SecurityScan $scan): void
    {
        Cache::forget(self::key($scan).'-saving');
    }

    /**
     * @return array<string, mixed>|null null when the scan is not running
     */
    public function for(SecurityScan $scan): ?array
    {
        if (! in_array($scan->status, ['queued', 'running'], true)) {
            return null;
        }
        $elapsed = $scan->started_at ? (int) $scan->started_at->diffInSeconds(now(), true) : 0;

        if ($scan->status === 'queued') {
            return $this->present([['key' => 'queued', 'label' => self::LABELS['queued']]], 'queued', 0, 0, null, 0);
        }

        // Server configuration checks take seconds and have no countable parts.
        if ($scan->website_id === null) {
            return $this->present([['key' => 'checking', 'label' => self::LABELS['checking']]], 'checking', 0, 0, null, $elapsed);
        }

        $phases = $this->phases($scan->scan_type);
        $phase = 'counting';
        $done = 0;
        $total = 0;
        $found = [];
        $recent = [];
        if (Cache::get(self::key($scan).'-saving')) {
            $phase = 'saving';
        } else {
            try {
                $live = $this->drust->scanProgress(self::key($scan));
            } catch (\Throwable) {
                $live = null;
            }
            if ($live !== null) {
                $phase = (string) ($live['phase'] ?? 'counting');
                $done = (int) ($live['done'] ?? 0);
                $total = (int) ($live['total'] ?? 0);
                $found = array_map('intval', (array) ($live['found'] ?? []));
                $recent = array_map(fn ($item) => $this->presentFound((array) $item), (array) ($live['recent'] ?? []));
            }
        }

        // Signature refresh happens inside the ClamAV step.
        $step = $phase === 'signatures' ? 'clamav' : $phase;
        $fraction = $total > 0 ? min(1, $done / $total) : 0;
        $weights = array_intersect_key(self::WEIGHTS, array_flip(array_column($phases, 'key')));
        $sum = array_sum($weights);
        $before = 0;
        foreach ($phases as $item) {
            if ($item['key'] === $step) {
                break;
            }
            $before += $weights[$item['key']] ?? 0;
        }
        $countable = in_array($phase, ['files', 'clamav'], true);
        $percent = $sum > 0 ? (int) floor(($before + ($weights[$step] ?? 0) * ($countable ? $fraction : 0)) / $sum * 100) : 0;

        return $this->present($phases, $step, $countable ? $done : 0, $countable ? $total : 0, min(99, $percent), $elapsed, self::LABELS[$phase] ?? null)
            + ['found' => $found, 'recent' => $recent];
    }

    /**
     * A finding drust just reported, with the score category it counts
     * against and the points it costs there.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function presentFound(array $item): array
    {
        $category = (string) ($item['category'] ?? '');
        $scoreCategory = config('security_center.score_category_map')[$category] ?? $category;
        $severity = (string) ($item['severity'] ?? 'info');

        return [
            'rule_id' => (string) ($item['rule_id'] ?? ''),
            'severity' => $severity,
            'title' => (string) ($item['title'] ?? ''),
            'file_path' => $item['file_path'] ?? null,
            'category_label' => config('security_center.category_labels')[$scoreCategory] ?? ucfirst($scoreCategory),
            'penalty' => (int) (config('security_center.severity_penalties')[$severity] ?? 0),
        ];
    }

    /**
     * The steps a website scan of this type goes through, in order.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function phases(string $scanType): array
    {
        $keys = ['counting', 'files'];
        if (in_array($scanType, ['quick', 'full', 'integrity'], true)) {
            $keys[] = 'integrity';
        }
        if (in_array($scanType, ['full', 'malware'], true)) {
            $keys[] = 'clamav';
        }
        $keys[] = 'saving';

        return array_map(fn (string $key) => ['key' => $key, 'label' => self::LABELS[$key]], $keys);
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $phases
     * @return array<string, mixed>
     */
    private function present(array $phases, string $current, int $done, int $total, ?int $percent, int $elapsed, ?string $label = null): array
    {
        $reached = false;
        $steps = [];
        foreach (array_reverse($phases) as $item) {
            $reached = $reached || $item['key'] === $current;
            $state = $item['key'] === $current ? 'active' : ($reached ? 'done' : 'pending');
            $steps[] = $item + ['state' => $state];
        }

        return [
            'phase' => $current,
            'label' => $label ?? self::LABELS[$current] ?? $current,
            'done' => $done,
            'total' => $total,
            // null: the step has no countable parts, so the bar is indeterminate.
            'percent' => $percent,
            'elapsed' => $elapsed,
            'steps' => array_reverse($steps),
        ];
    }
}
