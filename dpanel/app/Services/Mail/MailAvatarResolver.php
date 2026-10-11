<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Services\Dns\PublicDnsLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A sender's picture for webmail, looked up the way Gmail does:
 *
 *  1. a mailbox on this server: the photo its owner uploaded;
 *  2. a BIMI brand logo (default._bimi TXT record), only for a message
 *     whose DMARC or aligned DKIM passed, and only when the domain enforces
 *     DMARC, as the BIMI spec requires, so spoofed mail cannot borrow it;
 *  3. Gravatar.
 *
 * Remote images go through MailImageProxy (public addresses only, size
 * capped, cached), and misses are remembered for a day, so a sender costs
 * at most one lookup a day.
 */
class MailAvatarResolver
{
    private const TTL = 86400;

    public function __construct(
        private readonly MailImageProxy $images,
        private readonly PublicDnsLookup $dns,
    ) {}

    /**
     * @return array{body: string, type: string}|null
     */
    public function resolve(string $email, bool $allowBimi): ?array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $own = $this->mailboxPhoto($email);
        if ($own !== null) {
            return $own;
        }

        $domain = substr((string) strrchr($email, '@'), 1);
        if ($allowBimi && ($logo = $this->remember('bimi:'.$domain, fn (): ?array => $this->bimiLogo($domain))) !== null) {
            return $logo;
        }

        return $this->remember('gravatar:'.sha1($email), fn (): ?array => $this->images->fetch(
            'https://gravatar.com/avatar/'.hash('sha256', $email).'?s=128&d=404'
        ));
    }

    /**
     * @return array{body: string, type: string}|null
     */
    public function mailboxPhoto(string $email): ?array
    {
        $path = Mailbox::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->value('avatar_path');
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return ['body' => (string) Storage::disk('local')->get($path), 'type' => Storage::disk('local')->mimeType($path) ?: 'image/jpeg'];
    }

    /**
     * The logo URL from the domain's BIMI record, or from its organizational
     * domain's, provided DMARC is at quarantine or reject.
     *
     * @return array{body: string, type: string}|null
     */
    public function bimiLogo(string $domain): ?array
    {
        $url = $this->bimiLogoUrl($domain);
        if ($url === null) {
            return null;
        }
        $logo = $this->images->fetch($url);

        // BIMI logos are SVG (Tiny PS); anything else is not a BIMI logo.
        return $logo !== null && $logo['type'] === 'image/svg+xml' ? $logo : null;
    }

    public function bimiLogoUrl(string $domain): ?string
    {
        foreach (array_unique([$domain, $this->orgDomain($domain)]) as $candidate) {
            if (! $this->dmarcEnforced($candidate)) {
                continue;
            }
            foreach ($this->dns->txt('default._bimi.'.$candidate) as $record) {
                $record = trim($record, '" ');
                if (stripos($record, 'v=BIMI1') !== 0) {
                    continue;
                }
                if (preg_match('/(?:^|;)\s*l=\s*(https:\/\/[^;\s]+)/i', $record, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    private function dmarcEnforced(string $domain): bool
    {
        foreach ($this->dns->txt('_dmarc.'.$domain) as $record) {
            $record = trim($record, '" ');
            if (stripos($record, 'v=DMARC1') !== 0) {
                continue;
            }
            $policy = preg_match('/(?:^|;)\s*p=\s*(\w+)/i', $record, $p) ? strtolower($p[1]) : 'none';
            $percent = preg_match('/(?:^|;)\s*pct=\s*(\d+)/i', $record, $q) ? (int) $q[1] : 100;

            return in_array($policy, ['quarantine', 'reject'], true) && $percent === 100;
        }

        return false;
    }

    private function orgDomain(string $domain): string
    {
        $labels = explode('.', $domain);
        $count = count($labels);
        $take = $count > 2 && strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /**
     * Cache hits and misses alike (a miss is stored as false).
     *
     * @param  callable(): (array{body: string, type: string}|null)  $lookup
     * @return array{body: string, type: string}|null
     */
    private function remember(string $key, callable $lookup): ?array
    {
        // Base64, because the database cache store rejects raw image bytes.
        $value = Cache::remember('mail-avatar:'.$key, self::TTL, function () use ($lookup) {
            $image = $lookup();

            return $image === null ? false : ['base64' => base64_encode($image['body']), 'type' => $image['type']];
        });
        $body = is_array($value) && isset($value['base64']) ? base64_decode((string) $value['base64'], true) : false;

        return $body === false ? null : ['body' => $body, 'type' => (string) $value['type']];
    }
}
