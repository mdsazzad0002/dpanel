<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PanelUpdate;
use App\Services\PanelUpdate\PanelUpdateRefused;
use App\Services\PanelUpdate\PanelUpdateService;
use App\Services\PanelUpdate\ReleaseChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System → Updates (backlog U6): the running version, newer releases, and
 * updating or rolling back. Admins only. The work runs in drust's own unit,
 * so the page only starts it and polls `status`.
 */
class PanelUpdateController extends Controller
{
    public function index(PanelUpdateService $updates, ReleaseChecker $releases): Response
    {
        $error = null;
        try {
            $state = $updates->sync();
        } catch (PanelUpdateRefused $refused) {
            $state = ['active' => null, 'runs' => $updates->recent()];
            $error = $refused->getMessage();
        }

        return Inertia::render('System/Updates/Index', [
            'current' => $releases->current(),
            'repo' => (string) config('panel_update.repo'),
            'runs' => self::present($state['runs']),
            'active' => $state['active'],
            'drustError' => $error,
        ]);
    }

    public function check(Request $request, ReleaseChecker $releases): JsonResponse
    {
        try {
            return response()->json($releases->check($request->boolean('refresh')));
        } catch (PanelUpdateRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'update_available' => false], $refused->status);
        }
    }

    public function status(Request $request, PanelUpdateService $updates): JsonResponse
    {
        $validated = $request->validate([
            'run' => ['nullable', 'string', 'regex:'.PanelUpdateService::RUN_ID_PATTERN],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->attempt(function () use ($updates, $validated) {
            $log = isset($validated['run']) ? $updates->log($validated['run'], (int) ($validated['offset'] ?? 0)) : null;
            $state = $updates->sync();

            return ['active' => $state['active'], 'runs' => self::present($state['runs']), 'log' => $log];
        });
    }

    public function update(Request $request, PanelUpdateService $updates): JsonResponse
    {
        $validated = $request->validate([
            'target_ref' => ['required', 'string', 'max:100', 'regex:'.PanelUpdateService::REF_PATTERN],
        ]);

        return $this->attempt(fn () => [
            'message' => 'The update has started.',
            'run' => self::row($updates->update($request->user(), $validated['target_ref'])),
        ]);
    }

    public function rollback(Request $request, PanelUpdateService $updates): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['nullable', 'string', 'regex:'.PanelUpdateService::RUN_ID_PATTERN],
            'restore_database' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(fn () => [
            'message' => 'The rollback has started.',
            'run' => self::row($updates->rollback($request->user(), $validated['run_id'] ?? null, $request->boolean('restore_database', true))),
        ]);
    }

    /** @param  callable(): array<string, mixed>  $work */
    private function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (PanelUpdateRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }
    }

    /** @param  Collection<int, PanelUpdate>  $rows */
    private static function present(Collection $rows): array
    {
        return $rows->map(fn (PanelUpdate $row) => self::row($row))->values()->all();
    }

    private static function row(PanelUpdate $row): array
    {
        return [
            ...$row->only([
                'run_id', 'action', 'status', 'step', 'target_ref', 'from_version', 'from_ref', 'from_commit',
                'to_version', 'to_ref', 'to_commit', 'rollback_of', 'restore_database', 'has_backup', 'message',
            ]),
            'user' => $row->user?->name,
            'started_at' => $row->started_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
        ];
    }
}
