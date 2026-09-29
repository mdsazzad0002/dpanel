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

        $measured = $this->measuredCategories($websiteIds, $includeServer);

        $findings = $this->openFindings($websiteIds, $includeServer)->get(['category', 'severity']);
        $penaltyByCategory = [];
        $counts = array_fill_keys(SecurityFinding::SEVERITIES, 0);
        foreach ($findings as $finding) {
            $category = $map[$finding->category] ?? $finding->category;
            $penaltyByCategory[$category] = ($penaltyByCategory[$category] ?? 0) + (int) ($penalties[$finding->severity] ?? 0);
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
