<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxMessageMetadata extends Model
{
    protected $table = 'mailbox_message_metadata';

    protected $fillable = ['mailbox_id', 'folder', 'uid', 'subject', 'sender', 'message_date', 'seen', 'size', 'snippet', 'synced_at'];

    protected function casts(): array
    {
        return ['uid' => 'integer', 'seen' => 'boolean', 'size' => 'integer', 'synced_at' => 'datetime'];
    }
}
