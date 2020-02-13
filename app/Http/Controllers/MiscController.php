<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use DB;
use Validator;
use Session;
use App\BinsHistory;
use App\FarmSchedule;
use App\Deliveries;
use Auth;
use App\User;
use Cache;

class MiscController extends Controller
{

    /**
     * Get latest release notes for api
     *
     */
    public function apiGetReleaseNotes()
    {
      $release_notes = DB::table('feeds_release_notes')->orderBy('id','desc')->first();

      if($release_notes != NULL){
        $user = DB::table('feeds_release_notes_entries')
                ->where('release_notes_id',$release_notes->id)
                ->where('user_id',1)
                ->first();
        if($user == NULL){
          $data = array(
            'id'  => $release_notes->id,
            'description' => $release_notes->description
          );
          return $data;
        } else {
          return NULL;
        }

      }

      return NULL;

    }

    /**
     * API for Save Release Notes
     *
     */
    public function apiSaveReleaseNotes($description)
    {
      $date = date("Y-m-d H:i:s");
      $data = array(
        'created_date'  =>  $date,
        'description'   =>  Input::get('description')
      );

      if(DB::table('feeds_release_notes')->insert($data)){
        return "success";
      } else {
        return "failed";
      }
    }

    /**
     * API for Update Release Notes
     *
     */
    public function apiUpdateReleaseNotes($rn_id,$user_id)
    {
      $data = array(
        'release_notes_id'  =>  $rn_id,
        'user_id'           =>  $user_id
      );
      DB::table('feeds_release_notes_entries')->insert($data);
    }

}
