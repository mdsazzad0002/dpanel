<?php

namespace App\Http\Controllers;

use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\Models\SecurityScan;
use App\Models\SecurityScore;
use App\Models\User;
use App\Models\Website;
use App\Services\Security\ScanProgress;
use App\Services\Security\SecurityRuleCatalog;
use App\Services\Security\SecurityScanService;
use App\Services\Security\SecurityScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SecurityCenterController extends Controller
{
    public function __construct(
        private readonly SecurityScanService $scans,
        private readonly SecurityScoreService $scores,
        private readonly SecurityRuleCatalog $rules,
        private readonly ScanProgress $progress,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $this->isAdmin($user);
        $websiteIds = $this->websiteIds($user);

        if ($isAdmin) {
            $this->rules->sync();
        }

        $websites = $this->websites($user);

        return Inertia::render('Security/SecurityCenter', [
            'score' => $this->scores->calculate($websiteIds, $isAdmin),
            'unscanned' => $this->scores->unscannedWebsites(array_column($websites, 'id')),
            'scans' => $this->recentScans($websiteIds),
            'trend' => $this->trend($websiteIds, $isAdmin),
            'websites' => $websites,
            'scanTypes' => config('security_center.scan_types'),
            'isAdmin' => $isAdmin,
        ]);
    }

    public function findings(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $this->isAdmin($user);
        $websiteIds = $this->websiteIds($user);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'ignored', 'resolved', 'all'])],
            'severity' => ['nullable', Rule::in(SecurityFinding::SEVERITIES)],
            'category' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
        $status = $filters['status'] ?? 'open';

        $findings = SecurityFinding::query()
            ->with('website:id,domain')
            ->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->whereIn('category', $this->findingCategories($v)))
            ->when($filters['website'] ?? null, fn ($q, $v) => $v === 'server' ? $q->whereNull('website_id') : $q->where('website_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$v}%")
                ->orWhere('file_path', 'like', "%{$v}%")
                ->orWhere('rule_id', 'like', "%{$v}%")))
            ->orderByRaw($this->severityOrder())
            ->latest('last_seen_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (SecurityFinding $f) => $this->presentFinding($f));

        return Inertia::render('Security/SecurityFindings', [
            'findings' => $findings,
            'filters' => array_merge($filters, ['status' => $status]),
            'websites' => $this->websites($user),
            'categories' => array_values(array_unique(array_column((array) config('security_center.rules'), 'category'))),
            'isAdmin' => $isAdmin,
        ]);
    }

    public function startScan(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'website_id' => ['nullable', 'string', 'max:64'],
            'scan_type' => ['required', Rule::in(array_merge(array_keys((array) config('security_center.scan_types')), [SecurityScanService::SERVER_SCAN_TYPE]))],
        ]);

        $website = null;
        if (! empty($data['website_id'])) {
            $website = Website::query()->visibleTo($user)->whereKey($data['website_id'])->first();
            if ($website === null) {
                abort(404);
            }
            if ($data['scan_type'] === SecurityScanService::SERVER_SCAN_TYPE) {
                abort(422, 'Choose a website scan type.');
            }
        } elseif (! $this->isAdmin($user)) {
            abort(403, 'Only admins can scan the server configuration.');
        }

        $running = SecurityScan::query()
            ->where('website_id', $website?->id)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($running) {
            return response()->json(['success' => false, 'message' => 'A scan for this target is already running.'], 409);
        }

        $scan = $this->scans->queue($website, $data['scan_type'], $user);

        return response()->json(['success' => true, 'scan' => $this->presentScan($scan)]);
    }

    /**
     * Queue the scan that measures a website category on every visible
     * website that has not been measured for it yet (all of them when
     * every website already has a result).
     */
    public function scanCategory(Request $request): JsonResponse
    {
        $guides = (array) config('security_center.category_guides');
        $data = $request->validate(['category' => ['required', Rule::in(array_keys($guides))]]);
        $scanType = $guides[$data['category']]['scan'] ?? null;
        if ($scanType === null || $scanType === SecurityScanService::SERVER_SCAN_TYPE) {
            abort(422, 'This category is not measured by a website scan.');
        }

        $user = $request->user();
        $visible = array_column($this->websites($user), 'id');
        $targets = $this->scores->unscannedWebsites($visible)[$data['category']] ?? [];
        if ($targets === []) {
            $targets = $visible;
        }

        $busy = SecurityScan::query()
            ->whereIn('website_id', $targets)
            ->whereIn('status', ['queued', 'running'])
            ->pluck('website_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $queued = 0;
        foreach (Website::query()->whereKey(array_diff($targets, $busy))->get() as $website) {
            $this->scans->queue($website, $scanType, $user);
            $queued++;
        }

        return response()->json([
            'success' => true,
            'queued' => $queued,
            'skipped' => count($busy),
            'message' => $queued === 0 ? 'Every website already has a scan running.' : "Queued {$queued} ".($queued === 1 ? 'scan' : 'scans').'.',
        ]);
    }

    public function scanStatus(Request $request, string $token, int $scan): JsonResponse
    {
        $model = SecurityScan::query()->findOrFail($scan);
        $this->authorizeWebsite($request->user(), $model->website_id);

        return response()->json([
            'success' => true,
            'scan' => $this->presentScan($model->load('website:id,domain')),
            'progress' => $this->progress->for($model),
        ]);
    }

    public function updateFinding(Request $request, string $token, int $finding): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'ignored', 'resolved'])]]);
        $model = SecurityFinding::query()->findOrFail($finding);
        $this->authorizeWebsite($request->user(), $model->website_id);

        $model->update([
            'status' => $data['status'],
            'fixed_at' => $data['status'] === 'resolved' ? now() : null,
            'status_changed_by' => $request->user()?->id,
        ]);
        $this->scores->record();

        return response()->json(['success' => true, 'finding' => $this->presentFinding($model->load('website:id,domain'))]);
    }

    /**
     * Deletes the chosen findings. A finding whose problem is still there comes
     * back on the next scan, so this clears noise, it does not fix anything.
     */
    public function destroyFindings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);
        $websiteIds = $this->websiteIds($request->user());

        $deleted = SecurityFinding::query()
            ->whereIn('id', $data['ids'])
            // Non-admins only reach their own websites' findings, never server ones.
            ->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds))
            ->delete();
        $this->scores->record();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => $deleted.' finding'.($deleted === 1 ? '' : 's').' deleted.',
        ]);
    }

    /**
     * Deletes scan history rows. The newest completed scan of each target and
     * type stays, because the score counts a category as measured only while
     * one exists. Findings point at the last scan that saw them, so they move
     * to the newest scan left for their target instead of cascading away.
     */
    public function destroyScans(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);
        $websiteIds = $this->websiteIds($request->user());

        $scans = SecurityScan::query()
            ->whereIn('id', $data['ids'])
            ->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds))
            ->get(['id', 'website_id', 'scan_type', 'status']);
        $running = $scans->whereIn('status', ['queued', 'running']);
        $kept = $scans->whereIn('id', $this->latestCompletedScanIds());
        $doomed = $scans->diff($running)->diff($kept);

        $deleted = DB::transaction(function () use ($doomed, &$kept) {
            $ids = $doomed->pluck('id')->all();
            foreach ($doomed->groupBy(fn ($scan) => (string) $scan->website_id) as $scansOfTarget) {
                $websiteId = $scansOfTarget->first()->website_id;
                $heir = SecurityScan::query()
                    ->where('website_id', $websiteId)
                    ->whereNotIn('id', $ids)
                    ->latest('id')
                    ->value('id');
                $mine = $scansOfTarget->pluck('id')->all();
                if ($heir === null) {
                    // Nothing left to hold this target's findings: keep these scans.
                    if (SecurityFinding::query()->whereIn('scan_id', $mine)->exists()) {
                        $kept = $kept->merge($scansOfTarget);
                        $ids = array_values(array_diff($ids, $mine));
                    }

                    continue;
                }
                SecurityFinding::query()->whereIn('scan_id', $mine)->update(['scan_id' => $heir]);
            }

            return SecurityScan::query()->whereIn('id', $ids)->delete();
        });

        $message = $deleted.' scan'.($deleted === 1 ? '' : 's').' deleted.';
        if ($kept->isNotEmpty()) {
            $message .= ' Kept '.$kept->count().': the latest scan of each type is needed for the security score and findings.';
        }
        if ($running->isNotEmpty()) {
            $message .= ' Skipped '.$running->count().' still running.';
        }

        return response()->json(['success' => true, 'deleted' => $deleted, 'message' => $message]);
    }

    public function scans(Request $request): Response
    {
        $websiteIds = $this->websiteIds($request->user());
        $protected = $this->latestCompletedScanIds();

        return Inertia::render('Security/SecurityScans', [
            'scans' => SecurityScan::query()
                ->with('website:id,domain')
                ->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds))
                ->latest('id')
                ->paginate(50)
                ->through(fn (SecurityScan $scan) => $this->presentScan($scan) + [
                    'protected' => in_array($scan->id, $protected, true),
                ]),
        ]);
    }

    public function rules(): Response
    {
        $this->rules->sync();

        return Inertia::render('Security/SecurityRules', [
            'rules' => SecurityRule::query()
                ->orderBy('rule_id')
                ->get(['id', 'rule_id', 'name', 'category', 'severity', 'detection_type', 'description', 'remediation', 'enabled']),
        ]);
    }

    public function toggleRule(Request $request, string $token, string $rule): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $model = SecurityRule::query()->where('rule_id', $rule)->firstOrFail();
        $model->update(['enabled' => (bool) $data['enabled']]);

        return response()->json(['success' => true, 'rule' => $model->only(['id', 'rule_id', 'name', 'category', 'severity', 'enabled'])]);
    }

    private function authorizeWebsite(?User $user, ?string $websiteId): void
    {
        if ($this->isAdmin($user)) {
            return;
        }
        if ($websiteId === null || ! Website::query()->visibleTo($user)->whereKey($websiteId)->exists()) {
            abort(404);
        }
    }

    private function isAdmin(?User $user): bool
    {
        return $user !== null && $user->hasRole('admin');
    }

    /**
     * @return array<int, string>|null null means every website
     */
    private function websiteIds(?User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        return Website::query()->visibleTo($user)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @return array<int, array{id: string, domain: string}>
     */
    private function websites(?User $user): array
    {
        return Website::query()
            ->visibleTo($user)
            ->whereNotNull('root_path')
            ->where(fn ($q) => $q->whereNull('scope')->orWhere('scope', '!=', 'system'))
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->map(fn (Website $w) => ['id' => (string) $w->id, 'domain' => (string) $w->domain])
            ->all();
    }

    /**
     * @param  array<int, string>|null  $websiteIds
     */
    private function recentScans(?array $websiteIds)
    {
        return SecurityScan::query()
            ->with('website:id,domain')
            ->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds))
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (SecurityScan $scan) => $this->presentScan($scan));
    }

    /**
     * One point per day for the last 30 days. Admins get the stored overall
     * score; the score history is server-wide, so everyone else gets the
     * threats their own websites' scans found.
     *
     * @param  array<int, string>|null  $websiteIds
     * @return array{metric: string, label: string, max: int|null, points: array<int, array{date: string, value: int|null}>}
     */
    private function trend(?array $websiteIds, bool $isAdmin): array
    {
        $since = now()->subDays(29)->startOfDay();
        $days = collect(range(0, 29))->map(fn (int $offset) => $since->copy()->addDays($offset)->toDateString());

        if ($isAdmin) {
            // The last score of each day. A score holds until the next one, so a
            // quiet day repeats the day before; days before the first score stay empty.
            $current = SecurityScore::query()
                ->whereNull('website_id')
                ->where('calculated_at', '<', $since)
                ->latest('calculated_at')
                ->value('overall_score');
            $byDay = SecurityScore::query()
                ->whereNull('website_id')
                ->where('calculated_at', '>=', $since)
                ->orderBy('calculated_at')
                ->get(['overall_score', 'calculated_at'])
                ->mapWithKeys(fn (SecurityScore $row) => [$row->calculated_at->toDateString() => (int) $row->overall_score]);

            return [
                'metric' => 'score',
                'label' => 'Security score',
                'max' => 100,
                'points' => $days->map(function (string $day) use ($byDay, &$current) {
                    $current = $byDay[$day] ?? $current;

                    return ['date' => $day, 'value' => $current === null ? null : (int) $current];
                })->all(),
            ];
        }

        $byDay = SecurityScan::query()
            ->where('status', 'completed')
            ->whereIn('website_id', $websiteIds ?? [])
            ->where('completed_at', '>=', $since)
            ->get(['threats_found', 'completed_at'])
            ->groupBy(fn (SecurityScan $scan) => $scan->completed_at->toDateString())
            ->map(fn ($scans) => (int) $scans->sum('threats_found'));

        return [
            'metric' => 'threats',
            'label' => 'Threats found',
            'max' => null,
            'points' => $days->map(fn (string $day) => ['date' => $day, 'value' => $byDay[$day] ?? 0])->all(),
        ];
    }

    /**
     * The newest completed scan of each target and scan type.
     *
     * @return array<int, int>
     */
    private function latestCompletedScanIds(): array
    {
        return SecurityScan::query()
            ->where('status', 'completed')
            ->groupBy('website_id', 'scan_type')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentScan(SecurityScan $scan): array
    {
        return [
            'id' => $scan->id,
            'website_id' => $scan->website_id,
            'target' => $scan->website_id === null ? 'Server' : ($scan->website?->domain ?? 'Website'),
            'scan_type' => $scan->scan_type,
            'status' => $scan->status,
            'files_scanned' => $scan->files_scanned,
            'threats_found' => $scan->threats_found,
            'risk_score' => $scan->risk_score,
            'error' => $scan->error,
            'clamav' => $scan->summary['clamav'] ?? null,
            'truncated' => (bool) ($scan->summary['truncated'] ?? false),
            'started_at' => $scan->started_at?->toIso8601String(),
            'completed_at' => $scan->completed_at?->toIso8601String(),
            'created_at' => $scan->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFinding(SecurityFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'rule_id' => $finding->rule_id,
            'severity' => $finding->severity,
            'category' => $finding->category,
            'title' => $finding->title,
            'description' => $finding->description,
            'file_path' => $finding->file_path,
            'line_number' => $finding->line_number,
            'evidence' => $finding->evidence,
            'recommendation' => $finding->recommendation,
            'status' => $finding->status,
            'auto_fix_available' => $finding->auto_fix_available,
            'website' => $finding->website_id === null ? null : ['id' => $finding->website_id, 'domain' => $finding->website?->domain],
            'first_seen_at' => $finding->first_seen_at?->toIso8601String(),
            'last_seen_at' => $finding->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Finding categories behind a filter value: a score category also
     * matches the finding categories that roll up into it.
     *
     * @return array<int, string>
     */
    private function findingCategories(string $category): array
    {
        $rolled = array_keys((array) config('security_center.score_category_map'), $category, true);

        return array_merge([$category], $rolled);
    }

    private function severityOrder(): string
    {
        return "CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";
    }
}
