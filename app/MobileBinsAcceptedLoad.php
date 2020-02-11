<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class MobileBinsAcceptedLoad extends Model
{
  
  // Table Used
  protected $table = "feeds_bins_accepted_load";

  // Mass Assignment
  protected $fillable = [
     'bin_id',  //bin number
     'farm_id',
     'user_id',
     'current_amount',
     'created_at',
     'budgeted_amount',
     'actual_amount',
     'bin_size',
     'variance',
     'consumption',
     'feed_type',
     'medication',
     'med_name',
     'feed_name',
     'user_created_at',
     'num_of_pigs',
     'bin_no_id', // bin id
     'status',
     'unique_id'
  ];

}
