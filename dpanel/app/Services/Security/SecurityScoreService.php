<?php

namespace App\Services\Security;

use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\SecurityScore;

class SecurityScoreService
{
    /**
     * Weighted score over the categories that have actually been measured.
     *
     * @param  array<int, string>|null  $websiteIds  null = whole server
     * @return array{overall: int|null, grade: string, categories: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function calculate(?array $websiteIds = null, bool $includeServer = true): array
    {
        $weights = (array) config('security_center.weights');
        $labels = (array) config('security_center.category_labels');
        $penalties = (array) config('security_center.severity_penalties');
        $map = (array) config('security_center.score_category_map');
        $unmeasured = (array) config('security_center.unmeasured_categories');
        $guides = (array) config('security_center.category_guides');

        $measured = $this->measuredCategories($websiteIds, $includeServer);

        $findings = $this->openFindings($websiteIds, $includeServer)->get(['category', 'severity']);
        $penaltyByCategory = [];
        $findingsByCategory = [];
        $counts = array_fill_keys(SecurityFinding::SEVERITIES, 0);
        foreach ($findings as $finding) {
            $category = $map[$finding->category] ?? $finding->category;
            $penaltyByCategory[$category] = ($penaltyByCategory[$category] ?? 0) + (int) ($penalties[$finding->severity] ?? 0);
            $findingsByCategory[$category] = ($findingsByCategory[$category] ?? 0) + 1;
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }

        $categories = [];
        $weighted = 0.0;
        $totalWeight = 0;
        foreach ($weights as $category => $weight) {
            $isMeasured = in_array($category, $measured, true) && ! in_array($category, $unmeasured, true);
            $score = $isMeasured ? max(0, 100 - ($penaltyByCategory[$category] ?? 0)) : null;
            if ($score !== null) {
                $weighted += $score * $weight;
                $totalWeight += $weight;
            }
            $categories[] = [
                'key' => $category,
                'label' => $labels[$category] ?? ucfirst($category),
                'weight' => $weight,
                'score' => $score,
                'measured' => $isMeasured,
                'planned' => in_array($category, $unmeasured, true),
                'findings' => $findingsByCategory[$category] ?? 0,
                'server' => ($guides[$category]['scan'] ?? null) === SecurityScanService::SERVER_SCAN_TYPE,
                'scan_type' => $guides[$category]['scan'] ?? null,
                'checks' => str_replace(':days', (string) config('security_center.backup_max_age_days'), (string) ($guides[$category]['checks'] ?? '')),
                'fix_route' => $guides[$category]['fix_route'] ?? null,
                'fix_label' => $guides[$category]['fix_label'] ?? null,
            ];
        }

        $overall = $totalWeight > 0 ? (int) round($weighted / $totalWeight) : null;

        return [
            'overall' => $overall,
            'grade' => $this->grade($overall),
            'categories' => $categories,
            'counts' => $counts,
        ];
    }

    /**
     * Websites that no completed scan has measured yet, per website category.
     *
     * @param  array<int, string>  $websiteIds  the websites the user can scan
     * @return array<string, array<int, string>> category => website ids
     */
    public function unscannedWebsites(array $websiteIds): array
    {
        $measures = (array) config('security_center.scan_measures');
        $scanned = SecurityScan::query()
            ->where('status', 'completed')
            ->whereIn('website_id', $websiteIds)
            ->distinct()
            ->get(['website_id', 'scan_type']);

        $missing = [];
        foreach ((array) config('security_center.category_guides') as $category => $guide) {
            $scan = $guide['scan'] ?? null;
            if ($scan === null || $scan === SecurityScanService::SERVER_SCAN_TYPE) {
                continue;
            }
            $covered = $scanned
                ->filter(fn ($row) => in_array($category, (array) ($measures[$row->scan_type] ?? []), true))
                ->pluck('website_id')
                ->map(fn ($id) => (string) $id)
                ->all();
            $missing[$category] = array_values(array_diff($websiteIds, $covered));
        }

        return $missing;
    }

    public function record(): SecurityScore
    {
        $result = $this->calculate();
        $byKey = collect($result['categories'])->keyBy('key');

        return SecurityScore::query()->create([
            'overall_score' => $result['overall'] ?? 0,
            'firewall_score' => $byKey['firewall']['score'] ?? null,
            'waf_score' => $byKey['waf']['score'] ?? null,
            'malware_score' => $byKey['malware']['score'] ?? null,
            'php_score' => $byKey['php']['score'] ?? null,
            'ssh_score' => $byKey['ssh']['score'] ?? null,
            'ssl_score' => $byKey['ssl']['score'] ?? null,
            'update_score' => $byKey['updates']['score'] ?? null,
            'backup_score' => $byKey['backup']['score'] ?? null,
            'integrity_score' => $byKey['integrity']['score'] ?? null,
            'calculated_at' => now(),
        ]);
    }

    public function grade(?int $score): string
    {
        return match (true) {
            $score === null => 'Not scanned',
            $score >= 90 => 'Excellent',
            $score >= 75 => 'Good',
            $score >= 50 => 'Fair',
            default => 'Poor',
        };
    }

    /**
     * @param  array<int, string>|null  $websiteIds
     */
    public function openFindings(?array $websiteIds, bool $includeServer)
    {
        return SecurityFinding::query()
            ->where('status', 'open')
            ->when($websiteIds !== null, function ($query) use ($websiteIds, $includeServer) {
                $query->where(function ($inner) use ($websiteIds, $includeServer) {
                    $inner->whereIn('website_id', $websiteIds);
                    if ($includeServer) {
                        $inner->orWhereNull('website_id');
                    }
                });
            });
    }

    /**
     * @param  array<int, string>|null  $websiteIds
     * @return array<int, string>
     */
    private function measuredCategories(?array $websiteIds, bool $includeServer): array
    {
        $measures = (array) config('security_center.scan_measures');
        $types = SecurityScan::query()
            ->where('status', 'completed')
            ->when($websiteIds !== null, function ($query) use ($websiteIds, $includeServer) {
                $query->where(function ($inner) use ($websiteIds, $includeServer) {
                    $inner->whereIn('website_id', $websiteIds);
                    if ($includeServer) {
                        $inner->orWhereNull('website_id');
                    }
                });
            })
            ->distinct()
            ->pluck('scan_type');

        return $types->flatMap(fn ($type) => (array) ($measures[$type] ?? []))->unique()->values()->all();
    }
}
