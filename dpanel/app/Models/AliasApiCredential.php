<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AliasApiCredential extends Model {
    public $incrementing=false; protected $keyType='string';
    protected $fillable=['id','website_id','token_hash','token_hint','challenge_token','enabled','created_by','last_used_at'];
    protected $hidden=['token_hash']; protected $casts=['enabled'=>'boolean','last_used_at'=>'datetime'];
}
