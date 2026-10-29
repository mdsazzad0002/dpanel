<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Recovery path for a locked-out user: clears all 2FA state so the next login
 * needs only email and password. Admins and resellers are asked to set 2FA up
 * again after logging in, since it is enforced for those roles.
 */
class DisableUserTwoFactorCommand extends Command
{
    protected $signature = 'serverpanel:2fa-disable {email : Email of the user to disable 2FA for} {--force : Skip the confirmation prompt}';
    protected $description = 'Disable two-factor authentication for a user (lost authenticator recovery)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("User {$email} was not found.");

            return self::FAILURE;
        }

        if (! (bool) $user->two_factor_enabled) {
            $this->info("2FA is already off for {$email}.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Disable 2FA ({$user->two_factor_method}) for {$email}?", true)) {
            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_telegram_chat_id' => null,
            'two_factor_telegram_start_token' => null,
        ])->save();

        $this->info("2FA disabled for {$email}. Log in with email and password, then set 2FA up again.");

        return self::SUCCESS;
    }
}
