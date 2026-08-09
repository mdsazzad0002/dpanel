<?php

namespace App\Services;

use App\Models\DatabaseRequest;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\User;
use App\Models\Website;
use Illuminate\Validation\ValidationException;

class ResourceQuotaService
{
    public function ownerForDomain(string $domain): ?User
    {
        $domain = strtolower(trim($domain));
        $userId = Website::query()->whereRaw('LOWER(domain) = ?', [$domain])->value('assigned_user_id')
            ?? MailDomain::query()->whereRaw('LOWER(domain) = ?', [$domain])->value('assigned_user_id');

        return $userId ? User::query()->with('package')->find($userId) : null;
    }

    public function assertMailboxAllowed(User $user, int $quotaMb, bool $hasForwarding = false, ?string $exceptMailboxId = null): void
    {
        $package = $user->loadMissing('package')->package;

        if ($package !== null && $hasForwarding && ! $package->allow_forwarding) {
            throw ValidationException::withMessages(['forwarding_to' => 'This package does not allow email forwarding.']);
        }
        if ($package !== null && $quotaMb > (int) $package->max_storage_mb) {
            throw ValidationException::withMessages([
                'quota_mb' => "A single mailbox cannot exceed the package storage limit ({$package->max_storage_mb} MB).",
            ]);
        }

        $domains = $this->ownedDomains($user);
        $query = Mailbox::query()->whereIn('domain', $domains);
        if ($exceptMailboxId !== null) {
            $query->whereKeyNot($exceptMailboxId);
        }

        if ($package !== null && (int) $query->count() >= (int) $package->max_mailboxes) {
            throw ValidationException::withMessages(['mailbox' => "Package mailbox limit reached ({$package->max_mailboxes})."]);
        }

        $reseller = $this->quotaReseller($user);
        if ($reseller === null) {
            return;
        }
        $resellerDomains = $this->ownedDomainsForUsers($this->resellerUserIds($reseller));
        $resellerQuery = Mailbox::query()->whereIn('domain', $resellerDomains);
        if ($exceptMailboxId !== null) {
            $resellerQuery->whereKeyNot($exceptMailboxId);
        }
        if ((int) $resellerQuery->count() >= (int) $reseller->package->max_mailboxes) {
            throw ValidationException::withMessages(['mailbox' => "Reseller mailbox quota reached ({$reseller->package->max_mailboxes})."]);
        }
    }


    public function assertWebsiteAllowed(User $user, bool $isAlias = false): void
    {
        $package = $user->loadMissing('package')->package;
        if ($package !== null && $isAlias && ! $package->allow_aliases) {
            throw ValidationException::withMessages(['domain' => 'This package does not allow alias domains.']);
        }

        if ($isAlias) {
            return;
        }

        $used = Website::query()->where('assigned_user_id', $user->id)
            ->whereNotIn('type', ['alis', 'alias'])->count();
        if ($package !== null && $used >= (int) $package->max_websites) {
            throw ValidationException::withMessages(['assigned_user_id' => "Package website limit reached ({$package->max_websites})."]);
        }

        $reseller = $this->quotaReseller($user);
        if ($reseller !== null) {
            $resellerUsed = Website::query()->whereIn('assigned_user_id', $this->resellerUserIds($reseller))
                ->whereNotIn('type', ['alis', 'alias'])->count();
            if ($resellerUsed >= (int) $reseller->package->max_websites) {
                throw ValidationException::withMessages(['assigned_user_id' => "Reseller website quota reached ({$reseller->package->max_websites})."]);
            }
        }
    }

    public function assertDatabaseAllowed(User $user): void
    {
        $package = $user->loadMissing('package')->package;
        $used = DatabaseRequest::query()->where('assigned_user_id', $user->id)->count();
        if ($package !== null && $used >= (int) $package->max_databases) {
            throw ValidationException::withMessages(['database_name' => "Package database limit reached ({$package->max_databases})."]);
        }

        $reseller = $this->quotaReseller($user);
        if ($reseller !== null) {
            $resellerUsed = DatabaseRequest::query()->whereIn('assigned_user_id', $this->resellerUserIds($reseller))->count();
            if ($resellerUsed >= (int) $reseller->package->max_databases) {
                throw ValidationException::withMessages(['database_name' => "Reseller database quota reached ({$reseller->package->max_databases})."]);
            }
        }
    }

    /** @return array<int, string> */
    private function ownedDomains(User $user): array
    {
        return Website::query()->where('assigned_user_id', $user->id)->pluck('domain')
            ->merge(MailDomain::query()->where('assigned_user_id', $user->id)->pluck('domain'))
            ->map(fn ($domain) => strtolower(trim((string) $domain)))->filter()->unique()->values()->all();
    }

    private function quotaReseller(User $user): ?User
    {
        $reseller = $user->reseller_id
            ? User::query()->with('package')->find($user->reseller_id)
            : ($user->hasRole('reseller') ? $user->loadMissing('package') : null);

        return $reseller?->package === null ? null : $reseller;
    }

    /** @return array<int, int> */
    private function resellerUserIds(User $reseller): array
    {
        return User::query()->whereKey($reseller->id)
            ->orWhere('reseller_id', $reseller->id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<int, int> $userIds
     *  @return array<int, string>
     */
    private function ownedDomainsForUsers(array $userIds): array
    {
        return Website::query()->whereIn('assigned_user_id', $userIds)->pluck('domain')
            ->merge(MailDomain::query()->whereIn('assigned_user_id', $userIds)->pluck('domain'))
            ->map(fn ($domain) => strtolower(trim((string) $domain)))->filter()->unique()->values()->all();
    }
}
