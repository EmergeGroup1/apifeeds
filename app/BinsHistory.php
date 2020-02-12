<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BinsHistory extends Model
{

    // Table Used
    protected $table = "feeds_bin_history";

    /**
      * The primary key for the model.
      *
      * @var string
      */
    protected $primaryKey = 'history_id';

    // Mass Assignment
    protected $fillable = [
     'update_date',
     'bin_id',
     'farm_id',
     'num_of_pigs',
     'user_id',
     'amount',
     'update_type',
     'created_at',
     'updated_at',
     'budgeted_amount',
     'remaining_amount',
     'sub_amount',
     'variance',
     'consumption',
     'admin',
     'medication',
     'feed_type'
    ];

}
