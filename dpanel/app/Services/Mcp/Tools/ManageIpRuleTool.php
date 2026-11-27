<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Website;
use App\Models\WebsiteIpRule;
use App\Services\EdgeGatewayReloader;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;
use Illuminate\Support\Str;

/**
 * Ban or allow an IP for one website, or remove such a rule, with the same
 * rules as the website's IP Rules page. The edge gateway reloads the domain.
 */
class ManageIpRuleTool implements McpTool
{
    public function __construct(private readonly EdgeGatewayReloader $reloader) {}

    public function name(): string
    {
        return 'manage_ip_rule';
    }

    public function title(): string
    {
        return 'Ban or allow an IP for a website';
    }

    public function description(): string
    {
        return 'Block (ban) or whitelist (allow) one IP address for a website at the edge gateway, remove such a rule (action=remove), or list the rules (action=list). Use it against an attacking IP seen in the access or WAF log. Never ban the admin\'s own IP, search engine bots or the server itself.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['ban', 'allow', 'remove', 'list']],
                'domain' => ['type' => 'string'],
                'ip' => ['type' => 'string', 'description' => 'IPv4 or IPv6 address; not needed for list.'],
            ],
            'required' => ['action', 'domain'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $action = (string) ($arguments['action'] ?? '');
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        $website = Website::query()->visibleTo($token->user)->where('domain', $domain)->first();
        if ($website === null) {
            throw new McpToolException("No website {$domain} that this account can manage.");
        }

        $rules = fn () => WebsiteIpRule::query()->where('website_id', $website->id)->latest()->get(['rule_type', 'ip_address', 'created_at'])
            ->map(fn (WebsiteIpRule $r) => ['type' => $r->rule_type, 'ip' => $r->ip_address, 'since' => $r->created_at?->toDateTimeString()])->all();
        if ($action === 'list') {
            return ['domain' => $domain, 'rules' => $rules()];
        }

        if (! $token->allowsMutations()) {
            throw new McpToolException('This token is read-only. Switch the chat\'s server tools to full access to change IP rules.');
        }
        $ip = filter_var(trim((string) ($arguments['ip'] ?? '')), FILTER_VALIDATE_IP);
        if ($ip === false) {
            throw new McpToolException('ip must be a valid IPv4 or IPv6 address.');
        }

        if ($action === 'remove') {
            $deleted = WebsiteIpRule::query()->where('website_id', $website->id)->where('ip_address', $ip)->delete();
            if ($deleted) {
                $this->reloader->reloadDomains([$domain]);
            }

            return ['removed' => $deleted > 0, 'rules' => $rules()];
        }

        if (! in_array($action, ['ban', 'allow'], true)) {
            throw new McpToolException('action must be ban, allow, remove or list.');
        }
        if ($action === 'ban' && ($ip === request()->ip() || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new McpToolException('Refused: that is the admin\'s own IP or a private/loopback address; banning it would lock out the admin or the server.');
        }
        $opposite = $action === 'ban' ? 'allow' : 'ban';
        if (WebsiteIpRule::query()->where('website_id', $website->id)->where('rule_type', $opposite)->where('ip_address', $ip)->exists()) {
            throw new McpToolException("This IP is already on the {$opposite} list of {$domain}. Remove that rule first if the change is intended.");
        }

        $rule = WebsiteIpRule::query()->firstOrCreate(
            ['website_id' => $website->id, 'rule_type' => $action, 'ip_address' => $ip],
            ['id' => (string) Str::uuid(), 'created_by' => $token->user?->id],
        );
        if ($rule->wasRecentlyCreated) {
            $this->reloader->reloadDomains([$domain]);
        }

        return ['done' => $action, 'ip' => $ip, 'domain' => $domain, 'already_existed' => ! $rule->wasRecentlyCreated, 'undo' => 'manage_ip_rule action=remove with the same ip'];
    }
}
