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

Route::match(array('POST','GET'),'/', 'APIController@index');
Route::match(array('POST','GET'),'api', 'APIController@index');
Route::get('conautoupdate','HomeController@conAutoUpdate');
Route::get('schedulingcache','ScheduleController@scheduleCache');
Route::get('forecastingdata','HomeController@forecastingDataOutput');
Route::get('forecastdata','HomeController@forecastingDataCache');
Route::get('binscachebuilder','HomeController@binsDataCacheBuilder');
Route::get('binclearcache/{id}','HomeController@clearBinsCache');
Route::get('historyamount','HomeController@cacheBinHistoryAmount');
