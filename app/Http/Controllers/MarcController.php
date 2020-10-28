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
	public function hello()
	{
		return 'hello this is firing';
	}
}
