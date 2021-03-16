<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\FeedTypes;
use Auth;
use DB;
use Cache;

class FeedTypeController extends Controller
{

  /**
    * Display a listing of the resource.
    *
    * @return Response
    */
     public function apiListAll()
     {
         $feedTypes = DB::table('feeds_feed_types')
               ->select('feeds_feed_types.*','feeds_feed_type_budgeted_amount_per_day.*')
               ->where('name','!=','None')
               ->where('status','show')
               ->leftJoin('feeds_feed_type_budgeted_amount_per_day','feeds_feed_type_budgeted_amount_per_day.feed_type_id','=','feeds_feed_types.type_id')
               ->latest()
               ->get();

         $feedTypes = $this->toArray($feedTypes);

         return $feedTypes;
     }

     /**
     *  Build the feed types data
     *
     */
     private function apiGetBuild($data)
     {
        $output = array();
        for($i=0; $i<count($data); $i++){
          $output = array(

          );
        }

        return $output;
     }

    /**
     * Convert object to array
     *
     * @return Response
     */
    private function toArray($data)
    {
  		$resultArray = json_decode(json_encode($data), true);

  		return $resultArray;
  	}

    /**
     * API for creating feed type.
     *
     * @param  $data
     * @return Response
     */
    public function apiCreate($data)
    {
    		Cache::forget('feed_types');
        $check = FeedTypes::where('name', '=', $data['name'])->exists();

        if($check){
          return "Feed type has same name";
        }

        DB::table('feeds_feed_types')->insert($data);
        $latest = DB::table('feeds_feed_types')->select('type_id')->orderBy('type_id','desc')->first();
        DB::table('feeds_feed_type_budgeted_amount_per_day')->insert(array('feed_type_id' => $latest->type_id));


        return $data;

    }

    /**
     * API for updating feed type.
     *
     * @param  $data
     * @return Response
     */
    public function apiUpdate($data,$type_id)
    {
    		Cache::forget('feed_types');
        $check = FeedTypes::where('name', '=', $data['name'])
                          ->where('type_id', '!=', $type_id)
                          ->exists();

        if($check){
          return "Feed type has same name";
        }

        DB::table('feeds_feed_types')->where('type_id',$type_id)->update($data);

        if($data['total_days'] == 0){
          $days = $this->daysDefault();
          DB::table('feeds_feed_type_budgeted_amount_per_day')->where('feed_type_id',$type_id)->update($days);
        }

		    return $data;

    }

    /**
     * API for updating feed type.
     *
     * @param  $data
     * @return Response
     */
    public function apiUpdateDaysAmount($days_amount,$feed_type_id)
    {
        $data = array();

        foreach($days_amount as $k => $v){
          if($k != 'feed_type_id'){
            $data[$k] = $v;
          }
        }

        DB::table('feeds_feed_type_budgeted_amount_per_day')->where('feed_type_id',$feed_type_id)->update($data);

        return "success";
    }


    /**
     * days value default to zero
     *
     * @return Response
     */
    private function daysDefault(){
      $data = array();

      for($i=1; $i<=31; $i++){
        $data['day_'.$i] = 0;
      }

      return $data;
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function saveBudgedtedPerDay()
    {
        $budgeted_per_day = Input::all();
        $data = array();

        foreach($budgeted_per_day as $k => $v){
          if($k != 'feed_type_id'){
            $data[$k] = $v;
          }
        }

        $feed_type_id = $budgeted_per_day['feed_type_id'];
        DB::table('feeds_feed_type_budgeted_amount_per_day')->where('feed_type_id',$feed_type_id)->update($data);

		    return redirect('feedtype');
    }



    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function apiDelete($feed_type_id)
    {
  		Cache::forget('feed_types');
  		FeedTypes::findOrFail($feed_type_id)->delete();
      DB::table('feeds_feed_type_budgeted_amount_per_day')->where('feed_type_id',$feed_type_id)->delete();

  		return "deleted";
    }



}
