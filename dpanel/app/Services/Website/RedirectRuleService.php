<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Models\WebsiteRedirectRule;
use Illuminate\Validation\ValidationException;

/**
 * Checks shared by every place that writes a website's redirect rules
 * (Rules → Redirect Rules, the .htaccess resolver), so a rule the gateway
 * would mis-serve is refused the same way everywhere.
 */
class RedirectRuleService
{
    public function exists(Website $website, string $kind): bool
    {
        return WebsiteRedirectRule::query()->where('website_id', $website->id)->where('kind', $kind)->exists();
    }

    /** Appends an enabled rule after the website's existing ones. */
    public function create(Website $website, string $kind, string $name, ?string $target, int $statusCode, bool $preserveQuery): WebsiteRedirectRule
    {
        if ($this->exists($website, $kind)) {
            throw ValidationException::withMessages(['kind' => "{$website->domain} already has this redirect rule."]);
        }

        $rule = new WebsiteRedirectRule([
            'website_id' => (string) $website->id,
            'kind' => $kind,
            'position' => (int) WebsiteRedirectRule::query()->where('website_id', $website->id)->max('position') + 1,
            'enabled' => true,
        ]);
        $this->fill($rule, $website, $name, $target, $statusCode, $preserveQuery);
        $rule->save();

        return $rule;
    }

    public function fill(WebsiteRedirectRule $rule, Website $website, string $name, ?string $target, int $statusCode, bool $preserveQuery): void
    {
        $this->assertApplicable($rule->kind, $website);
        $normalizedTarget = null;
        if ($rule->kind === 'to_domain') {
            $normalizedTarget = strtolower(rtrim(trim((string) $target), '.'));
            $domain = strtolower((string) $website->domain);
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $normalizedTarget)) {
                throw ValidationException::withMessages(['target' => 'Enter a hostname such as example.com, without https:// or a path.']);
            }
            if ($normalizedTarget === $domain || $normalizedTarget === "www.{$domain}") {
                throw ValidationException::withMessages(['target' => 'The target must be a different domain than the website itself.']);
            }
        }

        $rule->fill([
            'name' => trim($name),
            'target' => $normalizedTarget,
            'status_code' => $statusCode,
            'preserve_query' => $preserveQuery,
        ]);
    }

    public function assertApplicable(string $kind, Website $website): void
    {
        if ($kind === 'http_to_https' && ! $website->enable_ssl) {
            throw ValidationException::withMessages(['website_id' => "{$website->domain} has no SSL certificate yet. Issue one first, or HTTPS visitors would land on an error."]);
        }
        if ($kind === 'www_to_root' && str_starts_with(strtolower((string) $website->domain), 'www.')) {
            throw ValidationException::withMessages(['website_id' => "{$website->domain} is itself a www hostname."]);
        }
    }
}
