<?php

namespace App\Services\Security;

use App\Models\Backup;
use App\Models\SslCertificate;
use App\Models\Website;
use Illuminate\Support\Carbon;

/**
 * Server-wide configuration checks (SSH, firewall, exposed ports, SSL and
 * backups). Returns findings in the same shape as the drust website scanner.
 */
class ServerPostureCheck
{
    public function __construct(private readonly DrustSecurityClient $drust)
    {
    }

    /**
     * @return array{findings: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(): array
    {
        $findings = [];
        $summary = [];

        $live = $this->drust->status();
        $summary['ssh'] = $live['ssh'] ?? null;
        $summary['firewall_enabled'] = (bool) ($live['firewall']['enabled'] ?? false);

        array_push($findings, ...$this->sshFindings((array) ($live['ssh'] ?? [])));
        array_push($findings, ...$this->firewallFindings($live));
        array_push($findings, ...$this->sslFindings());
        array_push($findings, ...$this->backupFindings());

        return ['findings' => $findings, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $ssh
     * @return array<int, array<string, mixed>>
     */
    public function sshFindings(array $ssh): array
    {
        if (! ($ssh['installed'] ?? false) || ! ($ssh['service_active'] ?? false)) {
            return [];
        }

        $findings = [];
        $rootLogin = strtolower((string) ($ssh['permit_root_login'] ?? 'no'));
        if ($rootLogin === 'yes') {
            $findings[] = $this->finding('DP-SSH-001', 'Root can log in over SSH with a password',
                'PermitRootLogin is "yes". Attackers target the root account first.', 'PermitRootLogin '.$rootLogin);
        }
        if (($ssh['password_authentication'] ?? 'Off') === 'On') {
            $findings[] = $this->finding('DP-SSH-002', 'SSH password login is enabled',
                'Any account with a weak password can be brute-forced.', 'PasswordAuthentication yes');
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $live
     * @return array<int, array<string, mixed>>
     */
    public function firewallFindings(array $live): array
    {
        $findings = [];
        $enabled = (bool) ($live['firewall']['enabled'] ?? false);
        if (! $enabled) {
            $findings[] = $this->finding('DP-FW-001', 'Firewall is disabled',
                'UFW is not active, so every listening service is reachable from the internet.');
        }

        $privatePorts = array_map('intval', (array) config('security_center.private_ports'));
        foreach ((array) ($live['ports'] ?? []) as $port) {
            $number = (int) ($port['port'] ?? 0);
            if (! in_array($number, $privatePorts, true) || ! ($port['listening'] ?? false)) {
                continue;
            }
            $public = array_filter((array) ($port['addresses'] ?? []), fn ($address) => ! $this->isLoopback((string) $address));
            if ($public === []) {
                continue;
            }
            // With the firewall on and no allow rule the port is not reachable.
            if ($enabled && ! ($port['firewall_allowed'] ?? false)) {
                continue;
            }
            $service = (string) ($port['service'] ?? 'service');
            $findings[] = $this->finding('DP-NET-001', "{$service} port {$number} is reachable from the internet",
                "Port {$number} listens on ".implode(', ', $public).($enabled ? ' and the firewall allows it.' : ' and the firewall is off.'),
                'listening on '.implode(', ', $public), "port:{$number}");
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sslFindings(): array
    {
        $findings = [];
        $warningDays = (int) config('security_center.ssl_expiry_warning_days', 14);

        foreach (SslCertificate::query()->whereNotNull('expires_at')->get(['domain', 'expires_at', 'website_id']) as $certificate) {
            $expires = Carbon::parse($certificate->expires_at);
            if ($expires->isPast()) {
                $findings[] = $this->finding('DP-SSL-001', "Certificate for {$certificate->domain} has expired",
                    'Expired on '.$expires->toDateString().'.', null, "ssl:{$certificate->domain}", $certificate->website_id);
            } elseif ($expires->lte(now()->addDays($warningDays))) {
                $findings[] = $this->finding('DP-SSL-002', "Certificate for {$certificate->domain} expires soon",
                    'Expires on '.$expires->toDateString().' ('.(int) now()->diffInDays($expires).' days).', null, "ssl:{$certificate->domain}", $certificate->website_id);
            }
        }

        $websites = Website::query()
            ->where('enable_ssl', false)
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->whereNull('scope')->orWhere('scope', '!=', 'system'))
            ->get(['id', 'domain']);
        foreach ($websites as $website) {
            $findings[] = $this->finding('DP-SSL-003', "{$website->domain} is served without HTTPS",
                'SSL is not enabled for this website.', null, "ssl:{$website->domain}", $website->id);
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function backupFindings(): array
    {
        $latest = Backup::query()->where('status', 'completed')->max('completed_at');
        if ($latest === null) {
            return [$this->finding('DP-BAK-001', 'No backup has completed yet',
                'Without a backup a compromised or broken site cannot be restored.')];
        }

        $maxAge = (int) config('security_center.backup_max_age_days', 7);
        $completed = Carbon::parse($latest);
        if ($completed->lt(now()->subDays($maxAge))) {
            return [$this->finding('DP-BAK-002', 'Newest backup is '.(int) $completed->diffInDays(now()).' days old',
                "The last completed backup finished on {$completed->toDateString()}; the limit is {$maxAge} days.")];
        }

        return [];
    }

    private function isLoopback(string $address): bool
    {
        return $address === '::1' || $address === 'localhost' || str_starts_with($address, '127.');
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $ruleId, string $title, string $description, ?string $evidence = null, ?string $path = null, ?string $websiteId = null): array
    {
        $rule = (array) config("security_center.rules.{$ruleId}");

        return [
            'rule_id' => $ruleId,
            'severity' => $rule['severity'],
            'category' => $rule['category'],
            'title' => $title,
            'description' => $description,
            'file_path' => $path,
            'line_number' => null,
            'evidence' => $evidence,
            'recommendation' => $rule['remediation'] ?? null,
            'auto_fix_available' => false,
            'website_id' => $websiteId,
        ];
    }
}
