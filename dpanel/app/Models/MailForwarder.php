<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An address without a mailbox whose mail goes on to other addresses (backlog M3). */
class MailForwarder extends Model
{
    use HasUuids;

    protected $fillable = ['address', 'domain', 'destinations', 'status', 'created_by', 'assigned_reseller_id'];

    /** @return list<string> */
    public function destinationList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->destinations))));
    }

    /** The same rule as mailboxes: admins see all, others what they made or resell. */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->hasRole('admin')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($actor): void {
            $query->where('created_by', $actor->id);
            if ($actor->hasRole('reseller')) {
                $query->orWhere('assigned_reseller_id', $actor->id);
            }
        });
    }
}
