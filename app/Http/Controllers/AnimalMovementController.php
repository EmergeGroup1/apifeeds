<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Farms;
use App\Bins;
use App\BinsHistory;
use Input;
use DB;
use Cache;
use Auth;
use Storage;
use App\User;

class AnimalMovementController extends Controller
{
      /**
      ** Display a listing of the resource.
      ** @return array
      **/
      public function listAPI()
      {
          $data = array(
            'date_from'	=> 	'2009-01-01',
            'date_to'		=>	date('Y-m-d'),
            'sort'			=>	"not_scheduled"
          );

          $output = $this->filterAll($data);
          Storage::put('animal_movement_data.txt',json_encode($output));
          $all = Storage::get('animal_movement_data.txt');

          $drivers = $this->filterAllDrivers();

          return array("all"=>$all,"drivers"=>$drivers);
      }

      /**
      ** Filter for animal movement page
      ** @param $data array
      ** @return array
      **/
      public function animalMovementFilterAPI($data)
      {
          $type = $data['type'];

          $data = array(
            'date_from'	=>	date("Y-m-d",strtotime($data['date_from'])),
            'date_to'	=>	date("Y-m-d",strtotime($data['date_to'])),
            'sort'		=>	$data['sort']
          );

          $nursery_groups = DB::table("feeds_movement_groups")
                              ->where('status','!=','removed')
                              ->where('type','=','nursery')
                              ->orderBy('group_name','asc')->get();
          $nursery_groups = $this->toArray($nursery_groups);
          Storage::put('nursery_groups_list.txt',json_encode($nursery_groups));
          $nursery_groups = Storage::get('nursery_groups_list.txt');

          $finisher_groups = DB::table("feeds_movement_groups")
                              ->where('status','!=','removed')
                              ->where('type','=','finisher')
                              ->orderBy('group_name','asc')->get();
          $finisher_groups = $this->toArray($finisher_groups);
          Storage::put('finisher_groups_list.txt',json_encode($finisher_groups));
          $finisher_groups = Storage::get('finisher_groups_list.txt');

          $output = array();

          switch($type){

            case 'all':

              $output = $this->filterAll($data,NULL);
              Storage::delete('animal_movement_data.txt');
              Storage::put('animal_movement_data.txt',json_encode($output));
              $output = Storage::get('animal_movement_data.txt');
              //return array("output"=>json_decode($output));
              return array("output"=>json_decode($output),"nursery_groups"=>json_decode($nursery_groups),"finisher_groups"=>json_decode($finisher_groups));

            case 'farrowing_to_nursery':

              $file_name = 'farrowing_data.txt';
              $output = $this->animalGroupSorter($data,["farrowing"],$file_name);
              //return array("output"=>json_decode($output));
              return array("output"=>json_decode($output),"nursery_groups"=>json_decode($nursery_groups),"finisher_groups"=>json_decode($finisher_groups));

            case 'nursery_to_finisher':

              $file_name = 'nursery_data.txt';
              $output = $this->animalGroupSorter($data,["nursery"],$file_name);
              //return array("output"=>json_decode($output));
              return array("output"=>json_decode($output),"nursery_groups"=>json_decode($nursery_groups),"finisher_groups"=>json_decode($finisher_groups));

            case 'finisher_to_market':

              $file_name = 'finisher_data.txt';
              $output = $this->animalGroupSorter($data,["finisher"],$file_name);
              //return array("output"=>json_decode($output));
              return array("output"=>json_decode($output),"nursery_groups"=>json_decode($nursery_groups),"finisher_groups"=>json_decode($finisher_groups));

            default:

              $output = $this->filterAll($data,"hah");
              Storage::delete('animal_movement_data.txt');
              Storage::put('animal_movement_data.txt',json_encode($output));
              $output = Storage::get('animal_movement_data.txt');
              //return array("output"=>json_decode($output));
              return array("output"=>json_decode($output),"nursery_groups"=>json_decode($nursery_groups),"finisher_groups"=>json_decode($finisher_groups));

          }


      }

      /**
      ** sort the animal groups
      ** @param $data array
      ** @param $type string
      ** @param $file_name string
      ** @return array
      **/
      private function animalGroupSorter($data,$type,$file_name)
      {
          $output_one = $this->filterTransferGroupTypes($data,$type);

          $checker = Storage::exists($file_name);
          if($checker == true){
            Storage::delete($file_name);
          }

          if($data['sort'] == 'day_remaining'){

            usort($output_one, function($a,$b){
              if($a['date_to_transfer'] == $b['date_to_transfer'])
              return ($a['date_to_transfer'] < $b['date_to_transfer']);
              return ($a['date_to_transfer'] > $b['date_to_transfer'])?1:-1;
            });

            Storage::put($file_name,json_encode($output_one));
            $output = Storage::get($file_name);

            return $output;

          }

          $output_two = $this->filterTransferCreated($data,$type,'feeds_movement_groups','feeds_movement_groups_bins');

          $output = array_merge($output_one,$output_two);

          Storage::put($file_name,json_encode($output));
          $output = Storage::get($file_name);

          return $output;
      }

      /**
      ** Filter for all farrowing to nursery groups
      ** @param $data array
      ** @param $type string
      ** @return Response
      **/
      private function filterTransferGroupTypes($data,$type)
      {
          if($type == "hah"){
            return $this->filterTransferOwnerGroupTypes($data);
          }
          $groups = DB::table("feeds_movement_groups")
                ->where('type',$type)
                ->whereNotIn('status',['finalized','removed','created'])
                ->whereBetween('date_created',[$data['date_from'],$data['date_to']])
                ->get();
          $groups = $this->toArray($groups);
          $groups = $this->filterTransferBins($groups,"feeds_movement_groups","feeds_movement_groups_bins");

          return $groups;

      }

      /**
      ** Filter for all farrowing to nursery groups
      ** @param $data array
      ** @return array
      **/
      private function filterTransferOwnerGroupTypes($data)
      {
          $owner_farms = $this->farmOwners();

          $groups = DB::table("feeds_movement_groups")
                ->where('type',$type)
                ->whereIn('farm_id',$owner)
                ->whereNotIn('status',['finalized','removed','created'])
                ->whereBetween('date_created',[$data['date_from'],$data['date_to']])
                ->get();
          $groups = $this->toArray($groups);
          $groups = $this->filterTransferBins($groups,"feeds_movement_groups","feeds_movement_groups_bins");

          return $groups;

      }

      /**
      ** Filter for all farms owner groups
      ** @return array
      **/
      private function farmOwners()
      {
          $owner_farms = Farms::select('id')->where('owner','H & H Farms')->get()->toArray();

          return $owner_farms;
      }

      /**
      ** Filter for all animal groups
      ** @return array
      **/
      private function filterAllDrivers()
      {
          $drivers = User::select('id','username')->where('type_id',2)->get()->toArray();

          Storage::put('drivers_data.txt',json_encode($drivers));
          $output = Storage::get('drivers_data.txt');

          return $output;
      }

      /**
      ** Filter for all animal groups
      ** @param $data array
      ** @param $type string
      ** @return array
      **/
      private function filterAll($data,$type)
      {
          if($type == "hah"){
            $farm_ids = $this->farmOwners();
            $groups = $this->filterTransferOwner($data,$farm_ids,'feeds_movement_groups','feeds_movement_groups_bins');
          }else{
            $groups = $this->filterTransfer($data,'feeds_movement_groups','feeds_movement_groups_bins');
          }
          $output_one = $groups;

          if($data['sort'] == 'day_remaining'){
            usort($output_one, function($a,$b){

              return ($a['date_to_transfer'] - $b['date_to_transfer'])
                    ?: ($a['group_type_int'] - $b['group_type_int'])
                    ?: ($b['group_type_int'] - $a['group_type_int']);

            });

            return $output_one;
          }
          $type = ['farrowing','nursery','finisher'];
          $output_two = $this->filterAdditional($data,$type);

          $output = array_merge($output_one,$output_two);

          return $output;
      }

      /**
      ** Filter for all additional animal groups
      ** @param $data array
      ** @return Response
      **/
      private function filterAdditional($data,$type)
      {
          $created_transfer = $this->filterTransferCreated($data,$type,'feeds_movement_groups','feeds_movement_groups_bins');

          return $created_transfer;
      }

      /**
      ** Type of groups detector
      ** @param $table string
      ** @return int
      **/
      private function groupTypeInt($table)
      {
          $type = "";

          if($table == 'farrowing'){
            $type = 1;
          }else if($table == 'nursery'){
            $type = 2;
          }else{
            $type = 3;
          }

          return $type;
      }

      /**
      ** Filter for all farrowing to nursery groups
      ** @param $data array
      ** @param $group_table string
      ** @param $group_bins_table string
      ** @return array
      **/
      private function filterTransfer($data,$group_table,$group_bins_table)
      {
          $groups = DB::table($group_table)
                ->whereNotIn('status',['finalized','removed','created'])
                ->whereBetween('date_created',[$data['date_from'],$data['date_to']])
                ->orderBy('date_to_transfer','desc')
                ->get();
          $groups = $this->toArray($groups);
          $groups = $this->filterTransferBins($groups,$group_table,$group_bins_table);

          return $groups;
      }

      /**
      ** Filter for all farrowing to nursery groups
      ** @param $data array
      ** @param $farm_ids array
      ** @param $group_table string
      ** @param $group_bins_table string
      ** @return array
      **/
      private function filterTransferOwner($data,$farm_ids,$group_table,$group_bins_table)
      {
          $groups = DB::table($group_table)
                ->whereIn('farm_id',$farm_ids)
                ->whereNotIn('status',['finalized','removed','created'])
                ->whereBetween('date_created',[$data['date_from'],$data['date_to']])
                ->orderBy('date_to_transfer','desc')
                ->get();
          $groups = $this->toArray($groups);
          $groups = $this->filterTransferBins($groups,$group_table,$group_bins_table);

          return $groups;
      }

      /**
      ** Filter for all farrowing to nursery groups
      ** @param $data array
      ** @param $group_table string
      ** @param $group_bins_table string
      ** @return array
      **/
      private function filterTransferCreated($data,$type,$group_table,$group_bins_table)
      {
          $groups = DB::table($group_table)
                ->where('status','created')
                ->whereIn('type',$type)
                ->whereBetween('date_created',[$data['date_from'],$data['date_to']])
                ->orderBy('date_to_transfer','asc')
                ->get();
          $groups = $this->toArray($groups);
          $groups = $this->filterTransferBins($groups,$group_table,$group_bins_table);

          return $groups;
      }


      /**
      ** Filter for all animal groups
      ** @param $groups array
      ** @param $group_table string
      ** @param $group_bins_table string
      ** @return array
      **/
      private function filterTransferBins($groups,$group_table,$group_bins_table)
      {
          $data = array();

          foreach($groups as $k => $v){

            $date_to_transfer = (strtotime(date('Y-m-d',strtotime($v['date_to_transfer']))) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);

            $data[] = array(
              'group_id'					=>	$v['group_id'],
              'group_name'				=>	$v['group_name'],
              'unique_id'					=>	$v['unique_id'],
              'date_created'			=>	$v['date_created'],
              'date_transfered'		=>	$v['date_transfered'],
              'date_to_transfer'	=>	$this->daysRemaining($date_to_transfer,$v['type']),
              'status'						=>	$v['status'],
              'start_weight'			=>	$v['start_weight'],
              'end_weight'				=>	$v['end_weight'],
              'type'							=>	$v['type'],//$this->groupType($group_bins_table),
              'crates'						=>	$v['crates'],
              'group_type_int'		=> 	$this->groupTypeInt($v['type']),
              'user_id'						=>	$v['user_id'],
              'farm_id'						=>	$v['farm_id'],
              'deceased'					=>	$this->deceasedPigs($v['group_id']),
              'treated'						=>	$this->treatedPigs($v['group_id']),
              'total_pigs'				=>	$this->totalPigsFilter($v['unique_id'],$group_bins_table),
              'farm_name'					=>	$this->farmData($v['farm_id']),
              'bin_data'					=>	$this->binsDataFilter($v['unique_id'],$group_bins_table,$v['farm_id']),
              'transfer_data'			=> 	$this->transferData($v['group_id']),
              'sched_pigs'				=>	$this->scheduledTransaferPigs($v['group_id'])
            );

          }

          return $data;

      }

      /**
      ** deceasedPigs()
      ** get the corresponding deceased pigs of a group
      ** @param $group_id int
      ** @return Response
      **/
      private function deceasedPigs($group_id)
      {
          $deceased = DB::table('feeds_deceased')->where('group_id',$group_id)->get();
          $deceased = $this->toArray($deceased);

          return $deceased;
      }

      /**
      ** treatedPigs()
      ** get the corresponding deceased pigs of a group
      ** @param $group_id int
      ** @return array
      **/
      private function treatedPigs($group_id)
      {
          $treated = DB::table('feeds_treatment')->where('group_id',$group_id)->get();
          $treated = $this->toArray($treated);

          return $treated;
      }

      /**
      ** transferData()
      ** get the corresponding transfer data of a group
      ** @param $group_id int
      ** @return Response
      **/
      private function transferData($group_id)
      {
          $transfer = DB::table('feeds_movement_transfer_v2')->where('group_from',$group_id)->whereIn('status',['created','edited'])->get();
          if($transfer == NULL){
            return NULL;
          }
          $transfer = $this->toArray($transfer);

          return $this->buildTransferData($transfer,NULL);
      }

      /**
      ** scheduledTransaferPigs()
      ** get the total scheduled for tansfer pigs
      ** @param $group_id int
      ** @return Response
      **/
      private function scheduledTransaferPigs($group_id)
      {
          $total_shipped = DB::table('feeds_movement_transfer_v2')
                        ->where('group_from',$group_id)
                        ->where('status','!=','finalized')
                        ->sum('shipped');
          return $total_shipped;
      }

      /**
      ** days remaining for all animal groups
      ** @param $date date
      ** @param $type string
      ** @return int
      **/
      private function daysRemaining($date,$type)
      {
          $output = NULL;
          if($type == 'farrowing') {
            if($date > 2) {
              $output = $date - 2 . "-" . $date;
            } else if ($date < 0) {
              $output = 0;
            } else {
              $output = $date;
            }
          } else if($type == 'nursery') {
            if ($date < 0) {
              $output = 0;
            } else {
              $output = $date;
            }
          } else if($type == 'finisher') {
            if($date > 10) {
              $output = $date - 10 . "-" . $date;
            } else if ($date < 0) {
              $output = 0;
            } else {
              $output = $date;

            }
          } else {
            $output = $output;
          }

          return round($output);
      }

      /**
      ** Get the total number of pigs for the specific animal groups
      ** @param $unique_id string
      ** @param $group_bins_table string
      ** @return int
      **/
      private function totalPigsFilter($unique_id,$group_bins_table)
      {
          $total = DB::table($group_bins_table)->where('unique_id',$unique_id)->sum('number_of_pigs');

          return $total;
      }

      /**
      ** Get all the data of the nursery group
      ** @param $farm_id int
      ** @return string
      **/
      private function farmData($farm_id)
      {
          $farm = Farms::select('name')->where('id',$farm_id)->first();
          return $farm != NULL ? $farm->name : NULL;
      }

      /**
      ** Get the bins data for the animal groups
      ** @param $unique_id string
      ** @param $group_bins_table string
      ** @param $farm_id int
      ** @return array
      **/
      private function binsDataFilter($unique_id,$group_bins_table,$farm_id)
      {

          $bins = DB::table($group_bins_table)->where('unique_id',$unique_id)->get();

          $bins = $this->toArray($bins);
          $data = array();
          foreach($bins as $k => $v){
            $data[] = array(
                    'id'			=>	$v['id'],
                    'alias_label' 	=> $this->binLabel($v['bin_id']),
                    'farm_name'	=> $this->farmName($farm_id),
                    'bin_id'		=>	$v['bin_id'],
                    'room_id'   =>  $v['room_id'],
                    'number_of_pigs'	=> $v['number_of_pigs']
                    );
          }

          return $data;

      }

      /**
      ** use to build the transfer data
      ** @param $transfer_data array
      ** @param $type string
      ** @return array
      **/
      public function buildTransferData($transfer_data,$type)
      {
          $data = array();

          foreach($transfer_data as $k => $v){
            $type = $type == NULL ? $v['transfer_type'] : $type;
            $farms = $this->animalGroupFarmName($v['group_from'],$v['group_to'],$type);
            $group_name_to = "Market";
            if($farms['group_name_to'] != NULL || $farms['group_name_to'] != ""){
              $group_name_to = $farms['group_name_to'];
            }

            $data[] = array(
              'transfer_id'	=>	$v['transfer_id'],
              'transfer_number'	=>	$v['transfer_number'],
              'transfer_type'	=>	$v['transfer_type'],
              'status'	=>	$v['status'],
              'date'	=>	date("M d, Y", strtotime($v['date'])),
              'date_ymd'    =>  date("Y-m-d", strtotime($v['date'])),
              'group_id'	=>	$v['group_from'],
              'group_from'	=>	$v['group_from'],
              'group_to'	=> $v['group_to'],
              'group_from_farm'	=>	$farms['farm_from'],
              'group_to_farm'	=> $farms['farm_to'],
              'group_name_from'	=>	$farms['group_name_from'],
              'group_name_to'	=> $group_name_to,
              'farm_id_from'	=>	$farms['farm_id_from'],
              'farm_id_to'	=> $farms['farm_id_to'],
              'empty_weight'	=>	$v['empty_weight'],
              'full_weight'	=>	$v['full_weight'],
              'ave_weight'	=> $v['ave_weight'],
              'shipped'	=>	$v['shipped'],
              'received'	=>	$v['received'],
              'dead'	=>	$v['dead'],
              'poor'	=>	$v['poor'],
              'initial_count'	=> $v['initial_count'],
              'farm_count'	=> $v['farm_count'],
              'final_count'	=> $v['final_count'],
              'notes'			=>	$v['notes'],
              'driver_id'		=>	$v['driver_id']
            );
          }

          return $data;
      }

      /**
      ** used to get the animal groups farm name
      ** @param $group_from int
      ** @param $group_to int
      ** @param $type string
      ** @return Response
      **/
      private function animalGroupFarmName($group_from,$group_to,$type)
      {
          $table_from = "";
          $table_to = "";
          if($type == 'farrowing_to_nursery' || $type == 'farrowing'){
            $table_from = 'feeds_movement_groups';
            $table_to = 'feeds_movement_groups';
          } else if($type == 'nursery_to_finisher' || $type == 'nursery'){
            $table_from = 'feeds_movement_groups';
            $table_to = 'feeds_movement_groups';
          } else if($type == 'finisher_to_market' || $type == 'finisher'){
            $table_from = 'feeds_movement_groups';
            $table_to = NULL;
            $farm_name_to = "market";
            $group_name_to = "";
            $farm_id_to = "";
          } else {
            return "none";
          }

          $table_from = 'feeds_movement_groups';
          $table_to = 'feeds_movement_groups';

          $group_from_data = DB::table($table_from)->where('group_id',$group_from)->first();
          $group_name_from = $group_from_data->group_name;
          $farm_id_from = $group_from_data->farm_id;
          $farm_name_from = Farms::where('id',$farm_id_from)->first();
          $farm_name_from = $farm_name_from->name;
          if($table_to != NULL){
            $group_to_data = DB::table($table_to)->where('group_id',$group_to)->first();
            $group_name_to = $group_to_data != NULL ? $group_to_data->group_name : "";
            $farm_id_to = $group_to_data != NULL ? $group_to_data->farm_id : 1;
            $farm_name_to = Farms::where('id',$farm_id_to)->first();
            $farm_name_to = $farm_name_to->name;
          }

          return array(
            'farm_from'=>$farm_name_from,
            'farm_to'=>$farm_name_to,
            'group_name_from' => $group_name_from,
            'group_name_to'	=>	$group_name_to,
            'farm_id_from'	=>	$farm_id_from,
            'farm_id_to'		=>	$farm_id_to
          );
      }

      /**
      ** Display the group of animals page
      ** @param $farm_id int
      ** @return Response
      **/
      private function farmName($farm_id)
      {
          $data = Farms::where('id',$farm_id)->first();
          return $data != NULL ? $data->name : NULL;
      }

      /**
      ** Display the farrowing group maintenance page
      ** @param $group_id int
      ** @return Response
      **/
      public function animalGroupEdit($group_id)
      {
          $group_data = DB::table('feeds_movement_groups')
                  ->select('feeds_movement_groups.*',
                    'feeds_farms.name')
                  ->leftJoin('feeds_farms','feeds_movement_groups.farm_id','=','feeds_farms.id')
                  ->where('feeds_movement_groups.status','!=','removed')
                  ->where('feeds_movement_groups.group_id',$group_id)
                  ->get();

          if($group_data[0]->type == 'farrowing'){
            $farms_data = Farms::select('id','name')->where('farm_type','farrowing')->orderBy('name','desc')->get()->toArray();
          }else if($group_data[0]->type == 'nursery'){
            $farms_data = Farms::select('id','name')->where('farm_type','nursery')->orderBy('name','desc')->get()->toArray();
          }else {
            $farms_data = Farms::select('id','name')->where('farm_type','finisher')->orderBy('name','desc')->get()->toArray();
          }
          $group_data = $this->buildData($group_data);

          return view('animalmovement.edit',compact("group_data","farms_data"));
      }

      /**
      ** Display the farrowing group maintenance page
      **
      ** @return Response
      **/
      public function farrowingPageAPI()
      {
          $farrow_data = DB::table('feeds_movement_groups')
                  ->select('feeds_movement_groups.*',
                    'feeds_farms.name')
                  ->leftJoin('feeds_farms','feeds_movement_groups.farm_id','=','feeds_farms.id')
                  ->where('feeds_movement_groups.status','!=','removed')
                  ->orderBy('group_id','desc')
                  //->take(8)
                  ->get();

          $farrow_count = DB::table('feeds_movement_groups')->count();

          $farms_data = Farms::select('id','name')->where('farm_type','farrowing')->orderBy('name','desc')->get()->toArray();
          $farrow_data = $this->buildData($farrow_data);

          return array(
            'farrow_data' =>  $farrow_data,
            'farms_data'  =>  $farms_data,
            'farrow_count'  =>  $farrow_count
          );

      }


      /**
      ** Convert object to array
      **
      ** @return Response
      **/
      private function toArray($data)
      {
          $resultArray = json_decode(json_encode($data), true);

          return $resultArray;
      }

      /**
      ** build the data for the animal groups
      **
      ** @return Response
      **/
      private function buildData($data)
      {
          $data = $this->toArray($data);
          $farrowdata = array();

          foreach($data as $v){
            $farrowdata[] = array(
              'group_id'					=>	$v['group_id'],
              'group_name'				=>	$v['group_name'],
              'name'							=>	$v['name'],
              'farm_id'						=>	$v['farm_id'],
              'start_weight'			=>	$v['start_weight'],
              'end_weight'				=>	$v['end_weight'],
              'crates'						=>	$v['crates'],
              'type'							=>	$v['type'],
              'date_created'			=>	$v['date_created'],
              'date_to_transfer'	=>	(strtotime(date('Y-m-d',strtotime($v['date_to_transfer']))) - strtotime(date('Y-m-d'))) / (60 * 60 * 24),
              'unique_id'					=>	$v['unique_id'],
              'bin_data'					=>	$this->binsData($v['unique_id']),
              'total_pigs'				=>	$this->totalPigs($v['unique_id']),
              'farrowing_farms'		=>	Farms::select('id','name')->where('farm_type','farrowing')->orderBy('name','desc')->get()->toArray()
            );
          }

          return $farrowdata;
      }

      /**
      ** Get the bins data for the animal groups
      **
      ** @return Response
      **/
      private function binsData($unique_id)
      {
          $bins = DB::table('feeds_movement_groups_bins')->where('unique_id',$unique_id)->get();

          $bins = $this->toArray($bins);
          $data = array();
          foreach($bins as $k => $v){
            $data[] = array(
                    'id'			=>	$v['id'],
                    'alias_label' 	=> 	$this->binLabel($v['bin_id']),
                    'bin_id'		=>	$v['bin_id'],
                    'number_of_pigs'	=> $v['number_of_pigs']
                    );
          }

          return $data;
      }

      /**
      ** Get the bins alias and label
      **
      ** @return Response
      **/
      private function binLabel($bin_id)
      {
          $data = DB::table('feeds_bins')->select('bin_number','alias')
                    ->where('bin_id',$bin_id)->first();

          if($data == NULL){
            return NULL;
          }

          $alias = $data->bin_number . " - ". $data->alias;

          return $alias;
      }

      /**
      ** Get the total number of pigs for the farrowing page
      **
      ** @return Response
      **/
      private function totalPigs($unique_id)
      {
          $total = DB::table('feeds_movement_groups_bins')
                    ->where('unique_id',$unique_id)->sum('number_of_pigs');

          return $total; //== NULL ? 0 : $total[0]->number_of_pigs;
      }

      /**
      ** generate the uniqueID for all the animal groups
      **
      ** @return Response
      **/
      public function generateUniqueID()
      {
          return date('ymshis').uniqid(rand());
      }

      /**
      **  Save created group
      **  Used to save the created animal group
      **  @param  array  $data
      **  @return Response
      **/
      public function saveGroupAPI($data)
      {

          $number_of_pigs = $data['number_of_pigs'];
          $unique_id = $this->generateUniqueID();

          $date_to_transfer = $this->dateToTransfer($data['type'],$data['date_created']);

          $data_group = array(
            'group_name'			  =>	$data['group_name'],
            'farm_id'				    =>	$data['farm_id'],
            'start_weight'	    =>	$data['start_weight'],
            'end_weight'	      =>	$data['end_weight'],
            'crates'			      =>	$data['crates'],
            'date_created'			=>	$data['date_created'],
            'date_to_transfer'	=>  $date_to_transfer,
            'status'				    =>	'entered',
            'type'              =>  $data['type'],
            'user_id'				    =>	$data['user_id'],
            'unique_id'				  =>	$unique_id
          );

          if($data['type'] == "farrowing"){
            $rooms = $data['rooms'];
            foreach($rooms as $k => $v){
              $data_group_bins = array(
                'room_id'			    =>	$rooms[$k],
                'number_of_pigs'	=>	$number_of_pigs[$k],
                'unique_id'			  =>	$unique_id
              );
              DB::table('feeds_movement_groups_bins')->insert($data_group_bins);
            }

            $save = DB::table('feeds_movement_groups')->insert($data_group,$data['farm_id']);

          } else {
            $bins = $data['bins'];
            foreach($bins as $k => $v){
              $data_group_bins = array(
                'bin_id'			    =>	$bins[$k],
                'number_of_pigs'	=>	$number_of_pigs[$k],
                'unique_id'			  =>	$unique_id
              );
              $this->saveGroupBins($data_group_bins);
            }

            $save = DB::table('feeds_movement_groups')->insert($data_group,$data['farm_id']);

            foreach($bins as $k => $v){
              $this->updateBinsHistoryNumberOfPigs($bins[$k],$number_of_pigs[$k],"create",$data['user_id']);
            }
          }




          if($save == 1){
            return "success";
          }

      }

      /**
      ** Save farrowing bins
      ** @return Response
      **/
      public function saveGroupBins($data)
      {
          DB::table('feeds_movement_groups_bins')->insert($data);
          // update the bin history and bin tables
          DB::table('feeds_bins')->where('bin_id',$data['bin_id'])->update(['num_of_pigs' => $data['number_of_pigs']]);
      }

      /**
      ** Detect the animal group type date to transfer
      ** @return Response
      **/
      private function dateToTransfer($type,$date_created)
      {
          $date_to_transfer = "wrong type of group";

          if($type == "farrowing"){
            $date_to_transfer = date('Y-m-d',strtotime($date_created . "+20 days"));
          } else if($type == "nursery"){
            $date_to_transfer = date('Y-m-d',strtotime($date_created . "+40 days"));
          } else if($type == "finisher") {
            $date_to_transfer = date('Y-m-d',strtotime($date_created . "+120 days"));
          } else {
            $date_to_transfer = $date_to_transfer;
          }

          return $date_to_transfer;
      }

      /**
      ** Update the animal group
      ** @return Response
      **/
      public function updateGroupAPI($data)
      {
          $date_to_transfer = $this->dateToTransfer($data['type'],$data['date_created']);

          $number_of_pigs = $data['number_of_pigs'];
          $group_bin_id = $data['group_bin_id'];

          $group_data = array(
            'group_name'		   =>	$data['group_name'],
            'farm_id'			     =>	$data['farm_id'],
            'date_created'		 =>	$data['date_created'],
            'start_weight'		 =>	$data['start_weight'],
            'end_weight'		   =>	$data['end_weight'],
            'crates'				   =>	$data['crates'],
            'date_to_transfer' => $date_to_transfer,
            'date_transfered'	 =>	"0000-00-00 00:00:00",
            'status'			     =>	'entered',
            'user_id'			     =>	$data['user_id'],
            'type'             => $data['type']
          );

          /*
          if farm is the same as the farm on the farrowing group update else, delete bins and insert new selected bins
          */
          $farm = $this->checkFarmExists($data['farm_id'],$data['unique_id']);
          if($farm == 0){
            $bins_to_delete = DB::table('feeds_movement_groups_bins')->where('unique_id',$data['unique_id'])->get();
            if($bins_to_delete != NULL){
              foreach($bins_to_delete as $k => $v){
                Cache::forget('bins-'.$v->bin_id);
              }
            }
            // delete bins
            DB::table('feeds_movement_groups_bins')->where('unique_id',$data['unique_id'])->delete();

            if($data['type'] == "farrowing"){
              $data_room = $data['rooms'];
              foreach($data_room as $k => $v){
                $data = array(
                'room_id'			=>	$v,
                'number_of_pigs'	=>	$number_of_pigs[$k],
                'unique_id'			=>	$data['unique_id']
                );
                DB::table('feeds_movement_groups_bins')->insert($data);
              }
            } else {
              $data_bin = $data['bins'];
              foreach($data_bin as $k => $v){
                $this->insertBinFarrowing($v,$data['unique_id'],$number_of_pigs[$k],$data['user_id']);
              }
            }

          } else {
            // update bins
            if($data['type'] == "farrowing"){
              $data_room = $data['rooms'];
              foreach($data_room as $k => $v){
                $d = array(
                'room_id'			=>	$v,
                'number_of_pigs'	=>	$number_of_pigs[$k]
                );

                DB::table('feeds_movement_groups_bins')
                ->where('id',$group_bin_id[$k])
                ->where('unique_id',$data['unique_id'])
                ->update($d);
              }
            } else {
              foreach($data_bin as $k => $v){
                $this->updateBinFarrowing($v,$data['unique_id'],$number_of_pigs[$k],$group_bin_id[$k],$data['user_id']);
              }
            }

          }

          // update farrowing group
          DB::table('feeds_movement_groups')->where('unique_id',$data['unique_id'])->update($group_data);

          return "success";
      }

      /**
      ** Farrowing farm data exists checker
      **
      ** @param  int  $bin_id
      ** @param  string  $unique_id
      ** @return Response
      **/
      private function checkFarmExists($farm_id,$unique_id)
      {
          $counter  = DB::table('feeds_movement_groups')
            ->where('unique_id',$unique_id)
            ->where('farm_id',$farm_id)
            ->count();

          return $counter;
      }

      /**
      ** Insert farrowing bin
      **
      ** @param  int  $bin_id
      ** @param  string  $unique_id
      ** @param  int  $pigs
      ** @return Response
      **/
      private function insertBinFarrowing($bin_id,$unique_id,$pigs,$user_id)
      {

          $data = array(
          'bin_id'			=>	$bin_id,
          'number_of_pigs'	=>	$pigs,
          'unique_id'			=>	$unique_id
          );

          DB::table('feeds_movement_groups_bins')->insert($data);

          $this->updateBinsHistoryNumberOfPigs($bin_id,$pigs,"create",$user_id);

      }

      /**
      ** Update farrowing bin
      **
      ** @param  int  $bin_id
      ** @param  string  $unique_id
      ** @param  int  $pigs
      ** @return Response
      **/
      private function updateBinFarrowing($bin_id,$unique_id,$pigs,$f_bin_id,$user_id)
      {

          $data = array(
          'bin_id'			=>	$bin_id,
          'number_of_pigs'	=>	$pigs
          );

          DB::table('feeds_movement_groups_bins')
          ->where('id',$f_bin_id)
          ->where('unique_id',$unique_id)
          ->update($data);

          $this->updateBinsHistoryNumberOfPigs($bin_id,$pigs,"update",$user_id);

      }


      /**
      ** Used to create the transfer for animal groups
      **
      ** @return Response
      **/
      public function createTransferAPI($data)
      {
          $type = $this->transferType(Input::get('group_type'));

          $data_transfer = array(
            'transfer_number'	=>	$this->transferIDGenerator(),
            'transfer_type'		=>	$data['transfer_type'],
            'group_from'			=>	$data['group_from'],
            'group_to'				=>	$data['group_to'],
            'status'					=>	"created",
            'driver_id'				=>	$data['driver_id'],
            'date'						=> 	$data['date'],
            'shipped'					=>	$data['number_of_pigs'],
            'initial_count'		=>	$data['number_of_pigs']
          );

          DB::table('feeds_movement_transfer_v2')->insert($data_transfer);
          DB::table('feeds_movement_groups')->where('group_id',$data['group_from'])->update(['status'=>'created']);

          $groups = DB::table('feeds_movement_groups')
                ->where('status','created')
                ->where('group_id',$data['group_from'])
                ->get();
          $groups = $this->toArray($groups);
          $groups = $this->filterTransferBins($groups,'feeds_movement_groups','feeds_movement_groups_bins');

          return array('output'=>$groups);

      }



      /**
      ** transferType()
      **
      ** @return Response
      **/
      private function transferType($type)
      {
        $final_type = "none";
        if($type == 'farrowing'){
          $final_type = 'farrowing_to_nursery';
        } else if($type == 'nursery'){
          $final_type = 'nursery_to_finisher';
        } else if($type == 'finisher'){
          $final_type = 'finisher_to_market';
        } else {
          $final_type = $final_type;
        }

        return $final_type;
      }

      /**
      ** trasnfer id generator()
      **
      ** @return Response
      **/
      private function transferIDGenerator()
      {
        $unique = 'trans-';//uniqid(rand())
        $dateToday = date('ymdhms');

        return $dateToday;
      }

      /**
      ** updateTransfer()
      **
      ** @return Response
      **/
      public function updateTransferAPI($data)
      {

          $transfer_id	=	$data['transfer_id'];
          //$group_to_previous	=	Input::get('group_to_previous');
          $data = array(
            'transfer_type'			=>	$data['transfer_type'],
            'group_from'				=>	$data['group_from'],
            'group_to'					=>	$data['group_to'],
            'status'						=>	'edited',
            'date'							=> 	$data['date'],
            'shipped'						=>	$data['shipped'],
            'empty_weight'			=>	$data['empty_weight'],
            'ave_weight'				=>	$data['ave_weight'],
            'driver_id'					=>	$data['driver_id'],
            'full_weight'				=>	$data['full_weight'],
            'received'					=>	$data['received'],
            'dead'							=>	$data['dead'],
            'poor'							=>	$data['poor'],
            'farm_count'				=>	$data['farm_count'],
            'final_count'				=>	$data['final_count'],
            'notes'							=>	$data['notes']
          );

          //$counter = $this->groupToChecker($data['group_from'],$data['group_to'],$group_to_previous,$data['transfer_type']);

          //if($counter == 0){
            DB::table('feeds_movement_transfer_v2')->where('transfer_id',$transfer_id)->update($data);
            DB::table('feeds_movement_groups')->where('group_id',$data['group_from'])->update(['status'=>'created']);
            return 'success';
          //} else {
            //return 'transfer already created';
          //}

      }

      /*
      *	finalizeTransfer()
      *
      * fetch bins info
      */
      private function finalizeTransferValidation($data)
      {

        $error = "";
        $data_to_transfer = $data;

        $transfer_data = $data['transfer_data'];

        $bins_from = $data['bins_from'];
        $bins_from_pigs = $data['bins_from_pigs'];

        $bins_to = $data['bins_to'];
        $bins_to_pigs = $data['bins_to_pigs'];

        $num_of_pigs_dead = $data['num_of_pigs_dead'];
        $num_of_pigs_poor = $data['num_of_pigs_poor'];

        $total_bins_from_pigs = 0;
        $total_bins_to_pigs = 0; // final count of the group to pigs ($total_group_to_pigs)
        $total_dead_pigs = 0;
        $total_poor_pigs = 0;

        for($i=0; $i<count($bins_from); $i++){

            $total_bins_from_pigs = $total_bins_from_pigs + $bins_from_pigs[$i];
            $total_bins_to_pigs = $total_bins_to_pigs + $bins_to_pigs[$i];

            $total_dead_pigs = $total_dead_pigs + $num_of_pigs_dead[$i];
            $total_poor_pigs = $total_poor_pigs + $num_of_pigs_poor[$i];

            if($bins_from_pigs[$i] == 0 && $bins_to_pigs[$i] == 0 && $num_of_pigs_dead[$i] == 0 && $num_of_pigs_poor[$i] != 0){
              $error = "Please enter the right matches of PIGS FROM and PIGS TO or DEAD or POOR per row";
              return $error;
            }

            if($bins_from_pigs[$i] == 0 && $bins_to_pigs[$i] == 0 && $num_of_pigs_dead[$i] != 0){
              $error = "Please enter the right matches of PIGS FROM and PIGS TO + POOR + DEAD per row";
              return $error;
            }

            if($bins_from_pigs[$i] > $bins_to_pigs[$i]){
              $error = "The number of pigs for PIGS FROM should not be greater than it's available pigs per row";
              return $error;
            }

            if($bins_from_pigs[$i] == 0 && $bins_to_pigs[$i] != 0){
              $error = "Please enter the right matches of PIGS FROM and PIGS TO + DEAD + POOR per row";
              return $error;
            }

            if($bins_to_pigs[$i] > $bins_from_pigs[$i]){
              $error = "Please enter the right matches of PIGS FROM and PIGS TO + DEAD + POOR per row";
              return $error;
            }

        }

        $total_pigs_to_transfer = $total_bins_to_pigs+$total_dead_pigs+$total_poor_pigs;

        if($total_pigs_to_transfer != $total_bins_from_pigs){
          $error = "PIGS FROM should always matched PIGS TO + POOR + DEAD per individual row";
        }

        if($transfer_data['shipped'] != $total_bins_from_pigs){
          $error = "total number of PIGS FROM should always matched the number of SHIPPED pigs";
        }

        if($transfer_data['final_count'] > $total_bins_from_pigs){
          $error = "FINAL COUNT should not be greater than the total PIGS FROM";
        }

        if($transfer_data['final_count'] > $transfer_data['shipped']){
          $error = "FINAL COUNT should not be greater than SHIPPED";
        }

        if($error != "") {

            return $error;

        } else {

            // proceed to finalize transfer
            return $this->finalizeTransfer($data_to_transfer);

        }



      }


      /*
      *	finalizeTransfer()
      *
      * fetch bins info
      */
      public function finalizeTransfer($data)
      {
        $transfer_data = $data['transfer_data'];
        $transfer_id = $transfer_data['transfer_id'];

        $bins_from = $data['bins_from'];
        $bins_from_pigs = $data['bins_from_pigs'];

        $bins_to = $data['bins_to'];
        $bins_to_pigs = $data['bins_to_pigs'];

        $num_of_pigs_dead = $data['num_of_pigs_dead'];
        $num_of_pigs_poor = $data['num_of_pigs_poor'];

        $transfer = array(
          'transfer_type'		=>	$transfer_data['transfer_type'],
          'status'					=>	'finalized',
          'date'						=>	date('Y-m-d',strtotime($transfer_data['date'])),
          'group_from'			=>	$transfer_data['group_from'],
          'group_to'				=>	$transfer_data['group_to'],
          'empty_weight'		=>	$transfer_data['empty_weight'],
          'full_weight'			=>	$transfer_data['full_weight'],
          'ave_weight'			=>	$transfer_data['ave_weight'],
          'shipped'					=>	$transfer_data['shipped'],
          'received'				=>	$transfer_data['received'],
          'dead'						=>	$transfer_data['dead'],
          'poor'						=>	$transfer_data['poor'],
          'initial_count'		=>	$transfer_data['shipped'],
          'farm_count'			=>	$transfer_data['farm_count'],
          'final_count'			=>	$transfer_data['final_count'],
          'driver_id'				=>	$transfer_data['driver_id']
        );

        // update the 'feeds_movement_transfer_v2'
        DB::table('feeds_movement_transfer_v2')->where('transfer_id',$transfer_id)->update($transfer);

        $transfer_bins = array();
        foreach($bins_from as $k => $v){

          //if($bins_from_pigs[$k]['value'] != 0){
              $transfer_bins[] = array(
                'transfer_id'		=>	$transfer_id,
                'bin_id_from'		=>	$v,
                'bin_id_to'			=>	$bins_to[$k],
                'number_of_pigs_transferred'	=>	$bins_to_pigs[$k],
                'dead'					=>	$num_of_pigs_dead[$k],
                'poor'					=>	$num_of_pigs_poor[$k],
              );

              $transfer_bins_update = array(
                'transfer_id'		=>	$transfer_id,
                'bin_id_from'		=>	$v,
                'bin_id_to'			=>	$bins_to[$k],
                'number_of_pigs_transferred'	=>	$bins_to_pigs[$k],
                'dead'					=>	$num_of_pigs_dead[$k],
                'poor'					=>	$num_of_pigs_poor[$k],
              );

              $this->updateGroupsBinsPigs($transfer_bins_update,$transfer_data['unique_id'],
                                          $transfer_data['transfer_type'],
                                          $transfer_data['group_from'],
                                          $transfer_data['group_to'],
                                          $num_of_pigs_poor[$k],$transfer_data['user_id']);
          //}

        }
        //dd($transfer_bins);
        // insert data on the 'feeds_movement_transfer_bins_v2'
        if(DB::table('feeds_movement_transfer_bins_v2')->insert($transfer_bins)){
          return "success";
        }

        // notify the driver

      }




      /**
      **	Update the bin history for update number of pigs
      **  @return Response
      **/
      public function updateBinsHistoryNumberOfPigs($bin_id,$number_of_pigs,$type,$user_id)
      {
            $bininfo = $this->getBinDefaultInfo($bin_id);
            $lastupdate  = $this->getLastHistory($bininfo);

            // get the total number of pigs based on the animal group total number of pigs
            $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroups($bin_id,$bininfo[0]->farm_id); //$number_of_pigs;

            if(!empty($lastupdate)){
                  $update_date = date("Y-m-d",strtotime($lastupdate[0]->update_date));
                  if($update_date == date("Y-m-d")){
                        $variance = $lastupdate[0]->variance;
                        $consumption = $lastupdate[0]->consumption;
                        DB::table('feeds_bin_history')
                        ->where('bin_id', '=', $bin_id)
                        ->whereBetween('update_date', array(date("Y-m-d") . " 00:00:00", date("Y-m-d") . " 23:59:59"))
                        ->delete();
                  }else{
                        $variance = 0;
                        $consumption = 0;
                  }
            }

            $data = array(
                'update_date' => date("Y-m-d H:i:s"),
                'bin_id' => $bin_id,
                'farm_id' => $bininfo[0]->farm_id,
                'num_of_pigs' => $total_number_of_pigs,
                'user_id' => $user_id,//Auth::id(),
                'amount' => $lastupdate[0]->amount,
                'update_type' => 'Manual Update Number of Pigs, '.$type.' Animal Groups Admin',
                'created_at' => date("Y-m-d H:i:s"),
                'updated_at' => date("Y-m-d H:i:s"),
                'budgeted_amount' => $lastupdate[0]->budgeted_amount,
                'budgeted_amount_tons' => $lastupdate[0]->budgeted_amount_tons,
                'actual_amount_tons' => $lastupdate[0]->actual_amount_tons,
                'remaining_amount' => $lastupdate[0]->remaining_amount,
                'sub_amount' => $lastupdate[0]->sub_amount,
                'variance' => $variance,
                'consumption' => $consumption,
                'admin' => 1,
                'medication' => !empty($lastupdate[0]->medication) ? $lastupdate[0]->medication : 0,
                'feed_type' => $lastupdate[0]->feed_type,
                'unique_id'	=> !empty($lastupdate[0]->unique_id) ? $lastupdate[0]->unique_id : "none"
              );

            BinsHistory::insert($data);

            // $notification = new CloudMessaging;
            // $farmer_data = array(
            //   'farm_id'		=> 	$bininfo[0]->farm_id,
            //   'bin_id'		=> 	$bin_id,
            //   'num_of_pigs'	=> 	$total_number_of_pigs
            //   );
            // $notification->updatePigsMessaging($farmer_data);
            // unset($notification);

            Cache::forget('bins-'.$bin_id);

      }

      /**
      ** Gets the Default Values of a certain Bin
      ** int bin_id Primary key
      ** @return array Object 2-19-2016
      **/
      private function getBinDefaultInfo($bin_id)
      {
          $output = DB::table('feeds_bins')
                ->select('bin_id','farm_id','num_of_pigs','amount', 'feed_type', 'bin_size')
                ->where('bin_id','=',$bin_id)
                ->get();
          return $output;
      }

      /**
      ** Gets last values from Update History
      ** bininfo array Object
      ** @return array Object 2-19-2016
      **/
      private  function getLastHistory($bininfo)
      {
          $output = DB::table('feeds_bin_history')
                ->where('bin_id','=',$bininfo[0]->bin_id)
                ->orderBy('update_date', 'DESC')
                ->take(1)
                ->get();

          if(count($output) == 0) {
            $output[0] =  (object)array(
              'num_of_pigs' => $bininfo[0]->num_of_pigs,
              'amount'=> 0,
              'budgeted_amount'=>$this->getBudgetedAmount($bininfo[0]->feed_type),
              'remaining_amount'=> 0,
              'sub_amount' => 0,
              'variance' => 0,
              'consumption' => 0
            );
          }

          return $output;
      }

      /**
      ** Gets the budgeted amount of specific feed type
      ** bininfo array Object
      ** @return array Object 2-19-2016
      **/
      private function getBudgetedAmount($feedtype) {
          $output = DB::table('feeds_feed_types')
                ->select('budgeted_amount')
                ->where('type_id','=',$feedtype)
                ->get();

          return !empty($output[0]->budgeted_amount) ? $output[0]->budgeted_amount : 0;
      }

      /**
      ** Get the total number of pigs on the animal group
      ** @return int
      **/
      private function totalNumberOfPigsAnimalGroups($bin_id,$farm_id){
          // check the farm type
          $type = $this->farmTypes($farm_id);
          $total_pigs = 0;

          if($type == 'farrowing'){

            $unique_id = $this->activeGroups('feeds_movement_groups','farrowing');
            if($unique_id != NULL){
              $total_pigs = $this->animalGroupTotalNumberOfPigs($bin_id,$unique_id);
            }

          } elseif ($type == 'nursery') {

            $unique_id = $this->activeGroups('feeds_movement_groups','nursery');
            if($unique_id != NULL){
              $total_pigs = $this->animalGroupTotalNumberOfPigs($bin_id,$unique_id);
            }

          } elseif ($type == 'finisher') {

            $unique_id = $this->activeGroups('feeds_movement_groups','finisher');
            if($unique_id != NULL){
              $total_pigs = $this->animalGroupTotalNumberOfPigs($bin_id,$unique_id);
            }

          } else {
            return $total_pigs;
          }

          return $total_pigs != NULL ? $total_pigs : 0;
      }

      /**
      ** Gets the active groups
      ** string $group_table Primary key
      ** return array
      **/
      private function activeGroups($group_table,$type)
      {

        $active_groups = DB::table($group_table)
                          ->select('unique_id')
                          ->where('type',$type)
                          ->where('status','!=','removed')
                          ->get();
        $active_groups = $this->toArray($active_groups);

        if($active_groups != NULL){
          return $active_groups;
        }

        return $active_groups;
      }

      /**
      **  used to get the farm types
      **	@return string
      **/
      private function farmTypes($farm_id)
      {
          $type = Farms::where('id',$farm_id)->select('farm_type')->first();

          return $type != NULL ? $type->farm_type : NULL;
      }

      /**
      **	animalGroupTotalNumberOfPigsAnimalGroup
      **	get the total number of pigs based on the animal groups bin
      **  @return int
      */
      private function animalGroupTotalNumberOfPigs($bin_id,$unique_id)
      {
        $sum = DB::table('feeds_movement_groups_bins')
                ->where('bin_id',$bin_id)
                ->whereIn('unique_id',$unique_id)
                ->sum('number_of_pigs');

        return $sum;
      }


      /**
      ** remove pigs group
      ** @param $group_id int
      ** @return Response
      **/
      public function removeGroupAPI($group_id,$user_id,$type)
      {

          // remove data on deceased and treatment
          DB::table('feeds_deceased')->where('group_id',$group_id)->delete();
          DB::table('feeds_treatment')->where('group_id',$group_id)->delete();

          $unique_id = DB::table('feeds_movement_groups')->where('group_id',$group_id)->first();
          $animal_bins = DB::table('feeds_movement_groups_bins')->where('unique_id',$unique_id->unique_id)->get();

          if($animal_bins != NULL){
            foreach($animal_bins as $k => $v){
              DB::table('feeds_movement_groups_bins')
              ->where('id',$v->id)
              ->delete();
              //DB::table('feeds_deceased')->where('group_id',$v->id)->delete();
              //DB::table('feeds_treatment')->where('group_id',$v->id)->delete();

              if($type != "farrowing"){
                  $this->removePigsHistory($v->bin_id,$user_id);
              }
            }
          }
          $this->removeTransferData($group_id);

          DB::table('feeds_movement_groups')->where('unique_id',$unique_id->unique_id)->delete();
          DB::table('feeds_movement_groups_bins')->where('unique_id',$unique_id->unique_id)->delete();

          return "deleted";

      }

      /**
       * remove pigs on the bin history
       *
       * @return Response
       */
      public function removePigsHistory($bin_id,$user_id)
      {
          $bin_history = BinsHistory::where('bin_id',$bin_id)->orderBy('history_id','desc')->first();

          if($bin_history != NULL){

            $total_pigs = DB::table('feeds_movement_groups_bins')
                          ->where('bin_id',$bin_id)
                          ->sum('number_of_pigs');
            BinsHistory::where('history_id',$bin_history->history_id)->update(['num_of_pigs'=>$total_pigs,'update_type'=>'Manual Update Number of Pigs, Remove Animal Groups Admin']);
            Cache::forget('bins-'.$bin_id);
            $this->updateBinsHistoryNumberOfPigs($bin_id,0,"remove",$user_id);

            return true;

          }

          $this->updateBinsHistoryNumberOfPigs($bin_id,0,"remove",1);

          return false;
      }

      /**
       * remove pigs on the bin history
       *
       * @return Response
       */
      private function removeTransferData($group_id)
      {

        $transfer = DB::table('feeds_movement_transfer_v2')->where('group_from',$group_id)->first();
        if($transfer != NULL){
          $transfer_bins = DB::table('feeds_movement_transfer_bins_v2')->where('transfer_id',$transfer->transfer_id)->get();
          foreach($transfer_bins as $k => $v){
            DB::table('feeds_movement_transfer_bins_v2')->where('transfer_id',$v->transfer_id)->delete();
          }
        }

        $transfer = DB::table('feeds_movement_transfer_v2')->where('group_to',$group_id)->first();
        if($transfer != NULL){
          $transfer_bins = DB::table('feeds_movement_transfer_bins_v2')->where('transfer_id',$transfer->transfer_id)->get();
          foreach($transfer_bins as $k => $v){
            DB::table('feeds_movement_transfer_bins_v2')->where('transfer_id',$v->transfer_id)->delete();
          }
        }

        DB::table('feeds_movement_transfer_v2')->where('group_from',$group_id)->delete();
        DB::table('feeds_movement_transfer_v2')->where('group_to',$group_id)->delete();
      }


      /*
      *	updateGroupsBinsPigs()
      *
      * update the status of group and group bins number of pigs
      */
      private function updateGroupsBinsPigs($transfer_bins,$unique_id,$transfer_type,$group_from_id,$group_to_id,$poor,$user_id)
      {
        $group_from_unique_id = $unique_id;
        if($transfer_type == 'farrowing_to_nursery'){

          // get the number_of_pigs for the bins in group from
          $number_of_pigs_from = DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_from'])->where('unique_id',$group_from_unique_id)->first();
          //$decreased_pigs = $number_of_pigs_from->number_of_pigs - ($transfer_bins['number_of_pigs_transferred'] + $transfer_bins['dead'] + $poor); // + $transfer_bins['poor'];
          $decreased_pigs = $number_of_pigs_from->number_of_pigs - ($transfer_bins['number_of_pigs_transferred'] + $transfer_bins['dead']);
          $decreased_pigs = $decreased_pigs < 0 ? 0 : $decreased_pigs;

          //update the feeds_movement_groups_bins for decreased transferred pigs
          DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_from'])->where('unique_id',$group_from_unique_id)->update(['number_of_pigs'=>$decreased_pigs]);

          // remove empty pigs group
          $pigs_count = $this->groupPigsCounter('feeds_movement_groups_bins',$group_from_unique_id);

          $group_to = DB::table('feeds_movement_groups')->select('unique_id')->where('group_id',$group_to_id)->first();
          $group_to_unique_id = $group_to->unique_id;

          // get the number_of_pigs for the bins in group to
          $number_of_pigs_to = DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_to'])->where('unique_id',$group_to_unique_id)->first();
          $added_pigs = $number_of_pigs_to->number_of_pigs + $transfer_bins['number_of_pigs_transferred'];
          if($number_of_pigs_to->number_of_pigs == 0){
            $added_pigs = $transfer_bins['number_of_pigs_transferred'];
          }

          //update the feeds_movement_groups_bins for added transferred pigs
          DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_to'])->where('unique_id',$group_to_unique_id)->update(['number_of_pigs'=>$added_pigs]);

          $this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_to'],$added_pigs,"update",$user_id);

          // bins from status updater
          if($pigs_count == 0){
            $this->removeEmptyPigsGroups('feeds_movement_groups','feeds_movement_groups_bins',$group_from_unique_id,$user_id);
            //$this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_from'],$decreased_pigs,"remove");
          } else {
            $this->animalGroupStatusUpdateChecker($group_from_id,'feeds_movement_groups');
            //if($decreased_pigs != 0){
              $this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_from'],$decreased_pigs,"update",$user_id);
            //}
          }


        } else if($transfer_type == 'nursery_to_finisher'){

          // get the number_of_pigs for the bins in group from
          $number_of_pigs_from = DB::table('feeds_movement_groups_bins')->select('number_of_pigs')
                                    ->where('bin_id',$transfer_bins['bin_id_from'])
                                    ->where('unique_id',$group_from_unique_id)->first();
          //$decreased_pigs = $number_of_pigs_from->number_of_pigs - ($transfer_bins['number_of_pigs_transferred'] + $transfer_bins['dead'] + $poor); // + $transfer_bins['poor'];
          $decreased_pigs = $number_of_pigs_from->number_of_pigs - ($transfer_bins['number_of_pigs_transferred'] + $transfer_bins['dead']);
          $decreased_pigs = $decreased_pigs < 0 ? 0 : $decreased_pigs;

          //update the feeds_movement_groups_bins for decreased transferred pigs
          DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_from'])->where('unique_id',$group_from_unique_id)->update(['number_of_pigs'=>$decreased_pigs]);

          // remove empty pigs group
          $pigs_count = $this->groupPigsCounter('feeds_movement_groups_bins',$group_from_unique_id);

          $group_to = DB::table('feeds_movement_groups')->select('unique_id')->where('group_id',$group_to_id)->first();
          $group_to_unique_id = $group_to->unique_id;

          // get the number_of_pigs for the bins in group to
          $number_of_pigs_to = DB::table('feeds_movement_groups_bins')->select('number_of_pigs')->where('bin_id',$transfer_bins['bin_id_to'])->where('unique_id',$group_to_unique_id)->orderBy('id','desc')->first();
          $added_pigs = $number_of_pigs_to->number_of_pigs + $transfer_bins['number_of_pigs_transferred'];
          if($number_of_pigs_to->number_of_pigs == 0){
            $added_pigs = $transfer_bins['number_of_pigs_transferred'];
          }

          //update the feeds_movement_groups_bins for added transferred pigs
          DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_to'])->where('unique_id',$group_to_unique_id)->update(['number_of_pigs'=>$added_pigs]);

          $this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_to'],$added_pigs,"update",$user_id);

          // bins from status updater
          if($pigs_count == 0){
            $this->removeEmptyPigsGroups('feeds_movement_groups','feeds_movement_groups_bins',$group_from_unique_id,$user_id);
            //$this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_from'],$decreased_pigs,"remove");
          } else {
            $this->animalGroupStatusUpdateChecker($group_from_id,'feeds_movement_groups');
            //if($decreased_pigs != 0){
              $this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_from'],$decreased_pigs,"update",$user_id);
            //}
          }

        } else {

          // get the number_of_pigs for the bins in group from
          $number_of_pigs_from = DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_from'])->where('unique_id',$group_from_unique_id)->first();
          $decreased_pigs = $number_of_pigs_from->number_of_pigs - ($transfer_bins['number_of_pigs_transferred'] + $transfer_bins['dead']); // + $transfer_bins['poor'];
          //update the feeds_movement_groups_bins for decreased transferred pigs
          DB::table('feeds_movement_groups_bins')->where('bin_id',$transfer_bins['bin_id_from'])->where('unique_id',$group_from_unique_id)->update(['number_of_pigs'=>$decreased_pigs]);

          $this->updateBinsHistoryNumberOfPigs($transfer_bins['bin_id_from'],$decreased_pigs,"create",$user_id);

          $this->animalGroupStatusUpdateChecker($group_from_id,'feeds_movement_groups');

        }
      }

      /*
      * Pigs counter
      *	Get the sum of pigs from a sepeficif group
      */
      private function groupPigsCounter($group_bins_table,$unique_id)
      {
        $pigs_count = DB::table($group_bins_table)
                        ->select('number_of_pigs')
                        ->where('unique_id',$unique_id)
                        ->sum('number_of_pigs');

        return $pigs_count;
      }

      /*
      *	Delete empty pigs
      * All empty animal group pigs will be deleted after transfer
      */
      private function removeEmptyPigsGroups($group_table,$bins_table,$unique_id,$user_id)
      {
        DB::table($group_table)->where('unique_id',$unique_id)->update(['status'=>'removed']);
        $bins = DB::table($bins_table)->where('unique_id',$unique_id)->get();
        foreach($bins as $k => $v){
          if($v != NULL){
            $this->updateBinsHistoryNumberOfPigs($v->bin_id,0,"remove",$user_id);
          }
        }
      }

      /*
      *	animalGroupStatusUpdateChecker()
      *	check and update the animal group
      */
      private function animalGroupStatusUpdateChecker($group_id,$table)
      {
        $counter = DB::table('feeds_movement_transfer_v2')->where('group_from',$group_id)->where('status','!=','finalized')->count();
        if($counter == 0){
          DB::table($table)->where('group_id',$group_id)->update(['status'=>'entered']);
        }
      }

      /*
      *	updateGroupStatus()
      *
      * update the status of group
      */
      private function updateGroupStatus($group_id,$status,$table)
      {
        DB::table($table)->where('group_id',$group_id)->update('status',$status);
      }

      /*
      *	removeTransfer()
      *
      * update the status of group
      */
      public function removeTransfer($transfer_id,$user_id)
      {

        $bins = DB::table('feeds_movement_transfer_bins_v2')->where('transfer_id',$transfer_id)->get();
        foreach($bins as $k => $v){
          $bin_id_from = $v->bin_id_from;
          $bin_id_to = $v->bin_id_to;
          $pigs_from = $this->getBinsPigs($bin_id_from);
          $pigs_to = $this->getBinsPigs($bin_id_to);
          $this->updateBinsHistoryNumberOfPigs($bin_id_from,$pigs_from,"update",$user_id);
          $this->updateBinsHistoryNumberOfPigs($bin_id_to,$pigs_to,"update",$user_id);
        }
        DB::table('feeds_movement_transfer_v2')->where('transfer_id',$transfer_id)->delete();
        DB::table('feeds_movement_transfer_bins_v2')->where('transfer_id',$transfer_id)->delete();

        return $bins;

      }

      /*
      * Get the bins number of pigs
      */
      private function getBinsPigs($bin_id){

        $pigs = DB::table('feeds_bin_history')
                  ->select('num_of_pigs')
                  ->where('bin_id', '=', $bin_id)
                  ->orderBy('history_id','desc')
                  ->first();

        if($pigs != NULL){
          return $pigs->num_of_pigs;
        } else {
          return 0;
        }

      }
}
