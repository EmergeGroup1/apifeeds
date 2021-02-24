<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Validator;
use DB;
use Cache;
use Auth;
use Storage;
use App\Farms;
use App\Bins;
use App\FarmSchedule;
use App\Deliveries;
use App\SchedTool;
use App\Http\Controllers\Controller;

class APIController extends Controller
{

  /**
   * Display a listing of the resource.
   *
   * @return \Illuminate\Http\Response
   */
  public function index(Request $request)
  {
    $api = $request->input('action');

    switch ($api) {

      case "cacherebuild":

        // get the medications medication()
        $home_controller = new HomeController;
        $cacheData = $home_controller->forecastingDataCache();
        unset($home_controller);

        return $cacheData;

        break;

      case "logUser":

        $username = $request->input('username');
        $password = $request->input('password');
        $login_controller = new LoginController;
        $checker = $login_controller->checker();
        if ($checker == 1) {
          return array(
            'err'  =>  1,
            'msg'  =>  "You're not allowed to access the admin site..."
          );
        }
        $output = $login_controller->loginChecker($username, $password);
        unset($login_controller);

        return $output;

        break;

      case "listFarms":

        $type = $request->input('type');
        $token = $request->input('token');

        if ($type == 0) {
          $farms_default = Farms::where('status', 1)->orderBy('name')->get();
        } else if ($type == 1) {
          $farms_default = Farms::where('column_type', 1)->where('status', 1)->orderBy('name')->get();
        } else if ($type == 2) {
          $farms_default = Farms::where('column_type', 2)->where('status', 1)->orderBy('name')->get();
        } else if ($type == 3) {
          $farms_default = Farms::where('column_type', 3)->where('status', 1)->orderBy('name')->get();
        } else if ($type == 4) {
          $farms_default = Farms::where('farm_type','farrowing')->where('status', 1)->orderBy('name')->get();
          $log_token = session('token');
          $sort_type = $request->input('sort');

          if ($sort_type == 1) {
            $farms = json_decode(Storage::get('forecasting_farrowing_data_a_to_z.txt'));
          } else {
            $farms = json_decode(Storage::get('forecasting_farrowing_data_low_bins.txt'));
          }

          $data = $this->farmsBuilder($sort_type, $farms, $farms_default);

          return array('farmID' => $data);

        } else if ($type == 5) {
          $farms_default = Farms::where('status', 1)->orderBy('name')->get();
        } else {
          return "no type passed";
        }

        $log_token = session('token');
        $sort_type = $request->input('sort');

        if ($sort_type == 1) {
          $farms = json_decode(Storage::get('forecasting_data_a_to_z.txt'));
        } else {
          $farms = json_decode(Storage::get('forecasting_data_low_bins.txt'));
        }

        $data = $this->farmsBuilder($sort_type, $farms, $farms_default);

        return array('farmID' => $data);

        break;

      case "listBins":

        $farm_id = $request->input('farmID');
        $token = $request->input('token');
        $rooms = NULL;
        $farm_selected = Farms::where('id', $farm_id)->first();

        if ($farm_selected == NULL) {
          return array(
            "err" =>  1,
            "msg" =>  "No farm with that selected id"
          );
        }

        // make selection for farrowing rooms
        if($farm_selected->farm_type == "farrowing") {
          $farms_controller = new FarmsController;
          $rooms = $farms_controller->listRoomsFarmAPI($farm_id);
          unset($farms_controller);
        }

        $forecasting = json_decode(Storage::get('forecasting_data_low_bins.txt'));

        $home_controller = new HomeController;
        // $farmsCount = count($forecasting);
        // $r = array();
        // for($i=0; $i<$farmsCount; $i++){
        //   $r[] =  $home_controller->binsData($forecasting[$i]->farm_id);
        // }
        // return $r;
        $bins = $home_controller->binsData($farm_id);
        unset($home_controller);

        $bins = json_decode($bins);
        $bins_v2 = $this->binsBuilderV2($bins);
        $bins = $this->binsBuilder($bins);
        $farm = Farms::where('id', $farm_id)->select('name', 'notes')->first()->toArray();

        $output = array(
          'farmName'  =>  $farm['name'],
          'farmID'    =>  $farm_id,
          'farmType'  =>  $farm_selected->farm_type,
          'numberofLowbins' =>  $this->farmsBuilderNumberOfLowBins($forecasting, $farm_id),
          'notes'     =>  $farm['notes'],
          'bins'      =>  $bins,
          'total_bins'  =>  count($bins),
          'bins_div'  =>  $this->binsDivision($bins_v2),
          'rooms'     =>  $rooms
        );

        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        return $output;

        break;

      case "listRooms":

        $farm_id = $request->input('farmID');
        $token = $request->input('token');

        $farm_selected = Farms::where('id', $farm_id)->first();

        if ($farm_selected == NULL) {
          return array(
            "err" =>  1,
            "msg" =>  "No farm with that selected id"
          );
        }

        // make selection for farrowing rooms
        if($farm_selected->farm_type == "farrowing") {
          $farms_controller = new FarmsController;
          $rooms = $farms_controller->listRoomsFarmAPI($farm_id);
          unset($farms_controller);

          return array(
                  "rooms"     =>  $rooms,
                  "farmName"  =>  $farm_selected->name
                );
        }

        break;

      case "listBinSizes":

        $output = DB::table('feeds_bin_sizes')
          ->select('size_id as id', 'name')
          ->get();

        return $output;

        break;

      case "deleteBatch":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $batch_id = $request->input('batchID');

        DB::table('feeds_batch')->where('id', $batch_id)->delete();

        return array(
          "err" =>  0,
          "msg" =>  "Batch Deleted"
        );
        break;

      case "deleteSchedule":
        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $unique_id = $request->input('uniqueID');

        $scheduling_controller = new ScheduleController;
        $scheduling_controller->deleteSchedDel($unique_id);
        unset($scheduling_controller);
        DB::table('feeds_batch')->where('unique_id', $unique_id)->delete();
        FarmSchedule::where('unique_id', $unique_id)->delete();

        return array(
          "err" =>  0,
          "msg" =>  "Scheduled Delivery Deleted"
        );
        break;

      case "driverList":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        // get the medications medication()
        $home_controller = new HomeController;
        $drivers = $home_controller->driver();
        unset($home_controller);

        return $drivers;

        break;

      case "listMedication":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }
        Cache::forget('medications');
        // get the medications medication()
        $home_controller = new HomeController;
        $medications = $home_controller->medication();
        unset($home_controller);

        return $medications + array(0 => 'none');

        break;

      case "listFeedType":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }
        Cache::forget('feed_types');
        // get the medications medication()
        $home_controller = new HomeController;
        $feedtypes = $home_controller->feedTypesAPI();
        unset($home_controller);

        return $feedtypes;

        break;

      case "updateBin":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $_POST['bin'] = $request->input('binID');
        $_POST['amount'] = $request->input('amount');
        $_POST['user'] = $request->input('userID');

        $home_controller = new HomeController;

        if($request->input('farmType') == "farrowing"){
          $data = array(
            'binID' => $request->input('binID'),
            'amount'  => $request->input('amount'),
            'userID'  =>  $request->input('userID')
          );

          $update_bin = $home_controller->updateSowAPI($data);

        } else {
          $update_bin = $home_controller->updateBinAPI();
        }

        unset($home_controller);

        return $update_bin;

        break;

      case "updateBinCacheRebuild":

        return date("Y-m-d", strtotime("-1 day"));

        return array("err" => "none");

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        // get the medications medication()
        $home_controller = new HomeController;
        if ($home_controller->rebuildCacheAPI()) {
          return array("err" => "none");
        }
        unset($home_controller);

        break;

      case "updatePigs":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $farm_id = $request->input('farmID');
        $bin_id = $request->input('binID');
        $number_of_pigs = $request->input('numberOfPigs');
        $animal_unique_id = $request->input('animalUniqueID');
        $user_id = $request->input('user_id');


        // get the medications medication()
        $home_controller = new HomeController;
        $update_pigs = $home_controller->updatePigsAPI($farm_id, $bin_id, $number_of_pigs, $animal_unique_id, $user_id);
        unset($home_controller);

        return $update_pigs + array(
          "err" =>  0,
          "msg" =>  "Successfully updated pigs"
        );

        break;

        case "updateSow":

          $bin_id = $request->input('binID');
          $number_of_pigs = $request->input('totalpigs');


          // get the medications medication()
          DB::table("feeds_bins")->where('bin_id',$bin_id)
            ->update(['num_of_sow_pigs'=>$number_of_pigs]);

          $home_controller = new HomeController;
          $home_controller->clearBinsCache($bin_id);
          unset($home_controller);

          return array(
            "err" =>  0,
            "msg" =>  "Successfully updated pigs"
          );

          break;

      case "updateRoomPigs":

          $token = $request->input('token');
          $log_token = session('token');
          if ($token != $log_token) {
            return array("err" => "Invalid token, please login");
          }

          $farm_id = $request->input('farmID');
          $room_id = $request->input('binID');
          $number_of_pigs = $request->input('numberOfPigs');
          $animal_unique_id = $request->input('animalUniqueID');
          $user_id = $request->input('user_id');


          // get the medications medication()
          $home_controller = new HomeController;
          $update_pigs = $home_controller->updateRoomPigsAPI($farm_id, $room_id, $number_of_pigs, $animal_unique_id, $user_id);
          unset($home_controller);

          return $update_pigs + array(
            "err" =>  0,
            "msg" =>  "Successfully updated pigs"
          );

          break;

      case "saveBatch":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'farm_id' => $request->input('farmID'),
          'bin_id' => $request->input('binID'),
          'feed_type' => $request->input('feedType'),
          'medication' => $request->input('medication'),
          'amount' => $request->input('amount'),
          'date' => date("Y-m-d", strtotime($request->input('date'))),
          'truck' => $request->input('truck'),
          'compartment' => $request->input('compartment'),
          'code_id' => $request->input("code")
        );

        $exists = DB::table('feeds_batch')
          ->where('status', 'pending')
          ->where('bin_id', $request->input('binID'))
          ->exists();

        $code_exists = DB::table('feeds_batch')
          ->where('code_id', $request->input('code'))
          ->exists();


        if ($code_exists) {
          if ($this->saveBatch($data)) {
            DB::table('feeds_batch')->where('status', 'pending')->where('unique_id', NULL)->update(['date' => date("Y-m-d", strtotime($request->input('date')))]);
            return $data + array(
              "err" =>  0,
              "msg" =>  "Successfully added"
            );
          } else {
            return $this->errorMessage();
          }
        } else {
          if ($exists) {
            return array(
              "err" =>  1,
              "msg" =>  "Duplicate Batch Entry"
            );
          }

          if ($this->saveBatch($data)) {
            DB::table('feeds_batch')->where('status', 'pending')->where('unique_id', NULL)->update(['date' => date("Y-m-d", strtotime($request->input('date')))]);
            return $data + array(
              "err" =>  0,
              "msg" =>  "Successfully added"
            );
          } else {
            return $this->errorMessage();
          }
        }

        break;


      case "listBatch":

        $token = $request->input('token');
        $log_token = session('token');

        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }
        $status = $request->input('status');

        $data = DB::table('feeds_batch')->where('status', $status)->orderBy('compartment', 'asc')->get();
        $output = array();
        foreach ($data as $k => $v) {
          $output[] = array(
            "id"               => $v->id,
            "unique_id"        => $v->unique_id,
            "farm_id"          => $v->farm_id,
            "farm_text"        => $this->farmName($v->farm_id),
            "bin_id"           => $v->bin_id,
            "bin_text"         => $this->binName($v->bin_id),
            "date"             => $v->date,
            "amount"           => $v->amount,
            "feed_type"        => $v->feed_type,
            "feed_type_text"   => $this->feedName($v->feed_type),
            "medication"       => $v->medication,
            "medication_text"  => $this->medicationName($v->medication),
            "truck"            => $v->truck,
            "truck_text"       => $this->truckName($v->truck),
            "compartment"      => $v->compartment
          );
        }

        return $output;

        break;

      case "listSchedule":

        $token = $request->input('token');
        $log_token = session('token');

        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $farms_scheduled_unique_id = DB::table('feeds_farm_schedule')
          ->select('unique_id', 'truck_id', 'farm_id', 'driver_id')
          ->where(DB::raw('LEFT(date_of_delivery,10)'), date("Y-m-d", strtotime($request->input('selectedDate'))))
          ->distinct()->get();

        $output = array();
        $farms = array();
        for ($i = 0; $i < count($farms_scheduled_unique_id); $i++) {
          $farms = $this->listScheduleBatchesFarmIDs($farms_scheduled_unique_id[$i]->unique_id);
          $outer = array(
            "delivery_time"    => $this->farmDeliveryTimes($farms),
            "truck"            => $farms_scheduled_unique_id[$i]->truck_id, //$truck_id,//$data_unique_id[$i]->truck,
            "truck_text"       => $this->truckName($farms_scheduled_unique_id[$i]->truck_id), //$truck_text,//$this->truckName($data_unique_id[$i]->truck),
            "total_tons"       => $this->totalTonsFarmSchedTable($farms_scheduled_unique_id[$i]->unique_id),
            "batch"            => $this->listScheduleBatches($farms_scheduled_unique_id[$i]->unique_id),
            "status"           => $this->getDeliveryStatus($farms_scheduled_unique_id[$i]->unique_id), //get the sattus of delivey by getting the
            "driver_id"        => $this->getDeliveryDriver($farms_scheduled_unique_id[$i]->unique_id),
            "delivery_number"  => $this->getDeliveryNumber($farms_scheduled_unique_id[$i]->unique_id)
          );
          $output[$farms_scheduled_unique_id[$i]->unique_id] = $outer;
        }

        if ($output == NULL) {
          return array(
            "err" =>  1,
            "msg" =>  "No batches to schedule"
          );
        }

        return array(
          "err" =>  0,
          "msg" =>  "Successfully pull data",
          "schedule"  => $output
        );

        break;


      case "listBatches":
        $unique_id = $request->input('uid');
        $batch = $this->listScheduleBatchesPrint($unique_id);

        return array(
          "err" =>  0,
          "msg" =>  "Successfully pull data",
          "batch"  => $batch
        );
      break;

      case "driverNote":

        $type = $request->input('type');
        $notes = $request->input('notes');
        $driver_id = $request->input('driver_id');
        $unique_id = $request->input('unique_id');

        $driver_notes = $this->driverNotes($notes,$driver_id,$unique_id);

        $data = array(
          "type" => $type,
          "notes" => $notes,
          "driver_id" => $driver_id,
          "unique_id" => $unique_id
        );

        return array(
          "err" =>  0,
          "msg" =>  "Successfully pull data",
          "data"  => $data
        );

      break;


      case "updateCompartment":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'farm_id' => $request->input('farmID'),
          'bin_id' => $request->input('binID'),
          'feed_type' => $request->input('feedType'),
          'medication' => $request->input('medication'),
          'amount' => $request->input('amount'),
          'date' => date("Y-m-d", strtotime($request->input('date'))),
          'truck' => $request->input('truck'),
          'compartment' => $request->input('compartment'),
        );

        DB::table('feeds_batch')->where("id", $request->input('id'))->update($data);

        $batch = DB::table('feeds_batch')->where("id", $request->input('id'))->first();

        $var = array();
        $var['selected_date'] = $batch->date;
        $var['unique_id'] = $batch->unique_id;
        $var['driver_id'] = $batch->driver_id;
        $var['user_id'] = $request->input('userID');

        DB::table('feeds_batch')->where("unique_id", $var['unique_id'])->update(['date' => $data['date']]);

        $scheduling_controller = new ScheduleController;
        $scheduled_item = $scheduling_controller->scheduledItemDriverAPI($var, "updatecompartment");
        unset($scheduling_controller);

        if ($scheduled_item) {
          return array("err" => "none");
        } else {
          return array("err" => "Something went wrong");
        }

        break;

      case "scheduleDelivery":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        return $request->input();

        $user = $request->input("userID");

        $home_controller = new HomeController;
        $unique_id = $home_controller->generator();
        unset($home_controller);

        // loop from the dates
        // duplicate the batches but not the date, code_id and unique_id
        // insert in feeds_bacth table


        $max_compartment = DB::table('feeds_batch')->where('status', 'pending')->max('compartment');
        $total_tons = DB::table('feeds_batch')->where('status', 'pending')->sum('amount');

        if ($max_compartment > 12) {
          $truck_id = 5;
        } else {
          if ($total_tons > 36) {
            $truck_id = 5;
          } else {
            $truck_id = 3;
          }
        }

        $update = array(
          'unique_id' =>  $unique_id,
          'status'    =>  "created",
          'truck'     =>  $truck_id
        );

        DB::table('feeds_batch')->where('status', 'pending')->update($update);

        $batches = DB::table('feeds_batch')->where('unique_id', $unique_id)->get();
        // insert data to feeds_farm_schedule
        $this->saveFarmSchedule($batches, $unique_id, $user);

        $farm_schedule_data = FarmSchedule::where('unique_id', $unique_id)->first()->toArray();

        $home_controller = new HomeController;
        $home_controller->forecastingDataCache();
        unset($home_controller);

        if ($farm_schedule_data != NULL) {

          return $update + array(
            "err" =>  0,
            "msg" =>  "Successfully Added"
          );
        } else {
          return $update + $this->errorMessage();
        }

        break;

      case "schedToolData":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));

        $scheduling_controller = new ScheduleController;
        $scheduled_item = $scheduling_controller->scheduledDataAPI($data);
        unset($scheduling_controller);

        if ($scheduled_item != NULL) {
          return $scheduled_item + array(
            "err" =>  0,
            "msg" =>  "Successfully Pull Data"
          );
        }

        return array(
          "err" =>  1,
          "msg" =>  "No scheduled item"
        );

        break;

      case "updateDate":
        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $user = $request->input('userID');
        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));
        $unique_id = $request->input('uniqueID');

        DB::table('feeds_batch')->where('unique_id', $unique_id)->update(['date' => $selected_date]);

        $scheduling_controller = new ScheduleController;
        $update = $scheduling_controller->saveChangeDateSchedAPI($user, $unique_id, $selected_date);
        unset($scheduling_controller);

        if ($update == "success") {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Updated Data"
          );
        }

        return array(
          "err" =>  1,
          "msg" =>  "No scheduled item"
        );

        break;

      case "totalTonsSchedTool":
        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));

        $scheduling_controller = new ScheduleController;
        $total_tons = $scheduling_controller->totalTonsAPI($selected_date);
        unset($scheduling_controller);


        if ($total_tons != NULL) {
          return array(
            "total_tons"  => $total_tons,
            "err"         =>  0,
            "msg"         =>  "Successfully Pull Data"
          );
        }

        return array(
          "err" =>  1,
          "msg" =>  "No scheduled item"
        );

        break;

      case "totalTonsDeliveredSchedTool":
        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));

        $scheduling_controller = new ScheduleController;
        $total_tons = $scheduling_controller->totalTonsDeliveredAPI($selected_date);
        unset($scheduling_controller);

        return array(
          "total_tons"  => $total_tons,
          "err"         =>  0,
          "msg"         =>  "Successfully Pull Data"
        );

        break;

      case "totalTonsScheduledSchedTool":
        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));

        $scheduling_controller = new ScheduleController;
        $total_tons = $scheduling_controller->totalTonsScheduledAPI($selected_date);
        unset($scheduling_controller);

        return array(
          "total_tons"  => $total_tons,
          "err"         =>  0,
          "msg"         =>  "Successfully Pull Data"
        );

        break;

      case "moveToSchedTool":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id'        =>  $request->input('driverID'),
          'unique_id'        =>  $request->input('uniqueID'),
          'user_id'         =>  $request->input('userID'),
          'selected_date'    =>  date("Y-m-d", strtotime($request->input('selectedDate')))
        );

        DB::table('feeds_batch')->where(
          'unique_id',
          $request->input('uniqueID')
        )->update(['driver_id' => $request->input('driverID'), 'date' => date("Y-m-d", strtotime($request->input('selectedDate')))]);

        $scheduling_controller = new ScheduleController;
        $scheduled_item = $scheduling_controller->scheduledItemDriverAPI($data, "movetoschedtool");
        unset($scheduling_controller);

        if ($scheduled_item == "More than 7 deliveries") {
          return array(
            "err" =>  1,
            "msg" =>  "More than 7 deliveries"
          );
        }

        if ($scheduled_item != NULL) {
          return $scheduled_item + array(
            "err" =>  0,
            "msg" =>  "Successfully Pull Data"
          );
        }

        return array(
          "err" =>  1,
          "msg" =>  "No batches move to schedeuling tool"
        );

        break;

      case "changeDeliveryNumber":
        $validate = array();
        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id'       => $request->input('driverID'),
          'unique_id'       => $request->input('uniqueID'),
          'delivery_number' => $request->input('deliveryNumber'),
          'selected_index'  => $request->input('selectedIndex'),
          'selected_date'    => date("Y-m-d", strtotime($request->input('selectedDate')))
        );

        $scheduling_controller = new ScheduleController;
        $validate = $scheduling_controller->deliveryNumberValidateAPI($data);

        if ($validate['output'] == 1) {
          unset($scheduling_controller);
          return array(
            "err" =>  1,
            "msg" =>  "delivery number already selected"
          );
        }

        $scheduled_item = $scheduling_controller->scheduledItemDeliveryNumberAPI($data);
        unset($scheduling_controller);



        if ($scheduled_item != NULL) {
          return $scheduled_item + array(
            "err" =>  0,
            "msg" =>  "Successfully Pull Data"
          );
        }

        if ($validate['output'] == 0) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Pull Data"
          );
        }

        return array(
          "err" =>  1,
          "msg" =>  "No batches move to schedeuling tool"
        );

        break;


      case "validateDeliveryNumber":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id'       => $request->input('driverID'),
          'unique_id'       => $request->input('uniqueID'),
          'delivery_number' => $request->input('deliveryNumber'),
          'selected_date'    => date("Y-m-d", strtotime($request->input('selectedDate')))
        );

        $scheduling_controller = new ScheduleController;
        $scheduled_item = $scheduling_controller->deliveryNumberValidateAPI($data);
        unset($scheduling_controller);

        if ($scheduled_item['output'] != 0) {
          return $scheduled_item + array(
            "err" =>  0,
            "msg" =>  "Successfully Pull Data"
          );
        }

        return $scheduled_item + array(
          "err" =>  1,
          "msg" =>  "Delivery number already selected"
        );

        break;


      case "loadDelivery":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id' => $request->input('driverID'),
          'unique_id' => $request->input('uniqueID'),
          'selected_date'    => date("Y-m-d", strtotime($request->input('selectedDate')))
        );

        DB::table('feeds_batch')->where('unique_id', $request->input('uniqueID'))->update(['driver_id' => $request->input('driverID')]);

        $delivery_exists = FarmSchedule::whereNotNull('delivery_unique_id')->where('unique_id', $request->input('uniqueID'))->get()->toArray();
        if ($delivery_exists != NULL) {
          return array(
            "err" =>  1,
            "msg" =>  "The schedule is already loaded"
          );
        }

        $user = $request->input('userID');

        $scheduling_controller = new ScheduleController;
        $delivery_data = $scheduling_controller->loadToTruckAPI($data, $user);
        unset($scheduling_controller);

        if ($delivery_data != NULL) {
          return $delivery_data + array(
            "err" =>  0,
            "msg" =>  "Successfully Pull Data"
          );
        }

        return array(
          "err" =>  1,
          "msg" =>  "No batches load to delivery"
        );

        break;

      case "driverStats":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'date_from'  =>  date("Y-m-d", strtotime($request->input('from'))),
          'date_to'    =>  date("Y-m-d", strtotime($request->input('to')))
        );

        $reports_controller = new ReportsController;
        $drivers_stats = $reports_controller->searchAPI($data);
        unset($reports_controller);


        return $drivers_stats + array(
          "err" =>  0,
          "msg" =>  "Successfully Pull Data"
        );

        break;

      case "schedDriversGraph":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));

        $scheduling_controller = new ScheduleController;
        $delivery_data = $scheduling_controller->schedToolOutputAPI($selected_date);
        unset($scheduling_controller);


        return  array(
          "err" =>  0,
          "msg" =>  "Successfully Pull Data",
          "driversGraph"  =>  $delivery_data
        );

        break;

      case "driverActivityReport":

        $token = $request->input('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime($request->input('selectedDate')));

        $scheduling_controller = new ScheduleController;
        $delivery_data = $scheduling_controller->driverActivityReportAPI($selected_date);
        unset($scheduling_controller);

        dd($delivery_data);


        return  array(
          "err" =>  0,
          "msg" =>  "Successfully Pull Data",
          "driversGraph"  =>  $delivery_data
        );

        break;

      case "updateSchedTime":

        $data = array(
          'start_time' => date("H:i", strtotime($request->input('startTime'))),
          'end_time' => date("H:i", strtotime($request->input('endTime'))),
          'delivery_number' => $request->input('deliveryNumber'),
          'unique_id' => $request->input('uniqueID'),
          'driver_id' => $request->input('driverID')
        );

        $scheduling_controller = new ScheduleController;
        $delivery_data = $scheduling_controller->updateScheduledItemAPI($data);
        unset($scheduling_controller);

        return array(
          "err" =>  0,
          "msg" =>  "Successfully Pull Data",
          "driversGraph"  =>  $delivery_data
        );
        break;

        /* Delivery Page */
      case "deliveryLists":

        $data = array(
          'from'            =>  date("Y-m-d", strtotime($request->input('from'))),
          'to'              =>  date("Y-m-d", strtotime($request->input('to'))),
          'driver'          =>  $request->input('driver'),
          'delivery_number' =>  $request->input('deliveryNumber'),
          'farm_id'         =>  $request->input('farmID')
        );
        $home_controller = new HomeController;
        $deliveries = $home_controller->deliveriesListAPI($data);
        unset($home_controller);

        return $deliveries;

        break;

        /* Delivery Page */
      case "deliveryLoadInfo":

        $unique_id = $request->input('uniqueID');
        $home_controller = new HomeController;
        $deliveries = $home_controller->loadBreakdownAPI($unique_id);
        unset($home_controller);

        return $deliveries;

        break;

      case "searchDeliveryLists":

        $from  = $request->input('from');
        $to = $request->input('to');
        $home_controller = new HomeController;
        $deliveries = $home_controller->deliveriesListAPI();
        unset($home_controller);

        return $deliveries;

        break;

      case "markDelivered":

        $unique_id = $request->input('uniqueID');
        $user_id = $request->input('userID');

        $home_controller = new HomeController;
        $delivery = $home_controller->markDeliveredAPI($unique_id, $user_id);
        unset($home_controller);
        return $delivery;
        if ($delivery) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully mark completed delivery"
          );
        } else {
          return array(
            "err" =>  1,
            "msg" =>  "Something went wrong to the delivery update"
          );
        }


        break;

      case "deleteDelivered":

        $unique_id = $request->input('uniqueID');

        $home_controller = new HomeController;
        $delivery = $home_controller->deleteDeliveredAPI($unique_id);
        unset($home_controller);

        if ($delivery) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Deleted Data"
          );
        } else {
          return array(
            "err" =>  1,
            "msg" =>  "Something went wrong to the delivery delete"
          );
        }


        break;

        /* Farms Administraton */
      case "listFarmAdmin":

        $farms_controller = new FarmsController;
        $farmsLists = $farms_controller->listFarmAPI();
        unset($farms_controller);

        // $home_controller = new HomeController;
        // $home_controller->forecastingDataCache();
        // unset($home_controller);

        if (!empty($farmsLists)) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Pulled Data",
            "farmsList" => $farmsLists
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "saveFarmAdmin":

        $data = array(
          'name'                =>  $request->input('farmName'),
          'delivery_time'       =>  $request->input('deliveryTime'),
          'packer'              =>  $request->input('packerName'),
          'contact'             =>  $request->input('ContactNumber'),
          'farm_type'           =>  $request->input('farmType'),
          'column_type'         =>  $request->input('columnType'),
          'owner'               =>  $request->input('farmOwner'),
          'update_notification' =>  $request->input('manualUpdateNotification'),
          'notes'               =>  $request->input('notes'),
          'address'             =>  $request->input('address'),
          'longtitude'          =>  $request->input('lng'),
          'lattitude'           =>  $request->input('lat'),
          'user_id'             =>  $request->input('userID'),
          'created_at'          =>  date('Y-m-d H:i:s'),
          'updated_at'          =>  date('Y-m-d H:i:s')
        );

        $farms_controller = new FarmsController;
        $status = $farms_controller->saveFarmAPI($data);
        unset($farms_controller);

        if ($status == "saved") {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Saved Data",
            "data" => $data
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "updateFarmAdmin":

        $farm_id = $request->input('farmID');
        $data = array(
          'name'                =>  $request->input('farmName'),
          'delivery_time'       =>  $request->input('deliveryTime'),
          'packer'              =>  $request->input('packerName'),
          'contact'             =>  $request->input('ContactNumber'),
          'farm_type'           =>  $request->input('farmType'),
          'column_type'         =>  $request->input('columnType'),
          'owner'               =>  $request->input('farmOwner'),
          'update_notification' =>  $request->input('manualUpdateNotification'),
          'notes'               =>  $request->input('notes'),
          'address'             =>  $request->input('address'),
          'longtitude'          =>  $request->input('lng'),
          'lattitude'           =>  $request->input('lat'),
          'user_id'             =>  $request->input('userID'),
          'created_at'          =>  date('Y-m-d H:i:s'),
          'updated_at'          =>  date('Y-m-d H:i:s')
        );

        if ($request->input('farmOwner') == NULL) {
          $data['owner'] = "none";
        }

        $farms_controller = new FarmsController;
        $status = $farms_controller->updateFarmAPI($farm_id, $data);
        unset($farms_controller);

        if ($status == "updated") {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Pulled Data",
            "data" => $data
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "deleteFarmAdmin":

        $farm_id = $request->input('farmID');

        $farms_controller = new FarmsController;
        $status = $farms_controller->deleteFarmAPI($farm_id);
        unset($farms_controller);

        if ($status == "deleted") {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Deleted Data",
            "farm_id" => $farm_id
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "turnOnFarmAdmin":

        $farm_id = $request->input('farmID');

        $farms_controller = new FarmsController;
        $status = $farms_controller->turnOnFarmAPI($farm_id);
        unset($farms_controller);

        if ($status == "turn on") {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully turn on farm",
            "farm_id" => $farm_id
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "turnOffFarmAdmin":

        $farm_id = $request->input('farmID');
        $reactivation_date = $request->input('reactivationDate');

        $farms_controller = new FarmsController;
        $status = $farms_controller->turnOffFarmAPI($reactivation_date, $farm_id);
        unset($farms_controller);

        if ($status == "turn off") {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully turn off farm",
            "farm_id" => $farm_id
          );
        } else {
          return $this->errorMessage();
        }

        break;

        case "listBinFarmAdmin":

        $farm_id = $request->input('farmID');

        $farms_controller = new FarmsController;
        $binsList = $farms_controller->listBinFarmAPI($farm_id);
        unset($farms_controller);

        if (!empty($binsList)) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Pulled Data",
            "binsList" => $binsList
          );
        } else {
          return array(
            "err" =>  1,
            "msg" =>  "Selected farm not exists"
          );
        }

        break;

      case "saveBinFarmAdmin":

        $data = array(
          'farm_id'     =>  $request->input('farmID'),
          'bin_number'  =>  $request->input('binNumber'),
          'alias'       =>  $request->input('alias'),
          'bin_size'    =>  $request->input('binSize'),
          'user_id'     =>  $request->input('userID'),
          'sow'         =>  $request->input('sow'),
        );

        $farms_controller = new FarmsController;
        $farms_controller->saveBinFarmAPI($data);
        unset($farms_controller);

        if (!empty($farmsLists)) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Pulled Data",
            "bin_data_saved" => $data
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "updateBinFarmAdmin":

        $data = array(
          'bin_id'      =>  $request->input('binID'),
          'farm_id'     =>  $request->input('farmID'),
          'feed_type'   =>  $request->input('feedType'),
          'alias'       =>  $request->input('alias'),
          'bin_size'    =>  $request->input('binSize'),
          'user_id'     =>  $request->input('userID'),
          'sow'         =>  $request->input('sow'),
        );

        $farms_controller = new FarmsController;
        $binsLists = $farms_controller->updateBinFarmAPI($data);
        unset($farms_controller);

        $h_c = new HomeController;
        $h_c->clearBinsCache($data['bin_id']);
        unset($h_c);

        if (!empty($binsLists)) {
          return array(
            "err" =>  0,
            "msg" =>  "Successfully Pulled Data",
            "bin_data_updated" => $data
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "deleteBinFarmAdmin":

        $farms_controller = new FarmsController;
        $farms_controller->deleteBinFarmAPI($request->input('binID'));
        unset($farms_controller);

        if (!empty($farmsLists)) {
          return array(
            "err"   =>  0,
            "msg"   =>  "Successfully Deleted Bin",
            "binid" =>  $request->input('binID')
          );
        } else {
          return $this->errorMessage();
        }

        break;

        case "listRoomsFarmAdmin":

            $farm_id = $request->input('farm_id');

            $farms_controller = new FarmsController;
            $roomsList = $farms_controller->listRoomsFarmAPI($farm_id);
            unset($farms_controller);

            if (!empty($roomsList)) {
              return array(
                "err" =>  0,
                "msg" =>  "Successfully Pulled Data",
                "roomsList" => $roomsList['data'],
                "totalRooms"  =>  $roomsList['total_rooms'],
                "totalCrates" =>  $roomsList['total_crates']
              );
            } else {
              return array(
                "err" =>  1,
                "msg" =>  "Selected farm has no rooms yet"
              );
            }

          break;


        case "saveRoomFarmAdmin":

          $data = array(
            'farm_id'     =>  $request->input('farm_id'),
            'room_number'  =>  $request->input('room_number'),
            'crates_number' =>  $request->input('crates_number')
          );

          $farms_controller = new FarmsController;
          $data = $farms_controller->saveRoomFarmAPI($data);
          unset($farms_controller);

          if (!empty($data)) {
            return array(
              "err" =>  0,
              "msg" =>  "Successfully Pulled Data",
              "rooms_data_saved" => $data
            );
          } else {
            return $this->errorMessage();
          }

          break;

        case "updateRoomFarmAdmin":

          $data = array(
            'id'     =>  $request->input('room_id'),
            'room_number'  =>  $request->input('room_number'),
            'crates_number' =>  $request->input('crates_number'),
            'pigs'       =>  $request->input('pigs'),
            'farm_id' => $request->input('farm_id'),
            'prev_room_number' => $request->input('prev_room_number')
          );

            $farms_controller = new FarmsController;
            $roomLists = $farms_controller->updateRoomFarmAPI($data);
            unset($farms_controller);

            if (!empty($roomLists)) {
              return array(
                "err" =>  0,
                "msg" =>  "Successfully Updated Data",
                "room_data_updated" => $roomLists
              );
            } else {
              return $this->errorMessage();
            }

            break;

        case "deleteRoomFarmAdmin":

          $farms_controller = new FarmsController;
          $farms_controller->deleteRoomFarmAPI($request->input('room_id'));
          unset($farms_controller);

          if (!empty($request->input('room_id'))) {
            return array(
              "err"   =>  0,
              "msg"   =>  "Successfully Deleted Room",
              "roomid" =>  $request->input('room_id')
            );
          } else {
            return $this->errorMessage();
          }

          break;


        /* End Farms Administraton */


        /* Driver Tracking */
      // case "driverTracking":
      //
      //   $livetruck_controller = new LiveTruckController;
      //   $drivers = $livetruck_controller->liveTrucksAPI();
      //   unset($livetruck_controller);
      //
      //   if (!empty($drivers)) {
      //     return array(
      //       "err"     =>  0,
      //       "msg"     =>  "Successfully Deleted Bin",
      //       "drivers" =>  $drivers
      //     );
      //   } else {
      //     return $this->errorMessage();
      //   }
      //
      //   break;
      //   /* End Farms Administraton */
      //
      //   /* Messaging */
      //
      //   // user lists for messages
      //   case "msList":
      //
      //     $logged_in_user_id = $request->input('userID');
      //
      //     $ms_ctrl = new MessagingController;
      //     $messages = $ms_ctrl->messagingListAPI($logged_in_user_id);
      //     unset($ms_ctrl);
      //
      //     if (!empty($messages)) {
      //       return array(
      //         "err"     =>  0,
      //         "msg"     =>  "Successfully Get Users List",
      //         "messagesList" =>  $messages
      //       );
      //     } else {
      //       return $this->errorMessage();
      //     }
      //
      //   break;
      //
      //   // history of messages from specific user
      //   case "msHistory":
      //     $logged_in_user_id = $request->input('userID');
      //     $pm_user_id = $request->input('pmUserID');
      //
      //     $ms_ctrl = new MessagingController;
      //     $messages = $ms_ctrl->loadMessageHistoryAPI($logged_in_user_id,$pm_user_id);
      //     unset($ms_ctrl);
      //
      //     if (!empty($messages)) {
      //       return array(
      //         "err"     =>  0,
      //         "msg"     =>  "Successfully Get Messages History",
      //         "messagesList" =>  $messages
      //       );
      //     } else {
      //       return $this->errorMessage();
      //     }
      //   break;
      //
      //   // update the notification
      //   case "msUpdateNotif":
      //     $logged_in_user_id = $request->input('userID');
      //     $pm_user_id = $request->input('pmUserID');
      //
      //     $ms_ctrl = new MessagingController;
      //     $notif = $ms_ctrl->updateNotifAPI($logged_in_user_id,$pm_user_id);
      //     unset($ms_ctrl);
      //
      //     if ($notif != NULL) {
      //       return array(
      //         "err"     =>  0,
      //         "msg"     =>  "Successfully Get Response",
      //         "message" =>  $notif
      //       );
      //     } else {
      //       return $this->errorMessage();
      //     }
      //   break;
      //
      //   // total notification
      //   case "msTotalNotif":
      //     $logged_in_user_id = $request->input('userID');
      //
      //     $ms_ctrl = new MessagingController;
      //     $notif = $ms_ctrl->totalNotifAPI($logged_in_user_id);
      //     unset($ms_ctrl);
      //
      //     return array(
      //       "err"     =>  0,
      //       "msg"     =>  "Successfully Get total notification",
      //       "totalNotif" =>  $notif
      //     );
      //
      //   break;
        /* End of Messaging */


        /* Start Animal Movement */
      case "amList":

        $data = array(
          'type'      =>  $request->input('type'), // (string) all, farrowing_to_nursery, nursery_to_finisher, finisher_to_market
          'date_from' =>  $request->input('date_from'), // (date)
          'date_to'   =>  $request->input('date_to'), // (date)
          'sort'      =>  $request->input('sort'), // (string) not_scheduled, day_remaining
          's_farm'    =>  $request->input('s_farm') // selected farm
        );

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->animalMovementFilterAPI($data);
        unset($am_controller);

        if (!empty($am_lists['output'])) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Get Animal Groups",
            "am_list" =>  $am_lists,
            "death_reasons" => $this->deathReasons()
          );
        } else {
          return array(
            "err" =>  1,
            "msg" =>  "No Records Found"
          );
        }

        break;


        case "amListRefresh":

          $data = array(
            'type'      =>  "all", // (string) all, farrowing_to_nursery, nursery_to_finisher, finisher_to_market
            'date_from' =>  date("Y-m-d", strtotime('-1280 days')), // (date)
            'date_to'   =>  date("Y-m-d"), // (date)
            'sort'      =>  "not_scheduled", // (string) not_scheduled, day_remaining
            's_farm'    =>  "all" // selected farm
          );

          $am_controller = new AnimalMovementController;
          $am_lists = $am_controller->animalMovementFilterAPI($data);
          unset($am_controller);
          // $am_lists = Cache::get('am_pig_tracker_data');

          if (!empty($am_lists['output'])) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully Get Animal Groups",
              "am_list" =>  $am_lists
            );
          } else {
            return array(
              "err" =>  1,
              "msg" =>  "No Records Found"
            );
          }

          break;


      case "amDeleteGroup":

        $group_id = $request->input('group_id');
        $type = $request->input('type');
        $user_id = $request->input('user_id');

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->removeGroupAPI($group_id, $user_id, $type);
        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Deleted Animal Group"
          );
        } else {
          return $this->errorMessage();
        }

        break;


      case "amCleanGroup":

        $am_controller = new AnimalMovementController;

        $groups = DB::table("feeds_movement_groups")
                    // ->where("created_at","0000-00-00 00:00:00")
                    ->get();

        for($i=0; $i<count($groups); $i++){

          $group_id = $groups[$i]->group_id;
          $type = $groups[$i]->type;
          $user_id = $groups[$i]->user_id;

          $am_lists[] = $am_controller->removeGroupAPI($group_id, $user_id, $type);

        }

        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Clean no created_at Animal Groups"
          );
        } else {
          return $this->errorMessage();
        }

        break;


      case "amCreateGroup":

        $date_created = date("Y-m-d H:i:s", strtotime($request->input('date_created')));

        $data = array(
          'group_name'        =>  $request->input('group_name'),
          'farm_id'            =>  $request->input('farm_id'),
          'start_weight'      =>  $request->input('start_weight'),
          'end_weight'        =>  $request->input('end_weight'),
          'crates'            =>  $request->input('crates') == NULL ? 0 : $request->input('crates'),
          'date_created'      =>  $date_created,
          'status'            =>  'entered',
          'user_id'            =>  $request->input('user_id'),
          'type'              =>  $request->input('type'),
          //'bins'              =>  $request->input('bins'),
          'number_of_pigs'    =>  $request->input('number_of_pigs')
        );

        if($request->input('type') == "farrowing"){
          $data['rooms'] = $request->input('rooms');
        } else {
          $data['bins'] = $request->input('bins');
        }

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->saveGroupAPI($data);
        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Created Animal Group",
            "created_group" =>  $am_lists
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "amUpdateGroup":

        $date_created = date("Y-m-d H:i:s", strtotime($request->input('date_created')));

        $data = array(
          'group_id'          =>  $request->input('group_id'),
          'group_name'        =>  $request->input('group_name'),
          'farm_id'            =>  $request->input('farm_id'),
          'start_weight'      =>  $request->input('start_weight'),
          'end_weight'        =>  $request->input('end_weight'),
          'crates'            =>  $request->input('crates') == NULL ? 0 : $request->input('crates'),
          'date_created'      =>  $date_created,
          'status'            =>  'entered',
          'user_id'            =>  $request->input('user_id'),
          'type'              =>  $request->input('type'),
          'group_bin_id'      =>  $request->input('group_bin_id'),
          // 'bins'              =>  $request->input('bins'),
          'number_of_pigs'    =>  $request->input('number_of_pigs'),
          'unique_id'         =>  $request->input('unique_id')
        );

        if($request->input('type') == "farrowing"){
          $data['rooms'] = $request->input('rooms');
        } else {
          $data['bins'] = $request->input('bins');
        }

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->updateGroupAPI($data);
        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Updated Animal Group",
            "group" =>  $am_lists
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "amCreateTransfer":

        // $year = substr($request->input('date'), -4);
        // $month_day = substr($request->input('date'), 0, 5);
        // $date = $year . "-" . $month_day;

        $data = array(
          'transfer_type'    =>  $request->input('transfer_type'),
          'group_from'      =>  $request->input('group_from'),
          'group_to'        =>  $request->input('group_to'),
          'driver_id'        =>  $request->input('driver_id'),
          'date'            =>   $request->input('date'),
          'number_of_pigs'  =>  $request->input('number_of_pigs'),
          'trailer'         =>  $request->input('trailer')
        );

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->createTransferAPI($data);
        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Created Transfer Animal Group",
            "group" =>  $am_lists
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "amCreateTransferV2":

          $data = array(
            'transfer_type'     =>  $request->input('transfer_type'),
            'group_from'        =>  $request->input('group_from'),
            'group_to'          =>  $request->input('group_to'),
            'status'            =>  'created',
            'date'              =>  date("Y-m-d", strtotime($request->input('date'))),
            'shipped'           =>  $request->input('shipped'), // sow/nursery/finisher
            'empty_weight'      =>  0,
            'ave_weight'        =>  $request->input('ave_weight'),
            'driver_id'         =>  $request->input('driver_id'),
            'full_weight'       =>  0,
            'received'          =>  0,
            'dead'              =>  0,
            'pigs_to'           =>  $request->input('to_pigs'),
            'raptured'          =>  $request->input('raptured'),
            'joint'             =>  $request->input('joint'),
            'poor'              =>  $request->input('poor'),
            'farm_count'        =>  $request->input('farm_count'), // nusery count/ finisher count/ market count
            'final_count'       =>  $request->input('final_count'), // start number
            'trailer_number'    =>  $request->input('trailer'),
            'notes'             =>  $request->input('notes') == NULL ? "--" : $request->input('notes'),
            'user_id'           =>  $request->input('user_id'),
          );

          // execute the finalize transfer


          $am_controller = new AnimalMovementController;
          $am_lists = $am_controller->createTransferAPIV2($data);
          unset($am_controller);

          if (!empty($am_lists)) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully Created Transfer Animal Group",
              "group" =>  $am_lists
            );
          } else {
            return $this->errorMessage();
          }

          break;

      case "amUpdateTransfer":

        $data = array(
          'transfer_id'       =>  $request->input('transfer_id'),
          'transfer_type'      =>  $request->input('transfer_type'),
          'group_from'        =>  $request->input('group_from'),
          'group_to'          =>  $request->input('group_to'),
          'status'            =>  'created',
          'date'              =>   date("Y-m-d", strtotime($request->input('date'))),
          'shipped'            =>  $request->input('shipped'),
          'empty_weight'      =>  $request->input('empty_weight'),
          'ave_weight'        =>  $request->input('ave_weight'),
          'driver_id'          =>  $request->input('driver_id'),
          'full_weight'        =>  $request->input('full_weight'),
          'received'          =>  $request->input('received'),
          // 'dead'              =>  $request->input('dead'),
          'raptured'          =>  $request->input('raptured'),
          'joint'             =>  $request->input('joint'),
          'poor'              =>  $request->input('poor'),
          'farm_count'        =>  $request->input('farm_count'),
          'final_count'        =>  $request->input('final_count'),
          'trailer_number'     =>   $request->input('trailer'),
          'notes'              =>  $request->input('notes')
        );

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->updateTransferAPI($data);
        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Created Transfer Animal Group",
            "group" =>  $am_lists
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "amFinalizeTransfer":

        $transfer_data = array(
          'transfer_id' => $request->input('transfer_id'),
          'transfer_type' =>  $request->input('transfer_type'),
          'date' =>  $request->input('date'),
          'group_from' =>  $request->input('group_from'),
          'group_to' =>  $request->input('group_to'),
          'empty_weight' =>  $request->input('empty_weight'),
          'full_weight' =>  $request->input('full_weight'),
          'ave_weight' =>  $request->input('ave_weight'),
          'shipped' =>  $request->input('shipped'),
          'received' =>  $request->input('received'),
          'raptured' =>  $request->input('raptured'),
          'joint' =>  $request->input('joint'),
          'poor' =>  $request->input('poor'),
          'farm_count' =>  $request->input('farm_count'),
          'final_count' =>  $request->input('final_count'),
          'driver_id' =>  $request->input('driver_id'),
          'unique_id' =>  $request->input('unique_id'),
          'user_id' =>  $request->input('user_id')
        );

        $validator = Validator::make($request->all(), [
          'transfer_id'     =>  'required',
          'transfer_type'   =>  'required',
          'date'            =>  'required',
          'group_from'      =>  'required',
          'group_to'        =>  'required',
          'empty_weight'    =>  'required',
          'full_weight'     =>  'required',
          'ave_weight'      =>  'required',
          'shipped'         =>  'required',
          'received'        =>  'required',
          'raptured'        =>  'required',
          'joint'            =>  'required',
          'poor'            =>  'required',
          'farm_count'      =>  'required',
          'final_count'     =>  'required',
          'driver_id'       =>  'required'
        ]);
        if (!empty($validator->errors()->all())) {
          return $validator->errors()->all();
        }

        $data = array(
          'transfer_data' => $transfer_data,
          'bins_from' => $request->input('bins_from'),
          'bins_from_pigs' => $request->input('bins_from_pigs'),
          'bins_to' => $request->input('bins_to'),
          'bins_to_pigs' => $request->input('bins_to_pigs'),
          // 'num_of_pigs_dead' => $request->input('num_of_pigs_dead'),
          'num_of_pigs_raptured' => $request->input('num_of_pigs_dead'),
          'num_of_pigs_joint' => $request->input('num_of_pigs_dead'),
          'num_of_pigs_poor' => $request->input('num_of_pigs_poor')
        );


        // $home_crtl = new HomeController;

        // for($i=0; $i<count($data['bins_to']); $i++){
        //
        //   $farm_id = DB::table("feeds_movement_groups")
        //               ->where("group_id",$transfer_data['group_to'])
        //               ->select('farm_id')
        //               ->first();
        //
        //   $u_id = $home_crtl->generator();
        //
        //   $dt_raptured = array(
        //     'death_date'    =>  date("Y-m-d"),
        //     'farm_id'       =>  $farm_id->farm_id,
        //     'group_id'      =>  $transfer_data['group_to'],
        //     'bin_id'        =>  $data['bins_to'][$i],
        //     'room_id'       =>  0,
        //     'cause'         =>  13,
        //     'amount'        =>  $data['num_of_pigs_raptured'][$i],
        //     'notes'         =>  "--",
        //     'unique_id'     =>  $u_id
        //   );
        //
        //   $dt_joint = array(
        //     'death_date'    =>  date("Y-m-d"),
        //     'farm_id'       =>  $farm_id->farm_id,
        //     'group_id'      =>  $transfer_data['group_to'],
        //     'bin_id'        =>  $data['bins_to'][$i],
        //     'room_id'       =>  0,
        //     'cause'         =>  13,
        //     'amount'        =>  $data['num_of_pigs_joint'][$i],
        //     'notes'         =>  "--",
        //     'unique_id'     =>  $u_id
        //   );
        //
        //   if($data['num_of_pigs_dead'][$i] != 0){
        //     DB::table("feeds_groups_dead_pigs")->insert($dt_raptured);
        //     DB::table("feeds_groups_dead_pigs")->insert($dt_joint);
        //   // }
        //
        // }
        // unset($home_crtl);



        $am_controller = new AnimalMovementController;
        $am_transfer = $am_controller->finalizeTransfer($data);
        unset($am_controller);

        if (!empty($am_lists)) {
          return array(
            "err"     =>  0,
            "msg"     =>  $am_transfer
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "amRemoveTransfer":

        $transfer_id = $request->input('transfer_id');
        $user_id = $request->input('user_id');
        $group_id = $request->input('groupID');
        $am_controller = new AnimalMovementController;
        $am_transfer = $am_controller->removeTransfer($transfer_id, $user_id, $group_id);
        unset($am_controller);


          return array(
            "data"    =>  $am_transfer,
            "err"     =>  0,
            "msg"     =>  "Successfully Deleted"
          );


        break;

        /* End Animal Movement */

        /* Feed Types */
      case "ftListAll":

        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiListAll();
        unset($ft_controller);

        if (!empty($ft_data)) {
          return array(
            "feed_types"  =>  $ft_data,
            "err"     =>  0,
            "msg"     =>  "Successfully get data"
          );
        } else {
          return $this->errorMessage();
        }

        break;


      case "ftCreate":

        $data = array(
          'name' => $request->input('name'),
          'description' => $request->input('description'),
          'budgeted_amount' => $request->input('budgeted_amount'),
          'total_days' => $request->input('total_days'),
          'user_id' => $request->input('user_id'),
          'created_at'  =>  date('Y-m-d H:i:s')
        );

        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiCreate($data);
        unset($ft_controller);

        if ($ft_data != "Feed type has same name") {
          return array(
            "feed_type_data"  =>  $ft_data,
            "err"     =>  0,
            "msg"     =>  "Successfully Created Feed Type"
          );
        } else {
          return array(
            "err" =>  1,
            "msg" =>  $ft_data
          );
        }

        break;


      case "ftUpdate":

        $data = array(
          'name' => $request->input('name'),
          'description' => $request->input('description'),
          'budgeted_amount' => $request->input('budgeted_amount'),
          'total_days' => $request->input('total_days'),
          'user_id' => $request->input('user_id'),
          'updated_at'  =>  date('Y-m-d H:i:s')
        );

        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiUpdate($data, $request->input('type_id'));
        unset($ft_controller);

        if ($ft_data != "Feed type has same name") {
          return array(
            "feed_type_data"  =>  $ft_data,
            "err"     =>  0,
            "msg"     =>  "Successfully Updated Feed Type"
          );
        } else {
          return array(
            "err" =>  1,
            "msg" =>  $ft_data
          );
        }

        break;


      case "ftDelete":

        $feed_type_id = $request->input('type_id');
        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiDelete($feed_type_id);
        unset($ft_controller);

        if ($ft_data == "deleted") {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Deleted Feed Type"
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "ftUpdateDaysAmount":

        $days_amount = $request->input('days_amount');
        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiUpdateDaysAmount($days_amount, $request->input('type_id'));
        unset($ft_controller);

        if ($ft_data == "success") {
          return array(
            "data"    =>  $ft_data,
            "err"     =>  0,
            "msg"     =>  "Successfully Updated Feed Type Budgeted Per Day"
          );
        } else {
          return $this->errorMessage();
        }

        break;
        /* End Feed Types */



        /* Release Notes API */
      case "rnList":

        $rn_controller = new MiscController;
        $rn_data = $rn_controller->apiGetReleaseNotes();
        unset($rn_controller);

        if ($rn_data != NULL) {
          return array(
            "data"    => $rn_data,
            "err"     =>  0,
            "msg"     =>  "Successfully get latest release notes data"
          );
        } else {
          return $this->errorMessage();
        }

        break;

      case "rnAdd":

        $rn_controller = new MiscController;
        $rn_data = $rn_controller->apiSaveReleaseNotes($request->input('description'));
        unset($rn_controller);

        if ($rn_data == "success") {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully added release notes"
          );
        } else {
          return $this->errorMessage();
        }

        break;
        /* End of Release Notes API */

        /* Drivers Map API */
      // case "lmDrivers":
      //
      //   $lm_controller = new MiscController;
      //   $lm_data = $lm_controller->apiLMDriver();
      //   unset($lm_controller);
      //
      //
      //   if ($lm_data != NULL) {
      //     return array(
      //       "err"     =>  0,
      //       "msg"     =>  "Successfully get the drivers with active deliveries",
      //       "data"    =>  $lm_data
      //     );
      //   } else {
      //     return array(
      //       "err"     =>  0,
      //       "msg"     =>  "empty result, no drivers with active deliveries",
      //       "data"    =>  $lm_data
      //     );
      //   }
      //
      //   break;

        /*
        * Farms Profile
        */
        case "fpList": // list of farms profile
          $fp_controller = new FarmsController;
          $fp_data = $fp_controller->apiPFList();
          unset($fp_controller);


          if ($fp_data != NULL) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully get the farms profile lists",
              "data"    =>  $fp_data
            );
          } else {
            return array(
              "err"     =>  0,
              "msg"     =>  "empty result",
              "data"    =>  $fp_data
            );
          }
        break;

        case "fpAFarmer": // available farmer
          $farm_id = $request->input('farm_id');
          $fp_controller = new FarmsController;
          $fp_data = $fp_controller->availableFarmersAPI($farm_id);
          unset($fp_controller);

          if ($fp_data != NULL) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully get the available farms",
              "data"    =>  $fp_data
            );
          } else {
            return array(
              "err"     =>  0,
              "msg"     =>  "empty result",
              "data"    =>  $fp_data
            );
          }
        break;

        case "fpAddFarmer": // add farmer
          $farm_id = $request->input('farm_id');
          $farmer_id = $request->input('farmer_id');
          $fp_controller = new FarmsController;
          $fp_data = $fp_controller->saveFarmerAPI($farm_id,$farmer_id);
          unset($fp_controller);


          if ($fp_data != NULL) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully get data",
              "data"    =>  $fp_data
            );
          } else {
            return array(
              "err"     =>  0,
              "msg"     =>  "empty result",
              "data"    =>  $fp_data
            );
          }
        break;

        case "fpRemoveFarmer": // remove farmer
          $farm_id = $request->input('farm_id');
          $farmer_id = $request->input('farmer_id');
          $fp_controller = new FarmsController;
          $fp_data = $fp_controller->removeFarmerAPI($farm_id,$farmer_id);
          unset($fp_controller);


          if ($fp_data != NULL) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully remove user",
              "data"    =>  $fp_data
            );
          } else {
            return array(
              "err"     =>  0,
              "msg"     =>  "empty result",
              "data"    =>  $fp_data
            );
          }
        break;
        /*
        * End of Farms Profile
        */

        /* Users API */
        case "uaList":

          $ua_controller = new UsersController;
          $ua_data = $ua_controller->apiList();
          unset($ua_controller);

          if ($ua_data) {
            return array(
              "data"    =>  $ua_data,
              "err"     =>  0,
              "msg"     =>  "Successfully get all the users data"
            );
          } else {
            return $this->errorMessage();
          }

          break;

        case "uaAdd":

          $data = array(
            'username' => $request->input('username'),
            'email'  =>  $request->input('email'),
            'password'  => $request->input('password'),
            'first_name'  => $request->input('first_name'),
            'last_name' =>  $request->input('last_name'),
            'contact_number'  =>  $request->input('contact_number'),
            'type'  => $request->input('type')
          );

          $ua_controller = new UsersController;
          $ua_data = $ua_controller->apiAdd($data);
          unset($ua_controller);

          if ($ua_data) {

            if ($ua_data['err'] == 1) {
              return $ua_data;
            }
            return $ua_data;
          } else {
            return $this->errorMessage();
          }

          break;

        case "uaUpdate":

          $data = array(
            'user_id' =>  $request->input('user_id'),
            'username' => $request->input('username'),
            'pass'  => $request->input('password'),
            'type_id'  => $request->input('type'),
            'email'  =>  $request->input('email'),
            'first_name'  => $request->input('first_name'),
            'last_name' =>  $request->input('last_name'),
            'contact_number'  =>  $request->input('contact_number')
          );

          $ua_controller = new UsersController;
          $ua_data = $ua_controller->apiUpdate($data);
          unset($ua_controller);

          return $ua_data;

          if ($ua_data) {
            if ($ua_data['err'] == 1) {
              return $ua_data;
            }
            return $ua_data;
          } else {
            return $this->errorMessage();
          }

          break;

        case "uaDelete":

          $ua_controller = new UsersController;
          $ua_data = $ua_controller->apiDelete($request->input('user_id'));
          unset($ua_controller);

          if ($ua_data == "deleted") {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully deleted user data"
            );
          } else {
            return $this->errorMessage();
          }

        break;
        /* End of Users API */

        /*
        * Death Tracker
        */
        // list death
        case "dtList":

          $data = $request->all();

          $dt = DB::table("feeds_death_tracker")
                ->whereBetween('death_date',[$data['start'],$data['end']])
                ->groupBy('unique_id')
                ->selectRaw('*, sum(death_number) as total_death')
                ->orderBy('death_id','desc')->get();

          for($i=0; $i<count($dt); $i++){

            $dt[$i]->bins_rooms = DB::table("feeds_death_tracker")
                                  ->where('feeds_death_tracker.unique_id',$dt[$i]->unique_id)
                                  ->get();

              for($y=0; $y<count($dt[$i]->bins_rooms); $y++){

                $dt[$i]->bins_rooms[$y]->room_number = "";

                if($dt[$i]->bins_rooms[$y]->room_id !=0){

                  $dt[$i]->bins_rooms[$y]->room_number = DB::table("feeds_farrowing_rooms")
                                                            ->where('id',$dt[$i]->bins_rooms[$y]->room_id)
                                                            ->select('room_number')
                                                            ->value('room_number');

                }

                $dt[$i]->bins_rooms[$y]->bin_alias = "";
                if($dt[$i]->bins_rooms[$y]->bin_id != 0){

                  $dt[$i]->bins_rooms[$y]->bin_alias = DB::table("feeds_bins")
                                                            ->where('bin_id',$dt[$i]->bins_rooms[$y]->bin_id)
                                                            ->select('alias')
                                                            ->value('alias');

                }


              }

            $dt[$i]->type = "farrowing";

            // $dt[$i]->death_logs = DB::table("feeds_death_tracker_logs")
            //                       ->where('death_unique_id',$dt[$i]->unique_id)
            //                       ->select('*', DB::raw('sum(total_pigs) as total_pigs'),DB::raw('sum(original_total_pigs) as original_total_pigs'))
            //                       ->groupBy('death_unique_id')
            //                       ->get();

            $dt[$i]->death_logs = DB::table("feeds_death_tracker_logs")
                                  ->where('death_unique_id',$dt[$i]->unique_id)
                                  ->where('action','!=','deleted')
                                  ->orderBy('log_id','desc')
                                  ->get();

            for($z=0; $z<count($dt[$i]->death_logs); $z++){
              $dt[$i]->death_logs[$z]->datereadable = date("H:i a M-d-Y", strtotime($dt[$i]->death_logs[$z]->date_time_logs));
            }

            if($dt[$i]->bin_id != 0){
              $dt[$i]->type = "notfarrowing";
            }

          }

          return $dt;

        break;

        // add death
        case "dtAdd":

          $data = $request->all();
          $home_crtl = new HomeController;
          $u_id = $home_crtl->generator();
          $dtl = array();

          for($i=0; $i<count($data['deathNumber']); $i++){
            $dt[] = array(
              'death_date'  =>  $request->input('dateOfDeath'),
              'farm_id'     =>  $request->input('farmID'),
              'group_id'    =>  $request->input('groupID'),
              'bin_id'      =>  $request->has("binID") ? $data['binID'][$i] : 0,
              'room_id'     =>  $request->has("roomID") ? $data['roomID'][$i] : 0,
              'reason'      =>  $data['reason'][$i] == "" ? "No entered reason." : $data['reason'][$i],
              'death_number'  =>  $data['deathNumber'][$i],
              'unique_id'   =>  $u_id
            );

            $group_uid = $this->animalGroupsData($request->input('groupID'));

            $pigs = $this->groupRoomsBinsPigs($group_uid->unique_id,
                                              $dt[$i]['bin_id'],
                                              $dt[$i]['room_id']);

            // save the logs of original total number of pigs
            $dtl[] = array(
                      'death_unique_id' => $u_id,
                      'date_time_logs'  =>  date("Y-m-d H:i:s"),
                      'user_id' =>  $request->input('userID'),
                      'bin_id'  =>  $dt[$i]['bin_id'],
                      'room_id' =>  $dt[$i]['room_id'],
                      'original_total_pigs' => $pigs->number_of_pigs,
                      'total_pigs'  => $data['deathNumber'][$i],
                      'action'  =>  "add death record"
                    );

            // deduct the death on rooms or bins, after deduction, update the cache
            $num_of_pigs = $pigs->number_of_pigs - $data['deathNumber'][$i];
            $this->updateBinsRooms($group_uid->unique_id,
                                   $dt[$i]['bin_id'],
                                   $dt[$i]['room_id'],
                                   $num_of_pigs);

            $home_crtl->clearBinsCache($dt[$i]['bin_id']);


          }

          DB::table("feeds_death_tracker_logs")->insert($dtl);
          DB::table("feeds_death_tracker")->insert($dt);

          unset($home_crtl);

          return $data;

        break;

        // update death
        case "dtUpdate":

          $data = $request->all();

          $home_crtl = new HomeController;
          $orig_total_pigs = 0;
          $unique_id = $data['uID'][0];

          for($i=0; $i<count($data['deathID']); $i++) {

              $death_id = $data['deathID'][$i];
              $death_number = $data['numberOfPigs'][$i];
              $reason = $data['reason'][$i];
              $bin_id = $data['binID'][$i];
              $room_id = $data['roomID'][$i];

              $dt_data = $this->deathTrackerData($death_id);

              $group_uid = $this->animalGroupsData($dt_data->group_id);

              $pigs = $this->groupRoomsBinsPigs($group_uid->unique_id,$bin_id,$room_id);

              // update the death tracker
              DB::table("feeds_death_tracker")
                ->where('death_id',$death_id)
                ->update([
                      'death_number'  =>  $death_number,
                      'reason'  =>  $reason
                ]);

              // insert the new data to the death tracker logs
              $death_logs[] = array(
                'date_time_logs'  =>  date("Y-m-d H:i:s"),
                'user_id' =>  $data['user_id'],
                'death_unique_id'  => $unique_id,
   	            'bin_id'  =>  $bin_id,
                'room_id' =>   $room_id,
  	            'original_total_pigs' => $pigs->number_of_pigs,
                'total_pigs' => $death_number,
                'action' => "update death record"
              );


              $death_number = ($dt_data->death_number + $pigs->number_of_pigs) - $death_number;


              $this->updateBinsRooms($group_uid->unique_id,
                                     $data['binID'][$i],
                                     $data['roomID'][$i],
                                     $death_number);

              $home_crtl->clearBinsCache($data['binID'][$i]);
          }

          DB::table("feeds_death_tracker_logs")->insert($death_logs);

          unset($home_crtl);

          return $data;

        break;

        // delete death
        case "dtDelete":

          $data = $request->all();

          // bring back the dead pigs
          $death = $this->deathTrackerBringBackData($data['uid']);

          DB::table("feeds_death_tracker")
                ->where('unique_id',$data['uid'])
                ->delete();

          DB::table("feeds_death_tracker_logs")
                ->where('death_unique_id',$data['uid'])
                ->update(["action"=>"deleted","user_id"=>$data['userID']]);

          return $data;

        break;

        // read reason
        case "drRead":

          $ds = DB::table("feeds_death_reasons")->orderBy('reason_id','desc')->get();

          $result = array(
            "err"     =>  0,
            "msg"     =>  "with result",
            "data"    =>  $ds
          );

          if(empty($ds)){
            $result['msg'] = "empty";
          }

          return $result;

        break;

        // add death reason
        case "drAdd":

          $data = $request->all();
          $reason = $data['reason'];

          $validation = Validator::make($data, [
  						'reason' => 'required|min:4'
  				]);

          if($validation->fails()){
  					return array(
  						'err' => 1,
  						'msg' => $validation->errors()->all()
  					);
  				}

          DB::table("feeds_death_reasons")->insert(['reason'=>$reason]);

          return $data;

        break;

        // update reason
        case "drUpdate":

          $data = $request->all();
          $id = $data['reason_id'];
          $reason = $data['reason'];

          $validation = Validator::make($data, [
  						'reason' => 'required|min:4'
  				]);

          if($validation->fails()){
  					return array(
  						'err' => 1,
  						'msg' => $validation->errors()->all()
  					);
  				}


          DB::table("feeds_death_reasons")
          ->where('reason_id',$id)
          ->update(['reason'=>$reason]);

          return $data;

        break;

        // delete reason
        case "dsDelete":

          $data = $request->all();
          $id = $data['reason_id'];
          DB::table("feeds_treatments")->where("reason_id",$id)->delete();

          return $data;

        break;


        // read treatment
        case "trRead":

          $ts = DB::table("feeds_treatments")->orderBy('t_id','desc')->get();

          $result = array(
            "err"     =>  0,
            "msg"     =>  "with result",
            "data"    =>  $ts
          );

          if(empty($ts)){
            $result['msg'] = "empty";
          }

          return $result;

        break;

        // add treatment
        case "trAdd":

          $data = $request->all();
          $treatmnent = $data['treatment'];

          $validation = Validator::make($data, [
  						'treatment' => 'required|min:4'
  				]);

          if($validation->fails()){
  					return array(
  						'err' => 1,
  						'msg' => $validation->errors()->all()
  					);
  				}

          DB::table("feeds_treatments")->insert(['treatment'=>$treatmnent]);

          return $data;

        break;

        // update treatment
        case "trUpdate":

          $data = $request->all();
          $id = $data['t_id'];
          $treatment = $data['treatment'];

          $validation = Validator::make($data, [
  						'treatment' => 'required|min:4'
  				]);

          if($validation->fails()){
  					return array(
  						'err' => 1,
  						'msg' => $validation->errors()->all()
  					);
  				}


          DB::table("feeds_treatments")
          ->where('t_id',$id)
          ->update(['treatment'=>$treatment]);

          return $data;

        break;

        // delete Treatment
        case "trDelete":

          $data = $request->all();
          $id = $data['t_id'];
          DB::table("feeds_treatments")->where("t_id",$id)->delete();

          return $data;

        break;


        /*
        * Group Death Feature
        */
        case "gdrAdd":

            $data = $request->all();

            $home_crtl = new HomeController;
            $u_id = $home_crtl->generator();
            $dtl = array();

            if($data['notes'] == "" || $data['notes'] == NULL){
              $data['notes'] = "--";
            }

            $dt = array(
              'death_date'    =>  $data['dateOfDeath'],
              'farm_id'       =>  $data['farmID'],
              'group_id'      =>  $data['groupID'],
              'bin_id'        =>  $data['binID'],
              'room_id'       =>  $data['roomID'],
              'cause'         =>  $data['reason'],
              'amount'        =>  $data['deathNumber'],
              'notes'         =>  $data['notes'],
              'unique_id'     =>  $u_id
            );

            // $group_uid = $this->animalGroupsData($dt['group_id']);

            $group_data = DB::table("feeds_movement_groups")
                            ->select('unique_id')
                            ->where("group_id",$dt['group_id'])
                            ->get();


            $pigs = $this->groupRoomsBinsPigs($group_data[0]->unique_id,
                                              $dt['bin_id'],
                                              $dt['room_id']);

            $dtl = array(
                      'death_unique_id' => $u_id,
                      'date_time_logs'  =>  date("Y-m-d H:i:s"),
                      'group_id'  =>  $dt['group_id'],
                      'user_id' =>  $data['userID'],
                      'bin_id'  =>  $dt['bin_id'],
                      'room_id' =>  $dt['room_id'],
                      'original_pigs' => $pigs->number_of_pigs,
                      'pigs'  => $data['deathNumber'],
                      'action'  =>  "add death record"
                    );

            // deduct the death on rooms or bins, after deduction, update the cache
            $num_of_pigs = $pigs->number_of_pigs - $data['deathNumber'];
            $this->updateBinsRooms($group_data[0]->unique_id,
                                   $dt['bin_id'],
                                   $dt['room_id'],
                                   $num_of_pigs);

            if($dt['bin_id'] != 0){
                $home_crtl->clearBinsCache($dt['bin_id']);
            }


            DB::table("feeds_groups_dead_pigs_logs")->insert($dtl);
            DB::table("feeds_groups_dead_pigs")->insert($dt);

            unset($home_crtl);

            // return the list of deaths with corresponding group id
            $aml_ctrl = new AnimalMovementController;
            $death_lists = $aml_ctrl->amDeadPigs($data['groupID']);
            $death_perc = $aml_ctrl->deathPercentage($data['groupID'],"open");
            $treated_perc = $aml_ctrl->treatedPercentage($data['groupID'],"open");
            $pigs_per_crate = $aml_ctrl->avePigsPerCrate($data['groupID']);
            unset($aml_ctrl);

            $result = array(
              "err"     =>  0,
              "msg"     =>  "with result",
              "data"    =>  $death_lists,
              "death_perc"  =>  $death_perc,
              "treated_perc"  =>  $treated_perc,
              "pigs_per_crate"  =>  $pigs_per_crate,
              "total_group_pigs" => $this->totalPigs($data['groupID'])
            );

            return $result;

            break;



            case "gdrUpdate":

              $data = $request->all();

              $home_crtl = new HomeController;
              $u_id = $home_crtl->generator();
              $dtl = array();


              if($data['notes'] == "" || $data['notes'] == NULL){
                $data['notes'] = "--";
              }

              $date = date("Y-m-d",strtotime($data['dateOfDeath']))." 00:00:00";


              $dt = array(
                'death_date'    =>  $date,
                'farm_id'       =>  $data['farmID'],
                'group_id'      =>  $data['groupID'],
                'bin_id'        =>  $data['binID'],
                'room_id'       =>  $data['roomID'],
                'cause'         =>  $data['reason'],
                'amount'        =>  $data['deathNumber'],
                'notes'         =>  $data['notes']
              );



              // $group_uid = $this->animalGroupsData($dt['group_id']);

              $dp_data = $this->deathTrackerDataV2($data['deathID']);

              $group_data = DB::table("feeds_movement_groups")
                              ->select('unique_id')
                              ->where("group_id",$dt['group_id'])
                              ->get();


              $pigs = $this->groupRoomsBinsPigs($group_data[0]->unique_id,
                                                $dt['bin_id'],
                                                $dt['room_id']);

              $current_dead = DB::table("feeds_groups_dead_pigs")
                                ->select('amount')
                                ->where('death_id',$data['deathID'])
                                ->get();

              $dtl = array(
                        'death_unique_id' => $data['uid'],
                        'date_time_logs'  =>  date("Y-m-d H:i:s"),
                        'group_id'  =>  $data['groupID'],
                        'user_id' =>  $data['userID'],
                        'bin_id'  =>  $data['binID'],
                        'room_id' =>  $data['roomID'],
                        'original_pigs' => $pigs->number_of_pigs,
                        'pigs'  => $current_dead[0]->amount,//$data['deathNumber'],
                        'action'  =>  "update death record"
                      );

              $type = "deduct-pig";
              if($dp_data[0]->amount > $data['deathNumber']) {
                $type = "bring-back-pig";
              }

              $death_number = ($dp_data[0]->amount + $pigs->number_of_pigs) - $data['deathNumber'];
              // deduct the death on rooms or bins, after deduction, update the cache
              // $num_of_pigs = $pigs->number_of_pigs - $data['deathNumber'];
              $this->updateBinsRooms($group_data[0]->unique_id,
                                     $dt['bin_id'],
                                     $dt['room_id'],
                                     $death_number);

              $home_crtl->clearBinsCache($dt['bin_id']);

              DB::table("feeds_groups_dead_pigs_logs")->insert($dtl);
              DB::table("feeds_groups_dead_pigs")
                ->where('death_id',$data['deathID'])
                ->update($dt);


              unset($home_crtl);

              // return the list of deaths with corresponding group id
              $aml_ctrl = new AnimalMovementController;
              $death_lists = $aml_ctrl->amDeadPigs($dt['group_id']);
              $death_perc = $aml_ctrl->deathPercentage($dt['group_id'],"open");
              $treated_perc = $aml_ctrl->treatedPercentage($dt['group_id'],"open");
              $pigs_per_crate = $aml_ctrl->avePigsPerCrate($dt['group_id']);
              unset($aml_ctrl);


              $result = array(
                "err"     =>  0,
                "msg"     =>  "with result",
                "data"    =>  $death_lists,
                "death_perc"  =>  $death_perc,
                "treated_perc"  =>  $treated_perc,
                "pigs_per_crate"  =>  $pigs_per_crate,
                "total_group_pigs" => $this->totalPigs($dt['group_id'])
              );

              return $result;


        break;



        case "gdrDelete":

          $data = $request->all();

          // bring back the data first before deleting the death record
          // get the death_id then the pigs and update the data based on room_id or bin_id
          $dp_data = DB::table("feeds_groups_dead_pigs")
                ->where('death_id',$data['death_id'])
                ->get();

          $bring_back_dp = array();

          $home_crtl = new HomeController;

          for($i=0; $i<count($dp_data); $i++){

            $ag_data = $this->animalGroupsData($dp_data[$i]->group_id);
            $bins_rooms_data = $this->groupRoomsBinsPigs($ag_data->unique_id,
                                                        $dp_data[$i]->bin_id,
                                                        $dp_data[$i]->room_id);

            $back_pigs = $bins_rooms_data->number_of_pigs + $dp_data[$i]->amount;

            $this->updateBinsRooms($ag_data->unique_id,
                                   $dp_data[$i]->bin_id,
                                   $dp_data[$i]->room_id,
                                   $back_pigs);

            $home_crtl->clearBinsCache($dp_data[$i]->bin_id);

            $bring_back_dp[] = array(
              'bin_id'  =>  $dp_data[$i]->bin_id,
              'room_id' =>  $dp_data[$i]->room_id,
              'number_of_pigs'  =>  $back_pigs,
              'unique_id' =>  $ag_data->unique_id
            );

          }

          unset($home_crtl);

          DB::table("feeds_groups_dead_pigs")
                ->where('death_id',$data['death_id'])
                ->delete();

          DB::table("feeds_groups_dead_pigs_logs")
                ->where('death_unique_id',$data['unique_id'])
                ->update(["action"=>"deleted","user_id"=>$data['user_id']]);

          // return the list of deaths with corresponding group id
          $aml_ctrl = new AnimalMovementController;
          $death_lists = $aml_ctrl->amDeadPigs($data['group_id']);
          $death_perc = $aml_ctrl->deathPercentage($data['group_id'],"open");
          $treated_perc = $aml_ctrl->treatedPercentage($data['group_id'],"open");
          $pigs_per_crate = $aml_ctrl->avePigsPerCrate($data['group_id']);
          unset($aml_ctrl);

          $result = array(
            "err"     =>  0,
            "msg"     =>  "with result",
            "data"    =>  $death_lists,
            "death_perc"  =>  $death_perc,
            "treated_perc"  =>  $treated_perc,
            "pigs_per_crate"  =>  $pigs_per_crate,
            "bring_back_pigs" =>  $bring_back_dp,
            "total_group_pigs"  => $this->totalPigs($data['group_id'])
          );

          return $result;

        break;
        // End of Death Feature


        /*
        * Group Treated Feature
        */
        case "gtrAdd":

            $data = $request->all();
            unset($data['action']);
            if($data['notes'] == "" || $data['notes'] == NULL){
              $data['notes'] = "--";
            }

            DB::table("feeds_groups_treated_pigs")
                  ->insert($data);

            $aml_ctrl = new AnimalMovementController;
            $tr_lists = $aml_ctrl->amTreatedPigs($data['group_id']);
            $treated_perc = $aml_ctrl->treatedPercentage($data['group_id'],"open");
            unset($aml_ctrl);

            $result = array(
              "err"     =>  0,
              "msg"     =>  "with result",
              "treated_perc"  =>  $treated_perc,
              "data"    =>  $tr_lists
            );

            return $result;

        break;

        case "gtrUpdate":

          $data = $request->all();
          unset($data['action']);
          if($data['notes'] == "" || $data['notes'] == NULL){
            $data['notes'] = "--";
          }

          $treated_id = $data['treated_id'];
          unset($data['treated_id']);


          $data['date'] = $data['date'] . " 00:00:00";

          DB::table("feeds_groups_treated_pigs")
                ->where("treated_id",$treated_id)
                ->update($data);

          $aml_ctrl = new AnimalMovementController;
          $tr_lists = $aml_ctrl->amTreatedPigs($data['group_id']);
          $treated_perc = $aml_ctrl->treatedPercentage($data['group_id'],"open");
          unset($aml_ctrl);

          $result = array(
            "err"     =>  0,
            "msg"     =>  "with result",
            "treated_perc"  =>  $treated_perc,
            "data"    =>  $tr_lists
          );

          return $result;

        break;

        case "gtrDelete":

          $data = $request->all();

          DB::table("feeds_groups_treated_pigs")
            ->where('treated_id',$data['treated_id'])
            ->delete();

            $aml_ctrl = new AnimalMovementController;
            $tr_lists = $aml_ctrl->amTreatedPigs($data['group_id']);
            $treated_perc = $aml_ctrl->treatedPercentage($data['group_id'],"open");
            unset($aml_ctrl);

            $result = array(
              "err"     =>  0,
              "msg"     =>  "with result",
              "treated_perc"  =>  $treated_perc,
              "data"    =>  $tr_lists
            );

            return $result;

        break;

        case "totalGroupPigs":

          $data = $request->all();

          $uid = DB::table("feeds_movement_groups")
            ->where('group_id',$data['group_id'])
            ->get();

          $group_transfer_shipped = DB::table("feeds_movement_transfer_v2")
                              ->where("group_from",$data['group_id'])
                              ->whereIn("status",["created","edited"])
                              ->sum("shipped");

          $total_pigs = DB::table("feeds_movement_groups_bins")
                          ->where("unique_id",$uid[0]->unique_id)
                          ->sum('number_of_pigs');

          $result = array(
            "err"     =>  0,
            "msg"     =>  "with result",
            "data"    =>  $total_pigs - $group_transfer_shipped
          );

          return $result;

        break;
        // End of Treated Feature

        /*
        * End of Death Tracker
        */

        case "binData":

          $data = $request->all();

          $group_id = $data['group_id'];

          $aml_ctrl = new AnimalMovementController;
          $bins_lists = $aml_ctrl->updatedBinData($group_id);
          unset($aml_ctrl);

          return $bins_lists;

        break;


        case "groupCon":

          $data = $request->all();

          $bin_id = $data['bin_id'];
          $amount_tons = $data['amount_tons'];
          $type = $data['type'];

          // $home_ctrl = new HomeController;
          // $cons = $home_ctrl->updateGroupsConsumption($bin_id,$amount_tons,$type);
          // unset($home_ctrl);

          return $cons;

        break;

        case "closeOutGroups":

            $data = array(
              'type'      =>  "closeOut", // (string) all, farrowing_to_nursery, nursery_to_finisher, finisher_to_market
              'date_from' =>  $request->input('date_from'), // (date)
              'date_to'   =>  $request->input('date_to'), // (date)
              'sort'      =>  $request->input('sort'), // (string) not_scheduled, day_remaining
              's_farm'    =>  $request->input('s_farm') // selected farm
            );


            $am_controller = new AnimalMovementController;
            $am_lists = $am_controller->animalMovementFilterAPI($data);
            unset($am_controller);

            if (!empty($am_lists['output'])) {
              return array(
                "err"     =>  0,
                "msg"     =>  "Successfully Get Animal Groups",
                "am_list" =>  $am_lists,
                "death_reasons" => $this->deathReasons()
              );
            } else {
              return array(
                "err" =>  1,
                "msg" =>  "No Records Found"
              );
            }

        break;

        /*
        * marc controller
        */
        case "MO":

          $output = NULL;

          $mo_ctrl = new MarcController;
          $output = $mo_ctrl->testMethod();
          unset($mo_ctrl);

          return $output;

        break;

      default:
        return array("err" => "Something went wrong");
    }
  }

  /**
   * error message
   */
  private function totalPigs($group_id)
  {
    $uid = DB::table("feeds_movement_groups")
              ->where('group_id',$group_id)
              ->select('unique_id')
              ->get();

    $am_ctrl = new AnimalMovementController;
    // $total_pigs = $am_ctrl->totalPigs($uid[0]->unique_id);
    $total_pigs = $am_ctrl->totalPigsFilter($uid[0]->unique_id,'feeds_movement_groups_bins','open');
    unset($am_ctrl);

    return $total_pigs;
  }


  /**
   * error message
   */
  private function deathReasons()
  {
    return DB::table("feeds_death_reasons")->orderBy('reason','asc')->get();
  }


  /**
   * error message
   */
  private function errorMessage()
  {
    return array(
      "err" =>  1,
      "msg" =>  "Something went wrong"
    );
  }


  /**
   * death tracker data.
   */
  private function deathTrackerData($death_id)
  {
      $dt = DB::table("feeds_death_tracker")
                ->where('death_id',$death_id)
                ->first();

      return $dt;
  }

  /**
   * death tracker data.
   */
  private function deathTrackerDataV2($death_id)
  {
      $dt = DB::table("feeds_groups_dead_pigs")
                ->where('death_id',$death_id)
                ->get();

      // for($i=0; $i<count($dt); $i++){
      //   $dt[$i]->datereadable = date("m-d-Y H:i a", strtotime($dt[$i]->date_time_logs));
      // }

      return $dt;
  }

  /**
   * death tracker logs data.
   */
  private function deathTrackerBringBackData($unique_id)
  {
      $dt = DB::table("feeds_death_tracker")
                ->where('unique_id',$unique_id)
                ->get();

      $home_crtl = new HomeController;
      for($i=0; $i<count($dt); $i++){

        $ag_data = $this->animalGroupsData($dt[$i]->group_id);
        $bins_rooms_data = $this->groupRoomsBinsPigs($ag_data->unique_id,
                                                    $dt[$i]->bin_id,
                                                    $dt[$i]->room_id);

        $back_pigs = $bins_rooms_data->number_of_pigs + $dt[$i]->death_number;

        $this->updateBinsRooms($ag_data->unique_id,
                               $dt[$i]->bin_id,
                               $dt[$i]->room_id,
                               $back_pigs);

        $home_crtl->clearBinsCache($dt[$i]->bin_id);

      }
      unset($home_crtl);

      return $dt;
  }

  /**
  * animal group
  */
  private function animalGroupsData($group_id)
  {

    $group_data = DB::table("feeds_movement_groups")
                    ->where("group_id",$group_id)
                    ->first();

    return $group_data;

  }

  private function groupsDeathRecordAdd($data)
  {

    $dt = array(
      'death_date'    =>  $data['dateOfDeath'],
      'farm_id'       =>  $data['farmID'],
      'group_id'      =>  $data['groupID'],
      'bin_id'        =>  $data['binID'],
      'room_id'       =>  $data['roomID'],
      'cause'         =>  $data['reason'],
      'amount'        =>  $data['deathNumber'],
      'notes'         =>  $data['notes'],
      'unique_id'     =>  $u_id
    );

  }


  /**
  * Get duplicate values from the array.
  */
  private function returnDup($arr)
  {

    $dups = array();
    foreach(array_count_values($arr) as $val => $c)
      if($c > 1) $dups[] = $val;

    return $dups;

  }

  /**
  * Get the number of pigs for rooms or bins in animal groups
  */
  private function groupRoomsBinsPigs($unique_id,$bin_id,$room_id)
  {

    $pigs = DB::table("feeds_movement_groups_bins");
    $pigs = $pigs->where('unique_id',$unique_id);
    if($bin_id != 0){
      $pigs = $pigs->where('bin_id',$bin_id);
    } else {
      $pigs = $pigs->where('room_id',$room_id);
    }
    $pigs = $pigs->select('number_of_pigs');
    $pigs = $pigs->first();

    return $pigs;

  }


  /*
  * Update the animal group number of pigs
  */
  private function updateBinsRooms($unique_id,$bin_id,$room_id,$num_of_pigs)
  {

    $update = DB::table("feeds_movement_groups_bins");
    $update = $update->where('unique_id',$unique_id);
    if($bin_id != 0){
      $update = $update->where('bin_id',$bin_id);
    } else {
      $update = $update->where('room_id',$room_id);
    }

    // if($type == "deduct-pig"){
      $update = $update->update(['number_of_pigs'=>$num_of_pigs]);
    // } else {
    //   $update = $update->update(['number_of_pigs'=>$num_of_pigs,"orig_number_of_pigs"=>$num_of_pigs]);
    // }


    return $update;
  }


  /*
  	*	get the delivery time of the farm
  	*/
  private function farmDeliveryTimes($farms)
  {

    $data = "";
    $farm = array_unique($farms);

    $output = Farms::select('delivery_time')->whereIn('id', $farm)->max('delivery_time');

    $counter = count($farm);
    $return = 0;
    if ($counter == 1) {
      $return = number_format((float) $output, 2, '.', '');
    } else {
      $added_minutes =  0.50 * ($counter - 1);
      $final = $output + $added_minutes;
      $return = number_format((float) $final, 2, '.', '');
    }

    //$output = "<strong class='ton_vw_sched_kb'> (". $return ." Hour/s)</strong><br/>";

    return $return;
  }


  /*
    * Get the farm name
    */
  private function farmName($id)
  {
    $output = DB::table('feeds_farms')->where('id', $id)->first();
    if ($output == NULL) {
      return "none";
    }
    return $output->name;
  }

  /*
    * Get the bin name
    */
  private function binName($id)
  {
    $output = DB::table('feeds_bins')->where('bin_id', $id)->select('bin_number', 'alias')->first();
    if ($output == NULL) {
      return "none";
    }
    return "Bin #" . $output->bin_number . " - " . $output->alias;
  }

  /*
    * Get the bin name
    */
  private function binAlias($id)
  {
    $output = DB::table('feeds_bins')->where('bin_id', $id)->select('bin_number', 'alias')->first();
    if ($output == NULL) {
      return "none";
    }
    return $output->alias;
  }

  /*
    * Get the feed name
    */
  private function feedName($id)
  {
    $output = DB::table('feeds_feed_types')->where('type_id', $id)->select('name')->first();
    if ($output == NULL) {
      return "none";
    }
    return $output->name;
  }

  /*
    * Get the medication name
    */
  private function medicationName($id)
  {
    $output = DB::table('feeds_medication')->where('med_id', $id)->select('med_name')->first();
    if ($output == NULL) {
      return "none";
    }
    return $output->med_name;
  }

  /*
    * Get the truck name
    */
  private function truckName($id)
  {
    $output = DB::table('feeds_truck')->where('truck_id', $id)->select('name')->first();
    if ($output == NULL) {
      return "none";
    }
    return $output->name;
  }

  /**
   * Build the array data of farms
   *
   * @return array
   */
  private function farmsBuilder($sort, $farms, $farms_default)
  {

    $data = array();
    foreach ($farms as $k => $v) {
      for ($i = 0; $i < count($farms_default); $i++) {
        if ($v->farm_id == $farms_default[$i]->id) {
          $data[$v->farm_id] = array(
            'farmID'              =>  $v->farm_id,
            'farmName'            =>  $v->name,
            'farmAbbr'            =>  strtoupper(substr(str_replace(" ", "", $v->name), 0, 2)),
            'farmType'            =>  $v->farm_type,
            'numberOfBins'        =>  $v->bins->bins_count,//(count((array) $v->bins) - 4) - 1,
            'numberOfLowBins'     =>  $sort != 5 ? $v->bins->lowBins : NULL,
            'hasPendingDelivery'  =>  $sort != 5 ? $v->delivery_status : NULL,
            'daysRemaining'       =>  $sort != 5 ? $this->binsDaysRemaining($v->bins) : NULL,
            'lastManulUpdate'     =>  $sort != 5 ? $v->bins->last_manual_update : NULL,
            'currentLAmount'      =>  $sort != 5 ? $v->bins->lowest_amount_bin : NULL
          );
        }
      }
    }

    if ($sort == 1) {

      // cache data via sort type a-z farms
      usort($data, function ($a, $b) {
        return strcasecmp($a["farmName"], $b["farmName"]);
      });
    } else if ($sort == 3) {

      // cache data via sort type a-z farms
      usort($data, function ($a, $b) {
        if ($a['lastManulUpdate'] == $b['lastManulUpdate']) return 0;
        return ($a['lastManulUpdate'] < $b['lastManulUpdate']) ? -1 : 1;
      });
    } else {

      // cache data via sort type low bins
      usort($data, function ($a, $b) {
        if ($a['numberOfLowBins'] == $b['numberOfLowBins'])
          return ($a['numberOfLowBins'] > $b['numberOfLowBins']);
        return ($a['numberOfLowBins'] < $b['numberOfLowBins']) ? 1 : -1;
      });
      //Storage::put('forecasting_data_v2_low_bins.txt',json_encode($data));

    }

    return $data;
  }

  /**
   * Build the array data of specific farms
   *
   * @return array
   */
  private function farmsBuilderNumberOfLowBins($farms, $farm_id)
  {
    $data = "";

    for ($i = 0; $i < count($farms); $i++) {
      if ($farm_id == $farms[$i]->farm_id) {
        $data =  $farms[$i]->bins->lowBins;
      }
    }

    return $data;
  }

  /**
   * Get the bins days remaining amount for feed consumption
   *
   * @return int
   */
  private function binsDaysRemaining($bins)
  {
    $output = array();

    foreach ($bins as $k => $v) {
      $output[] = array($k => $v);
    }

    return $output[0][0]->first_list_days_to_empty;
  }

  /**
   * Build the data of bins
   *
   * @return array
   */
  private function binsBuilder($bins)
  {
    $data = array();
    for ($i = 0; $i < count($bins); $i++) {

      $data[$bins[$i]->bin_id] = array(
        'binName'                       =>  'Bin #' . $bins[$i]->bin_number . ' - ' . $bins[$i]->alias,
        'binNumber'                     =>  $bins[$i]->bin_number,
        'binAlias'                      =>  $bins[$i]->alias,
        'amountTons'                    =>  $bins[$i]->current_bin_amount_tons,
        'dateToBeEmpty'                 =>  date("Y-m-d", strtotime($bins[$i]->empty_date)) == "1969-12-31" ? "--" : date("Y-m-d", strtotime($bins[$i]->empty_date)),
        'inComingDelivery'              =>  $bins[$i]->delivery_amount,
        'lastDelivery'                  =>  date("Y-m-d", strtotime($bins[$i]->next_deliverydd)),
        'lastUpdate'                    =>  date("Y-m-d h:i a", strtotime($bins[$i]->last_update)),
        //'lastUpdate'                    =>  date("Y-m-d H:i a",strtotime($bins[$i]->last_manual_update)),
        'sow'                           =>  $bins[$i]->num_of_sow_pigs,
        'user'                          =>  $bins[$i]->username,
        'daysRemaining'                 =>  $bins[$i]->days_to_empty,
        'currentMedication'             =>  $bins[$i]->medication_name,
        'currentMedicationDescription'  =>  $bins[$i]->medication,
        'currentMedicationID'           =>  $bins[$i]->medication_id,
        'currentFeed'                   =>  $bins[$i]->feed_type_name_orig,
        'currentFeedDescription'        =>  $bins[$i]->feed_type_name,
        'currentFeedID'                 =>  $bins[$i]->feed_type_id,
        'numberOfPigs'                  =>  $bins[$i]->total_number_of_pigs,
        'binSize'                       =>  $bins[$i]->bin_s,
        'groups'                        =>  $bins[$i]->default_val,
        'ringAmount'                    =>  $this->currentRingAmount($bins[$i]->bin_s, $bins[$i]->current_bin_amount_tons)
      );
    }

    return $data;
  }


  /**
   * Build the data of bins V2
   *
   * @return array
   */
  private function binsBuilderV2($bins)
  {
    $data = array();
    for ($i = 0; $i < count($bins); $i++) {

      $data[] = array(
        'binID'                         =>  $bins[$i]->bin_id,
        'binName'                       =>  'Bin #' . $bins[$i]->bin_number . ' - ' . $bins[$i]->alias,
        'binNumber'                     =>  $bins[$i]->bin_number,
        'binAlias'                      =>  $bins[$i]->alias,
        'amountTons'                    =>  $bins[$i]->current_bin_amount_tons,
        'dateToBeEmpty'                 =>  date("Y-m-d", strtotime($bins[$i]->empty_date)) == "1969-12-31" ? "--" : date("Y-m-d", strtotime($bins[$i]->empty_date)),
        'inComingDelivery'              =>  $bins[$i]->delivery_amount,
        'lastDelivery'                  =>  date("Y-m-d", strtotime($bins[$i]->next_deliverydd)),
        'lastUpdate'                    =>  date("Y-m-d h:i a", strtotime($bins[$i]->last_update)),
        'sow'                           =>  $bins[$i]->num_of_sow_pigs,
        'user'                          =>  $bins[$i]->username,
        'daysRemaining'                 =>  $bins[$i]->days_to_empty,
        'currentMedication'             =>  $bins[$i]->medication_name,
        'currentMedicationDescription'  =>  $bins[$i]->medication,
        'currentMedicationID'           =>  $bins[$i]->medication_id,
        'currentFeed'                   =>  $bins[$i]->feed_type_name_orig,
        'currentFeedDescription'        =>  $bins[$i]->feed_type_name,
        'currentFeedID'                 =>  $bins[$i]->feed_type_id,
        'numberOfPigs'                  =>  $bins[$i]->total_number_of_pigs,
        'binSize'                       =>  $bins[$i]->bin_s,
        'groups'                        =>  $bins[$i]->default_val,
        'ringAmount'                    =>  $this->currentRingAmount($bins[$i]->bin_s, $bins[$i]->current_bin_amount_tons)
      );
    }

    return $data;
  }


  /**
   * Build the data of bins
   *
   * @return array
   */
  private function binsDivision($bins)
  {

    $output_division = array();

    $counter = $this->binsCounterDevider($bins);

    $total_bins = count($bins);

      for ($i = 0; $i < count($bins); $i++) {

        if($total_bins <= 20){

          $devider = (int)($total_bins/2) - 1;

          if($total_bins == 3){
            $devider = 1;
          }

          if($total_bins == 7){
            $devider = 3;
          }

          if($total_bins == 11){
            $devider = 5;
          }

          if($total_bins == 13){
            $devider = 6;
          }



          if($i <= $devider){
            $output_division["div_1"][] = $bins[$i];
          } else {
            $output_division["div_2"][] = $bins[$i];
          }

        } else {

          if($i <= $counter['counter_one']){
            $output_division["div_1"][] = $bins[$i];
          } else if($i > $counter['counter_one'] && $i <= $counter['counter_two']){
            $output_division["div_2"][] = $bins[$i];
          } else {
            $output_division["div_3"][] = $bins[$i];
          }

        }


      }

    return $output_division;
  }

  /*
  * Brings the dynamic counter for rooms devider
  */
  private function binsCounterDevider($bins)
  {

    $total = count($bins);
    $counter_one = $total/3;
    $counter_one = floor($counter_one);

    return array(
      'counter_one' => $counter_one,
      'counter_two' => ($counter_one + $counter_one) + 1,
      'counter_three' => ($counter_one + $counter_one + $counter_one) - 1
    );

  }

  /**
   * get the current ringAmount
   *
   * @return array
   */
  private function currentRingAmount($bin_size, $current_amount)
  {
    $arr_val = array();
    $test_val = array();
    $ring_amount = array();
    foreach ($bin_size as $i => $v) {
      $arr_val[] = $i;
      $ring_amount[$i] = $v;
      $test_val[] = array($i => $v);
    }

    if ($current_amount == 0) {
      return $ring_amount[0];
    }

    $closest = NULL;
    $counter = 0;
    foreach ($arr_val as $item) {

      if ($item == $current_amount) {
        return $ring_amount[$item];
      }

      if ($closest === NULL || abs($current_amount - $closest) > abs($item - $current_amount)) {
        $closest = $item;
        $counter = $counter + 1;
      }
    }

    foreach ($test_val[$counter - 1] as $k => $v) {
      return $v;
    }
  }

  /**
   * Build the data of bins with graph consumption
   *
   * @return array
   */
  private function binInfoBuilder($farm_id, $bin_id, $bins)
  {
    $data = array();
    $budgeted_amount = 0;
    for ($i = 0; $i < count($bins); $i++) {

      $consumption = array();
      for ($j = 0; $j < count($bins[$i]->graph_data); $j++) {
        if ($bins[$i]->graph_data[$j]->budgeted_amount != 0) {
          $budgeted_amount = $bins[$i]->graph_data[$j]->budgeted_amount;
        }

        $consumption[date("Y-m-d", strtotime($bins[$i]->graph_data[$j]->update_date))] = array(
          'budgeted'  =>  number_format((float) $budgeted_amount, 2, '.', ''), //$bins[$i]->graph_data[$j]->budgeted_amount,
          'actual'    =>  $bins[$i]->graph_data[$j]->actual
        );
      }

      if ($bin_id == $bins[$i]->bin_id) {

        $ring_amount = $this->getClosest($bins[$i]->default_amount, $bins[$i]->bin_s);
        $actual = $this->avgActual($bins[$i]->average_actual, $bins[$i]->num_of_update);
        $variance = $this->avgVariance($bins[$i]->average_actual, $bins[$i]->num_of_update, $bins[$i]->budgeted_amount);

        $data[] = array(
          'farmID'            =>  $farm_id,
          'binID'             =>  $bin_id,
          'currentMedication' =>  $bins[$i]->medication,
          'nextDelivery'      =>  $bins[$i]->next_delivery,
          'currentFeed'       =>  $bins[$i]->feed_type_name,
          'numberOfPigs'      =>  $bins[$i]->total_number_of_pigs,
          'ringAmount'        =>  $ring_amount,
          'variance'          =>  $variance,
          'actual'            =>  $actual,
          'budgeted'          =>  $bins[$i]->budgeted_amount,
          'consumptions'      =>  $consumption //$this->binConsumption($bins[$i]->graph_data)
        );
      }
    }

    return $data;
  }

  /**
   * Compute the average variance of the bin
   *
   * @return int
   */
  private function avgVariance($average_actual, $num_of_update, $budgeted_amount)
  {
    $result = $average_actual / $num_of_update;
    $result = $result - $budgeted_amount;
    return number_format((float) $result, 2, '.', '');
  }

  private function avgActual($average_actual, $num_of_update)
  {
    $result = $average_actual / $num_of_update;
    return number_format((float) $result, 2, '.', '');
  }

  /**
   * Get the nearest data for ring amount.
   *
   * @return string
   */
  private function getClosest($search, $arr)
  {

    $array = array();
    foreach ($arr as $k => $v) {
      $array[] = $k;
    }

    $max = count($array) - 1;

    if ($search > $array[$max]) {
      return $search;
    }

    $closest = null;
    foreach ($arr as $key => $value) {
      if ($key == $search) {
        return $value;
      }

      if ($closest === null || abs((float) $search - (float) $closest) > abs((float) $key - (float) $search)) {
        $closest = $key;
      }
    }

    return $closest;
  }

  /**
   * build the consumption graph data.
   *
   *  @return array
   */
  private function binConsumption($graph_data)
  {
    $budgeted_amount = 0;
    $data = array();
    $budgeted_amount = 0;
    for ($i = 0; $i < count($graph_data); $i++) {
      if ($graph_data[$i]->budgeted_amount != 0) {
        $budgeted_amount = $graph_data[$i]->budgeted_amount;
      }

      $data[date("Y-m-d", strtotime($graph_data[$i]->update_date))] = array(
        'budgeted'  =>  $graph_data[$i]->budgeted_amount,
        'actual'    =>  $graph_data[$i]->actual
      );
    }

    return $data;
  }

  /**
   * Save the batch
   *
   */
  public function saveBatch($data)
  {
    $home_controller = new HomeController;
    $unique_id = $home_controller->generator();
    unset($home_controller);

    if (DB::table('feeds_batch')->insert($data)) {
      DB::table('feeds_batch')->where('status', 'pending')->update(['unique_id' => $unique_id, 'date' => $data['date']]);
      return true;
    }
  }

  /*
    * save the batches to the feeds_farm_schedule
    */
  private function saveFarmSchedule($data, $unique_id, $user)
  {
    $farm_sched_data = array();
    for ($i = 0; $i < count($data); $i++) {
      $farm_sched_data[] = array(
        'date_of_delivery'    =>  $data[$i]->date,
        'truck_id'            =>  $data[$i]->truck,
        'farm_id'              =>  $data[$i]->farm_id,
        'feeds_type_id'        =>  $data[$i]->feed_type,
        'medication_id'        =>  $data[$i]->medication,
        'amount'              =>  $data[$i]->amount,
        'bin_id'              =>  $data[$i]->bin_id,
        'user_id'              =>  $user,
        'unique_id'            =>  $unique_id
      );
      Cache::forget('farm_holder-'.$data[$i]->farm_id);
    }

    FarmSchedule::insert($farm_sched_data);
  }

  /*
    * get the delivery status to the feeds_deliveries
    */
  private function getDeliveryStatus($unique_id)
  {

    $delivery_unique_id = FarmSchedule::select('delivery_unique_id')->where('unique_id', $unique_id)->first();

    if ($delivery_unique_id == NULL) {
      return "pending";
    }

    if ($delivery_unique_id->delivery_unique_id == NULL) {
      return "pending";
    }

    $home_controller = new HomeController;
    $delivery_status = $home_controller->deliveriesStatusAPI($delivery_unique_id->delivery_unique_id);
    unset($home_controller);

    if ($delivery_status == "pending") {
      return "created";
    }

    return $delivery_status;
  }

  /*
    * get the delivery driver id
    */
  private function getDeliveryDriver($unique_id)
  {

    $driver = DB::table('feeds_batch')->select('driver_id')->where('unique_id', $unique_id)->first();

    if ($driver == NULL) {
      return 0;
    }

    return $driver->driver_id;
  }

  /*
    * get the delivery driver id
    */
  private function getDeliveryNumber($unique_id)
  {

    $delivery = SchedTool::select('delivery_number')->where('farm_sched_unique_id', $unique_id)->first();

    if ($delivery == NULL) {
      return 0;
    }

    return $delivery->delivery_number;
  }

  /*
    * get the total tons on feeds_farm_schedule table
    */
  private function totalTonsFarmSchedTable($unique_id)
  {

    $sum = FarmSchedule::where('unique_id', $unique_id)->sum('amount');

    if ($sum == NULL) {
      return 0;
    }

    return $sum;
  }

  /*
    * build the listSschedule batches
    */
  private function listScheduleBatches($unique_id)
  {
    $batch = array();
    $data = DB::table('feeds_batch')
      ->where('unique_id', $unique_id)
      ->orderBy('compartment', 'asc')->get();

    foreach ($data as $k => $v) {

      $medication_id = $v->medication;
      if ($this->medicationName($v->medication) == "none") {
        $medication_id = 0;
      }

      $batch[] = array(
        "id"               => $v->id,
        "farm_id"          => $v->farm_id,
        "farm_text"        => $this->farmName($v->farm_id),
        "bin_id"           => $v->bin_id,
        "bin_text"         => $this->binName($v->bin_id),
        "date"             => $v->date,
        "amount"           => $v->amount,
        "feed_type"        => $v->feed_type,
        "feed_type_text"   => $this->feedName($v->feed_type),
        "medication"       => $medication_id,
        "medication_text"  => $this->medicationName($v->medication),
        "compartment"      => $v->compartment
      );
    }

    return $batch;
  }

  /*
    * build the listSschedule batches
    */
  private function listScheduleBatchesPrint($unique_id)
  {
    $batch = array();
    $data = DB::table('feeds_batch')
      ->where('unique_id', $unique_id)
      ->orderBy('compartment', 'asc')->get();

    $home_controller = new HomeController;

    $bin_ids = $this->distinctBatches($unique_id);

    foreach ($data as $k => $v) {

      $medication_id = $v->medication;
      if ($this->medicationName($v->medication) == "none") {
        $medication_id = 0;
      }

      // get the previous delivery data based in the bin_id
      $bin_history = DB::table('feeds_bin_history')
                        ->where('bin_id',$v->bin_id)
                        ->orderBy('history_id','desc')
                        ->first();

      $farmType = $home_controller->farmTypes($v->farm_id);
      $bh_pigs = $bin_history->num_of_pigs;
      if($farmType == "farrowing"){
        $bh_bins = DB::table("feeds_bins")->select('num_of_sow_pigs')
                    ->where('bin_id',$v->bin_id)
                    ->first();
        $bh_pigs = $bh_bins->num_of_sow_pigs;
      }

      $empty_date = $home_controller->emptyDateAPI($v->date,$v->bin_id,$bh_pigs,$bin_history->budgeted_amount,$v->amount);

      $batch[] = array(
        "id"               => $v->id,
        "farm_id"          => $v->farm_id,
        "farm_text"        => $this->farmName($v->farm_id),
        "bin_id"           => $v->bin_id,
        "bin_text"         => $this->binName($v->bin_id),
        "date"             => $v->date,
        "amount"           => $v->amount,
        "feed_type"        => $v->feed_type,
        "feed_type_text"   => $this->feedName($v->feed_type),
        "medication"       => $medication_id,
        "medication_text"  => $this->medicationName($v->medication),
        "compartment"      => $v->compartment,
        "bh_ft_text"       => $this->feedName($bin_history->feed_type),
        "bh_cons"          => $bin_history->budgeted_amount,
        "bh_num_of_pigs"   => $bh_pigs,
        "bh_empty_date"    => $empty_date,
      );
    }

    unset($home_controller);

    return array($batch,$bin_ids);
  }

  /*
  *
  * Count the data that has same bin and feed types
  */
  private function distinctBatches($unique_id)
  {
    $comp = array();
    $result = array();
    $data = DB::table('feeds_batch')
      ->where('unique_id', $unique_id)
      ->orderBy('compartment','asc')
      ->groupBy('bin_id')->get();

    $home_controller = new HomeController;

    $farm_data = DB::table('feeds_batch')
                    ->select('farm_id','compartment')
                    ->where('unique_id', $unique_id)
                    ->orderBy('compartment','desc')
                    ->groupBy('farm_id')->get();


    foreach($data as $k => $v){

      $medication_id = $v->medication;
      if ($this->medicationName($v->medication) == "none") {
        $medication_id = 0;
      }

      // get the previous delivery data based in the bin_id
      $bin_history = DB::table('feeds_bin_history')
                        ->where('bin_id',$v->bin_id)
                        ->orderBy('history_id','desc')
                        ->first();

      $comp = DB::table('feeds_batch')
        ->where('unique_id', $unique_id)
        ->where('bin_id',$v->bin_id);
      $com_min = $comp->min('compartment');
      $com_max = $comp->max('compartment');
      $total_amount = $comp->sum('amount');

      //$budgeted_amount
      $budgeted_amount = $home_controller->daysCounterbudgetedAmount($v->farm_id,$v->bin_id,$v->feed_type,date("Y-m-d H:i:s"));

      $farmType = $home_controller->farmTypes($v->farm_id);
      $bh_pigs = $bin_history->num_of_pigs;
      if($farmType == "farrowing"){
        $bh_bins = DB::table("feeds_bins")->select('num_of_sow_pigs')
                    ->where('bin_id',$v->bin_id)
                    ->first();
        $bh_pigs = $bh_bins->num_of_sow_pigs;
      }

      $empty_date = $home_controller->emptyDateAPI($v->date,$v->bin_id,$bh_pigs,$budgeted_amount,$total_amount);

      $total_amount = DB::table('feeds_batch')
                          ->where('unique_id', $unique_id)
                          ->where('bin_id', $v->bin_id)
                          ->sum('amount');



      $result[] = array(
        'bin_id'           => $v->bin_id,
        'comp_min'         => $com_min,
        'comp_max'         => $com_max,
        'comp_count'       => $comp->count(),
        "id"               => $v->id,
        "farm_id"          => $v->farm_id,
        "farm_text"        => $this->farmName($v->farm_id),
        "farm_count"       => count($farm_data),
        "farm_data"        => $farm_data,
        "bin_id"           => $v->bin_id,
        "bin_text"         => $this->binAlias($v->bin_id),
        "date"             => $v->date,
        "amount"           => $total_amount,//$v->amount,
        "feed_type"        => $v->feed_type,
        "feed_type_text"   => $this->feedName($v->feed_type),
        "medication"       => $medication_id,
        "medication_text"  => $this->medicationName($v->medication),
        "compartment"      => $v->compartment,
        "bh_ft_text"       => $this->feedName($bin_history->feed_type),
        "bh_cons"          => $budgeted_amount,
        "bh_num_of_pigs"   => $bh_pigs,
        "bh_empty_date"    => $empty_date,
      );
    }

    unset($home_controller);

    return $result;
  }

  /*
    * build the listSschedule batches
    */
  private function listScheduleBatchesFarmIDs($unique_id)
  {
    $farm_ids = array();
    $data = DB::table('feeds_batch')
      ->select('farm_id')
      ->where('unique_id', $unique_id)
      ->get();

    foreach ($data as $k => $v) {

      $farm_ids[] = $v->farm_id;
    }

    return $farm_ids;
  }


  /*
  * driver notes
  */
  private function driverNotes($notes,$driver_id,$unique_id)
  {

    $table = DB::table("feeds_drivers_notes");
    $check = $table->where('del_batch_unique_id',$unique_id)->exists();

    if($check){
      $table->where('del_batch_unique_id',$unique_id);
      $table->update(["notes" => $notes,
      "driver_id" => $driver_id,
      "del_batch_unique_id" => $unique_id]);

      return "success";
    }

    $table->insert(["notes" => $notes,
    "driver_id" => $driver_id,
    "del_batch_unique_id" => $unique_id]);

    return "success";
  }

}
