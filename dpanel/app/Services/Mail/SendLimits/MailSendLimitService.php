<?php

namespace App\Services\Mail\SendLimits;

use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\Website;
use App\Services\Mail\Backend\MailBackend;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Backlog P5: the package's outbound mail limit per hour and day.
 *
 * Rspamd counts and refuses (dpanel_ratelimit.lua); the panel only tells it
 * which senders belong to which account and what each account may send, and
 * reads the counters back to show usage and send one alert per refusal
 * window. An account is charged for mail from its mailboxes (SMTP login),
 * from its domains, and from its sites' system users (PHP mail()).
 */
class MailSendLimitService
{
    private const APPLIED_KEY = 'mail-send-limits:applied';

    private const NOTIFIED_KEY = 'mail-send-limits:notified:';

    // Same patterns mail-send-limits.sh checks; anything else is left out
    // so one odd row cannot stop the whole list from being written.
    private const DOMAIN = '/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/';

    private const LOCAL_PART = '/^[a-z0-9!#$%&*+\/=?^_`{|}~.-]{1,64}$/';

    private const SYSTEM_USER = '/^[a-z_][a-z0-9_.-]{0,31}$/';

    public function __construct(
        private readonly MailBackend $mail,
        private readonly MailSendCounters $counters,
        private readonly NotificationService $notifications,
    ) {}

    public static function accountKey(User $user): string
    {
        return 'a'.$user->id;
    }

    /** @return Collection<int, User> */
    public function limitedUsers(): Collection
    {
        return User::query()
            ->whereHas('package', fn ($query) => $query->where('max_emails_per_hour', '>', 0)->orWhere('max_emails_per_day', '>', 0))
            ->with('package')
            ->orderBy('id')
            ->get();
    }

    /**
     * The two maps for MailBackend::setSendLimits, sorted.
     *
     * @return array{senders: string, limits: string}
     */
    public function maps(): array
    {
        $senders = [];
        $limits = [];
        foreach ($this->limitedUsers() as $user) {
            $account = self::accountKey($user);
            $limits[$account] = sprintf('%d:%d', (int) $user->package->max_emails_per_hour, (int) $user->package->max_emails_per_day);

            foreach ($this->sendersOf($user) as $sender) {
                // A sender shared by two accounts stays with the first.
                $senders[$sender] ??= $account;
            }
        }
        ksort($senders, SORT_STRING);
        ksort($limits, SORT_STRING);

        return [
            'senders' => implode("\n", array_map(fn ($key, $account) => "{$key} {$account}", array_keys($senders), $senders)),
            'limits' => implode("\n", array_map(fn ($account, $limit) => "{$account} {$limit}", array_keys($limits), $limits)),
        ];
    }

    /**
     * Writes the maps when they changed, and at least once an hour so a
     * reinstalled Rspamd gets them back.
     *
     * @return array{changed: bool, ready: bool|null, reason: string}
     *
     * @throws \App\Services\Mail\Backend\MailBackendException
     */
    public function sync(bool $force = false): array
    {
        $maps = $this->maps();
        $hash = sha1($maps['senders']."\0".$maps['limits']);
        if (! $force && Cache::get(self::APPLIED_KEY) === $hash) {
            return ['changed' => false, 'ready' => null, 'reason' => ''];
        }

        $state = $this->mail->setSendLimits($maps['senders'], $maps['limits']);
        Cache::put(self::APPLIED_KEY, $hash, now()->addHour());

        return ['changed' => true, ...$state];
    }

    /**
     * Usage of every limited account, for the Mail Health page.
     *
     * @return array{ready: bool, reason: string, counters: bool, accounts: list<array<string, mixed>>}
     */
    public function report(): array
    {
        $users = $this->limitedUsers();
        $now = now();
        $counts = $this->counters->read($users->map(fn (User $user) => self::accountKey($user))->all(), $now);
        $status = $this->mail->sendLimitStatus();

        return [
            'ready' => $status['ready'],
            'reason' => $status['reason'],
            'counters' => $counts !== null,
            'accounts' => $users->map(function (User $user) use ($counts, $now): array {
                $count = $counts[self::accountKey($user)] ?? ['hour' => 0, 'day' => 0, 'hit' => null];

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'package' => $user->package->name,
                    'hour' => $count['hour'],
                    'day' => $count['day'],
                    'per_hour' => (int) $user->package->max_emails_per_hour,
                    'per_day' => (int) $user->package->max_emails_per_day,
                    'held' => $this->currentHit($count['hit'], $now) !== null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * One notification per account and refusal window (this hour, or today).
     *
     * @return int how many notifications were sent
     */
    public function alert(): int
    {
        $users = $this->limitedUsers();
        $now = now();
        $counts = $this->counters->read($users->map(fn (User $user) => self::accountKey($user))->all(), $now);
        if ($counts === null) {
            return 0;
        }

        $sent = 0;
        foreach ($users as $user) {
            $account = self::accountKey($user);
            $hit = $this->currentHit($counts[$account]['hit'] ?? null, $now);
            if ($hit === null || Cache::get(self::NOTIFIED_KEY.$account) === $hit) {
                continue;
            }
            Cache::put(self::NOTIFIED_KEY.$account, $hit, now()->addDays(2));
            $this->notify($user, str_starts_with($hit, 'h:'));
            $sent++;
        }

        return $sent;
    }

    /** The refusal, when it was in the hour or day that is still running. */
    private function currentHit(?string $hit, \Carbon\CarbonInterface $now): ?string
    {
        return in_array($hit, ['h:'.MailSendCounters::hourWindow($now), 'd:'.MailSendCounters::dayWindow($now)], true) ? $hit : null;
    }

    private function notify(User $user, bool $hourly): void
    {
        $package = $user->package;
        $limit = $hourly ? "{$package->max_emails_per_hour} emails per hour" : "{$package->max_emails_per_day} emails per day";
        $this->notifications->create(
            type: 'mail_send_limit',
            status: 'blocked',
            title: 'Email sending limit reached',
            message: "{$user->name} reached the limit of {$limit}. Further mail is held back until the "
                .($hourly ? 'hour' : 'day').' is over (UTC). If this was not expected, a mailbox password or a website may be compromised.',
            assignedUserId: $user->id,
            assignedResellerId: $user->reseller_id,
            subject: $user,
            data: ['window' => $hourly ? 'hour' : 'day', 'per_hour' => (int) $package->max_emails_per_hour, 'per_day' => (int) $package->max_emails_per_day],
        );
    }

    /** @return list<string> map keys charged to this account */
    private function sendersOf(User $user): array
    {
        // Aliases carry only their main site's id, not the owner.
        $siteIds = Website::query()->where('assigned_user_id', $user->id)->pluck('id');
        $domains = Website::query()->whereIn('id', $siteIds)->orWhereIn('parent_id', $siteIds)->pluck('domain')
            ->merge(MailDomain::query()->where('assigned_user_id', $user->id)->pluck('domain'))
            ->map(fn ($domain) => rtrim(strtolower(trim((string) $domain)), '.'))
            ->filter(fn (string $domain) => preg_match(self::DOMAIN, $domain) === 1)
            ->unique()->values();

        $mailboxes = Mailbox::query()
            ->where(fn ($query) => $query->where('created_by', $user->id)->orWhereIn('domain', $domains->all()))
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(function (string $email): bool {
                [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

                return preg_match(self::LOCAL_PART, $local) === 1 && preg_match(self::DOMAIN, $domain) === 1;
            });

        $systemUsers = Website::query()->where('assigned_user_id', $user->id)->whereNotNull('site_owner')->pluck('site_owner')
            ->map(fn ($owner) => strtolower(trim((string) $owner)))
            ->filter(fn (string $owner) => preg_match(self::SYSTEM_USER, $owner) === 1)
            ->map(fn (string $owner) => "user:{$owner}");

        return $domains->merge($mailboxes)->merge($systemUsers)->unique()->values()->all();
    }
}
