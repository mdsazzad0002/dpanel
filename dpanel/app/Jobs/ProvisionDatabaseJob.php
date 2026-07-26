<?php

namespace App\Jobs;

use App\Models\DatabaseUser;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionDatabaseJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public string $databaseUserId,
        public string $action = 'create',
        public array $params = [],
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $dbUser = DatabaseUser::find($this->databaseUserId);
        if (! $dbUser) {
            return;
        }

        match ($this->action) {
            'create' => $this->createDatabase($dbUser, $sshClient),
            'create_user' => $this->createUser($dbUser, $sshClient),
            'grant' => $this->grantPrivileges($dbUser, $sshClient),
            'revoke' => $this->revokePrivileges($dbUser, $sshClient),
            'drop' => $this->dropDatabase($dbUser, $sshClient),
            'rotate_password' => $this->rotatePassword($dbUser, $sshClient),
            default => null,
        };
    }

    private function createDatabase(DatabaseUser $dbUser, SshClientService $sshClient): void
    {
        $dbName = $this->params['database_name'] ?? $dbUser->username;
        // CREATE DATABASE and GRANT
    }

    private function createUser(DatabaseUser $dbUser, SshClientService $sshClient): void
    {
        // CREATE USER with encrypted password
    }

    private function grantPrivileges(DatabaseUser $dbUser, SshClientService $sshClient): void
    {
        $dbName = $this->params['database_name'] ?? '*';
        $privileges = $this->params['privileges'] ?? ['ALL PRIVILEGES'];
        // GRANT privileges
    }

    private function revokePrivileges(DatabaseUser $dbUser, SshClientService $sshClient): void
    {
        $dbName = $this->params['database_name'] ?? '*';
        // REVOKE privileges
    }

    private function dropDatabase(DatabaseUser $dbUser, SshClientService $sshClient): void
    {
        $dbName = $this->params['database_name'] ?? '';
        if (empty($dbName)) {
            return;
        }
        // DROP DATABASE
    }

    private function rotatePassword(DatabaseUser $dbUser, SshClientService $sshClient): void
    {
        $newPassword = $this->params['new_password'] ?? bin2hex(random_bytes(16));
        // ALTER USER PASSWORD
        $dbUser->update(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)]);
    }
}
