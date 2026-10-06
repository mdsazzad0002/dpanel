<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\Mail\MailHostnameDetector;
use App\Services\Mail\MailTlsCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server-wide mail hostname (Postfix HELO name and every domain's MX).
 * Updates pick it automatically; this lets an admin re-run detection or pick
 * another candidate later.
 */
class MailHostnameController extends Controller
{
    public function __construct(
        private readonly MailHostnameDetector $detector,
        private readonly MailTlsCertificate $tls,
    ) {
    }

    public function page(): Response
    {
        return Inertia::render('Email/Manage/MailSsl');
    }

    public function show(): JsonResponse
    {
        try {
            return response()->json($this->state());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not check the mail hostname: '.$e->getMessage()], 502);
        }
    }

    public function update(Request $request, ActivityLogService $activity): JsonResponse
    {
        $validated = $request->validate(['host' => ['nullable', 'string', 'max:253']]);
        $host = trim((string) ($validated['host'] ?? ''));

        $result = $host === '' ? $this->detector->applyBest() : $this->detector->apply($host);
        if ($result['ok'] && $result['changed']) {
            try {
                $activity->log('mail.hostname.set', null, ['host' => $result['host']], $request);
            } catch (\Throwable $e) {
                report($e);
            }
            // A new hostname needs its own certificate, or STARTTLS fails on a name mismatch.
            $result['message'] .= ' '.$this->tls->ensure($result['host'])['message'];
        }

        return response()->json(['success' => $result['ok'], 'message' => $result['message'], ...$this->state()], $result['ok'] ? 200 : 422);
    }

    /** @return array{current: string, best: string, system: string, ip: string, ptr: string, candidates: array<int, array<string, mixed>>} */
    private function state(): array
    {
        $candidates = $this->detector->candidates();
        $best = collect($candidates)->firstWhere('usable', true)['host'] ?? '';

        return [
            'current' => $this->detector->current(),
            'best' => $best,
            'system' => $this->detector->systemHostname(),
            ...$this->detector->reverseDns(),
            'candidates' => $candidates,
        ];
    }

    public function tlsStatus(): JsonResponse
    {
        try {
            return response()->json($this->tls->status());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not read the mail certificate: '.$e->getMessage()], 502);
        }
    }

    public function issueTls(Request $request, ActivityLogService $activity): JsonResponse
    {
        $validated = $request->validate(['host' => ['nullable', 'string', 'max:253']]);
        $host = strtolower(trim((string) ($validated['host'] ?? '')));

        // The CA checks the name over HTTP on this server, so refuse names that point elsewhere.
        if ($host !== '' && $host !== $this->detector->current() && ! $this->detector->resolvesHere($host)) {
            return response()->json(['success' => false, 'message' => "{$host} does not resolve to this server. Add an A record (DNS only, not proxied) first.", ...$this->tls->status()], 422);
        }

        $result = $this->tls->ensure($host);
        if ($result['ok'] && $result['changed']) {
            try {
                $activity->log('mail.tls.issued', null, ['host' => $result['host']], $request);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['success' => $result['ok'], 'message' => $result['message'], ...$this->tls->status()], $result['ok'] ? 200 : 422);
    }
}
