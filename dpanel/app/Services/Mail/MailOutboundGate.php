<?php

namespace App\Services\Mail;

use App\Services\Dns\PublicDnsLookup;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps outbound mail from leaving on an address Gmail will refuse.
 *
 * Gmail (550-5.7.25) needs the sending IP's PTR name to resolve back to that
 * IP. Postfix sends over IPv6 whenever the destination has an AAAA record, so
 * a server whose IPv4 PTR is fine but whose IPv6 has none bounced every
 * other message. The panel can fix the IPv6 side itself (send over IPv4
 * only); the IPv4 PTR is set at the IP provider, so while it is wrong
 * outbound mail is held in the queue — nothing bounces or is lost — and it
 * leaves on its own once the PTR is corrected. Holding is the admin's
 * choice (Mail Health > Outbound), off by default; the IPv6 fix always applies.
 */
class MailOutboundGate
{
    private const STATE_KEY = 'mail.outbound_gate';

    // DNS can answer wrongly for a moment; pause only after this many failed checks in a row.
    private const FAILURES_BEFORE_PAUSE = 2;

    public function __construct(
        private readonly MailDnsRecords $records,
        private readonly PublicDnsLookup $dns,
        private readonly ScriptExecutionGateway $gateway,
        private readonly MailSettings $settings,
    ) {
    }

    /**
     * The DNS facts the decision rests on; changes nothing.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $ipv4 = $this->records->serverIp();
        $ipv6 = $this->publicIpv6();

        return [
            'relayhost' => trim((string) @shell_exec('postconf -h relayhost 2>/dev/null')),
            'gate_enabled' => $this->settings->read()['outbound_gate'],
            'ipv4' => $ipv4 !== '' ? $this->reverseCheck($ipv4) : null,
            'ipv6' => $ipv6 !== '' ? $this->reverseCheck($ipv6) : null,
        ];
    }

    /**
     * Evaluates and makes Postfix match.
     *
     * @return array<string, mixed> the new state
     */
    public function check(bool $dryRun = false): array
    {
        $facts = $this->evaluate();
        $previous = $this->status();
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/mail-outbound-policy.sh';
        if (! in_array($previous['outbound'], ['active', 'paused'], true)) {
            // The state lives in the cache, which a panel update clears; start
            // from what Postfix is actually doing so a pause is not lifted early.
            $previous = $this->postfixState($script) + $previous;
        }

        // IPv4-only is always safe, so IPv6 is used only once its PTR is proven.
        $ipv6 = ($facts['ipv6']['ok'] ?? false) ? 'allow' : 'deny';

        [$outbound, $failures, $reason] = $this->decideOutbound($facts, $previous);

        $state = [
            'ipv6' => $ipv6,
            'outbound' => $outbound,
            'failures' => $failures,
            'reason' => $reason,
            'facts' => $facts,
            'checked_at' => now()->toIso8601String(),
            'applied' => false,
            'error' => null,
        ];

        if ($dryRun) {
            return $state;
        }

        try {
            $result = $this->gateway->execute($script, ["--ipv6={$ipv6}", "--outbound={$outbound}"], [], true);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'output' => $e->getMessage()];
        }
        $state['applied'] = (bool) $result['success'];
        if (! $result['success']) {
            $state['error'] = trim((string) $result['output']) ?: 'Postfix could not be updated.';
            // Postfix kept its previous state; report that rather than the intended one.
            $state['ipv6'] = $previous['ipv6'];
            $state['outbound'] = $previous['outbound'];
        }

        Cache::forever(self::STATE_KEY, $state);

        return $state;
    }

    /**
     * Turns holding mail on a wrong PTR on or off, and applies it at once:
     * turning it off releases mail that was held.
     *
     * @return array<string, mixed> the new state
     */
    public function setEnabled(bool $enabled): array
    {
        $this->settings->write(['outbound_gate' => $enabled]);

        return $this->check();
    }

    /** @return array<string, mixed> the last check, or "unknown" before the first */
    public function status(): array
    {
        $state = Cache::get(self::STATE_KEY);

        return is_array($state) ? $state : [
            'ipv6' => 'unknown',
            'outbound' => 'unknown',
            'failures' => 0,
            'reason' => 'Not checked yet.',
            'facts' => null,
            'checked_at' => null,
            'applied' => false,
            'error' => null,
        ];
    }

    /** @return array{ipv6?: string, outbound?: string, failures?: int} */
    private function postfixState(string $script): array
    {
        try {
            $result = $this->gateway->execute($script, ['--status'], [], true);
        } catch (\Throwable) {
            return [];
        }
        if (! $result['success'] || ! preg_match('/^MAIL_OUTBOUND=(active|paused)$/m', $result['output'], $outbound)) {
            return [];
        }
        preg_match('/^MAIL_OUTBOUND_IPV6=(allow|deny)$/m', $result['output'], $ipv6);

        return [
            'ipv6' => $ipv6[1] ?? 'unknown',
            'outbound' => $outbound[1],
            'failures' => $outbound[1] === 'paused' ? self::FAILURES_BEFORE_PAUSE : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $previous
     * @return array{0: string, 1: int, 2: string}
     */
    private function decideOutbound(array $facts, array $previous): array
    {
        $keep = in_array($previous['outbound'], ['active', 'paused'], true) ? $previous['outbound'] : 'active';
        $failures = (int) ($previous['failures'] ?? 0);

        if ($facts['relayhost'] !== '') {
            return ['active', 0, "Mail is sent through the relay {$facts['relayhost']}, so this server's PTR is not checked."];
        }
        if (! $facts['gate_enabled']) {
            return ['active', 0, 'Holding mail on a wrong PTR is turned off, so outbound mail is never paused.'];
        }
        $ipv4 = $facts['ipv4'];
        if ($ipv4 === null) {
            return [$keep, $failures, 'The server\'s public IPv4 address could not be determined; outbound mail left as it was.'];
        }
        if ($ipv4['lookup_failed']) {
            return [$keep, $failures, 'DNS did not answer for '.$ipv4['ip'].'; outbound mail left as it was.'];
        }
        if ($ipv4['ok']) {
            return ['active', 0, "PTR of {$ipv4['ip']} is {$ipv4['ptr']}, which resolves back to it."];
        }

        $failures++;
        if ($failures < self::FAILURES_BEFORE_PAUSE) {
            return [$keep, $failures, $ipv4['message'].' Outbound mail will be paused if the next check fails too.'];
        }

        return ['paused', $failures, $ipv4['message'].' Outbound mail is held in the queue until this is fixed at your IP provider.'];
    }

    /**
     * Forward-confirmed reverse DNS, as Gmail checks it.
     *
     * @return array{ip: string, ptr: string, ok: bool, lookup_failed: bool, message: string}
     */
    private function reverseCheck(string $ip): array
    {
        $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
        $ptr = strtolower(rtrim((string) ($this->dns->ptr($ip)[0] ?? ''), '.'));
        $failed = $this->dns->failed($ip, 'PTR');
        $result = ['ip' => $ip, 'ptr' => $ptr, 'ok' => false, 'lookup_failed' => $failed, 'message' => ''];

        if ($ptr === '') {
            $result['message'] = "{$ip} has no PTR (reverse DNS) record.";

            return $result;
        }

        $addresses = $this->dns->records($ptr, $type);
        $result['lookup_failed'] = $failed || $this->dns->failed($ptr, $type);
        $packed = inet_pton($ip);
        foreach ($addresses as $address) {
            if (@inet_pton(trim($address)) === $packed) {
                $result['ok'] = true;

                return $result;
            }
        }
        $result['message'] = "PTR of {$ip} is {$ptr}, but {$ptr} has no {$type} record pointing back to {$ip}.";

        return $result;
    }

    /** The global IPv6 address outbound connections leave from, or ''. */
    private function publicIpv6(): string
    {
        $src = trim((string) @shell_exec("ip -6 route get 2001:4860:4860::8888 2>/dev/null | awk '{for(i=1;i<=NF;i++) if (\$i==\"src\") {print \$(i+1); exit}}'"));

        return filter_var($src, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $src : '';
    }
}
