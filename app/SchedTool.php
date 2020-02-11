<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SchedTool extends Model
{
    // Table Used
    protected $table = "feeds_sched_tool";


    // Disable timestamps
    public $timestamps = false;

    // Mass Assignment
    protected $fillable = [
      'driver_id',
      'farm_sched_id',
      'delivery_number',
      'start_time',
      'end_time'
    ];
}
