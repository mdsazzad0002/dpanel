<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RedisConfigRevision extends Model {
    public $incrementing = false; protected $keyType = 'string';
    protected $fillable = ['id','website_id','framework','config_path','backup_path','status','created_by','rolled_back_at'];
    protected $casts = ['rolled_back_at' => 'datetime'];
}
