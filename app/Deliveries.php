<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Deliveries extends Model
{
    // Table Used
    protected $table = "feeds_deliveries";

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'delivery_id';

    // Mass Assignment
    protected $fillable = [
      'delivery_date',
      'truck_id',
      'farm_id',
      'feeds_type_id',
      'batch_code',
      'amount',
      'bins',
      'driver_id',
      'user_id',
      'status'
    ];
}
