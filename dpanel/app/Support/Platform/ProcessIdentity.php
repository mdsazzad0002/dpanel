<?php

namespace App\Support\Platform;

/**
 * Who this PHP process runs as (plans/windows-macos-native-plan.md, Windows).
 *
 * ext-posix does not exist on Windows. Without it no answer is known: the
 * process is not root and no system account can be looked up.
 */
class ProcessIdentity
{
    public function isRoot(): bool
    {
        return $this->effectiveUserId() === 0;
    }

    public function effectiveUserId(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    /** The account name for a user id, or null when it cannot be found. */
    public function userName(int $uid): ?string
    {
        $info = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;

        return is_array($info) ? (string) $info['name'] : null;
    }

    /**
     * A system account, or null when it is missing or cannot be looked up.
     *
     * @return array{name: string, uid: int, gid: int}|null
     */
    public function account(string $name): ?array
    {
        $info = function_exists('posix_getpwnam') ? @posix_getpwnam($name) : false;

        return is_array($info)
            ? ['name' => (string) $info['name'], 'uid' => (int) $info['uid'], 'gid' => (int) $info['gid']]
            : null;
    }

    /**
     * The argv that runs $command with root rights: as it is when already root
     * or where sudo does not exist, otherwise behind non-interactive sudo.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public function privilegedCommand(array $command): array
    {
        if ($this->isRoot() || ! $this->hasSudo()) {
            return $command;
        }

        return array_merge(['sudo', '-n'], $command);
    }

    protected function hasSudo(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }
}
