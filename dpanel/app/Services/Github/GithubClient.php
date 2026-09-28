<?php

namespace App\Services\Github;

use App\Models\GithubAccount;
use App\Models\GithubOAuthApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around GitHub's OAuth + REST API. Works with both a classic
 * OAuth App (non-expiring tokens) and a GitHub App's user-to-server tokens
 * (8h access token + refresh token), whichever the admin registered.
 */
class GithubClient
{
    // OAuth Apps only; a GitHub App ignores scopes and uses the permissions set
    // in its settings (Contents, Workflows, Webhooks: Read and write).
    public const SCOPES = ['repo', 'workflow', 'admin:repo_hook', 'read:user'];

    public function authorizationUrl(GithubOAuthApp $app, string $redirectUri, string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $app->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'allow_signup' => 'false',
            // Always show GitHub's account picker so a user who is signed in
            // to one GitHub account can still link a second one.
            'prompt' => 'select_account',
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_at: ?\Illuminate\Support\Carbon, scopes: ?string} */
    public function exchangeCode(GithubOAuthApp $app, string $code, string $redirectUri): array
    {
        return $this->tokenRequest($app, [
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /** @return array{id: int, login: string, name: ?string, avatar_url: ?string} */
    public function user(string $accessToken): array
    {
        $user = $this->check($this->http($accessToken)->get('https://api.github.com/user'))->json();

        return [
            'id' => (int) $user['id'],
            'login' => (string) $user['login'],
            'name' => $user['name'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
        ];
    }

    /** A valid access token for the account, refreshing an expiring one first. */
    public function tokenFor(GithubAccount $account): string
    {
        $expiring = $account->token_expires_at && $account->token_expires_at->lte(now()->addMinutes(5));
        if ($expiring && $account->refresh_token) {
            $app = GithubOAuthApp::current() ?? throw new \RuntimeException('GitHub integration is not configured by the administrator.');
            $token = $this->tokenRequest($app, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);
            $account->forceFill([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => $token['expires_at'],
            ])->save();
        }
        $account->forceFill(['last_used_at' => now()])->saveQuietly();

        return (string) $account->access_token;
    }

    /** @return list<array<string, mixed>> */
    public function repositories(GithubAccount $account, int $page = 1, string $search = ''): array
    {
        $response = $this->check($this->http($this->tokenFor($account))->get('https://api.github.com/user/repos', [
            'per_page' => 100,
            'page' => max(1, $page),
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ]));

        return collect($response->json())
            ->filter(fn (array $repo): bool => $search === '' || str_contains(strtolower($repo['full_name']), strtolower($search)))
            ->map(fn (array $repo): array => [
                'full_name' => $repo['full_name'],
                'clone_url' => $repo['clone_url'],
                'private' => (bool) $repo['private'],
                'default_branch' => $repo['default_branch'] ?? 'main',
                'can_admin' => (bool) ($repo['permissions']['admin'] ?? false),
                'can_push' => (bool) ($repo['permissions']['push'] ?? false),
                'updated_at' => $repo['pushed_at'] ?? $repo['updated_at'] ?? null,
            ])->values()->all();
    }

    /** @return list<string> */
    public function branches(GithubAccount $account, string $fullName): array
    {
        $response = $this->check($this->http($this->tokenFor($account))
            ->get("https://api.github.com/repos/{$this->repoPath($fullName)}/branches", ['per_page' => 100]));

        return collect($response->json())->pluck('name')->values()->all();
    }

    public function createPushHook(GithubAccount $account, string $fullName, string $url, string $secret): int
    {
        $response = $this->check($this->http($this->tokenFor($account))->post("https://api.github.com/repos/{$this->repoPath($fullName)}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]));

        return (int) $response->json('id');
    }

    public function deleteHook(GithubAccount $account, string $fullName, int $hookId): void
    {
        $response = $this->http($this->tokenFor($account))->delete("https://api.github.com/repos/{$this->repoPath($fullName)}/hooks/{$hookId}");
        if ($response->status() !== 404) {
            $this->check($response);
        }
    }

    private function tokenRequest(GithubOAuthApp $app, array $params): array
    {
        $json = Http::acceptJson()->asForm()->timeout(20)->post('https://github.com/login/oauth/access_token', array_merge([
            'client_id' => $app->client_id,
            'client_secret' => $app->client_secret,
        ], $params))->throw()->json();

        if (! empty($json['error']) || empty($json['access_token'])) {
            throw new \RuntimeException((string) ($json['error_description'] ?? $json['error'] ?? 'GitHub did not return an access token.'));
        }

        return [
            'access_token' => (string) $json['access_token'],
            'refresh_token' => $json['refresh_token'] ?? null,
            'expires_at' => isset($json['expires_in']) ? now()->addSeconds((int) $json['expires_in']) : null,
            'scopes' => $json['scope'] ?? null,
        ];
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'dPanel'])
            ->timeout(20);
    }

    private function check(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }
        $message = (string) ($response->json('message') ?? $response->body());
        throw new \RuntimeException(match ($response->status()) {
            401 => 'GitHub rejected the saved token. Reconnect this GitHub account.',
            403, 404 => "GitHub denied access ({$response->status()}): {$message}. Check that this account can reach the repository and granted the requested scopes.",
            default => "GitHub API error ({$response->status()}): {$message}",
        });
    }

    private function repoPath(string $fullName): string
    {
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName)) {
            throw new \InvalidArgumentException('Invalid repository name.');
        }

        return $fullName;
    }
}
