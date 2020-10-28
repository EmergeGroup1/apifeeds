<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Cache;
use DB;
use Storage;
use Carbon\Carbon;
use App\Cme;
use App\Http\Resources\Cme as CmeResource;

class MarcController extends Controller
{
	public function hello()
	{

		//GET CME
    $cmes = Cme::all();

    // RETURN collection of CME as a resource
    return CmeResource::collection($cmes);

		// return 'hello this is firing';
	}
}
