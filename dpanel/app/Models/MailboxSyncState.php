<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxSyncState extends Model
{
    protected $fillable = ['mailbox_id', 'folder', 'uid_validity', 'uid_next', 'message_count', 'unseen_count', 'folders', 'folders_synced_at', 'messages_synced_at'];

    protected function casts(): array
    {
        return ['folders' => 'array', 'folders_synced_at' => 'datetime', 'messages_synced_at' => 'datetime'];
    }
}
