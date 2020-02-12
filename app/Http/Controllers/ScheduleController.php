<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use DB;
use Cache;
use Artisan;
use App\SchedTool;
use App\FarmSchedule;
use App\Deliveries;
use App\Farms;
use App\User;

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



      /*
    	*	Data output for sched tool bar
    	*/
    	public function schedToolOutputAPI($delivery_date=NULL){

      		$delivery_date = !empty($delivery_date) ? $delivery_date : date("Y-m-d",strtotime(Input::get('selected_data')));

      		$stDrivers = SchedTool::select(DB::raw('DISTINCT(driver_id) as driver_id,
      								(SELECT username FROM feeds_user_accounts WHERE id = driver_id) as driver_name'))
      								->where('delivery_date','=',$delivery_date)
      								->get()->toArray();
      		$data = array();
      		for($i = 0; $i < count($stDrivers); $i++){

      			$data[] = array(
      					'driver_id'	=> $stDrivers[$i]['driver_id'],
      					'title'			=> $stDrivers[$i]['driver_name'],
      					'eta'				=> $this->deliveriesETAPerDriver($stDrivers[$i]['driver_id'],$delivery_date),
      					'schedule'	=> $this->schedToolLevelTwoAPI($stDrivers[$i]['driver_id'],$delivery_date),
      					'dar'				=> $this->driverActivityReport($delivery_date)
      				);
      		}


      		return $data;

    	}




      /*
    	*	ETA Detector per driver
    	*/
    	private function deliveriesETAPerDriver($driver_id,$delivery_date)
    	{

      		$schedData = SchedTool::select('driver_id','delivery_number','status','farm_sched_unique_id','delivery_unique_id','start_time','end_time',DB::raw('farm_title as text'))
      								->where('driver_id','=',$driver_id)
      								->where('delivery_date','=',$delivery_date)
      								->whereNotIn('status',['scheduled','delivered','created'])
      								->orderBy('start_time','asc')
      								->get()->toArray();

      		$output = array();
      		for($i = 0; $i < count($schedData); $i++){

      			$status = $schedData[$i]['status']; //!empty($schedData[$i]['delivery_unique_id']) ? $this->deliveriesStatus($schedData[$i]['delivery_unique_id']) : $schedData[$i]['status'];
      			$scheduled_start_time = date("H:i",strtotime($schedData[0]['start_time']));
      			$scheduled_end_time = date("H:i",strtotime($schedData[$i]['end_time']));
      			$farms_delivery_hours = $this->farmsDeliveryHours($schedData[$i]['farm_sched_unique_id']);
      			$delivery_ETA = $this->deliveriesETA($status,$schedData[$i]['delivery_unique_id'],$scheduled_end_time,$farms_delivery_hours);

      			$output[] = array(
      				'status'	=>	$status,
      				'start_time'	=>	$scheduled_start_time,
      				'end_time'		=>	$scheduled_end_time,
      				'farms_hours'	=> $farms_delivery_hours,
      				'delivery_eta'	=>	$delivery_ETA,
      				'delivery_eta_combined_farm'	=>	date("h:i a",strtotime($delivery_ETA))//date("h:i a",strtotime($delivery_ETA."+ ".$farms_delivery_hours))
      			);

      		}

      		//return json_encode($output);
      		if($output != NULL){
      			if($output[count($schedData) - 1]['status'] == 'unloaded'){
      				return date("h:i a",strtotime($output[count($schedData) - 1]['delivery_eta']));
      			}
      		}


      		return $output != NULL ? $output[count($schedData) - 1]['delivery_eta_combined_farm'] : "--:--";

    	}




      /*
    	*	ETA Detector
    	*/
    	private function deliveriesETA($status,$deliveries_unique_id,$scheduled_end_time,$farms_delivery_hours)
    	{

      		$end_time = $scheduled_end_time;

      		if($status == "pending"){

      			//When the driver accepted the load and 10 minutes after the driver accepted the load (10 mins interval)
      			$end_time = $this->deliveryETAAcceptLoadAndTenMinutesAfter($deliveries_unique_id);

      		} else if($status == "ongoing"){

      			$end_time = $this->deliveryETAAcceptLoadAndTenMinutesAfter($deliveries_unique_id);

      		} else if($status == "unloaded"){

      			$end_time = $this->deliveryETAUnloadLastAndTenMinutesAfter($deliveries_unique_id);

      		}else{
      			$end_time = $end_time;
      		}

      		return $end_time;

    	}




    	/*
    	*	ETA every 10 minutes after the truck leaves the Mill (10 mins interval)
    	*/
    	private function deliveryETAAcceptLoadAndTenMinutesAfter($deliveries_unique_id)
    	{

      		$ten_mins_interval = DB::table('feeds_driver_stats_drive_time_interval')->where('deliveries_unique_id',$deliveries_unique_id)->orderBy('id','desc')->get();

      		if($ten_mins_interval != NULL){
      			return date("H:i",strtotime($ten_mins_interval[0]->eta));
      		}

      		return NULL;

    	}




    	/*
    	*	ETA every 10 minutes after the truck leaves the Mill (10 mins interval)
    	*/
    	private function deliveryETAUnloadLastAndTenMinutesAfter($deliveries_unique_id)
    	{

      		$ten_mins_interval = DB::table('feeds_driver_stats_drive_time_interval_mill')->where('deliveries_unique_id',$deliveries_unique_id)->orderBy('id','desc')->get();

      		if($ten_mins_interval != NULL){
      			return date("H:i",strtotime($ten_mins_interval[0]->eta));
      		}

      		return NULL;

    	}




      /*
    	*	farms delivery timnes
    	*/
    	private function farmsDeliveryHours($unique_id)
    	{

      		$data = FarmSchedule::select(DB::raw("GROUP_CONCAT(farm_id) AS farm_id"))
      							->where("unique_id","=",$unique_id)
      							->get()->toArray();

      		$farm = array_unique(explode(",",(string)$data[0]['farm_id']));
      		$output = Farms::select('delivery_time')->whereIn('id',$farm)->sum('delivery_time');
      		$output = number_format((float)$output, 2, '.', '');

      		$delivery_time = $output;
      		list($hours, $wrongMinutes) = explode('.', $delivery_time);
      		$minutes = ($wrongMinutes < 100 ? $wrongMinutes * 100 : $wrongMinutes) * 0.6 / 100;
      		$calculated_hour = $hours . ' hours ' . ceil($minutes) . ' minutes';

      		return $calculated_hour;

    	}



      /*
    	*	scheduled data
    	*/
    	private function schedToolLevelTwoAPI($driver_id,$delivery_date){

      		$schedData = SchedTool::select('driver_id','delivery_number','status','farm_sched_unique_id','delivery_unique_id','start_time','end_time',DB::raw('farm_title as text'))
      								->where('driver_id','=',$driver_id)
      								->where('delivery_date','=',$delivery_date)
      								->get()->toArray();

      		for($i = 0; $i < count($schedData); $i++){

      			$output[] = array(
      				'start'				=>	date("H:i",strtotime($schedData[$i]['start_time'])),
      				'end'					=>	date("H:i",strtotime($schedData[$i]['end_time'])),
      				'text'				=>	$schedData[$i]['text'],
      				'data'				=> 	array(
      											'delivery_number'	=>	$schedData[$i]['delivery_number'],
      											'unique_id'			=>	$schedData[$i]['farm_sched_unique_id'],
      											'driver_id'			=>	$schedData[$i]['driver_id'],
      											'status'			=>	$this->statusSchedToolAPI($schedData[$i]['status'],$schedData[$i]['delivery_unique_id'])
      											)
      			);

      		}

      		return $output;

    	}


      /*
    	*	scheduled data status
    	*/
    	private function statusSchedToolAPI($status,$delivery_unique_id){

      		if($status == 'scheduled') {

      			$status = "created";

      		} else if($status == 'ongoing'){

      			$status = "ongoing_green";

      		} else if($status == 'unloaded'){

      			$status = "ongoing_red";

      		} else if($status == 'pending'){

      			$status = "ongoing_green";

      		} else if($status == 'delivered'){

      			$status = "completed";

      		} else {

      			$status = $status;

      		}

      		return $status;

    	}


      /*
    	*	Driver Activity Report
    	*/
    	private function driverActivityReport($delivery_date)
    	{

      		// always get the scheduled and created just get the last 2 data
      		$schedData = SchedTool::select('id','driver_id','delivery_number','status','farm_sched_unique_id','delivery_unique_id','start_time','end_time',DB::raw('farm_title as text'))
      								//->where('driver_id','=',$driver_id)
      								->where('delivery_date','=',$delivery_date)
      								//->whereIn('status',['scheduled','delivered','created'])
      								->orderBy('start_time','asc')
      								->get()->toArray();

      		$data = array();
      		$exclude = array();

      		if($schedData != NULL){
      			for($i=0; $i < count($schedData); $i++){

      				$next_delivery_start_time = "--:--";
      				$farms_delivery_hours = $this->farmsDeliveryHours($schedData[$i]['farm_sched_unique_id']);
      				$farms_delivery_hours = str_replace("hours","h",$farms_delivery_hours);
      				$farms_delivery_hours = str_replace(" h","h",$farms_delivery_hours);
      				$farms_delivery_hours = str_replace("minutes","m",$farms_delivery_hours);
      				$farms_delivery_hours = str_replace(" m","m",$farms_delivery_hours);

      				$exclude[] = array($schedData[$i]['id']);
      				// next delivery for driver
      				$next_delivery_start_time_query = SchedTool::where('driver_id',$schedData[$i]['driver_id'])
      																							->whereNotIn('id',$exclude)
      																							->where('delivery_date','=',$delivery_date)
      																							->orderBy('start_time','asc')
      																							->value('start_time');

      				if($next_delivery_start_time_query != NULL){
      					$next_delivery_start_time = date("g:i a",strtotime($next_delivery_start_time_query));
      				}

      				$actual_time_back = "--:--"; // get the end time on feeds_driver_stats_delivery_time
      				$end_time_driver_stats = DB::table('feeds_driver_stats_delivery_time')->select('end_time')->where('deliveries_unique_id',$schedData[$i]['delivery_unique_id'])->orderBy('id','desc')->first();
      				if($end_time_driver_stats != NULL){
      					//$actual_time_back = $end_time_driver_stats->end_time;
      					if($end_time_driver_stats->end_time != "0000-00-00 00:00:00"){
      						$actual_time_back = date("g:i a",strtotime($end_time_driver_stats->end_time));
      					}
      				}

      				$data[] = array(
      					'driver_name'				=> 	User::where('id',$schedData[$i]['driver_id'])->value('username'),
      					'start_time'				=>	date("g:i a",strtotime($schedData[$i]['start_time'])),
      					'farm'							=>	$schedData[$i]['text'],
      					'run_time'					=>	$farms_delivery_hours, //get the feeds_farm_schedule farm id's and get the sum of farms delivery time
      					'return_time'				=>	$next_delivery_start_time, // next delivery
      					'actual_time_back'	=>	$actual_time_back //end time
      				);
      			}
      		}

      		return $data;

      		// start time = start time
      		// truck = driver name
      		// farm delivery = text
      		// run time = farm delivery delivery time
      		// return time = next delivery
      		// actual time back = arive time at mill

    	}



      /*
    	*	update the sched time tool
    	*/
    	public function updateScheduledItemAPI($data){

      		$delivery_number = $data['delivery_number'];
      		$driver_id = $data['driver_id'];
      		$unique_id = $data['unique_id'];
      		$start_time = $data['start_time'];
      		$end_time = $data['end_time'];

      		if($end_time == "00:00"){
      			$end_time = "23:50:00";
      		}


      		$delivery_date = SchedTool::where('farm_sched_unique_id',$unique_id)->get()->toArray();
      		$delivery_date = $delivery_date[0]['delivery_date'];

      		$sched_time = array(
      						'start_time'			=>	$start_time,
      						'end_time'				=>	$end_time
      					);

      		$update = SchedTool::where('farm_sched_unique_id',$unique_id)->update($sched_time);

      		$driver_data = SchedTool::where('delivery_date',$delivery_date)
      								->where('driver_id',$driver_id)
      								->orderBy('start_time')
      								->get()->toArray();

      		for($i = 0; $i < count($driver_data); $i++){
      			$data = array('delivery_number' => $i+1);
      			SchedTool::where('farm_sched_unique_id',$driver_data[$i]['farm_sched_unique_id'])->update($data);
      		}

      		$output = array(
      			'status'		=> 	$update,
      			'delivery_date'	=>	$delivery_date
      		);
      		$this->updateFarmSchedDelivery($unique_id,$delivery_date." ".$start_time);
      		//$this->updateFarmDeliveryTime($unique_id,$sched_time);
      		return $output;

    	}



      /*
    	*	Update the farm schedule and the delivery time
    	*/
    	private function updateFarmSchedDelivery($farm_sched_unique_id,$delivery_date){

      		$farm_sched_data = FarmSchedule::where('unique_id',$farm_sched_unique_id)->get()->toArray();

      		if($farm_sched_data != NULL){
      			$delivery_unique_id = $farm_sched_data[0]['delivery_unique_id'];
      			FarmSchedule::where('unique_id',$farm_sched_unique_id)->update(['date_of_delivery'=>$delivery_date]);
      			Deliveries::where('unique_id',$delivery_unique_id)->update(['delivery_date'=>$delivery_date]);
      		}

    	}



      /*
    	*	delete sched deliveries
    	*/
    	public function deleteSchedDel($uniqueID){


        		SchedTool::where('farm_sched_unique_id','=',$uniqueID)->delete();

        		$farmSched = FarmSchedule::where('unique_id','=',$uniqueID)->get()->toArray();

        		for($i=0; $i<count($farmSched);$i++){
        			Cache::forget('bins-'.$farmSched[$i]['bin_id']);
        			Cache::forget('farm_holder-'.$farmSched[$i]['farm_id']);
        			Cache::forget('farm_holder_bins_data-'.$farmSched[$i]['bin_id']);
        		}

        		$delivery_unique_id = !empty($farmSched[0]['delivery_unique_id']) ? $farmSched[0]['delivery_unique_id'] : NULL;

        		$deliveries = Deliveries::where('unique_id',$delivery_unique_id)->get()->toArray();

        		if($deliveries != NULL){

        			// $notification = new CloudMessaging;
              //
        			// $notification_data_driver = array(
        			// 	'unique_id'		=> 	$deliveries[0]['unique_id'],
        			// 	'driver_id'		=> 	$deliveries[0]['driver_id']
        			// 	);
              //
        			// $notification->deleteDeliveryNotifier($notification_data_driver);

        				for($i=0; $i<count($deliveries); $i++){
        					Cache::forget('bins-'.$deliveries[$i]['bin_id']);
        					Cache::forget('farm_holder-'.$deliveries[$i]['farm_id']);
        					Cache::forget('farm_holder_bins_data-'.$deliveries[$i]['bin_id']);


        					$notification_data_farmer = array(
        						'farm_id'		=> 	$deliveries[$i]['farm_id'],
        						'unique_id'		=> 	$deliveries[$i]['unique_id']
        						);

        					$this->deleteDriverStats($deliveries[$i]['unique_id']);

        					// $notification->deleteDeliveryNotifier($notification_data_farmer);
        					// DB::table('feeds_mobile_notification')->where('unique_id',$deliveries[$i]['unique_id'])->delete();
        				}

        			Deliveries::where('unique_id',$delivery_unique_id)->update(['delivery_label'=>'deleted']);
        			//DB::table('feeds_mobile_notification')->where('unique_id',$delivery_unique_id)->delete();
        			//unset($notification);
        		}

        		FarmSchedule::where('unique_id','=',$uniqueID)->delete();
        		DB::table('feeds_mobile_notification')->where('unique_id',$uniqueID)->delete();

        		//Artisan::call("forecastingdatacache");

    	}


      /*
    	*	Delete delivered items for the driver stats
    	*/
    	private function deleteDriverStats($unique_id)
    	{

        		DB::table('feeds_driver_stats')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_delivery_time')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_drive_time_google_est_mill')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_drive_time')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_drive_time_google_est')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_time_at_farm')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_time_at_mill')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_drive_time_interval')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_drive_time_interval_mill')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_driver_stats_total_miles')->where('deliveries_unique_id',$unique_id)->delete();
        		DB::table('feeds_mobile_notification')->where('unique_id',$unique_id)->delete();

    	}




      /*
    	*	Scheduled items for driver dropdown
    	*/
    	public function scheduledItemDriverAPI($data,$request){

        		$selected_date = $data['selected_date'];
        		$unique_id = $data['unique_id'];
        		$driver_id = $data['driver_id'];
        		$user_id = $data['user_id'];
        		$delivery_data = SchedTool::where('farm_sched_unique_id','=',$unique_id)->get()->toArray();
        		$delivery_number = !empty($delivery_data[0]['delivery_number']) ? $delivery_data[0]['delivery_number'] : 0;
        		$delivery_unique_id = !empty($delivery_data[0]['delivery_unique_id']) ? $delivery_data[0]['delivery_unique_id'] : 0;
        		$selected_index = $delivery_number;

        		// count the deliveries of the driver
        		$delivery_counter = SchedTool::select('farm_sched_unique_id')
        																			->where('delivery_date',$selected_date)
        																			->where('driver_id',$driver_id)
        																			->count();
        		if($delivery_counter > 7){
        			return "More than 7 deliveries";
        		}

        		if($request == "movetoschedtool"){
        		$delivery_number = $this->selectedIndexPosition($delivery_number,$selected_index=NULL,$driver_id,$selected_date);
        		}
        		// get the id's of farm
        		$data = FarmSchedule::select(DB::raw("GROUP_CONCAT(farm_id) AS farm_id"))
        							->where("unique_id","=",$unique_id)
        							->get()->toArray();

        		$delivery_time = $this->deliveryTimes($data[0]['farm_id']);
        		list($hours, $wrongMinutes) = explode('.', $delivery_time);
        		$minutes = ($wrongMinutes < 100 ? $wrongMinutes * 100 : $wrongMinutes) * 0.6 / 100;
        		$calculated_hour = $hours . 'hours ' . ceil($minutes) . 'minutes';

        		if($delivery_number == 1){
        			$start_time = "06:00:00";
        			$end_time = date("H:i:s",strtotime($start_time."+".$calculated_hour));
        		} else {
        			// get the max delivery number add 10 minutes interval then add the start time and end time
        			$items = SchedTool::where('delivery_date','=',$selected_date)
        								->where('driver_id','=',$driver_id)
        								->where('farm_sched_unique_id','!=',$unique_id)
        								->orderBy('delivery_number','desc')
        								->get()->toArray();
        			$start_time = !empty($items[0]['end_time']) ? date("H:i:s",strtotime($items[0]['end_time']."+ 10 minutes")) : "06:00:00";

        			//if(date("H",strtotime($start_time)) > 16){
        				//$start_time = "06:00:00";
        			//}

        			$end_time = date("H:i:s",strtotime($start_time."+".$calculated_hour));
        		}

        		$farm = array_unique(explode(",",(string)$data[0]['farm_id']));
        		$farm_names = Farms::select(DB::raw("GROUP_CONCAT(name) AS name"))->whereIn('id',$farm)->get()->toArray();

        		$data_to_save = array(
        			'driver_id'							=>	$driver_id,
        			'farm_sched_unique_id'	=>	$unique_id,
        			'farm_title'						=>	$farm_names[0]['name'],
        			'delivery_number'				=>	$delivery_number,
        			'delivery_date'					=>	$selected_date,
        			'start_time'						=>	$start_time,
        			'end_time'							=>	$end_time,
        			'selected_index'				=>	$selected_index
        		);

        		// delete existing same record
        		SchedTool::where('delivery_date',$selected_date)->where('farm_sched_unique_id',$unique_id)->delete();
        		FarmSchedule::where('unique_id',$unique_id)->update(['date_of_delivery'=>$selected_date." ".$start_time,'user_id'=>$user_id]);

        		if($delivery_number != 0 || $driver_id !=0){
        			// save record
        			SchedTool::insert($data_to_save);
        		}

        		if($driver_id ==0){
        			SchedTool::where('farm_sched_unique_id',$unique_id)->delete();
        		}

        		// check if the delivery is already created
        		if($delivery_unique_id != 0){
        			//update the delivery
        			$this->updateCreatedLoadAPI($delivery_unique_id,$user_id);
        		}

        		$this->updateScheduledDriver($driver_id,$unique_id);

        		$output = $this->schedToolOutput($selected_date);

        		return $output;

    	}


      /*
    	*	Selected index positioner
    	*/
    	private function selectedIndexPosition($delivery_number,$selected_index,$driver_id,$delivery_date)
      {

        		$sched_data = SchedTool::where('driver_id','=',$driver_id)
        					->where('delivery_date','=',$delivery_date)
        					->orderBy('delivery_number','desc')
        					->get()->toArray();

        		//if(!empty($selected_index) || $selected_index == 0){
        			//$output = $delivery_number;
        		//} else {
        			$output = !empty($sched_data[0]['delivery_number']) ? $sched_data[0]['delivery_number'] + 1 : 1;
        		//}

        		return $output;

    	}



      /*
    	*	get the delivery time of the farm
    	*/
    	private function deliveryTimes($ids)
      {

      		$farm = array_unique(explode(",",(string)$ids));
      		$output = Farms::select('delivery_time')->whereIn('id',$farm)->max('delivery_time');

      		$counter = count($farm);
      		$return = 0;
      		if($counter == 1){
      			$return = number_format((float)$output, 2, '.', '');
      		} else{
      			$added_minutes = ($counter-1) * 0.50;
      			$final = $output + $added_minutes;
      			$return = number_format((float)$final, 2, '.', '');
      		}

      		return $return;

    	}



      /*
    	* update the created load and remove the previous notification for mobile app
    	*/
    	private function updateCreatedLoadAPI($delivery_unique_id,$user_id)
      {

      		$farm_sched_data = FarmSchedule::select('unique_id','date_of_delivery')->where('delivery_unique_id',$delivery_unique_id)->first();
      		$farm_sched_unique_id = $farm_sched_data->unique_id;
      		$deliveries_data = Deliveries::where('unique_id','=',$delivery_unique_id)->first();
      		$farm_sched_date_of_delivery = $deliveries_data->delivery_date;

      		// $data_previous_driver = array(array(
      		// 	'driver_id'					=>	$deliveries_data->driver_id,
      		// 	'truck_id'					=>	$deliveries_data->truck_id,
      		// 	'delivery_date'			=>	$deliveries_data->delivery_date,
      		// ));
          //
      		// $this->loadTruckDriverNotification($data_previous_driver,$deliveries_data->unique_id);
      		// DB::table('feeds_mobile_notification')->where('unique_id',$deliveries_data->unique_id)->delete();

      		$this->loadToTruckUpdateAPI($delivery_unique_id,$farm_sched_unique_id,$user_id);

    	}



      /*
    	*	Load to truck used by the APIController
    	*/
    	public function loadToTruckUpdateAPI($delivery_unique_id,$farm_sched_unique_id,$user_id){

      		$data_to_delivery = array();
      		$farm_schedule_data = array();


      		// fetch the data from the batch table
      		$batch = DB::table('feeds_batch')
      								->where('status','created')
      								->where('unique_id',$farm_sched_unique_id)
      								->get();

      		SchedTool::where('farm_sched_unique_id',$farm_sched_unique_id)->update(['status'=>'created','delivery_unique_id'=>$delivery_unique_id,'driver_id'=>$batch[0]->driver_id]);
      		$farm_sched_data = FarmSchedule::select('date_of_delivery')->where('delivery_unique_id',$delivery_unique_id)->first()->toArray();

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
      				'user_id'							=>	$user_id,
      				'driver_id'						=>	$v->driver_id,
      				'amount'							=>	$v->amount,
      				'bin_id'							=>	$v->bin_id,
      				'compartment_number'	=>	$v->compartment,
      				'status'							=>	0,
      				'unique_id'						=>	$delivery_unique_id,
      				'created_at'					=>	date('Y-m-d h:i:s'),
      				'updated_at'					=>	date('Y-m-d h:i:s'),
      				'delivered'						=>	0,
      			);

      			$farm_schedule_data[] = array(
      				'date_of_delivery'		=>	date("Y-m-d H:i:s",strtotime($farm_sched_data['date_of_delivery'])),
      				'truck_id'						=>	$v->truck,
      				'farm_id'							=>	$v->farm_id,
      				'bin_id'							=>	$v->bin_id,
      				'driver_id'						=>	$v->driver_id,
      				'medication_id'				=>	$medication,
      				'amount'							=>	$v->amount,
      				'feeds_type_id'				=>	$v->feed_type,
      				'unique_id'						=>	$farm_sched_unique_id,
      				'ticket'							=>	"-",
      				'delivery_unique_id'	=>	$delivery_unique_id,
      				'status'							=>	1,
      				'user_id'							=>	$user_id
      			);

      			Cache::forget('bins-'.$v->bin_id);
      		}

      		// update the feeds_batch (put the driver_id)
      		//DB::table('feeds_batch')->where('driver_id',NULL)->where('unique_id',$data['unique_id'])->update(['driver_id'=>$data['driver_id'],'status'=>'loaded']);


      		//delete the previous feeds farm data
      		FarmSchedule::where('unique_id',$farm_sched_unique_id)->delete();
      		//delete the inserted delivery
      		Deliveries::where('unique_id',$delivery_unique_id)->delete();
      		// save the data to feeds_farm_schedule
      		FarmSchedule::insert($farm_schedule_data);
      		// sve the data to feeds_deliveries
      		Deliveries::insert($data_to_delivery);

      		// notify the driver and farmer
      		//$this->loadTruckDriverNotification($data_to_delivery,$delivery_unique_id);

      		$deliveries = Deliveries::where('unique_id','=',$delivery_unique_id)->get()->toArray();

      		//$this->loadTruckFarmerNotification($deliveries);

      		return $data_to_delivery;

    	}


      /*
    	* update the farm schedule
    	*/
    	private function updateScheduledDriver($driver_id,$unique_id)
      {
      		$driver = array('driver_id'=>$driver_id);
      		FarmSchedule::where('unique_id',$unique_id)->update($driver);
    	}



      /*
    	*	Data output for sched tool bar
    	*/
    	public function schedToolOutput($delivery_date=NULL){

      		$delivery_date = !empty($delivery_date) ? $delivery_date : date("Y-m-d",strtotime(Input::get('selected_data')));

      		$stDrivers = SchedTool::select(DB::raw('DISTINCT(driver_id) as driver_id,
      								(SELECT username FROM feeds_user_accounts WHERE id = driver_id) as driver_name'))
      								->where('delivery_date','=',$delivery_date)
      								->get()->toArray();
      		$data = array();
      		for($i = 0; $i < count($stDrivers); $i++){

      			$data[] = array(
      					'driver_id'	=> $stDrivers[$i]['driver_id'],
      					'title'			=> $stDrivers[$i]['driver_name'],
      					'eta'				=> $this->deliveriesETAPerDriver($stDrivers[$i]['driver_id'],$delivery_date),
      					'schedule'	=> $this->schedToolLevelTwo($stDrivers[$i]['driver_id'],$delivery_date),
      					'dar'				=> $this->driverActivityReport($delivery_date)
      				);
      		}


      		return $data;

    	}


      /*
    	*	scheduled data
    	*/
    	private function schedToolLevelTwo($driver_id,$delivery_date){

      		$schedData = SchedTool::select('driver_id','delivery_number','status','farm_sched_unique_id','delivery_unique_id','start_time','end_time',DB::raw('farm_title as text'))
      								->where('driver_id','=',$driver_id)
      								->where('delivery_date','=',$delivery_date)
      								->get()->toArray();

      		for($i = 0; $i < count($schedData); $i++){

      			$status = !empty($schedData[$i]['delivery_unique_id']) ? $this->deliveriesStatus($schedData[$i]['delivery_unique_id']) : $schedData[$i]['status'];

      			$output[] = array(
      				'start'				=>	date("H:i",strtotime($schedData[$i]['start_time'])),
      				'end'					=>	date("H:i",strtotime($schedData[$i]['end_time'])),
      				'text'				=>	$schedData[$i]['text'],
      				'data'				=> 	array(
      											'delivery_number'	=>	$schedData[$i]['delivery_number'],
      											'unique_id'			=>	$schedData[$i]['farm_sched_unique_id'],
      											'driver_id'			=>	$schedData[$i]['driver_id'],
      											'status'			=>	$schedData[$i]['status']//$status
      											)
      			);

      		}

      		return $output;

    	}


      /*
    	*	deliveries status counter
    	*/
    	public function deliveriesStatus($unique_id){

      		$status = "";

      		$loads  = Deliveries::where('unique_id','=',$unique_id)->count();
      		$delivered = Deliveries::where('unique_id','=',$unique_id)->where('status','=',3)->count();

      		if($delivered == $loads){
      			$status = "delivered";
      		}else{
      			$status = "pending";
      		}

      		return $status;
    	}

}
