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


//get ALL CME
Route::get('cmes', 'MarcController@index');
// get SINGLE CME RECORD
Route::get('cmee/{id}', 'MarcController@cmeByID');
//update CME
Route::post('cme/{id}', 'MarcController@cmeUpdate');
//update CME ui visibility
Route::post('cme/v/{id}', 'MarcController@visibility');
//select non-zero 'last' values
Route::get('cmezerolast', 'MarcController@excludeZeroValues');
//add month
Route::post('addmonth', 'MarcController@store');
// change isDeleted status
Route::post('cme/del/{id}', 'MarcController@deleteStatus');





// // CREATE NEW CME RECORD
// Route::post('cme', 'CmeController@store');
// // UPDATE CME RECORD
// Route::post('cme/{id}', 'CmeController@store');
// //UPDATE VISIBILITY
// Route::post('cme/v/{id}', 'CmeController@visibility');
// // DELETE CME RECORD
// Route::delete('cme/{id}', 'MarcController@destroy');
