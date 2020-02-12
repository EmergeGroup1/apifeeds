<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FarmSchedule extends Model
{
    // Table Used
    protected $table = "feeds_farm_schedule";

    /**
      * The primary key for the model.
      *
      * @var string
      */
    protected $primaryKey = 'schedule_id';


    // Disable timestamps
    public $timestamps = false;

    // Mass Assignment
    protected $fillable = [
     'date_of_delivery',
     'truck_id',
     'farm_id'
    ];
}
