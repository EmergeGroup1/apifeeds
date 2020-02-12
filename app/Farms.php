<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Farms extends Model
{
    // Table Used
    protected $table = "feeds_farms";

    // Mass Assignment
    protected $fillable = [
    'id',
    'name',
    'packer',
    'farm_type',
    'address',
    'delivery_time',
    'lattitude',
    'longtitude',
    'contact',
    'notes',
    'owner',
    'update_notification'
    ];
}
