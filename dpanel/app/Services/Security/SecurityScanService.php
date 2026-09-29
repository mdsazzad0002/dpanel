<?php

namespace App\Services\Security;

use App\Jobs\RunSecurityScanJob;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class SecurityScanService
{
    public const SERVER_SCAN_TYPE = 'configuration';

    public function __construct(
        private readonly DrustSecurityClient $drust,
        private readonly ServerPostureCheck $posture,
        private readonly SecurityRuleCatalog $rules,
        private readonly SecurityScoreService $scores,
    ) {
    }

    public function queue(?Website $website, string $scanType, ?User $user): SecurityScan
    {
        $scan = SecurityScan::query()->create([
            'website_id' => $website?->id,
            'scan_type' => $website === null ? self::SERVER_SCAN_TYPE : $scanType,
            'status' => 'queued',
            'requested_by' => $user?->id,
        ]);

        RunSecurityScanJob::dispatch($scan->id);

        return $scan;
    }

    public function execute(SecurityScan $scan): void
    {
        $scan->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            if ($scan->website_id === null) {
                $result = $this->posture->run();
                $findings = $result['findings'];
                $summary = $result['summary'];
                $filesScanned = 0;
            } else {
                $website = Website::query()->find($scan->website_id);
                $root = trim((string) $website?->root_path);
                if ($website === null || $root === '') {
                    throw new \RuntimeException('Website not found or has no root path.');
                }
                $report = $this->drust->scanWebsite($root, (string) $website->id, $scan->scan_type);
                $findings = (array) ($report['findings'] ?? []);
                $summary = collect($report)->except('findings')->all();
                $filesScanned = (int) ($report['files_scanned'] ?? 0);
            }

            $stored = $this->persist($scan, $findings);

            $scan->update([
                'status' => 'completed',
                'completed_at' => now(),
                'files_scanned' => $filesScanned,
                'threats_found' => $stored['threats'],
                'risk_score' => $stored['risk'],
                'summary' => $summary,
            ]);

            $this->event($scan, $stored['threats'] > 0 ? $stored['worst'] : 'info', 'scan_completed',
                $this->scanLabel($scan).' finished: '.$stored['new'].' new, '.$stored['open'].' open, '.$stored['resolved'].' resolved.');
        } catch (\Throwable $e) {
            $scan->update(['status' => 'failed', 'completed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            $this->event($scan, 'medium', 'scan_failed', $this->scanLabel($scan).' failed: '.mb_substr($e->getMessage(), 0, 500));
        }

        $this->scores->record();
    }

    /**
     * Upsert findings by fingerprint so a recurring issue stays one row, keep
     * ignored ones ignored, and close open findings the scan checked for but
     * no longer sees.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{new: int, open: int, resolved: int, threats: int, risk: int, worst: string}
     */
    public function persist(SecurityScan $scan, array $findings): array
    {
        $disabled = $this->rules->disabledRuleIds();
        $penalties = (array) config('security_center.severity_penalties');
        $now = now();
        $seen = [];
        $new = 0;
        $open = 0;
        $risk = 0;
        $threats = 0;
        $worst = 'info';

        DB::transaction(function () use ($scan, $findings, $disabled, $penalties, $now, &$seen, &$new, &$open, &$risk, &$threats, &$worst) {
            foreach ($findings as $finding) {
                $ruleId = (string) ($finding['rule_id'] ?? '');
                if ($ruleId === '' || in_array($ruleId, $disabled, true)) {
                    continue;
                }
                $severity = in_array($finding['severity'] ?? null, SecurityFinding::SEVERITIES, true) ? $finding['severity'] : 'info';
                $websiteId = $scan->website_id ?? ($finding['website_id'] ?? null);
                $path = isset($finding['file_path']) ? mb_substr((string) $finding['file_path'], 0, 1024) : null;
                $fingerprint = SecurityFinding::fingerprintFor($websiteId, $ruleId, $path);
                $seen[] = $fingerprint;

                $attributes = [
                    'scan_id' => $scan->id,
                    'website_id' => $websiteId,
                    'rule_id' => $ruleId,
                    'severity' => $severity,
                    'category' => (string) ($finding['category'] ?? 'php'),
                    'title' => mb_substr((string) ($finding['title'] ?? $ruleId), 0, 255),
                    'description' => $finding['description'] ?? null,
                    'file_path' => $path,
                    'line_number' => $finding['line_number'] ?? null,
                    'evidence' => isset($finding['evidence']) ? mb_substr((string) $finding['evidence'], 0, 1000) : null,
                    'recommendation' => $finding['recommendation'] ?? null,
                    'auto_fix_available' => (bool) ($finding['auto_fix_available'] ?? false),
                    'last_seen_at' => $now,
                ];

                $existing = SecurityFinding::query()->where('fingerprint', $fingerprint)->latest('id')->first();
                if ($existing === null || $existing->status === 'resolved') {
                    SecurityFinding::query()->create($attributes + [
                        'fingerprint' => $fingerprint,
                        'status' => 'open',
                        'first_seen_at' => $now,
                    ]);
                    $new++;
                } else {
                    $existing->update($attributes);
                    if ($existing->status === 'ignored') {
                        continue;
                    }
                }

                $open++;
                $risk += (int) ($penalties[$severity] ?? 0);
                if (in_array($severity, ['critical', 'high', 'medium'], true)) {
                    $threats++;
                }
                if (array_search($severity, SecurityFinding::SEVERITIES, true) < array_search($worst, SecurityFinding::SEVERITIES, true)) {
                    $worst = $severity;
                }
            }
        });

        $covered = (array) config('security_center.scan_coverage.'.$scan->scan_type, []);
        $resolved = 0;
        if ($covered !== []) {
            $query = SecurityFinding::query()
                ->where('status', 'open')
                ->whereIn('rule_id', $covered)
                ->whereNotIn('fingerprint', $seen);
            // A website scan only speaks for that website; the server scan
            // covers its rules everywhere (e.g. per-site SSL findings).
            if ($scan->website_id !== null) {
                $query->where('website_id', $scan->website_id);
            }
            $resolved = $query->update(['status' => 'resolved', 'fixed_at' => $now, 'updated_at' => $now]);
        }

        return ['new' => $new, 'open' => $open, 'resolved' => $resolved, 'threats' => $threats, 'risk' => min(100, $risk), 'worst' => $worst];
    }

    private function scanLabel(SecurityScan $scan): string
    {
        if ($scan->website_id === null) {
            return 'Server configuration scan';
        }

        $domain = Website::query()->whereKey($scan->website_id)->value('domain') ?? 'website';

        return ucfirst($scan->scan_type).' scan of '.$domain;
    }

    private function event(SecurityScan $scan, string $severity, string $type, string $message): void
    {
        SecurityEvent::query()->create([
            'website_id' => $scan->website_id,
            'event_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'metadata' => ['scan_id' => $scan->id, 'scan_type' => $scan->scan_type],
        ]);
    }
}
