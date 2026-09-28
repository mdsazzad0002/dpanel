<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxMessageMetadata extends Model
{
    protected $table = 'mailbox_message_metadata';

    protected $fillable = ['mailbox_id', 'folder', 'uid', 'subject', 'sender', 'recipient', 'message_date', 'seen', 'size', 'snippet', 'body_text', 'synced_at'];

    protected function casts(): array
    {
        return ['uid' => 'integer', 'seen' => 'boolean', 'size' => 'integer', 'synced_at' => 'datetime'];
    }
}
