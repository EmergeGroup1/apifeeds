<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// lIST ALL CME
Route::get('cmes', 'CmeController@index');
// LIST SINGLE CME RECORD
Route::get('cme/{id}', 'CmeController@show');
// CREATE NEW CME RECORD
Route::post('cme', 'CmeController@store');
// UPDATE CME RECORD
Route::put('cme/{id}', 'CmeController@store');
//UPDATE VISIBILITY
Route::put('cme/{id}', 'CmeController@visibility');
// DELETE CME RECORD
Route::delete('cme/{id}', 'CmeController@destroy');
