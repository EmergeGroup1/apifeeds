<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Input;
use Validator;
use DB;
use Cache;
use Auth;
use Storage;
use App\Http\Requests;
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
  public function index(Input $request)
  {
    $api = $request->get('action');

    switch ($api) {

      case "logUser":

        $username = Input::get('username');
        $password = Input::get('password');
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

        $type = Input::get('type');
        $token = Input::get('token');

        if ($type == 0) {
          $farms_default = Farms::where('status', 1)->where('farm_type','!=','farrowing')->orderBy('name')->get();
        } else if ($type == 1) {
          $farms_default = Farms::where('column_type', 1)->where('farm_type','!=','farrowing')->where('status', 1)->orderBy('name')->get();
        } else if ($type == 2) {
          $farms_default = Farms::where('column_type', 2)->where('farm_type','!=','farrowing')->where('status', 1)->orderBy('name')->get();
        } else if ($type == 3) {
          $farms_default = Farms::where('column_type', 3)->where('farm_type','!=','farrowing')->where('status', 1)->orderBy('name')->get();
        } else {
          return "no type passed";
        }

        $log_token = session('token');
        $sort_type = Input::get('sort');

        //$home_controller = new HomeController;
        //$home_controller->forecastingDataCache();
        //unset($home_controller);
        //if($token == $log_token){
        if ($sort_type == 1) {
          $farms = json_decode(Storage::get('forecasting_data_a_to_z.txt'));
        } else {
          $farms = json_decode(Storage::get('forecasting_data_low_bins.txt'));
        }

        $data = $this->farmsBuilder($sort_type, $farms, $farms_default);

        return array('farmID' => $data);

        break;

      case "listBins":

        $farm_id = Input::get('farmID');
        $token = Input::get('token');

        $farm = Farms::where('id', $farm_id)->first();

        if ($farm == NULL) {
          return array(
            "err" =>  1,
            "msg" =>  "No farm with that selected id"
          );
        }

        $forecasting = json_decode(Storage::get('forecasting_data_low_bins.txt'));
        $home_controller = new HomeController;
        $bins = $home_controller->binsData($farm_id);
        unset($home_controller);
        $bins = json_decode($bins);

        $bins = $this->binsBuilder($bins);
        $farm = Farms::where('id', $farm_id)->select('name', 'notes')->first()->toArray();

        $output = array(
          'farmName'  =>  $farm['name'],
          'farmID'    =>  $farm_id,
          'numberofLowbins' =>  $this->farmsBuilderNumberOfLowBins($forecasting, $farm_id),
          'notes'     =>  $farm['notes'],
          'bins'      =>  $bins
        );

        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        return $output;

        break;

      case "listBinSizes":

        $output = DB::table('feeds_bin_sizes')
          ->select('size_id as id', 'name')
          ->get();

        return $output;

        break;

      case "deleteBatch":

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $batch_id = Input::get('batchID');

        DB::table('feeds_batch')->where('id', $batch_id)->delete();

        return array(
          "err" =>  0,
          "msg" =>  "Batch Deleted"
        );
        break;

      case "deleteSchedule":
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $unique_id = Input::get('uniqueID');

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

        $token = Input::get('token');
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

        $token = Input::get('token');
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

        $token = Input::get('token');
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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $bin_id = Input::get('binID');
        $amount = Input::get('amount');


        $_POST['bin'] = $bin_id;
        $_POST['amount'] = $amount;
        $_POST['user'] = Input::get('userID');

        // get the medications medication()
        $home_controller = new HomeController;
        $update_bin = $home_controller->updateBinAPI();
        unset($home_controller);

        return $update_bin;

        break;

      case "updateBinCacheRebuild":

        $token = Input::get('token');
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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $farm_id = Input::get('farmID');
        $bin_id = Input::get('binID');
        $number_of_pigs = Input::get('numberOfPigs');
        $animal_unique_id = Input::get('animalUniqueID');
        $user_id = Input::get('user_id');


        // get the medications medication()
        $home_controller = new HomeController;
        $update_pigs = $home_controller->updatePigsAPI($farm_id, $bin_id, $number_of_pigs, $animal_unique_id, $user_id);
        unset($home_controller);

        return $update_pigs + array(
          "err" =>  0,
          "msg" =>  "Successfully updated pigs"
        );

        break;

      case "saveBatch":

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'farm_id' => Input::get('farmID'),
          'bin_id' => Input::get('binID'),
          'feed_type' => Input::get('feedType'),
          'medication' => Input::get('medication'),
          'amount' => Input::get('amount'),
          'date' => date("Y-m-d", strtotime(Input::get('date'))),
          'truck' => Input::get('truck'),
          'compartment' => Input::get('compartment'),
          'code_id' => Input::get("code")
        );



        $exists = DB::table('feeds_batch')
          ->where('status', 'pending')
          ->where('bin_id', Input::get('binID'))
          ->exists();

        $code_exists = DB::table('feeds_batch')
          ->where('code_id', Input::get('code'))
          ->exists();


        if ($code_exists) {
          if ($this->saveBatch($data)) {
            DB::table('feeds_batch')->where('status', 'pending')->where('unique_id', NULL)->update(['date' => date("Y-m-d", strtotime(Input::get('date')))]);
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
            DB::table('feeds_batch')->where('status', 'pending')->where('unique_id', NULL)->update(['date' => date("Y-m-d", strtotime(Input::get('date')))]);
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

        $token = Input::get('token');
        $log_token = session('token');

        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }
        $status = Input::get('status');

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

        $token = Input::get('token');
        $log_token = session('token');

        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $farms_scheduled_unique_id = DB::table('feeds_farm_schedule')
          ->select('unique_id', 'truck_id', 'farm_id', 'driver_id')
          ->where(DB::raw('LEFT(date_of_delivery,10)'), date("Y-m-d", strtotime(Input::get('selectedDate'))))
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
        $unique_id = Input::get('uid');
        $batch = $this->listScheduleBatchesPrint($unique_id);

        return array(
          "err" =>  0,
          "msg" =>  "Successfully pull data",
          "batch"  => $batch
        );
      break;

      case "driverNote":

        $type = Input::get('type');
        $notes = Input::get('notes');
        $driver_id = Input::get('driver_id');
        $unique_id = Input::get('unique_id');

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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'farm_id' => Input::get('farmID'),
          'bin_id' => Input::get('binID'),
          'feed_type' => Input::get('feedType'),
          'medication' => Input::get('medication'),
          'amount' => Input::get('amount'),
          'date' => date("Y-m-d", strtotime(Input::get('date'))),
          'truck' => Input::get('truck'),
          'compartment' => Input::get('compartment'),
        );

        DB::table('feeds_batch')->where("id", Input::get('id'))->update($data);

        $batch = DB::table('feeds_batch')->where("id", Input::get('id'))->first();

        $var = array();
        $var['selected_date'] = $batch->date;
        $var['unique_id'] = $batch->unique_id;
        $var['driver_id'] = $batch->driver_id;
        $var['user_id'] = Input::get('userID');

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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $user = Input::get("userID");

        $home_controller = new HomeController;
        $unique_id = $home_controller->generator();
        unset($home_controller);

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
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));

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
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $user = Input::get('userID');
        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));
        $unique_id = Input::get('uniqueID');

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
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));

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
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));

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
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));

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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id'        =>  Input::get('driverID'),
          'unique_id'        =>  Input::get('uniqueID'),
          'user_id'         =>  Input::get('userID'),
          'selected_date'    =>  date("Y-m-d", strtotime(Input::get('selectedDate')))
        );

        DB::table('feeds_batch')->where(
          'unique_id',
          Input::get('uniqueID')
        )->update(['driver_id' => Input::get('driverID'), 'date' => date("Y-m-d", strtotime(Input::get('selectedDate')))]);

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
        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id'       => Input::get('driverID'),
          'unique_id'       => Input::get('uniqueID'),
          'delivery_number' => Input::get('deliveryNumber'),
          'selected_index'  => Input::get('selectedIndex'),
          'selected_date'    => date("Y-m-d", strtotime(Input::get('selectedDate')))
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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id'       => Input::get('driverID'),
          'unique_id'       => Input::get('uniqueID'),
          'delivery_number' => Input::get('deliveryNumber'),
          'selected_date'    => date("Y-m-d", strtotime(Input::get('selectedDate')))
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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'driver_id' => Input::get('driverID'),
          'unique_id' => Input::get('uniqueID'),
          'selected_date'    => date("Y-m-d", strtotime(Input::get('selectedDate')))
        );

        DB::table('feeds_batch')->where('unique_id', Input::get('uniqueID'))->update(['driver_id' => Input::get('driverID')]);

        $delivery_exists = FarmSchedule::whereNotNull('delivery_unique_id')->where('unique_id', Input::get('uniqueID'))->get()->toArray();
        if ($delivery_exists != NULL) {
          return array(
            "err" =>  1,
            "msg" =>  "The schedule is already loaded"
          );
        }

        $user = Input::get('userID');

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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $data = array(
          'date_from'  =>  date("Y-m-d", strtotime(Input::get('from'))),
          'date_to'    =>  date("Y-m-d", strtotime(Input::get('to')))
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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));

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

        $token = Input::get('token');
        $log_token = session('token');
        if ($token != $log_token) {
          return array("err" => "Invalid token, please login");
        }

        $selected_date = date("Y-m-d", strtotime(Input::get('selectedDate')));

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
          'start_time' => date("H:i", strtotime(Input::get('startTime'))),
          'end_time' => date("H:i", strtotime(Input::get('endTime'))),
          'delivery_number' => Input::get('deliveryNumber'),
          'unique_id' => Input::get('uniqueID'),
          'driver_id' => Input::get('driverID')
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
          'from'            =>  date("Y-m-d", strtotime(Input::get('from'))),
          'to'              =>  date("Y-m-d", strtotime(Input::get('to'))),
          'driver'          =>  Input::get('driver'),
          'delivery_number' =>  Input::get('deliveryNumber'),
          'farm_id'         =>  Input::get('farmID')
        );
        $home_controller = new HomeController;
        $deliveries = $home_controller->deliveriesListAPI($data);
        unset($home_controller);

        return $deliveries;

        break;

        /* Delivery Page */
      case "deliveryLoadInfo":

        $unique_id = Input::get('uniqueID');
        $home_controller = new HomeController;
        $deliveries = $home_controller->loadBreakdownAPI($unique_id);
        unset($home_controller);

        return $deliveries;

        break;

      case "searchDeliveryLists":

        $from  = Input::get('from');
        $to = Input::get('to');
        $home_controller = new HomeController;
        $deliveries = $home_controller->deliveriesListAPI();
        unset($home_controller);

        return $deliveries;

        break;

      case "markDelivered":

        $unique_id = Input::get('uniqueID');
        $user_id = Input::get('userID');

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

        $unique_id = Input::get('uniqueID');

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
          'name'                =>  Input::get('farmName'),
          'delivery_time'       =>  Input::get('deliveryTime'),
          'packer'              =>  Input::get('packerName'),
          'contact'             =>  Input::get('ContactNumber'),
          'farm_type'           =>  Input::get('farmType'),
          'column_type'         =>  Input::get('columnType'),
          'owner'               =>  Input::get('farmOwner'),
          'update_notification' =>  Input::get('manualUpdateNotification'),
          'notes'               =>  Input::get('notes'),
          'address'             =>  Input::get('address'),
          'longtitude'          =>  Input::get('lng'),
          'lattitude'           =>  Input::get('lat'),
          'user_id'             =>  Input::get('userID'),
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

        $farm_id = Input::get('farmID');
        $data = array(
          'name'                =>  Input::get('farmName'),
          'delivery_time'       =>  Input::get('deliveryTime'),
          'packer'              =>  Input::get('packerName'),
          'contact'             =>  Input::get('ContactNumber'),
          'farm_type'           =>  Input::get('farmType'),
          'column_type'         =>  Input::get('columnType'),
          'owner'               =>  Input::get('farmOwner'),
          'update_notification' =>  Input::get('manualUpdateNotification'),
          'notes'               =>  Input::get('notes'),
          'address'             =>  Input::get('address'),
          'longtitude'          =>  Input::get('lng'),
          'lattitude'           =>  Input::get('lat'),
          'user_id'             =>  Input::get('userID'),
          'created_at'          =>  date('Y-m-d H:i:s'),
          'updated_at'          =>  date('Y-m-d H:i:s')
        );

        if (Input::get('farmOwner') == NULL) {
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

        $farm_id = Input::get('farmID');

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

        $farm_id = Input::get('farmID');

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

        $farm_id = Input::get('farmID');
        $reactivation_date = Input::get('reactivationDate');

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

        $farm_id = Input::get('farmID');

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
          'farm_id'     =>  Input::get('farmID'),
          'bin_number'  =>  Input::get('binNumber'),
          'alias'       =>  Input::get('alias'),
          'bin_size'    =>  Input::get('binSize'),
          'user_id'     =>  Input::get('userID')
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
          'bin_id'      =>  Input::get('binID'),
          'farm_id'     =>  Input::get('farmID'),
          'feed_type'   =>  Input::get('feedType'),
          'alias'       =>  Input::get('alias'),
          'bin_size'    =>  Input::get('binSize'),
          'user_id'     =>  Input::get('userID')
        );

        $farms_controller = new FarmsController;
        $binsLists = $farms_controller->updateBinFarmAPI($data);
        unset($farms_controller);

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
        $farms_controller->deleteBinFarmAPI(Input::get('binID'));
        unset($farms_controller);

        if (!empty($farmsLists)) {
          return array(
            "err"   =>  0,
            "msg"   =>  "Successfully Deleted Bin",
            "binid" =>  Input::get('binID')
          );
        } else {
          return $this->errorMessage();
        }

        break;

        /* End Farms Administraton */


        /* Driver Tracking */
      case "driverTracking":

        $livetruck_controller = new LiveTruckController;
        $drivers = $livetruck_controller->liveTrucksAPI();
        unset($livetruck_controller);

        if (!empty($drivers)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Deleted Bin",
            "drivers" =>  $drivers
          );
        } else {
          return $this->errorMessage();
        }

        break;
        /* End Farms Administraton */

        /* Messaging */

        // user lists for messages
        case "msList":

          $logged_in_user_id = Input::get('userID');

          $ms_ctrl = new MessagingController;
          $messages = $ms_ctrl->messagingListAPI($logged_in_user_id);
          unset($ms_ctrl);

          if (!empty($messages)) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully Get Users List",
              "messagesList" =>  $messages
            );
          } else {
            return $this->errorMessage();
          }

        break;

        // history of messages from specific user
        case "msHistory":
          $logged_in_user_id = Input::get('userID');
          $pm_user_id = Input::get('pmUserID');

          $ms_ctrl = new MessagingController;
          $messages = $ms_ctrl->loadMessageHistoryAPI($logged_in_user_id,$pm_user_id);
          unset($ms_ctrl);

          if (!empty($messages)) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully Get Messages History",
              "messagesList" =>  $messages
            );
          } else {
            return $this->errorMessage();
          }
        break;

        // update the notification
        case "msUpdateNotif":
          $logged_in_user_id = Input::get('userID');
          $pm_user_id = Input::get('pmUserID');

          $ms_ctrl = new MessagingController;
          $notif = $ms_ctrl->updateNotifAPI($logged_in_user_id,$pm_user_id);
          unset($ms_ctrl);

          if ($notif != NULL) {
            return array(
              "err"     =>  0,
              "msg"     =>  "Successfully Get Response",
              "message" =>  $notif
            );
          } else {
            return $this->errorMessage();
          }
        break;

        // total notification
        case "msTotalNotif":
          $logged_in_user_id = Input::get('userID');

          $ms_ctrl = new MessagingController;
          $notif = $ms_ctrl->totalNotifAPI($logged_in_user_id);
          unset($ms_ctrl);

          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Get total notification",
            "totalNotif" =>  $notif
          );

        break;
        /* End of Messaging */


        /* Start Animal Movement */
      case "amList":

        $data = array(
          'type'      =>  Input::get('type'), // (string) all, farrowing_to_nursery, nursery_to_finisher, finisher_to_market
          'date_from' =>  Input::get('date_from'), // (date)
          'date_to'   =>  Input::get('date_to'), // (date)
          'sort'      =>  Input::get('sort') // (string) not_scheduled, day_remaining
        );

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->animalMovementFilterAPI($data);
        unset($am_controller);

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

        $group_id = Input::get('group_id');
        $user_id = Input::get('user_id');

        $am_controller = new AnimalMovementController;
        $am_lists = $am_controller->removeGroupAPI($group_id, $user_id);
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

      case "amCreateGroup":

        $date_created = date("Y-m-d H:i:s", strtotime(Input::get('date_created')));

        $data = array(
          'group_name'        =>  Input::get('group_name'),
          'farm_id'            =>  Input::get('farm_id'),
          'start_weight'      =>  Input::get('start_weight'),
          'end_weight'        =>  Input::get('end_weight'),
          'crates'            =>  Input::get('crates') == NULL ? 0 : Input::get('crates'),
          'date_created'      =>  $date_created,
          'status'            =>  'entered',
          'user_id'            =>  Input::get('user_id'),
          'type'              =>  Input::get('type'),
          'bins'              =>  Input::get('bins'),
          'number_of_pigs'    =>  Input::get('number_of_pigs')
        );

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

        $date_created = date("Y-m-d H:i:s", strtotime(Input::get('date_created')));

        $data = array(
          'group_id'          =>  Input::get('group_id'),
          'group_name'        =>  Input::get('group_name'),
          'farm_id'            =>  Input::get('farm_id'),
          'start_weight'      =>  Input::get('start_weight'),
          'end_weight'        =>  Input::get('end_weight'),
          'crates'            =>  Input::get('crates') == NULL ? 0 : Input::get('crates'),
          'date_created'      =>  $date_created,
          'status'            =>  'entered',
          'user_id'            =>  Input::get('user_id'),
          'type'              =>  Input::get('type'),
          'group_bin_id'      =>  Input::get('group_bin_id'),
          'bins'              =>  Input::get('bins'),
          'number_of_pigs'    =>  Input::get('number_of_pigs'),
          'unique_id'         =>  Input::get('unique_id')
        );

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

        $data = array(
          'transfer_type'    =>  Input::get('transfer_type'),
          'group_from'      =>  Input::get('group_from'),
          'group_to'        =>  Input::get('group_to'),
          'driver_id'        =>  Input::get('driver_id'),
          'date'            =>   date("Y-m-d", strtotime(Input::get('date'))),
          'number_of_pigs'  =>  Input::get('number_of_pigs')
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

      case "amUpdateTransfer":

        $data = array(
          'transfer_id'       =>  Input::get('transfer_id'),
          'transfer_type'      =>  Input::get('transfer_type'),
          'group_from'        =>  Input::get('group_from'),
          'group_to'          =>  Input::get('group_to'),
          'status'            =>  'created',
          'date'              =>   date("Y-m-d", strtotime(Input::get('date'))),
          'shipped'            =>  Input::get('shipped'),
          'empty_weight'      =>  Input::get('empty_weight'),
          'ave_weight'        =>  Input::get('ave_weight'),
          'driver_id'          =>  Input::get('driver_id'),
          'full_weight'        =>  Input::get('full_weight'),
          'received'          =>  Input::get('received'),
          'dead'              =>  Input::get('dead'),
          'poor'              =>  Input::get('poor'),
          'farm_count'        =>  Input::get('farm_count'),
          'final_count'        =>  Input::get('final_count'),
          'notes'              =>  Input::get('notes')
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
          'transfer_id' => Input::get('transfer_id'),
          'transfer_type' =>  Input::get('transfer_type'),
          'date' =>  Input::get('date'),
          'group_from' =>  Input::get('group_from'),
          'group_to' =>  Input::get('group_to'),
          'empty_weight' =>  Input::get('empty_weight'),
          'full_weight' =>  Input::get('full_weight'),
          'ave_weight' =>  Input::get('ave_weight'),
          'shipped' =>  Input::get('shipped'),
          'received' =>  Input::get('received'),
          'dead' =>  Input::get('dead'),
          'poor' =>  Input::get('poor'),
          'farm_count' =>  Input::get('farm_count'),
          'final_count' =>  Input::get('final_count'),
          'driver_id' =>  Input::get('driver_id'),
          'unique_id' =>  Input::get('unique_id'),
          'user_id' =>  Input::get('user_id')
        );

        $validator = Validator::make(Input::all(), [
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
          'dead'            =>  'required',
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
          'bins_from' => Input::get('bins_from'),
          'bins_from_pigs' => Input::get('bins_from_pigs'),
          'bins_to' => Input::get('bins_to'),
          'bins_to_pigs' => Input::get('bins_to_pigs'),
          'num_of_pigs_dead' => Input::get('num_of_pigs_dead'),
          'num_of_pigs_poor' => Input::get('num_of_pigs_poor')
        );

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

        $transfer_id = Input::get('transfer_id');
        $user_id = Input::get('user_id');
        $am_controller = new AnimalMovementController;
        $am_transfer = $am_controller->removeTransfer($transfer_id, $user_id);
        unset($am_controller);

        if (empty($am_transfer)) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully Deleted"
          );
        } else {
          return $this->errorMessage();
        }

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
          'name' => Input::get('name'),
          'description' => Input::get('description'),
          'budgeted_amount' => Input::get('budgeted_amount'),
          'total_days' => Input::get('total_days'),
          'user_id' => Input::get('user_id'),
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
          'name' => Input::get('name'),
          'description' => Input::get('description'),
          'budgeted_amount' => Input::get('budgeted_amount'),
          'total_days' => Input::get('total_days'),
          'user_id' => Input::get('user_id'),
          'updated_at'  =>  date('Y-m-d H:i:s')
        );

        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiUpdate($data, Input::get('type_id'));
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

        $feed_type_id = Input::get('type_id');
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

        $days_amount = Input::get('days_amount');
        $ft_controller = new FeedTypeController;
        $ft_data = $ft_controller->apiUpdateDaysAmount($days_amount, Input::get('type_id'));
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
        $rn_data = $rn_controller->apiSaveReleaseNotes(Input::get('description'));
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
      case "lmDrivers":

        $lm_controller = new MiscController;
        $lm_data = $lm_controller->apiLMDriver();
        unset($lm_controller);


        if ($lm_data != NULL) {
          return array(
            "err"     =>  0,
            "msg"     =>  "Successfully get the drivers with active deliveries",
            "data"    =>  $lm_data
          );
        } else {
          return array(
            "err"     =>  0,
            "msg"     =>  "empty result, no drivers with active deliveries",
            "data"    =>  $lm_data
          );
        }

        break;

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
          $farm_id = Input::get('farm_id');
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
          $farm_id = Input::get('farm_id');
          $farmer_id = Input::get('farmer_id');
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
          $farm_id = Input::get('farm_id');
          $farmer_id = Input::get('farmer_id');
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
            'username' => Input::get('username'),
            'email'  =>  Input::get('email'),
            'password'  => Input::get('password'),
            'first_name'  => Input::get('first_name'),
            'last_name' =>  Input::get('last_name'),
            'contact_number'  =>  Input::get('contact_number'),
            'type'  => Input::get('type')
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
            'user_id' =>  Input::get('user_id'),
            'username' => Input::get('username'),
            'pass'  => Input::get('password'),
            'type_id'  => Input::get('type'),
            'email'  =>  Input::get('email'),
            'first_name'  => Input::get('first_name'),
            'last_name' =>  Input::get('last_name'),
            'contact_number'  =>  Input::get('contact_number')
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
          $ua_data = $ua_controller->apiDelete(Input::get('user_id'));
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
        * User Accounts Role
        */
        // update user

        // delete user

        // add role

        // update role

        // delete role

        /*
        * End of User Accounts
        */

      default:
        return array("err" => "Something went wrong");
    }
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
            'numberOfBins'        =>  count((array) $v->bins) - 4,
            'numberOfLowBins'     =>  $v->bins->lowBins,
            'hasPendingDelivery'  =>  $v->delivery_status,
            'daysRemaining'       =>  $this->binsDaysRemaining($v->bins),
            'lastManulUpdate'     =>  $v->bins->last_manual_update,
            'currentLAmount'      =>  $v->bins->lowest_amount_bin
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
    $output = "";

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
        'amountTons'                    =>  $bins[$i]->current_bin_amount_tons,
        'dateToBeEmpty'                 =>  date("Y-m-d", strtotime($bins[$i]->empty_date)) == "1969-12-31" ? "--" : date("Y-m-d", strtotime($bins[$i]->empty_date)),
        'inComingDelivery'              =>  $bins[$i]->delivery_amount,
        'lastDelivery'                  =>  date("Y-m-d", strtotime($bins[$i]->next_deliverydd)),
        'lastUpdate'                    =>  date("Y-m-d h:i a", strtotime($bins[$i]->last_update)),
        //'lastUpdate'                    =>  date("Y-m-d H:i a",strtotime($bins[$i]->last_manual_update)),
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

      $empty_date = $home_controller->emptyDateAPI($v->date,$v->bin_id,$bin_history->num_of_pigs,$bin_history->budgeted_amount,$v->amount);

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
        "bh_num_of_pigs"   => $bin_history->num_of_pigs,
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

      $empty_date = $home_controller->emptyDateAPI($v->date,$v->bin_id,$bin_history->num_of_pigs,$budgeted_amount,$total_amount);

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
        "bh_num_of_pigs"   => $bin_history->num_of_pigs,
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
