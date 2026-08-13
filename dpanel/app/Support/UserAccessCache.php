<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UserAccessCache
{
    private const VERSION_KEY = 'dpanel:user-access:version';

    /**
     * @return array{roles: array<int, string>, permissions: array<int, string>}
     */
    public static function get(User $user): array
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        return Cache::remember(
            "dpanel:user-access:v{$version}:user:{$user->getKey()}",
            now()->addDay(),
            static fn (): array => [
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            ],
        );
    }

    /**
     * Rotate the namespace so all user access entries refresh on next request.
     */
    public static function invalidate(): void
    {
        $nextVersion = (int) Cache::get(self::VERSION_KEY, 1) + 1;
        Cache::forever(self::VERSION_KEY, $nextVersion);
    }
}
