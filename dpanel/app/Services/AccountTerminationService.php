<?php

namespace App\Services;

use App\Models\FtpAccount;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\Website;
use App\Services\Ftp\FtpProvisioningService;
use App\Services\Website\WebsiteDeletionService;

/**
 * Terminating an account suspends it and keeps its data for a grace period
 * (30 days). Unsuspending in that time cancels the termination.
 * After it, `purge` archives each website to the trash, then deletes the
 * websites, FTP accounts, mailboxes and the user.
 */
class AccountTerminationService
{
    public const GRACE_DAYS = 30;

    public function __construct(
        private readonly AccountSuspensionService $suspension,
        private readonly WebsiteDeletionService $websites,
        private readonly FtpProvisioningService $ftp,
    ) {}

    public function terminate(User $user): void
    {
        $this->suspension->suspend($user);
        $user->update([
            'terminated_at' => $user->terminated_at ?? now(),
            'purge_after' => $user->purge_after ?? now()->addDays(self::GRACE_DAYS),
        ]);
    }

    /** @return array{purged: int, failed: int} */
    public function purgeDue(): array
    {
        $purged = $failed = 0;
        User::query()->whereNotNull('purge_after')->where('purge_after', '<=', now())->orderBy('id')->get()
            ->each(function (User $user) use (&$purged, &$failed): void {
                try {
                    $this->purge($user);
                    $purged++;
                } catch (\RuntimeException $e) {
                    report($e);
                    $failed++;
                }
            });

        return ['purged' => $purged, 'failed' => $failed];
    }

    /**
     * Stops at the first website that cannot be archived and deleted, so the
     * user and the rest of their data stay for the next run.
     */
    public function purge(User $user): void
    {
        if ($user->purge_after === null || $user->purge_after->isFuture()) {
            throw new \RuntimeException("Account {$user->id} is not due for deletion.");
        }

        $websites = Website::query()->where('assigned_user_id', $user->id)->get();
        $domains = $websites->pluck('domain')->map(fn ($domain) => strtolower(trim((string) $domain)))->filter()->values()->all();

        // FTP accounts are system accounts; the website row's cascade would
        // only remove the panel's record of them.
        FtpAccount::query()->whereIn('website_id', $websites->pluck('id'))->get()
            ->each(function (FtpAccount $account): void {
                $this->ftp->run(['action' => 'delete', 'username' => (string) $account->username]);
                $account->delete();
            });

        $websites->filter(fn (Website $website) => in_array(strtolower((string) $website->type), ['primary', 'main'], true))
            ->each(fn (Website $website) => $this->websites->delete($website, null));
        // Aliases share the primary site's files, which are gone now.
        Website::query()->where('assigned_user_id', $user->id)->delete();

        Mailbox::query()
            ->where(function ($query) use ($user, $domains): void {
                $query->where('created_by', $user->id);
                if ($domains !== []) {
                    $query->orWhereRaw('LOWER(domain) IN ('.implode(',', array_fill(0, count($domains), '?')).')', $domains);
                }
            })
            ->delete();

        $user->delete();
    }
}
