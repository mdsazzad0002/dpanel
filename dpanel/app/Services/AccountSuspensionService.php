<?php

namespace App\Services;

use App\Models\CronJob;
use App\Models\FtpAccount;
use App\Models\Mailbox;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\Website;
use App\Services\Cron\CronSystemService;
use App\Services\Platform\SystemAccountSuspender;
use App\Services\Website\LaravelQueueService;

/**
 * The one way an account is suspended, from the panel and from WHMCS alike.
 *
 * Each part changes only what was running: an `active` row becomes
 * `suspended`, and unsuspending turns only `suspended` rows back on, so
 * whatever the owner had turned off stays off.
 */
class AccountSuspensionService
{
    public function __construct(
        private readonly EdgeGatewayReloader $gateway,
        private readonly CronSystemService $cron,
        private readonly LaravelQueueService $queues,
        private readonly SystemAccountSuspender $accounts,
    ) {}

    /** A reseller's customers go with them, and come back with them. */
    public function suspend(User $user): void
    {
        $this->close($user, withReseller: false);
        $user->managedUsers()->where('is_suspended', false)->get()
            ->each(fn (User $customer) => $this->close($customer, withReseller: true));
    }

    /** A customer suspended on their own stays suspended. */
    public function unsuspend(User $user): void
    {
        $this->open($user);
        $user->managedUsers()->where('suspended_with_reseller', true)->get()
            ->each(fn (User $customer) => $this->open($customer));
    }

    private function close(User $user, bool $withReseller): void
    {
        $user->update(['is_suspended' => true, 'suspended_at' => $user->suspended_at ?? now(), 'suspended_with_reseller' => $withReseller]);
        PanelSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->setServingState($user, 'active', 'suspended');
        $this->setCronStatus($user, 'active', 'suspended');
        $this->syncQueueWorkers($user->refresh());
        $this->eachSystemAccount($user, fn (string $account) => $this->accounts->suspend($account));
        $this->setMailboxStatus($user, 'active', 'suspended');
        $this->setFtpStatus($user, 'active', 'suspended', fn (string $account) => $this->accounts->suspend($account));
    }

    private function open(User $user): void
    {
        // Unsuspending a terminated account also cancels its deletion.
        $user->update(['is_suspended' => false, 'suspended_at' => null, 'suspended_with_reseller' => false, 'terminated_at' => null, 'purge_after' => null]);
        $this->setServingState($user, 'suspended', 'active');
        $this->setCronStatus($user, 'suspended', 'active');
        $this->syncQueueWorkers($user->refresh());
        $this->eachSystemAccount($user, fn (string $account) => $this->accounts->unsuspend($account));
        $this->setMailboxStatus($user, 'suspended', 'active');
        $this->setFtpStatus($user, 'suspended', 'active', fn (string $account) => $this->accounts->unsuspend($account));
    }

    /** A site over its limit stays that way: suspension does not own that state. */
    private function setServingState(User $user, string $from, string $to): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->where('serving_state', $from);
        $domains = $websites->pluck('domain')->all();
        $websites->update(['serving_state' => $to]);
        $this->gateway->reloadDomains($domains);
    }

    /**
     * A job the owner turned off stays off. When drust cannot be reached the
     * job is still marked, so the account is suspended either way; the error
     * is reported and the next sync of that job removes it.
     */
    private function setCronStatus(User $user, string $from, string $to): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->get()->keyBy('id');
        CronJob::query()->whereIn('website_id', $websites->keys())->where('status', $from)->get()
            ->each(function (CronJob $job) use ($to, $websites): void {
                $job->update(['status' => $to]);
                try {
                    $this->cron->sync($job, $websites[$job->website_id]);
                } catch (\RuntimeException $e) {
                    report($e);
                }
            });
    }

    /** `sync` reads the owner's suspension, so the same call stops and restarts. */
    private function syncQueueWorkers(User $user): void
    {
        Website::query()->where('assigned_user_id', $user->id)->whereHas('queueWorkers')->get()
            ->each(function (Website $website): void {
                try {
                    $this->queues->sync($website);
                } catch (\RuntimeException $e) {
                    report($e);
                }
            });
    }

    /** One call per account: several of the user's websites can share one. */
    private function eachSystemAccount(User $user, \Closure $change): void
    {
        Website::query()->where('assigned_user_id', $user->id)->pluck('site_owner')
            ->map(fn ($owner) => trim((string) $owner))
            ->filter(fn (string $owner) => SystemAccountSuspender::suspendable($owner))
            ->unique()
            ->each(function (string $account) use ($change): void {
                try {
                    $change($account);
                } catch (\RuntimeException $e) {
                    report($e);
                }
            });
    }

    /**
     * Postfix and Dovecot only see `active` mailboxes, so a suspended one can
     * neither log in nor receive mail. The account's mail is every mailbox
     * on its websites' domains and every mailbox it created.
     */
    private function setMailboxStatus(User $user, string $from, string $to): void
    {
        $domains = Website::query()->where('assigned_user_id', $user->id)->pluck('domain')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))->filter()->unique()->values()->all();
        Mailbox::query()
            ->where('status', $from)
            ->where(function ($query) use ($user, $domains): void {
                $query->where('created_by', $user->id);
                if ($domains !== []) {
                    $query->orWhereRaw('LOWER(domain) IN ('.implode(',', array_fill(0, count($domains), '?')).')', $domains);
                }
            })
            ->update(['status' => $to]);
    }

    /**
     * An FTP account is a system account of its own, so drust blocks its
     * login the same way as the site owner's. The row is marked even when
     * drust cannot be reached; the error is reported.
     */
    private function setFtpStatus(User $user, string $from, string $to, \Closure $change): void
    {
        $websites = Website::query()->where('assigned_user_id', $user->id)->pluck('id');
        FtpAccount::query()->whereIn('website_id', $websites)->where('status', $from)->get()
            ->each(function (FtpAccount $account) use ($to, $change): void {
                $account->update(['status' => $to]);
                try {
                    $change((string) $account->username);
                } catch (\RuntimeException $e) {
                    report($e);
                }
            });
    }
}
