<?php

namespace App\Http\Controllers;

use App\Models\DnsZone;
use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsRecordReplacer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Finds text in record content across the user's zones and replaces it, after a preview. */
class DnsFindReplaceController extends Controller
{
    public function preview(Request $request, DnsRecordReplacer $replacer): JsonResponse
    {
        [$profiles, $options] = $this->input($request);
        $changes = $replacer->plan($profiles, $options);

        return response()->json([
            'changes' => $changes,
            'limited' => count($changes) >= DnsRecordReplacer::LIMIT,
        ]);
    }

    public function apply(Request $request, DnsRecordReplacer $replacer): RedirectResponse
    {
        $request->validate([
            'record_ids' => ['required', 'array', 'min:1', 'max:'.DnsRecordReplacer::LIMIT],
            'record_ids.*' => ['integer', 'min:1'],
        ]);
        [$profiles, $options] = $this->input($request);
        $result = $replacer->apply($profiles, $options, array_map('intval', $request->input('record_ids')));

        $updated = $result['updated'];
        $message = "Updated {$updated} record".($updated === 1 ? '' : 's').' in '.$result['zones'].' zone'.($result['zones'] === 1 ? '' : 's').'.';
        if ($result['skipped'] !== []) {
            $message .= ' Skipped '.count($result['skipped']).': '.implode(' | ', array_slice($result['skipped'], 0, 3)).(count($result['skipped']) > 3 ? ' …' : '');
        }

        return redirect()->route('dns.zones')->with($updated > 0 ? 'success' : 'error', $message);
    }

    /** @return array{0: Collection<int, DnsZone>, 1: array{find:string, replace:?string, mode:string, match_case:bool, types:list<string>}} */
    private function input(Request $request): array
    {
        $validated = $request->validate([
            'find' => ['required', 'string', 'max:2048'],
            'replace' => ['nullable', 'string', 'max:2048'],
            'mode' => ['required', 'in:'.implode(',', DnsRecordReplacer::MODES)],
            'match_case' => ['boolean'],
            'types' => ['array'],
            'types.*' => ['in:'.implode(',', DnsRecordContent::TYPES)],
            'zones' => ['array'],
            'zones.*' => ['string', 'max:64'],
        ]);

        // No zones picked means every zone the user can see.
        $profiles = DnsZone::query()
            ->visibleTo($request->user())
            ->whereNotNull('powerdns_domain_id')
            ->when(! empty($validated['zones']), fn ($query) => $query->whereIn('id', $validated['zones']))
            ->get();

        return [$profiles, [
            'find' => $validated['find'],
            'replace' => $validated['replace'] ?? '',
            'mode' => $validated['mode'],
            'match_case' => (bool) ($validated['match_case'] ?? false),
            'types' => array_values($validated['types'] ?? []),
        ]];
    }
}
