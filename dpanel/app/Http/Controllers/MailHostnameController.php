<?php

namespace App\Http\Controllers;

use App\Models\MailDomain;
use App\Models\MailIp;
use App\Models\Mailbox;
use App\Services\ActivityLogService;
use App\Services\Dns\PublicDnsLookup;
use App\Services\Mail\MailHostnameDetector;
use App\Services\Mail\MailIps;
use App\Services\Mail\MailTlsCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mail IPs, their hostnames (HELO name and MX) and SSL. The default IP's
 * hostname is picked automatically on updates; extra IPs, their hostnames,
 * which domain sends from which IP, and certificates are managed here.
 */
class MailHostnameController extends Controller
{
    public function __construct(
        private readonly MailHostnameDetector $detector,
        private readonly MailTlsCertificate $tls,
        private readonly MailIps $ips,
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
            $result['message'] .= ' Generate its SSL on the Mail SSL page.';
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

    /** Mail IPs with hostname, PTR and certificate, plus every mail domain's IP. */
    public function ips(): JsonResponse
    {
        try {
            return response()->json($this->ipState());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not read the mail IPs: '.$e->getMessage()], 502);
        }
    }

    public function storeIp(Request $request, ActivityLogService $activity): JsonResponse
    {
        $validated = $request->validate(['ip' => ['required', 'ip'], 'hostname' => ['required', 'string', 'max:253']]);

        return $this->ipResult($this->ips->add($validated['ip'], $validated['hostname']), 'mail.ip.added', $validated, $request, $activity);
    }

    public function updateIp(Request $request, ActivityLogService $activity, string $token, string $id): JsonResponse
    {
        $validated = $request->validate(['hostname' => ['required', 'string', 'max:253']]);
        $mailIp = MailIp::query()->findOrFail($id);

        return $this->ipResult($this->ips->setHostname($mailIp, $validated['hostname']), 'mail.ip.hostname', ['ip' => $mailIp->ip, ...$validated], $request, $activity);
    }

    public function destroyIp(Request $request, ActivityLogService $activity, string $token, string $id): JsonResponse
    {
        $mailIp = MailIp::query()->findOrFail($id);

        return $this->ipResult($this->ips->remove($mailIp), 'mail.ip.removed', ['ip' => $mailIp->ip], $request, $activity);
    }

    public function assignDomain(Request $request, ActivityLogService $activity): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'mail_ip_id' => ['nullable', 'string', 'exists:mail_ips,id'],
        ]);
        $this->ips->assign($validated['domain'], $validated['mail_ip_id'] ?? null);
        $mailIp = MailDomain::query()->where('domain', strtolower(trim($validated['domain'])))->first()?->mailIp;
        $message = "{$validated['domain']} now sends from ".($mailIp ? "{$mailIp->ip} ({$mailIp->hostname})" : 'the default IP').'. Update its MX and SPF records in the Mail DNS Guide.';

        return $this->ipResult(['ok' => true, 'message' => $message], 'mail.domain.ip', $validated, $request, $activity);
    }

    /** Issues or renews the certificate of every mail IP hostname. */
    public function issueTls(Request $request, ActivityLogService $activity): JsonResponse
    {
        $result = $this->tls->ensure();

        return $this->ipResult($result, $result['changed'] ? 'mail.tls.issued' : '', [], $request, $activity);
    }

    /** @param array{ok: bool, message: string} $result */
    private function ipResult(array $result, string $event, array $properties, Request $request, ActivityLogService $activity): JsonResponse
    {
        if ($result['ok'] && $event !== '') {
            try {
                $activity->log($event, null, $properties, $request);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['success' => $result['ok'], 'message' => $result['message'], ...$this->ipState()], $result['ok'] ? 200 : 422);
    }

    private function ipState(): array
    {
        $ips = $this->ips->all();
        $certificates = collect($this->tls->status())->keyBy('host');
        $dns = app(PublicDnsLookup::class);
        $dns->prefetch($ips->map(fn (MailIp $mailIp) => [$mailIp->ip, 'PTR'])->all());
        $assigned = MailDomain::query()->whereNotNull('mail_ip_id')->pluck('mail_ip_id', 'domain');
        $domains = Mailbox::query()->distinct()->pluck('domain')->merge($assigned->keys())
            ->map(fn ($domain) => strtolower(trim((string) $domain)))->filter()->unique()->sort()->values();

        return [
            'ips' => $ips->map(fn (MailIp $mailIp) => [
                'id' => $mailIp->id,
                'ip' => $mailIp->ip,
                'hostname' => (string) $mailIp->hostname,
                'is_default' => $mailIp->is_default,
                'ptr' => strtolower(rtrim((string) ($dns->ptr($mailIp->ip)[0] ?? ''), '.')),
                'certificate' => $certificates->get((string) $mailIp->hostname),
                'domains' => $mailIp->is_default
                    ? $domains->reject(fn ($domain) => $assigned->has($domain))->values()->all()
                    : $assigned->filter(fn ($id) => $id === $mailIp->id)->keys()->values()->all(),
            ])->values()->all(),
            'domains' => $domains->map(fn ($domain) => ['domain' => $domain, 'mail_ip_id' => $assigned->get($domain)])->all(),
            'addable' => array_values(array_diff($this->ips->localAddresses(), $ips->pluck('ip')->all())),
        ];
    }
}
