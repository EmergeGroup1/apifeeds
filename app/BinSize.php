<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BinSize extends Model
{

  // Table Used
  protected $table = "feeds_bin_sizes";

  //disable timestamps
   public $timestamps = false;

  /**
     * The primary key for the model.
     *
     * @var string
     */
  protected $primaryKey = 'size_id';

  // Mass Assignment
  protected $fillable = [
    'name',
    'ring'
  ];

}
