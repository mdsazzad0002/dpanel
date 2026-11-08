<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A panel name, logo and colour: the server's (owner_id null) or a reseller's (backlog U4). */
class Branding extends Model
{
    protected $fillable = ['owner_id', 'panel_name', 'logo_path', 'primary_color'];
}
