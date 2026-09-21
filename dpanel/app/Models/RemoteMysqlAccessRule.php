<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RemoteMysqlAccessRule extends Model {
    public $incrementing=false; protected $keyType='string';
    protected $fillable=['id','ip_address','note','created_by'];
}
