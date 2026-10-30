<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\Mcp\McpToolException;

/** Finds the Git deployment of a website the token's user can see. */
trait GitConflictsSupport
{
    private function deploymentFor(array $arguments, McpAccessToken $token): WebsiteGitDeployment
    {
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        if ($domain === '') {
            throw new McpToolException('domain is required.');
        }
        $site = Website::query()->visibleTo($token->user)
            ->whereIn('domain', [$domain, preg_replace('/^www\./', '', $domain)])
            ->first();
        if (! $site) {
            throw new McpToolException("No website found for {$domain}. Call list_websites to search.");
        }
        $deployment = WebsiteGitDeployment::query()->where('website_id', $site->id)->first();
        if (! $deployment) {
            throw new McpToolException("{$site->domain} is not deployed from Git.");
        }

        return $deployment;
    }
}
