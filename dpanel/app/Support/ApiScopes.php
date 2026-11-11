<?php

namespace App\Support;

use App\Models\User;

/**
 * Scopes of the public API (backlog U1). Each area has a read and a write
 * scope. A scope only works while the token's owner may also use that area
 * in the panel: the same roles and permission as the area's panel routes.
 */
final class ApiScopes
{
    /** @var array<string, array{roles: list<string>, permission: string}> */
    public const AREAS = [
        'websites' => ['roles' => ['admin', 'reseller'], 'permission' => 'manage_websites'],
        'dns' => ['roles' => ['admin', 'reseller', 'general'], 'permission' => 'manage_dns'],
        'databases' => ['roles' => ['admin', 'reseller'], 'permission' => 'manage_databases'],
        'mail' => ['roles' => ['admin', 'reseller'], 'permission' => 'manage_email'],
    ];

    /** @return list<string> every scope, such as `websites:read` */
    public static function all(): array
    {
        $scopes = [];
        foreach (array_keys(self::AREAS) as $area) {
            $scopes[] = "{$area}:read";
            $scopes[] = "{$area}:write";
        }

        return $scopes;
    }

    /** @return list<string> the scopes this user may put on a token */
    public static function allowedFor(User $user): array
    {
        return array_values(array_filter(self::all(), fn (string $scope): bool => self::userMay($user, $scope)));
    }

    /** Whether the user may use the area of `$scope` in the panel right now. */
    public static function userMay(User $user, string $scope): bool
    {
        $area = self::AREAS[explode(':', $scope)[0]] ?? null;
        if ($area === null || ! in_array($scope, self::all(), true)) {
            return false;
        }

        return $user->hasAnyRole($area['roles']) || $user->hasAccess($area['permission']);
    }
}
