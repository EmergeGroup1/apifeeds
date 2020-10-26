<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Cache;
use DB;
use Storage;
use Carbon\Carbon;

class MarcController extends Controller
{

    /*
  	* Test Method
  	*/
  	public function inputBasis(Request $id, $value)
  	{

      DB::update('update tbldownload set basis = ? where id = ?',[$value,$id]);

  	}

}
