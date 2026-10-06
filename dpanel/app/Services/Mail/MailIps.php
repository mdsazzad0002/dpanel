<?php

namespace App\Services\Mail;

use App\Models\MailDomain;
use App\Models\MailIp;
use App\Services\Dns\PublicDnsLookup;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use Illuminate\Support\Collection;

/**
 * The public IPs mail can leave from. Each IP has one hostname (its HELO
 * name, the MX of its domains and the name its PTR should give) and one
 * certificate. The default IP is the server's own mail IP and uses Postfix's
 * myhostname; every other IP gets its own smtp transport, and each mail
 * domain is sent from the IP it is assigned (the default when unassigned).
 */
class MailIps
{
    public function __construct(
        private readonly MailDnsRecords $records,
        private readonly MailHostnameDetector $hostname,
        private readonly ScriptExecutionGateway $gateway,
        private readonly PublicDnsLookup $dns,
    ) {
    }

    /** @return Collection<int, MailIp> default first */
    public function all(): Collection
    {
        $default = MailIp::query()->where('is_default', true)->first();
        $ip = $this->records->serverIp();
        if (! $default && $ip !== '') {
            $default = MailIp::query()->updateOrCreate(['ip' => $ip], ['is_default' => true]);
        }
        // The default IP's name is Postfix's myhostname; keep the row in step with it.
        $current = $this->hostname->current();
        if ($default && MailDomainProvisioner::isValidFqdn($current) && $default->hostname !== $current) {
            $default->forceFill(['hostname' => $current])->save();
        }

        return MailIp::query()->orderByDesc('is_default')->orderBy('ip')->get();
    }

    /** Mail hostnames with the default first: the certificates the mail server needs. */
    public function hostnames(): array
    {
        return $this->all()->pluck('hostname')->filter(fn ($host) => MailDomainProvisioner::isValidFqdn((string) $host))->unique()->values()->all();
    }

    /** Public IPv4 addresses on this machine's interfaces: the only ones Postfix can bind to. */
    public function localAddresses(): array
    {
        $output = (string) @shell_exec("ip -4 -o addr show scope global 2>/dev/null | awk '{split(\$4,a,\"/\"); print a[1]}'");

        return array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)));
    }

    /** @return array{ok: bool, message: string} */
    public function add(string $ip, string $hostname): array
    {
        $ip = trim($ip);
        $hostname = strtolower(trim($hostname));
        if (! in_array($ip, $this->localAddresses(), true)) {
            return ['ok' => false, 'message' => "{$ip} is not a public IPv4 address on this server's network interfaces. Add it to the server (your provider's panel and netplan) first."];
        }
        if (MailIp::query()->where('ip', $ip)->exists()) {
            return ['ok' => false, 'message' => "{$ip} is already a mail IP."];
        }
        if ($error = $this->hostnameError($hostname, $ip)) {
            return ['ok' => false, 'message' => $error];
        }
        MailIp::query()->create(['ip' => $ip, 'hostname' => $hostname, 'is_default' => false]);

        return $this->sync("Mail IP {$ip} added with hostname {$hostname}. Generate its SSL next.");
    }

    /** @return array{ok: bool, message: string} */
    public function setHostname(MailIp $mailIp, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        if ($mailIp->is_default) {
            $result = $this->hostname->apply($hostname);
            if ($result['ok']) {
                $mailIp->forceFill(['hostname' => $hostname])->save();
            }

            return ['ok' => $result['ok'], 'message' => $result['message']];
        }
        if ($error = $this->hostnameError($hostname, $mailIp->ip)) {
            return ['ok' => false, 'message' => $error];
        }
        $mailIp->forceFill(['hostname' => $hostname])->save();

        return $this->sync("Hostname for {$mailIp->ip} set to {$hostname}. Generate its SSL next.");
    }

    /** @return array{ok: bool, message: string} */
    public function remove(MailIp $mailIp): array
    {
        if ($mailIp->is_default) {
            return ['ok' => false, 'message' => 'The default mail IP cannot be removed.'];
        }
        $moved = MailDomain::query()->where('mail_ip_id', $mailIp->id)->update(['mail_ip_id' => null]);
        $mailIp->delete();

        return $this->sync("Mail IP {$mailIp->ip} removed.".($moved ? " {$moved} domain(s) now send from the default IP; update their MX and SPF." : ''));
    }

    /**
     * Sends the domain's mail from this IP (null: the default IP). Postfix
     * reads the assignment from MySQL, so nothing needs reloading.
     */
    public function assign(string $domain, ?string $mailIpId): void
    {
        $mailIp = $mailIpId ? MailIp::query()->find($mailIpId) : null;
        MailDomain::query()->updateOrCreate(
            ['domain' => strtolower(trim($domain))],
            ['mail_ip_id' => $mailIp && ! $mailIp->is_default ? $mailIp->id : null],
        );
    }

    /** Writes one Postfix transport per non-default IP. @return array{ok: bool, message: string} */
    public function sync(string $message = 'Mail IPs applied.'): array
    {
        $pairs = MailIp::query()->where('is_default', false)->whereNotNull('hostname')->get()
            ->map(fn (MailIp $mailIp) => "{$mailIp->ip}={$mailIp->hostname}")->all();
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/configure-mail-ips.sh';
        $result = $this->gateway->execute($script, $pairs, [], true);

        return $result['success']
            ? ['ok' => true, 'message' => $message]
            : ['ok' => false, 'message' => 'Saved, but Postfix could not be updated: '.(trim($result['output']) ?: 'unknown error.')];
    }

    private function hostnameError(string $hostname, string $ip): ?string
    {
        if (! MailDomainProvisioner::isValidFqdn($hostname)) {
            return "'{$hostname}' is not a valid hostname.";
        }
        if (MailIp::query()->where('hostname', $hostname)->where('ip', '!=', $ip)->exists()) {
            return "{$hostname} already belongs to another mail IP.";
        }
        if (! in_array($ip, $this->dns->a($hostname), true)) {
            return "{$hostname} does not resolve to {$ip}. Add an A record (DNS only, not proxied) first.";
        }

        return null;
    }
}
