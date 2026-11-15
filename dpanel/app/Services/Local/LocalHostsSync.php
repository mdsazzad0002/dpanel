<?php

namespace App\Services\Local;

use App\Models\Website;
use Illuminate\Support\Facades\Http;

/**
 * On a PC (local profile, backlog F6) every website's name points at this
 * machine through the hosts file, so `mysite.test` opens the site. drust
 * owns only its own `# dpanel` lines; writing them asks the person at the
 * screen for an administrator, and only when the list changed.
 */
class LocalHostsSync
{
    /** Waiting for the person to answer Windows' administrator prompt. */
    private const TIMEOUT = 180;

    public static function enabled(): bool
    {
        return config('app.profile') === 'local';
    }

    /**
     * Every website and alias name that is a host name (no IP address).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return Website::query()
            ->pluck('domain')
            ->map(static fn ($domain) => strtolower(trim((string) $domain)))
            ->filter(static fn (string $domain) => self::isHostName($domain))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public static function isHostName(string $name): bool
    {
        if ($name === '' || strlen($name) > 253 || filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (explode('.', $name) as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sends the full list to drust: "unchanged", "written" or "declined".
     */
    public function sync(): string
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust API is not configured (dPanel_EXECUTION_API_BASE_URL).');
        }
        $request = Http::acceptJson()->asJson()->timeout(self::TIMEOUT);
        $token = trim((string) config('dpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }
        $response = $request->put(rtrim($baseUrl, '/').'/api/v1/local/hosts', ['names' => $this->names()]);
        $json = $response->json();
        $outcome = is_array($json) ? (string) ($json['outcome'] ?? '') : '';
        if ($outcome === 'declined') {
            return $outcome;
        }
        if (! $response->successful() || $outcome === '') {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new \RuntimeException($message !== '' ? $message : 'drust could not update the hosts file.');
        }

        return $outcome;
    }
}
