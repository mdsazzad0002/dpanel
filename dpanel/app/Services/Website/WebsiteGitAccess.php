<?php

namespace App\Services\Website;

use App\Models\User;
use App\Models\WebsiteGitDeployment;
use App\Models\WebsiteGitUserLink;

/**
 * Who may work with a website's Git connection. Admins and the user who
 * connected the repository always may; anyone else first links their own
 * GitHub account or token (WebsiteGitUserLink) and then works with it.
 */
class WebsiteGitAccess
{
    /**
     * @return array{connected: bool, via: ?string, login: ?string, connected_by: ?string}
     */
    public function describe(WebsiteGitDeployment $deployment, ?User $user): array
    {
        $deployment->loadMissing('githubAccount');
        $owner = $deployment->created_by ? User::query()->find($deployment->created_by) : null;
        $link = $user ? $this->linkFor($deployment, $user) : null;
        $via = match (true) {
            $user === null => null,
            $link !== null => 'link',
            $this->isOwner($deployment, $user) => 'owner',
            $user->hasRole('admin') => 'admin',
            default => null,
        };

        return [
            'connected' => $via !== null,
            'via' => $via,
            // The login the user's own link works as; null when it uses a typed token.
            'login' => $link?->githubAccount?->login,
            'connected_by' => $deployment->githubAccount ? '@'.$deployment->githubAccount->login : $owner?->name,
        ];
    }

    public function allows(WebsiteGitDeployment $deployment, ?User $user): bool
    {
        return $this->describe($deployment, $user)['connected'];
    }

    public function linkFor(WebsiteGitDeployment $deployment, User $user): ?WebsiteGitUserLink
    {
        return $deployment->userLinks()->with('githubAccount')->where('user_id', $user->id)->first();
    }

    private function isOwner(WebsiteGitDeployment $deployment, User $user): bool
    {
        return (int) $deployment->created_by === (int) $user->id
            || ($deployment->githubAccount !== null && (int) $deployment->githubAccount->user_id === (int) $user->id);
    }
}
