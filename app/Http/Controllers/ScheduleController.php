<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use DB;
use Cache;
use App\SchedTool;
use App\FarmSchedule;
use App\Deliveries;

class ScheduleController extends Controller
{


      /*
      *	Load to truck used by the APIController
      */
      public function loadToTruckAPI($data,$user){

          $data_to_delivery = array();
          $farm_schedule_data = array();
          $unique_id_for_delivery = $this->generator();

          // fetch the data from the batch table
          $batch = DB::table('feeds_batch')
                      //->where('driver_id',NULL)
                      ->where('status','created')
                      ->where('unique_id',$data['unique_id'])
                      ->get();

          SchedTool::where('farm_sched_unique_id',$data['unique_id'])->update(['status'=>'created','delivery_unique_id'=>$unique_id_for_delivery,'driver_id'=>$data['driver_id']]);
          $farm_sched_data = FarmSchedule::select('date_of_delivery')->where('unique_id',$data['unique_id'])->first()->toArray();

          // build the data format to insert to deliveries table
          foreach($batch as $k => $v){

            //$this->updateSchedTool($data['unique_id'],$data['driver_id']);

            $medication = $v->medication == 8 ? 0 : $v->medication;

            $data_to_delivery[] = array(
              'delivery_date'				=>	date("Y-m-d H:i:s",strtotime($farm_sched_data['date_of_delivery'])),
              'truck_id'						=>	$v->truck,
              'farm_id'							=>	$v->farm_id,
              'feeds_type_id'				=>	$v->feed_type,
              'medication_id'				=>	$medication,
              'user_id'							=>	$user,//Auth::id(),
              'driver_id'						=>	$data['driver_id'],
              'amount'							=>	$v->amount,
              'bin_id'							=>	$v->bin_id,
              'compartment_number'	=>	$v->compartment,
              'status'							=>	0,
              'unique_id'						=>	$unique_id_for_delivery,
              'created_at'					=>	date('Y-m-d h:i:s'),
              'updated_at'					=>	date('Y-m-d h:i:s'),
              'delivered'						=>	0,
            );

            $farm_schedule_data[] = array(
              'date_of_delivery'		=>	date("Y-m-d H:i:s",strtotime($farm_sched_data['date_of_delivery'])),
              'truck_id'						=>	$v->truck,
              'farm_id'							=>	$v->farm_id,
              'bin_id'							=>	$v->bin_id,
              'driver_id'						=>	$data['driver_id'],
              'medication_id'				=>	$medication,
              'amount'							=>	$v->amount,
              'feeds_type_id'				=>	$v->feed_type,
              'unique_id'						=>	$data['unique_id'],
              'ticket'							=>	"-",
              'delivery_unique_id'	=>	$unique_id_for_delivery,
              'status'							=>	1,
              'user_id'							=>	$user//Auth::id()
            );

            Cache::forget('bins-'.$v->bin_id);
          }

          // update the feeds_batch (put the driver_id)
          //DB::table('feeds_batch')->where('driver_id',NULL)->where('unique_id',$data['unique_id'])->update(['driver_id'=>$data['driver_id'],'status'=>'loaded']);


          //delete the previous feeds farm data
          FarmSchedule::where('unique_id',$data['unique_id'])->delete();
          // save the data to feeds_farm_schedule
          FarmSchedule::insert($farm_schedule_data);
          // save the data to feeds_sched_tool(with delivery number)

          // sve the data to feeds_deliveries
          Deliveries::insert($data_to_delivery);

          // notify the driver and farmer
          //$this->loadTruckDriverNotification($data_to_delivery,$unique_id_for_delivery);

          $deliveries = Deliveries::where('unique_id','=',$unique_id_for_delivery)->get()->toArray();

          //$this->loadTruckFarmerNotification($deliveries);

          return $data_to_delivery;

      }



      /*
    	*	Unique ID generator
    	*/
    	private function generator(){

      		$unique = uniqid(rand());
      		$dateToday = date('ymdhms');

      		$unique_id = Deliveries::where('unique_id','=',$unique)->exists();

      		$output = ($unique_id == true ? $unique.$dateToday : $unique );

      		return $output;

    	}



      

}
