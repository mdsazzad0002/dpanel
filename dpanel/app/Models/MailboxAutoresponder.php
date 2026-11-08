<?php

namespace App\Models;

use App\Services\Mail\Backend\AutoresponderData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mailbox's automatic reply (backlog M1). */
class MailboxAutoresponder extends Model
{
    protected $fillable = ['mailbox_id', 'enabled', 'subject', 'body', 'interval_days', 'starts_on', 'ends_on'];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_days' => 'integer',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function data(): AutoresponderData
    {
        return new AutoresponderData(
            (string) $this->subject,
            (string) $this->body,
            (int) $this->interval_days,
            $this->starts_on?->format('Y-m-d'),
            $this->ends_on?->format('Y-m-d'),
        );
    }
}
