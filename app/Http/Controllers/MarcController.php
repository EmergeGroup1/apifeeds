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

	//RETURN ALL CME
	public function index()
	{
		return response()->json(Cme::get(), 200);
	}

	public function cmeByID($id)
	{
		return response()->json(Cme::find($id), 200);
	}

	public function cmeUpdate(Request $request, $id)
	{
		$cme = Cme::find($id);
		$cme->update($request->all());
		return response()->json($cme, 200)
			->header('Content-Type', 'application/json');
	}
















	// /**
	//  * Store a newly created resource in storage.
	//  *
	//  * @param  \Illuminate\Http\Request
	//  * @return \Illuminate\Http\Response
	//  */
	// public function store(Request $request)
	// {
	// 	$cme = $request->isMethod('put') ? Cme::findOrFail($request->id) : new Cme;

	// 	$cme->id = $request->input('id');
	// 	$cme->month = $request->input('month');
	// 	$cme->last = $request->input('last');
	// 	$cme->converted_last = $request->input('converted_last');
	// 	$cme->basis = $request->input('basis');
	// 	$cme->bidvalue = $request->input('bidvalue');
	// 	$cme->visibility_ui = $request->input('visibility_ui');

	// 	if ($cme->save()) {
	// 		return $cme;
	// 	}
	// }

	// // Update Visibility
	// public function visibility(Request $request)
	// {
	// 	$cme = $request->isMethod('put') ? Cme::findOrFail($request->id) : new Cme;

	// 	$cme->visibility_ui = $request->input('visibility_ui');
	// 	if ($cme->save()) {
	// 		return $cme;
	// 	}
	// }

	// /**
	//  * RETURN SINGLE CME RECORD.
	//  *
	//  * @param  int  $id
	//  * @return \Illuminate\Http\Response
	//  */
	// public function show($id)
	// {
	// 	// Get CME via id
	// 	$cme = Cme::findOrFail($id);

	// 	//return single cme record
	// 	return $cme;
	// }


	// /**
	//  * Remove the specified resource from storage.
	//  *
	//  * @param  int  $id
	//  * @return \Illuminate\Http\Response
	//  */
	// public function destroy($id)
	// {
	// 	// Get CME record via id
	// 	$cme = Cme::FindOrFail($id);

	// 	// Delete single cme record
	// 	if ($cme->delete()) {
	// 		return $cme;
	// 	}
	// }
}
