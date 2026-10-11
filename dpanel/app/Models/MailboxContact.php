<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxContact extends Model
{
    protected $fillable = ['mailbox_id', 'email', 'name', 'starred', 'last_used_at'];

    protected function casts(): array
    {
        return ['starred' => 'boolean', 'last_used_at' => 'datetime'];
    }

    /** @return array{id: int, email: string, name: string, saved: bool} */
    public function toClient(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'name' => (string) $this->name, 'saved' => $this->starred];
    }
}
