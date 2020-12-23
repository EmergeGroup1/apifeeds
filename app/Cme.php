<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cme extends Model
{
    protected $table = 'cme';
    protected $fillable = [
        'month',
        'last',
        'converted_last',
        'visibility_ui',
        'basis',
        'bidvalue',
        'isDeleted',
        'isManual'
    ];
}
