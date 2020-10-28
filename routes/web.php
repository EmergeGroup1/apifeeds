<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Route::get('/', function () {
//     return "Nothing to do here...";
// });

Route::match(array('POST', 'GET'), '/', 'APIController@index');
Route::match(array('POST', 'GET'), 'api', 'APIController@index');
Route::get('conautoupdate', 'HomeController@conAutoUpdate');
Route::get('schedulingcache', 'ScheduleController@scheduleCache');
Route::get('forecastingdata', 'HomeController@forecastingDataOutput');
Route::get('forecastdata', 'HomeController@forecastingDataCache');
Route::get('binscachebuilder', 'HomeController@binsDataCacheBuilder');
Route::get('binclearcache/{id}', 'HomeController@clearBinsCache');
Route::get('historylatest', 'HomeController@cacheBinHistoryLatest');
Route::get('ugn', 'AnimalMovementController@updateGroupName');
Route::get('pigtrackerdata', 'AnimalMovementController@listAPI');
Route::get('hello', 'MarcController@hello');
// lIST ALL CME
Route::get('cmes', 'MarcController@index');
// LIST SINGLE CME RECORD
Route::get('cme/{id}', 'CmeController@show');
// CREATE NEW CME RECORD
Route::post('cme', 'MarcController@store');
// UPDATE CME RECORD
Route::put('cme/{id}', 'MarcController@store');
//UPDATE VISIBILITY
Route::put('cme/{id}', 'MarcController@visibility');
// DELETE CME RECORD
Route::delete('cme/{id}', 'MarcController@destroy');
