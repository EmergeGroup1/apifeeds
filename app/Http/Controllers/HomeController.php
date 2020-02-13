<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Cache;
use DB;
use Storage;
use Carbon\Carbon;
use App\Medication;
use App\Farms;
use App\FarmSchedule;
use App\BinsHistory;
use App\User;
use App\FeedTypes;
use App\Deliveries;
use App\MobileBinsAcceptedLoad;
use App\SchedTool;

class HomeController extends Controller
{


    /*
    *	Bins forecating Data
    */
    public function binsData($farm_id) {

        Cache::forget('bins-'.$farm_id);

        $bins = DB::table('feeds_bins')
               ->select('feeds_bins.*',
                    'feeds_bin_sizes.name AS bin_size_name',
                    'feeds_feed_types.name AS feed_type_name',
                    'feeds_feed_types.budgeted_amount')
               ->leftJoin('feeds_bin_sizes','feeds_bin_sizes.size_id', '=', 'feeds_bins.bin_size')
               ->leftJoin('feeds_feed_types','feeds_feed_types.type_id', '=', 'feeds_bins.feed_type')
               ->where('farm_id', '=', $farm_id)
               ->orderBy('feeds_bins.bin_number','asc')
               ->get();

        if($bins == NULL){
          return false;
        }


        $bins = json_decode(json_encode($bins),true);

        $binsData = array();

        $binsCount = count($bins) - 1;
        for($i=0; $i<=$binsCount; $i++){

          $current_bin_amount_lbs = $this->currentBinCapacity($bins[$i]['bin_id']);
          $last_update = json_decode(json_encode($this->lastUpdate($bins[$i]['bin_id'])), true);
          $last_update_user = json_decode(json_encode($this->lastUpdateUser($bins[$i]['bin_id'])), true);
          $up_hist[$i] = json_decode(json_encode($this->lastUpdate_numpigs($bins[$i]['bin_id'])), true);
          $numofpigs_ = $this->displayDefaultNumberOfPigs($bins[$i]['num_of_pigs'], $up_hist[$i][0]['num_of_pigs']);
          $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
          $budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'], $up_hist[$i][0]['budgeted_amount']);
          $delivery = $this->nextDel_($farm_id,$bins[$i]['bin_id']);
          $last_delivery = $this->lastDelivery($farm_id,$bins[$i]['bin_id'],$last_update);

          $bins_items = NULL; //Cache::store('file')->get('bins-'.$bins[$i]['bin_id']);
          if($bins_items == NULL){
            // rebuild cache data
            $bins_items = array(
              'bin_s'										=>  $this->getmyBinSize($bins[$i]['bin_size']),
              'bin_id'									=>	$bins[$i]['bin_id'],
              'bin_number'							=>	$bins[$i]['bin_number'],
              'alias'										=>	$bins[$i]['alias'],
              'num_of_pigs'							=>	$bins[$i]['num_of_pigs'],
              'total_number_of_pigs'		=>	$total_number_of_pigs,
              'default_amount'					=>	$this->displayDefaultAmountofBin($bins[$i]['amount'], $up_hist[$i][0]['amount']),
              'hex_color'								=>	$bins[$i]['hex_color'],
              'bin_size'								=>	$bins[$i]['bin_size'],
              'bin_size_name'						=>	$bins[$i]['bin_size_name'],
              'feed_type_name'					=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->description,
              'feed_type_name_orig'			=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->name,
              'feed_type_id'						=>	$up_hist[$i][0]['feed_type'],
              'budgeted_amount'					=>	$budgeted_,
              'current_bin_amount_tons'	=>	$up_hist[$i][0]['amount'],
              'current_bin_amount_lbs'	=>	(int)$current_bin_amount_lbs,
              'days_to_empty'						=>	$this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs),
              'empty_date'							=>	$this->emptyDate($this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs)),
              'next_delivery'						=>	$delivery['name'],
              'medication'							=>	$this->getMedDesc($up_hist[$i][0]['medication']),
              'medication_name'					=>	$this->getMedName($up_hist[$i][0]['medication']),
              'medication_id'						=>	$up_hist[$i][0]['medication'],
              'last_update'							=>	$last_update_user[0]['update_date'],
              'next_deliverydd'					=>  $last_delivery,
              'delivery_amount'					=>  $delivery['amount'],
              //'default_val'							=>  $this->animalGroup($bins[$i]['bin_id'],$bins[$i]['farm_id']),
              'default_val'							=>  $this->animalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']),
              'graph_data'							=>	NULL,//$this->graphData($bins[$i]['bin_id'],$total_number_of_pigs),
              'num_of_update'						=>  NULL,//$this->getNumberOfUpdates($bins[$i]['bin_id']),
              'average_variance'				=>	NULL,//$this->averageVariancelast6days($bins[$i]['bin_id']),
              'average_actual'					=>	NULL,//$this->averageActuallast6days($bins[$i]['bin_id']),
              'username'								=>	$this->usernames($last_update_user[0]['user_id']),
              'last_manual_update'			=>	$this->lastManualUpdate($bins[$i]['bin_id'])
            );
            Cache::forever('bins-'.$bins[$i]['bin_id'],$bins_items);
          }

          $binsData[] = $bins_items;

        }

        $sorted_bins = $binsData;
        usort($sorted_bins, function($a,$b){
          if($a['days_to_empty'] == $b['days_to_empty']) return 0;
          return ($a['days_to_empty']<$b['days_to_empty'])?-1:1;
        });

        $days_to_empty_first = array(
          'first_list_days_to_empty'	=>	!empty($sorted_bins[0]['days_to_empty']) ? $sorted_bins[0]['days_to_empty'] : 0
        );

        $empty_bins = array(
          'empty_bins'	=>	$this->countEmptyBins($binsData)
        );
        for($i=0; $i < count($binsData); $i++){
          $binsDataFinal[] = $empty_bins+$days_to_empty_first+$binsData[$i];
        }


      //} else {
        //$binsDataFinal = $binsDataFinal ;
      //}

      Storage::put('bins_data'.$farm_id.'.txt',json_encode($binsDataFinal));
      $binsDataFinal = Storage::get('bins_data'.$farm_id.'.txt');

      return $binsDataFinal;

    }

    /*
  	*	Current Bin Capacity converted to pounds
  	*/
  	public function currentBinCapacity($bin_id){


  		$data =  DB::table('feeds_bin_history')
  				->select(DB::raw('round(feeds_bin_history.amount * 2000,0) AS amount'))
  				->where('feeds_bin_history.bin_id','=',$bin_id)
  				->orderBy('feeds_bin_history.created_at','desc')
  				->take(1)->get();

  		if($data == NULL){
  			$data = 0;
  		} else {

  			$data = json_decode(json_encode($data), true);

  			foreach($data as $k => $v){
  				$data = $v['amount'];
  			}

  		}

  		return $data;
  	}



    /*
  	*	Get the last updated
  	*/
  	private function lastUpdate($bin_id){

  		$output = BinsHistory::select('update_date','unique_id','user_id')
  					->where('bin_id','=',$bin_id)
  					->where('update_date','<=',date("Y-m-d")." 23:59:59")
  					->orderBy('update_date','DESC')
  					->take(1)->get()->toArray();
  		return $output;

  	}



    /*
  	*	Get the last updated that is not admin
  	*/
  	private function lastUpdateUser($bin_id){

  		$output = BinsHistory::select('user_id','update_date')
  					->where('bin_id','=',$bin_id)
  					->where('update_date','<=',date("Y-m-d")." 23:59:59")
  					//->where('user_id','!=',1)
  					->where('update_type','LIKE','%manual%')
  					->orderBy('update_date','DESC')
  					->take(1)->get()->toArray();
  		return $output;

  	}



    /*
  	*	Get the last updated number of pigs
  	*/
    private function lastUpdate_numpigs($bin_id){

  		$output = BinsHistory::select('num_of_pigs','amount','budgeted_amount','medication', 'feed_type','update_type')
  					->where('bin_id','=',$bin_id)
  					->orderBy('created_at','desc')
  					->take(1)->get()->toArray();

  		if(empty($output)) {

  			$output = array(
  						array(
  							'num_of_pigs' => 0,
  							'amount' => 0,
  							'budgeted_amount' => 0,
  							'feed_type' => 51,
  							'medication' => 7
  						)
  					  );

  		}

  		return $output;

  	}




    /**
  	** Private Method
  	** @Int Value Default, @Int Value from History
  	** Compare two
  	** Return Highest Value
  	**/
  	private function displayDefaultNumberOfPigs($default, $history) {

  		$a = $default;

  		if($history !=0) {

  			$a = $history;

  		}

  		return $a;

  	}




    /*
  	*	totalNumberOfPigsAnimalGroup
  	*	get the total number of pigs based on the animal groups bin
  	*/
  	private function totalNumberOfPigsAnimalGroupAPI($bin_id,$farm_id)
  	{
  		// check the farm type
  		$type = $this->farmTypes($farm_id);
  		$total_pigs = 0;

  		if($type != NULL){

  			$unique_id = $this->activeGroups('feeds_movement_groups');
  			if($unique_id != NULL){
  				$total_pigs = $this->animalGroupsBinsTotalNumberOfPigs($bin_id,$unique_id);
  			}

  		} else {
  			return $total_pigs;
  		}

  		return $total_pigs != NULL ? $total_pigs : 0;

  	}


    /*
  	*	farmTypes()
  	*/
  	private function farmTypes($farm_id)
  	{
  		$type = Farms::where('id',$farm_id)->select('farm_type')->first();

  		return $type != NULL ? $type->farm_type : NULL;
  	}



    /**
  	** Gets the active groups
  	** string $group_table Primary key
  	** return array
  	**/
  	private function activeGroups($group_table)
  	{

  		$active_groups = DB::table($group_table)
  											->select('unique_id')
  											->where('status','!=','removed')
  											->get();
  		$active_groups = $this->toArray($active_groups);

  		if($active_groups != NULL){
  			return $active_groups;
  		}

  		return $active_groups;
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



    /*
  	*	farrowingTotalNumberOfPigsAnimalGroup
  	*	get the total number of pigs based on the animal groups bin
  	*/
  	private function animalGroupsBinsTotalNumberOfPigs($bin_id,$unique_id)
  	{
  		$sum = DB::table('feeds_movement_groups_bins')
  						->where('bin_id',$bin_id)
  						->whereIn('unique_id',$unique_id)
  						->sum('number_of_pigs');

  		return $sum;
  	}



    /*
  	*	getmyBudgetedAmountTwo()
  	*/
  	private function getmyBudgetedAmountTwo($latest_feed_type,$feed_type,$budgeted_amount){

  		if($latest_feed_type == NULL || $latest_feed_type == 0){
  			$feedtype = $feed_type;
  		} else {
  			$feedtype = $latest_feed_type;
  		}

  		return $budgeted_amount;

  		$output = FeedTypes::select('budgeted_amount')
  					->where('type_id','=',$feedtype)
  					->get()->toArray();

  		return !empty($output[0]['budgeted_amount']) ? $output[0]['budgeted_amount'] : 0;

  	}



    /*
  	*	Next delivery simplified
  	*/
  	private function nextDel_($farm_id, $bin_id)
  	{

  		$data = FarmSchedule::where('farm_id', $farm_id)
  							->where('bin_id',$bin_id)
  							->where('date_of_delivery','>',date('Y-m-d'))
  							->where('status',0)
  							->orderBy('date_of_delivery','desc')
  							->take(1)->get()->toArray();

  		$amount_final = FarmSchedule::where('farm_id', $farm_id)
  							->where('bin_id',$bin_id)
  							->where('date_of_delivery','>',date('Y-m-d'))
  							->where('status',0)
  							->orderBy('date_of_delivery','desc')
  							->sum('amount');

  		$amount_deliveries_total = Deliveries::where('bin_id',$bin_id)
  															->where('delivery_date','>',date('Y-m-d'))
  															->where('delivery_label','active')
  															->whereIn('status',[0,1])
  															->orderBy('delivery_date','desc')
  															->sum('amount');

  		$amount_final = $amount_final + $amount_deliveries_total;

  		if(empty($data)){

  			$final = $this->nextDelivery_($farm_id,$bin_id);

  		} else {

  			$output = array();

  			// feeds_id
  			$feed = $this->feedName($data[0]['feeds_type_id']);

  			// med_id
  			$med = $this->medName($data[0]['medication_id']);

  			if($data[0]['feeds_type_id'] != NULL){
  				$output = $feed['name'] . ", " . $med['med_name'] .", ". date('m-d-Y',strtotime($data[0]['date_of_delivery']));
  			}


  			// the amount is base on last update, because this delivery is not delivered yet
  			$amount = BinsHistory::where('farm_id',$farm_id)
  								->where('bin_id',$bin_id)
  								->orderBy('history_id','desc')
  								->orderBy('update_date','desc')
  								->take(1)->get()->toArray();

  			//$final = array('name'=> $output, 'amount' => $data != NULL ? $data[0]['amount'] . " T" : $amount[0]['amount'] . " T");
  			$final = array('name'=> $output, 'amount' => $data != NULL ? $amount_final . " T" : $amount[0]['amount'] . " T");

  		}

  		return $final;

  	}



    /*
  	*	Next Delivery
  	*/
  	public function nextDelivery_($farm_id,$bin_id){

    		$data = Deliveries::where('farm_id','=',$farm_id)
    				->where('bin_id','=',$bin_id)
    				->where('delivered','=', 0)
    				->where('delivery_label','active')
    				->where('delivery_date','>',date('Y-m-d'))
    				->orderBy('delivery_date','desc')
    				->first();

    		$datas = DB::table('feeds_deliveries')
    				->selectRaw('sum(amount) as sum')
    				->where('delivered','=', 0)
    				->where('bin_id', $bin_id)
    				->where('farm_id', $farm_id)
    				->where('delivery_label','active')
    				->where('delivery_date','>',date('Y-m-d'))
    				->groupBy('unique_id')
    				->orderBy('delivery_date','desc')->get();


    		if(count($datas) == 0) {

    			$datas = array(
    						array(
    							'sum' => 0
    						)
    					);

    		}

    		$data2 = json_decode(json_encode($datas), true);

    		// feeds_id
    		$feed = $this->feedName($data['feeds_type_id']);

    		// med_id
    		$med = $this->medName($data['medication_id']);

    		if($feed != "-"){
    			$output = $feed['name'] . ", " . $med['med_name'] .", ". date('m-d-Y',strtotime($data['delivery_date']));
    			$amount = !empty($data2[0]['sum']) ? $data2[0]['sum'] . " T" : 0;
    			$deliv = date('M d, A', strtotime($data['delivery_date']));
    		} else {
    			$output = 'No delivery yet';
    			$amount =  '-';
    			$deliv = '-';
    		}

    		$amount_deliveries_total = Deliveries::where('bin_id',$bin_id)
    															->where('delivery_date','>',date('Y-m-d'))
    															->whereIn('status',[0,1])
    															->where('delivery_label','active')
    															->orderBy('delivery_date','desc')
    															->sum('amount');
    		$amount_deliveries_total = $amount_deliveries_total != 0 ? $amount_deliveries_total ." T" : "-";

    		return $d = array('name'=> $output, 'date' => $deliv, 'amount' => $amount_deliveries_total);

  	}

  	/*
  	*	Feed Name
  	*/
  	public function feedName($feedId){

        $data = FeedTypes::where('type_id','=',$feedId)
    				->select('*')
    				->first();
    		return !empty($data) ? $data : "-";

  	}

  	/*
  	*	Medicine Name
  	*/
  	public function medName($medId){

        $data = Medication::where('med_id','=',$medId)
    				->select('*')
    				->first();

    		return $data;

  	}



    /*
  	*	get the last delivery
  	*/
    public function lastDelivery($farm_id,$bin_id,$unique_id){

    		$data = Deliveries::where('farm_id','=',$farm_id)
    				->select('delivery_date')
    				->where('bin_id','=',$bin_id)
    				->where('delivery_date','<=', date('y-m-d') . " 23:59:59")
    				->where('status','=',2)
    				->where('delivery_label','!=','deleted')
    				->orderBy('delivery_date','desc')
    				->first();

    		if($data == NULL){
    			if($unique_id != NULL){
    				$unique_id = $unique_id[0]['unique_id'] == 'none' ? 'empty' : $unique_id[0]['unique_id'];
    				$additional_bin = MobileBinsAcceptedLoad::where('unique_id',$unique_id)
    									->get()->toArray();
    				if($additional_bin != NULL){
    					$output = date("M d",strtotime($additional_bin[0]['created_at']));
    				}else{
    					$output = "-";
    				}
    			} else {
    				$output = "-";
    			}
    		}else{
    			$output = date("M d",strtotime($data['delivery_date']));
    		}

    		return $output;
  	}


    /*
  	*	get the bin sizes
  	*/
    public function getmyBinSize($bin_s_id) {

    		$output = DB::table('feeds_bin_sizes')
    					->select('ring','type')
    					->where('size_id','=',$bin_s_id)
    					->get();

    		if($output == NULL) {

    			$output = array(
    						array(
    							'ring' => '0',
    							'type' => '0'
    						)
    					  );

    		}

    		$output = json_decode(json_encode($output));

    		switch($output[0]->type){

    			case 0:
    				$return  = $this->binSizesS($output[0]->ring);
    			break;

    			case 1:
    				$return  = $this->binSizesL($output[0]->ring);
    			break;

    			case 2:
    				$return  = $this->binSizeSixFootRing($output[0]->ring);
    			break;

    			case 3:
    				$return  = $this->binSizeSevenFootRing($output[0]->ring);
    			break;

    			case 4:
    				$return  = $this->binSizeNineFootRing($output[0]->ring);
    			break;

    			case 5:
    				$return  = $this->binSizesCustom();
    			break;

    		}

    		return $return;

  	}



    /*
  	*	Bin Sizes
  	*/
  	private function binSizesS($ring){

    		$data = array(
    			'0' 	=> 	'Empty 			-	 0 Ton',
    			'0.5' 	=> 	'1/4 Cone  		-	 0.5 Ton',
    			'0.75'		=>	'1/2 Cone		-	 0.75 Ton',
    			'1.25' 	=> 	'3/4 Cone		-	 1.25 Ton',
    			'1.5'  	=>	'Full Cone		-	 1.5 Tons',
    			'2.0'	=>	'1/4 Ring		-	 2 Tons',
    			'2.5'		=>	'1/2 Ring		-	 2.5 Tons',
    			'2.75'	=>	'3/4 Ring		-	 2.75 Tons',
    			'3.0'		=>	'1 Ring			-	 3 Tons',
    			'3.5'	=>	'1 1/4 Ring		-	 3.5 Tons',
    			'4.0'		=>	'1 1/2 Ring		-	 4 Tons',
    			'4.25'	=>	'1 3/4 Ring		-	 4.25 Tons',
    			'4.5'		=>	'2 Rings 		-	4.5 Tons',
    			'5.0'	=>	'2 1/4 Rings 	-	5 Tons',
    			'5.25'		=>	'2 1/2 Rings 	-	5.25 Tons',
    			'5.5'	=>	'2 3/4 Rings 	-	5.5 Tons',
    			'5.75'		=>	'3 Rings 		-	5.75 Tons'
    		);

    		return array_splice($data,0,(($ring*4)+5));

  	}

  	/*
  	*	Bin Sizes
  	*/
  	private function binSizesL($ring){

    		$data = array(
    			'0' 	=> 	'Empty 			-	 0 Ton',
    			'1.0' 	=> 	'1/4 Cone  		-	 1 Ton',
    			'2.0'	=>	'1/2 Cone		-	 2 Ton',
    			'2.75' 	=> 	'3/4 Cone		-	 2.75 Ton',
    			'3.75'  	=>	'Full Cone		-	 3.75 Tons',
    			'4.5'		=>	'1/4 Ring		-	 4.5 Tons',
    			'5.25'		=>	'1/2 Ring		-	 5.25 Tons',
    			'6.0'		=>	'3/4 Ring		-	 6 Tons',
    			'6.75'	=>	'1 Ring			-	 6.75 Tons',
    			'7.5'	    =>	'1 1/4 Ring		-	 7.5 Tons',
    			'8.25'		=>	'1 1/2 Ring		-	 8.25 Tons',
    			'9.0'	    =>	'1 3/4 Ring		-	 9 Tons',
    			'9.75'	=>	'2 Rings 		-	9.75 Tons',
    			'10.5'	=>	'2 1/4 Rings 	-	10.5 Tons',
    			'11.75'	=>	'2 1/2 Rings 	-	11.75 Tons',
    			'12.5'	=>	'2 3/4 Rings 	-	12.5 Tons',
    			'13.25'	=>	'3 Rings 		-	13.25 Tons',
    			'14.0'	=>	'3 1/4 Rings 	-	14 Tons',
    			'14.75'	=>	'3 1/2 Rings 	-	14.75 Tons',
    			'15.5'	=>	'3 3/4 Rings 	-	15.5 Tons',
    			'16.25'	=>	'4 Rings 		-	16.25 Tons'
    		);

    		return array_splice($data,0 ,(($ring*4)+5));

  	}

  	/*
  	*	6 Foot Ring Bin Sizes
  	*/
  	private function binSizeSixFootRing($ring){

    		$data = array(
    			'0'		=>	'Empty',
    			'0.5'	=>	'1/4 Cone - 0.5 Ton',
    			'0.75'	=>	'1/2 Cone - 0.75 Ton',
    			'1.0'		=>	'3/4 Cone - 1 Ton',
    			'1.5'	=>	'Full Cone - 1.5 Tons',
    			'2.0'		=>	'1/4 Ring - 2 Tons',
    			'2.5'	=>	'1/2 Ring - 2.5 Tons',
    			'2.75'	=>	'3/4 Ring - 2.75 Tons',
    			'3.0'		=>	'1 Ring - 3 Tons',
    			'3.5'	=>	'1 1/4 Ring - 3.5 Tons',
    			'4.0'		=>	'1 1/2 Ring - 4 Tons',
    			'4.25'	=>	'1 3/4 Ring - 4.25 Tons',
    			'4.5'	=>	'2 Ring - 4.5 Tons',
    			'5.0'		=>	'2 1/4 Ring - 5 Tons',
    			'5.5'	=>	'2 1/2 Ring - 5.5 Tons',
    			'5.75'	=>	'2 3/4 Ring - 5.75 Tons',
    			'6.0'		=>	'3 Ring - 6 Tons',
    			'6.5'	=>	'3 1/4 Ring - 6.5 Tons',
    			'7.0'		=>	'3 1/2 Ring - 7 Tons',
    			'7.25'	=>	'3 3/4 Ring - 7.25 Tons',
    			'7.5'	=>	'4 Ring - 7.5 Tons'
    		);

    		return array_splice($data,0 ,(($ring*4)+5));

  	}

  	/*
  	*	7 Foot Ring Bin Sizes
  	*/
  	private function binSizeSevenFootRing($ring){

    		$data = array(
    			'0'		=>	'Empty',
    			'0.75'	=>	'1/4 Cone - 0.75 Ton',
    			'1.75'	=>	'1/2 Cone - 1.75 Tons',
    			'2.5'	=>	'3/4 Cone - 2.5 Tons',
    			'3.0'		=>	'Full Cone - 3 Tons',
    			'3.5'	=>	'1/4 Ring - 3.5 Tons',
    			'4.0'		=>	'1/2 Ring - 4 Tons',
    			'4.5'	=>	'3/4 Ring - 4.5 Tons',
    			'5.0'		=>	'1 Ring - 5 Tons',
    			'5.5'	=>	'1 1/4 Ring - 5.5 Tons',
    			'6.0'		=>	'1 1/2 Ring - 6 Tons',
    			'6.5'	=>	'1 3/4 Ring - 6.5 Tons',
    			'7.0'		=>	'2 Ring - 7 Tons',
    			'7.5'	=>	'2 1/4 Ring - 7.5 Tons',
    			'8.0'		=>	'2 1/2 Ring - 8 Tons',
    			'8.5'	=>	'2 3/4 Ring - 8.5 Tons',
    			'9.0'		=>	'3 Ring - 9 Tons',
    			'9.5'	=>	'3 1/4 Ring - 9.5 Tons',
    			'10.0'	=>	'3 1/2 Ring - 10 Tons',
    			'10.5'	=>	'3 3/4 Ring - 10.5 Tons',
    			'11.0'	=>	'4 Ring - 11 Tons'
    		);

    		return array_splice($data,0 ,(($ring*4)+5));

  	}

  	/*
  	*	9 Foot Ring Bin Sizes
  	*/
  	private function binSizeNineFootRing($ring){

    		$data = array(
    			'0'			=>	'Empty',
    			'1.0'		=>	'1/4 Cone - 1 Ton',
    			'2.0'		=>	'1/2 Cone - 2 Tons',
    			'3.0'		=>	'3/4 Cone - 3 Ton',
    			'3.75'		=>	'Full Cone - 3.75 Tons',
    			'4.5'		=>	'1/4 Ring - 4.5 Tons',
    			'5.25'		=>	'1/2 Ring - 5.25 Tons',
    			'6.0'		=>	'3/4 Ring - 6 Tons',
    			'6.5'		=>	'1 Ring - 6.5 Tons',
    			'7.25'		=>	'1 1/4 Ring - 7.25 Tons',
    			'8.0'		=>	'1 1/2 Ring - 8 Tons',
    			'8.75'		=>	'1 3/4 Ring - 8.75 Tons',
    			'9.25'		=>	'2 Ring - 9.25 Tons',
    			'10.0'		=>	'2 1/4 Ring - 10 Tons',
    			'10.75'		=>	'2 1/2 Ring - 10.75 Tons',
    			'11.5'		=>	'2 3/4 Ring - 11.5 Tons',
    			'12.0'		=>	'3 Ring - 12 Tons',
    			'12.75'		=>	'3 1/4 Ring - 12.75 Tons',
    			'13.5'		=>	'3 1/2 Ring - 13.5 Tons',
    			'14.25'		=>	'3 3/4 Ring - 14.25 Tons',
    			'14.75'		=>	'4 Ring - 14.75 Tons'
    		);

    		return array_splice($data,0 ,(($ring*4)+5));

  	}


  	/*
  	*	New Custom Bin Size
  	*	Created 2016-07-04
  	*/
  	private function binSizesCustom(){

    		$data = array(
    			'0' 		=> 	'Empty 			-	 0 Ton',
    			'1.0' 		=> 	'Cone  			-	 1 Ton',
    			'2.25'		=>	'1 Rings		-	 2.25 Tons',
    			'3.50' 		=> 	'2 Rings		-	 3.50 Tons',
    			'4.75'  	=>	'3 Rings		-	 4.75 Tons',
    			'6.00'		=>	'4 Rings		-	 6.00 Tons'
    		);

    		return $data;

  	}



    /**
  	** Private Method
  	** @Int Value Default, @Int Value from History
  	** Compare two
  	** Return Highest Value
  	**/
  	private function displayDefaultAmountofBin($default, $history) {
  		// fetch the scheduled amount
  		$a = 0;

  		if($history != 0) {
  			$a = $history;
  		}

  		return $a;

  	}


    /*
    *   Get the feed type update
    */
    public function getFeedTypeUpdate($feedupd, $feeddef) {

  		$feedTypes = FeedTypes::select('type_id')->where('type_id','=',$feeddef)->get()->toArray();
  		$feedTypes2 = FeedTypes::select('type_id')->where('type_id','=',$feedupd)->get()->toArray();
  		if(!empty($feedTypes2[0]['type_id'])){
  			$o = $feedTypes2[0]['type_id'];

  			if($feedupd != 51) {

  				$o = $feedupd;

  			}
  		} else{
  			$o = 51;
  		}
  		return $o;

  	}


    /*
  	*	daysOfBins()
  	*	Current Bin Amount / budgeted amount
  	*/
  	public function daysOfBins($currentBinAmount,$budgetedAmount,$numOfPigs){

  		$budgetedAmount = str_replace(' lbs pig per day','',$budgetedAmount);

  		$currentBinAmount = (int)$currentBinAmount;
  		if($currentBinAmount != NULL && $budgetedAmount != NULL){
  			$result_one = (int)(((float)$budgetedAmount)*$numOfPigs);
  			$daysOfBins = @($currentBinAmount/$result_one);
  			$daysOfBins = (int)round($daysOfBins,0);
  		} else {
  			$daysOfBins = 0;
  		}

  		return $daysOfBins;
  	}


    /*
  	*	Empty date
  	*/
  	public function emptyDate($days){

  		$emptyDate = Carbon::now()->addDays($days)->format('m-d-Y');
  		$soon = Carbon::now()->addDays($days)->format('M d');

  		if($emptyDate == Carbon::now()->format('m-d-Y')){
  			$output = "Empty";
  		} else {
  			$output = $soon;
  		}

  		return $output;
  	}


    /*
  	*	Get the medication description
  	*/
    public function getMedDesc($medid) {

    		$output = DB::table('feeds_medication')
    					->select('med_description')
    					->where('med_id','=',$medid)
    					->first();

        if($output == NULL) {

    			$output = 'No Medication';

          return $output;

    		}

        $output = $output->med_description;

    		return $output;

  	}


    /*
  	*	Get the medication name
  	*/
  	public function getMedName($medid) {

  		$output = DB::table('feeds_medication')
  					->select('med_name')
  					->where('med_id','=',$medid)
  					->first();

      if($output == NULL) {

        $output = 'No Medication';

        return $output;

      }

      $output = $output->med_name;

      return $output;

  	}


    /*
  	*	animalGroup()
  	*/
  	private function animalGroupAPI($bin_id,$farm_id)
  	{
  		// check the farm type
  		$type = $this->farmTypes($farm_id);

  		if($type != NULL){

  			$output = $this->animalGroupBinsAPI($bin_id);

  		} else {

  			$output = NULL;

  		}

  		return $output;
  	}



    /*
  	*	farrowingBins()
  	*/
  	private function animalGroupBinsAPI($bin_id)
  	{
    		$farrow_bins = DB::table('feeds_movement_groups_bins')->where('bin_id',$bin_id)->get();
    		$total_pigs_per_bins = DB::table('feeds_movement_groups_bins')->where('bin_id',$bin_id)->sum('number_of_pigs');
    		$farrow_bins = $this->toArray($farrow_bins);

    		if($farrow_bins == NULL){
    			return NULL;
    		}

    		$data = array();
    		foreach($farrow_bins as $k => $v){
    			$farrowing_groups = $this->animalGroupsAPI($v['unique_id']);
    			if($farrowing_groups['group_name'] != NULL){
    				$data[] = array(
    					'type'					=>	'farrowing',
    					'group_name'		=>	$farrowing_groups['group_name'],
    					'group_id'			=>	$farrowing_groups['group_id'],
    					'farm_id'			=>	$farrowing_groups['farm_id'],
    					'number_of_pigs'	=>	$total_pigs_per_bins,//$v['number_of_pigs'],
    					'pigs_per_group'	=> $v['number_of_pigs'],
    					'bin_id'			=>	$v['bin_id'],
    					'unique_id'			=>	$v['unique_id']
    				);
    			}
    		}

    		if(count($data) == 1){
    			if($data[0]['group_name'] == NULL){
    				return NULL;
    			}
    		}

    		if($data == NULL){
    			return NULL;
    		}

    		return $data;

  	}

  	/*
  	*	animalGroupsFarrowing()
  	*	get the group info of the farrowing groups
  	*/
  	private function animalGroupsAPI($unique_id)
  	{
    		$farrowing = DB::table('feeds_movement_groups')->where('status','!=','removed')->where('unique_id',$unique_id)->get();
    		$farrowing = $this->toArray($farrowing);

    		return $farrowing != NULL ? $farrowing[0] : NULL;
  	}



    /*
  	*	Get the usernames
  	*/
  	private function usernames($user_id)
  	{
    		$user = User::where('id',$user_id)->first();

    		$output = $user != NULL ? $user->username : "System Auto Update";

    		return $output;
  	}


    /*
  	*	lastManualUpdate()
  	*	Get the bins last manual update record from the bins history
  	* $bin_id (int)
  	*/
  	private function lastManualUpdate($bin_id)
  	{

    		$output = "none";
    		$last_update = BinsHistory::where('bin_id',$bin_id)
    												->where('update_type','!=','Automatic Update Admin')
    												->select('update_date')
    												->orderBy('update_date','desc')
    												->first();
    		if($last_update != NULL){
    			$output = date("Y-m-d",strtotime($last_update->update_date));
    		}

    		return $output;

  	}



    /*
  	*	empty bins counter
  	*/
  	private function countEmptyBins($bins){

    		$counter = 0;

    		for($i=0; $i < count($bins); $i++){
    			if($bins[$i]['days_to_empty'] == 0 || $bins[$i]['days_to_empty'] == 1 || $bins[$i]['days_to_empty'] == 2){
    				$counter++;
    			}
    		}

    		return $counter;

  	}


    /*
  	*	deliveries
  	*/
  	public function deliveriesListAPI($data){

    		$deliveries = $this->defaultDeliveriesAPI($data);

    		return $deliveries;

  	}

  	/*
  	*	get deliveries information
  	*/
  	private function defaultDeliveriesAPI($data)
  	{

    		$data['delivery_number'] = str_replace("#","",$data['delivery_number']);


    		if($data['farm_id'] != "0" && $data['farm_id'] != 0 && $data['farm_id'] != NULL){
    			$deliveries = $this->searchFarmDeliveriesAPI($data);
    		} elseif($data['driver'] != "0" && $data['driver'] != 0  && $data['driver'] != NULL){
    			$deliveries = $this->searchDriverDeliveriesAPI($data);
    		} elseif($data['delivery_number'] != "0" && $data['delivery_number'] != 0 && $data['delivery_number'] != NULL){
    			$deliveries = $this->searchUniqueIdDeliveriesAPI($data['delivery_number']);
    		}  else {
    			$deliveries = DB::table('feeds_deliveries')
    										->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id',
    										'feeds_deliveries.status','feeds_deliveries.delivery_id','feeds_deliveries.delivery_date','feeds_deliveries.truck_id','feeds_deliveries.driver_id',
    										DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
    										'feeds_truck.name AS truck_name',
    										'feeds_user_accounts.username AS driver')
    										->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
    										->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
    										->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
    										->where('feeds_deliveries.delivery_label','!=','deleted')
    										->whereBetween('feeds_deliveries.delivery_date', array($data['from'] . " 00:00:00", $data['to'] . " 23:59:59"))
    										->groupBy('feeds_deliveries.unique_id')
    										->orderBy('feeds_deliveries.delivery_date','DESC')
    										->orderBy('feeds_deliveries.delivery_id','DESC')
    										->get();
    		}

    		return $this->defaultDeliveriesBuilderAPI($deliveries);

  	}


    /*
  	*	search by farm
  	*/
  	private function searchFarmDeliveriesAPI($data)
  	{

    		$deliveries = DB::table('feeds_deliveries')
    							->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id',
    							'feeds_deliveries.status','feeds_deliveries.delivery_date','feeds_deliveries.truck_id','feeds_deliveries.driver_id',
    							DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
    							'feeds_truck.name AS truck_name',
    							'feeds_user_accounts.username AS driver')
    							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
    							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
    							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
    							->where('feeds_deliveries.delivery_label','!=','deleted')
    							->Where('feeds_deliveries.farm_id',$data['farm_id'])
    							->whereBetween('feeds_deliveries.delivery_date', array($data['from'] . " 00:00:00", $data['to'] . " 23:59:59"))
    							->groupBy('feeds_deliveries.unique_id')
    							->orderBy('feeds_deliveries.delivery_date','DESC')
    							->orderBy('feeds_deliveries.delivery_id','DESC')
    							->take(100)
    							->get();

    		return $deliveries;

  	}



    /*
  	*	search by driver
  	*/
  	private function searchDriverDeliveriesAPI($data)
  	{

    		$deliveries = DB::table('feeds_deliveries')
    							->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id',
    							'feeds_deliveries.status','feeds_deliveries.delivery_date','feeds_deliveries.truck_id','feeds_deliveries.driver_id',
    							DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
    							'feeds_truck.name AS truck_name',
    							'feeds_user_accounts.username AS driver')
    							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
    							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
    							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
    							->where('feeds_deliveries.delivery_label','!=','deleted')
    							->Where('feeds_deliveries.driver_id',$data['driver'])
    							->whereBetween('feeds_deliveries.delivery_date', array($data['from'] . " 00:00:00", $data['to'] . " 23:59:59"))
    							->groupBy('feeds_deliveries.unique_id')
    							->orderBy('feeds_deliveries.delivery_date','DESC')
    							->orderBy('feeds_deliveries.delivery_id','DESC')
    							->take(100)
    							->get();

    		return $deliveries;

  	}



    /*
  	*	search by unique_id
  	*/
  	private function searchUniqueIdDeliveriesAPI($unique_id)
  	{

    		$deliveries = DB::table('feeds_deliveries')
    							->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id',
    							'feeds_deliveries.status','feeds_deliveries.delivery_date','feeds_deliveries.truck_id','feeds_deliveries.driver_id',
    							DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
    							'feeds_truck.name AS truck_name',
    							'feeds_user_accounts.username AS driver')
    							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
    							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
    							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
    							->where('feeds_deliveries.delivery_label','!=','deleted')
    							->Where(DB::raw('LEFT(feeds_deliveries.unique_id,7)'),$unique_id)
    							->groupBy('feeds_deliveries.unique_id')
    							->orderBy('feeds_deliveries.delivery_date','DESC')
    							->orderBy('feeds_deliveries.delivery_id','DESC')
    							->take(10)
    							->get();

    		return $deliveries;

  	}



    /*
  	*	get deliveries information
  	*/
  	private function defaultDeliveriesBuilderAPI($deliveries)
  	{

    		$data = array();
    		for($i=0;$i<count($deliveries);$i++){
    			$data[] = array(
    				'unique_id' => $deliveries[$i]->unique_id,
    				'delivery_number'	=>	$deliveries[$i]->delivery_number,
    				'status'		=>	 $this->deliveriesStatusAPI($deliveries[$i]->unique_id),
    				'delivery_date'	=>	$deliveries[$i]->delivery_date,
    				'farm_names'	=>	$deliveries[$i]->farm_names,
    				'truck_name'	=>	$deliveries[$i]->truck_name,
    				'driver'	=>	$deliveries[$i]->driver,
    				'load_info'	=>	'',//$this->loadInformationAPI($deliveries[$i]->delivery_date,$deliveries[$i]->truck_id,$deliveries[$i]->driver_id),
    				'load_breakdown'	=> '',//$this->loadBreakdownAPI($deliveries[$i]->unique_id)
    			);
    		}

    		return $data;

  	}



    /*
  	*	deliveries status counter
  	*/
  	public function deliveriesStatusAPI($unique_id){

    		$status = "";

    		$loads  = Deliveries::where('unique_id','=',$unique_id)->count();
    		$on_going = Deliveries::where('unique_id','=',$unique_id)->where('status','=',1)->count();
    		$on_going_red = Deliveries::where('unique_id','=',$unique_id)->where('status','=',2)->count();
    		$delivered = Deliveries::where('unique_id','=',$unique_id)->where('status','=',3)->count();
    		$pending = Deliveries::where('unique_id','=',$unique_id)->where('status','=',0)->count();

    		if($delivered == $loads){
    			$status = "completed";
    		}elseif($on_going == $loads){
    			$status = "ongoing_green";
    		}elseif($on_going_red == $loads){
    			$status = "ongoing_red";
    		}elseif($pending == $loads){
    			$status = "pending";
    		}elseif($on_going_red == 1){
    			$status = "ongoing_red";
    		}else{
    			$status = "ongoing_green";
    		}

    		return $status;

  	}


    /**
     * driver
     *
     * @return Response
     */
    public function driver()
    {

    		$drivers_cache = Cache::store('file')->get('drivers');

    		if($drivers_cache == NULL){

          $drivers = array_merge(
              [''=>'-'],
              User::where('type_id','=',2)
        					->orderBy('username')
                  ->pluck('username','id')->toArray()
          );

    			Cache::forever('drivers',$drivers);

    			$drivers_cache = Cache::store('file')->get('drivers');
    		}

    		return $drivers_cache;

  	}


    /*
  	*	Medication
  	*/
  	public function medication()
    {

    		$medication_cache = Cache::store('file')->get('medications');

    		if($medication_cache == NULL){

          $medication = array_merge(
              [''=>'Please Select'],
              DB::table('feeds_medication')
      						->where('med_name','!=','No Medication')
      						->orderBy('med_name')
                  ->pluck('med_name','med_id')->toArray()
          );

    			Cache::forever('medications',$medication);
    			$medication_cache = Cache::store('file')->get('medications');

    		}

    		return $medication_cache;

  	}



    /*
  	*	Feed Types
  	*/
  	public function feedTypesAPI()
    {

        $feeds = array_merge(
            [''=>'Please Select'],
            DB::table('feeds_feed_types')
      					->where('name','!=','None')
      					->orderBy('name')
                ->pluck('description','type_id')->toArray()
        );

  			return $feeds;

  	}



    /*
  	*	Update Bin
  	*	update the current bin based on yesterday or today's update on forecasting
  	*/
  	public function updateBinAPI() {

      		$msg = "OK";
      		$yesterday = 0;

      		// update today
      		$lastupdate = $this->todayBinUpdate($_POST['bin']);
      		$amount = $lastupdate[0]['amount'] - $_POST['amount'];
      		if($lastupdate[0]['amount'] < $_POST['amount']){
      				$amount = str_replace("-","",$amount);
      		} else {
      				$amount = "-".$amount;
      		}

      		// update yesterdays
      		if(empty($lastupdate)){
      			$lastupdate = $this->yesterdayBinUpdate($_POST['bin']);
      			$yesterday = 1;
      		}

      		$budgeted_amount_tons = 0;

      		if($_POST['amount'] > $lastupdate[0]['amount']){
      			$variance = $lastupdate[0]['variance'];
      			$actual_consumption_per_pig = $lastupdate[0]['consumption'];
      			$budgeted_amount_tons = $lastupdate[0]['budgeted_amount_tons'];
      		} else {
      			$new_amount = round(($lastupdate[0]['amount'] - $_POST['amount'])*2000,2);
      			if($lastupdate[0]['num_of_pigs'] == 0){
      					$actual_consumption_per_pig = $new_amount;
      			} else {
      					$actual_consumption_per_pig = $new_amount / $lastupdate[0]['num_of_pigs'];
      			}
      			$variance = round($actual_consumption_per_pig - $lastupdate[0]['budgeted_amount'],2);
      			$update_type = $lastupdate[0]['update_type'];
      			if($update_type == 'Manual Update Bin Forecasting Admin' || $update_type == 'Manual Update Mobile Farmer' || $update_type == 'Delivery Manual Update Admin'){
      				$budgeted_amount_tons = $lastupdate[0]['budgeted_amount_tons'];
      			} else {
      				$budgeted_amount_tons = $lastupdate[0]['amount'];
      			}
      		}

      		$budgeted_amount_tons = $budgeted_amount_tons*2000 - ($lastupdate[0]['budgeted_amount'] * $lastupdate[0]['num_of_pigs']);
      		$budgeted_amount_tons = $budgeted_amount_tons/2000;

      		$budgeted_amount = $this->daysCounterbudgetedAmount($lastupdate[0]['farm_id'],$_POST['bin'],$lastupdate[0]['feed_type'],date("Y-m-d H:i:s"));

      		$currentAmount = $this->currentBinCapacity($_POST['bin']);

      		//feeds
      		$feeds = FeedTypes::where('type_id','=',$lastupdate[0]['feed_type'])->get()->toArray();

      		// data to insert
      		$bin_history_data = array(
      				'update_date' 					=> 	date("Y-m-d H:i:s"),
      				'bin_id' 								=> 	$_POST['bin'],
      				'farm_id' 							=> 	$lastupdate[0]['farm_id'],
      				'num_of_pigs' 					=> 	$lastupdate[0]['num_of_pigs'],
      				'user_id' 							=> 	$_POST['user'],
      				'amount' 								=> 	$_POST['amount'],
      				'update_type' 					=> 	'Manual Update Bin Forecasting Admin',
      				'created_at' 						=> 	date("Y-m-d H:i:s"),
      				'budgeted_amount' 			=> 	$budgeted_amount,
      				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
      				'actual_amount_tons'		=>	$_POST['amount'],
      				'remaining_amount' 			=> 	$lastupdate[0]['remaining_amount'],
      				'sub_amount' 						=> 	$lastupdate[0]['sub_amount'],
      				'variance' 							=> 	$variance,
      				'consumption' 					=> 	$actual_consumption_per_pig,
      				'admin' 								=> 	$_POST['user'],
      				'feed_type'							=>	!empty($lastupdate[0]['feed_type']) ? $lastupdate[0]['feed_type'] : 51,
      				'unique_id'							=>	!empty($lastupdate[0]['unique_id']) ? $lastupdate[0]['unique_id'] : 'none'
      		);

      		if($yesterday == 0){
      			BinsHistory::where('history_id','=',$lastupdate[0]['history_id'])->update($bin_history_data);
      		}else{
      			BinsHistory::insert($bin_history_data);
      		}


      		if($_POST['amount'] > $lastupdate[0]['amount']){
      			$avg_variance = 0;
      			$avg_actual = 0;
      		}else{
      			//calculate average variance and actual consumption based on last 6 days
      			$avg_variance = round(($this->averageVariancelast6days($_POST['bin'])/$this->getNumberOfUpdates($_POST['bin'])),2);
      			$avg_actual = round(($this->averageActuallast6days($_POST['bin'])/$this->getNumberOfUpdates($_POST['bin'])),2);
      		}

      		//bins
      		$bins = Bins::where('bin_id','=',$_POST['bin'])->get()->toArray();
      		//bin Size
      		$bin_size = BinSize::where('size_id','=',$bins[0]['bin_size'])->get()->toArray();
      		//medication
      		$medication = Medication::where('med_id','=',$lastupdate[0]['medication'])->get()->toArray();


      		$numofpigs_ = $lastupdate[0]['num_of_pigs'] != NULL ? $lastupdate[0]['num_of_pigs'] : $bins[0]['num_of_pigs'];
      		if(!empty($lastupdate)){
      			$budgeted_ = $lastupdate[0]['budgeted_amount'] != NULL ? $lastupdate[0]['budgeted_amount'] : $feeds[0]['budgeted_amount'];
      		} else {
      			$budgeted_ = 0;
      		}

      		if($budgeted_ != 0.0){
      			if($numofpigs_ != 0){
      				$daysto = round($_POST['amount'] * 2000 / ($numofpigs_ * $budgeted_),0);
      			} else {
      				$daysto = 0;
      			}
      		} else {
      			$daysto = 0;
      		}

      		// // send mobile notification
      		// $mobile_data = array(
      		// 	'bin_id'					=>	!empty($bins[0]['bin_number']) ? $bins[0]['bin_number'] : 0,  //bin number
      		// 	'farm_id'					=>	$bin_history_data['farm_id'],
      		// 	'user_id'					=>	$_POST['user'],
      		// 	'current_amount'	=>	$bin_history_data['amount'],
      		// 	'created_at'			=>	date('Y-m-d H:i:s'),
      		// 	'budgeted_amount'	=>	$budgeted_amount,//$bin_history_data['budgeted_amount'],
      		// 	'actual_amount'		=>	$bin_history_data['amount'],
      		// 	'bin_size'				=>	$bin_size[0]['ring'],
      		// 	'variance'				=>	$variance,
      		// 	'consumption'			=>	$actual_consumption_per_pig,
      		// 	'feed_type'				=>	$bin_history_data['feed_type'],
      		// 	'medication'			=>	!empty($medication[0]['med_id']) ? $medication[0]['med_id'] : 0,
      		// 	'med_name'				=>	!empty($medication[0]['med_name']) ? $medication[0]['med_name'] : 'No Medicaiton',
      		// 	'feed_name'				=>	!empty($feeds[0]['name']) ? $feeds[0]['name'] : '-',
      		// 	'user_created_at'	=>	date('Y-m-d H:i:s'),
      		// 	'num_of_pigs'			=>	$bin_history_data['num_of_pigs'],
      		// 	'bin_no_id'				=>	$bin_history_data['bin_id'], // bin id
      		// 	'status'					=>	2,
      		// 	'unique_id'				=>	!empty($bin_history_data['unique_id']) ? $bin_history_data['unique_id'] : "none"
      		// );
          //
          //
      		// $history_id = !empty($lastupdate[0]['history_id']) ? $lastupdate[0]['history_id'] : NULL;
      		// $this->mobileSaveAccepted($mobile_data);
          //
      		// $notification = new CloudMessaging;
      		// $farmer_data = array(
      		// 	'update_date'				=>	date('Y-m-d H:i:s'),
      		// 	'bin_id'						=>	$mobile_data['bin_id'],
      		// 	'farm_id'						=>	$mobile_data['farm_id'],
      		// 	'num_of_pigs'				=>	$mobile_data['num_of_pigs'],
      		// 	'user_id'						=>	$mobile_data['user_id'],
      		// 	'amount'						=>	$mobile_data['current_amount'],
      		// 	'update_type'				=>	"Manual Update Bin Forecasting Admin",
      		// 	'created_at'				=>	$mobile_data['created_at'],
      		// 	'updated_at'				=>	date('Y-m-d H:i:s'),
      		// 	'budgeted_amount'		=>	$budgeted_amount,//$mobile_data['budgeted_amount'],
      		// 	'remaining_amount'	=>	$lastupdate[0]['remaining_amount'],
      		// 	'sub_amount'				=>	$lastupdate[0]['sub_amount'],
      		// 	'variance'					=>	round($mobile_data['variance'],2),
      		// 	'consumption'				=>	round($mobile_data['consumption'],2),
      		// 	'admin'							=>	$_POST['user'],
      		// 	'medication'				=>	$mobile_data['medication'],
      		// 	'feed_type'					=>	$mobile_data['feed_type']
      		// 	);
      		// $notification->autoUpdateMessaging($farmer_data,$history_id);
      		// unset($notification);

      		if($daysto > 3) {
      			$color = "success";
      		} elseif($daysto < 3) {
      			$color = "danger";
      		} else {
      			$color = "warning";
      		}

      		if($daysto > 5) {
      			$text = $daysto . " Days";
      		} else {
      			$text = $daysto . " Days";
      		}

      		$perc = ($daysto<=5 ? (($daysto*2)*10) : 100 );

      		$ring_amount = $this->getmyBinSize($bins[0]['bin_size']);
      		$ring = "Empty";
      		foreach($ring_amount as $k => $v){
      			if($_POST['amount'] == $k){
      				$ring = $v;
      			}
      		}

      		$user = User::where('id',$_POST['user'])->first();

      		return json_encode(array(

      			'msg' 				=> 	$msg,
      			'empty' 			=> 	$this->emptyDate($daysto),
      			'daystoemp' 	=> 	$daysto,
      			'percentage' 	=> 	$perc,
      			'color' 			=> 	$color,
      			'text' 				=> 	$text,
      			'tdy' 				=> 	date('M d'),
      			'ringAmount'	=>	$ring,
      			'lastUpdate'	=>	date("M d"),
      			'user'				=> $user->username,
      			'farm_id'			=> $bin_history_data['farm_id']
      		));

  	}



    /*
  	*	get the update bin history yesterday
  	*/
  	private function todayBinUpdate($bin_id)
    {

    		$date_today = date("Y-m-d");
    		$output = BinsHistory::where('bin_id','=',$bin_id)
    					->where('update_date','<=',$date_today.' 23:59:59')
    					->orderBy('update_date','desc')
    					->take(1)->get()->toArray();
    		return $output;

  	}



    /*
  	*	get the update bin hostory yesterday
  	*/
  	private function yesterdayBinUpdate($bin_id)
    {

    		$date_yesterday = date("Y-m-d", time() - 60 * 60 * 24);
    		$output = BinsHistory::where('bin_id','=',$bin_id)
    					->where('update_date','<=',$date_yesterday.' 23:59:59')
    					->orderBy('update_date','desc')
    					->take(1)->get()->toArray();
    		return $output;

  	}



    /*
  	* get the last update feed type and budgeted amount
  	*/
  	public function daysCounterbudgetedAmount($farm_id,$bin_id,$feed_type_id,$date_to_update)
  	{

    		// get the budgeted amount from feed types table
    		$feed_type = FeedTypes::where('type_id',$feed_type_id)->first();

    		// check if the feed type has per day budgeted amount

  			if($feed_type->total_days != 0){
  				// check the feeds_budgeted_amount_counter
  				$budgeted_amount_counter = DB::table('feeds_budgeted_amount_counter')
  																			->where('feed_type_id',$feed_type_id)
  																			->where('farm_id',$farm_id)
  																			->where('bin_id',$bin_id)
  																			->orderBy('update_date','desc')
  																			->first();
  				if($budgeted_amount_counter != NULL){

  					// get the update date
  					$update_date = $budgeted_amount_counter->update_date;
  					$now = strtotime($date_to_update); // or your date as well
  					$your_date = strtotime($update_date);
  					$datediff = $now - $your_date;
  					$days_counter = round($datediff / (60 * 60 * 24));
  					$days_counter = $days_counter == 0 ? 1 : $days_counter + 1;
  					$days_counter = str_replace(".0","",$days_counter);
  					$days_counter = $days_counter == 0 ? 1 : $days_counter;

  					// get the days counted column
  					$days = DB::table('feeds_feed_type_budgeted_amount_per_day')
  										->where('feed_type_id',$feed_type_id)
  										->orderBy('id','desc')
  										->first();
  					$days = $this->toArray($days);

  					if($days_counter >= 32 || $days_counter == 32){
  						$days_counter = 31;
  					}

  					// if the selected day is 0, select the last column with a non zero value
  					if($days['day_'.$days_counter] != 0){
  						return $days['day_'.$days_counter];
  					} else {
  						// loop backwards to get the nearest non zero value
  						for($i=31; $i>=1; $i--){
  							if($days['day_'.$i] != 0){
  								return $days['day_'.$i];
  							}
  						}
  					}

  				}

  				// get the day one budgeted amount
  				$day_one_counter = DB::table('feeds_feed_type_budgeted_amount_per_day')
  														->select('day_1')
  														->where('feed_type_id',$feed_type_id)->first();
  				return $day_one_counter->day_1;
  			}

  			return $feed_type->budgeted_amount;

  	}



    /*
  	* get the average variance of the last 6 days
  	*/
    private function averageVariancelast6days($bin_id)
    {

    		$output = DB::select(DB::raw('SELECT SUM(variance) AS variance
    									FROM (
    									SELECT variance AS variance
    									FROM  `feeds_bin_history` WHERE bin_id = "'. $bin_id.'"
    									ORDER BY history_id DESC
    									LIMIT 6
    									)x'));

    		return $output[0]->variance;

  	}



    /*
  	* get the average actual consumption last 6 days
  	*/
  	private function averageActuallast6days($bin_id)
    {

    		$output = DB::select(DB::raw('SELECT SUM(consumption) AS consumption
    										FROM (
    										SELECT consumption AS consumption
    										FROM  `feeds_bin_history` WHERE bin_id = "'. $bin_id.'"
    										ORDER BY history_id DESC
    										LIMIT 6
    										)x'));

    		return $output[0]->consumption;

  	}



    /*
  	* get the number of updates of specific bin
  	*/
    public function getNumberOfUpdates($bin_id) {

    		$output = $this->graphQuery3($bin_id);

    		$outputData = json_decode(json_encode($output),true);

    		$count = 0;

    		foreach($outputData as $k => $v){

    			if($v['consumption'] != 0) {

    				$count +=1;

    			}

    		}

    		return ($count == 0 ? 1 : $count);

  	}



    /*
  	*	rebuild cache API
  	*/
  	public function rebuildCacheAPI()
  	{

    		$this->binDataRebuildCache($_POST['bin']);
    		$this->clearBinsCache($_POST['bin']);
    		$this->farmHolderBinClearCache($_POST['bin']);

    		return true;

  	}



    /*
  	*	Bins forecating Data based on bin id
  	*/
  	private function binDataRebuildCache($bin_id)
  	{

    		$bins = DB::table('feeds_bins')
    						 ->select('feeds_bins.*',
    									'feeds_bin_sizes.name AS bin_size_name')
    						 ->leftJoin('feeds_bin_sizes','feeds_bin_sizes.size_id', '=', 'feeds_bins.bin_size')
    						 ->where('bin_id',$bin_id)
    						 ->orderBy('feeds_bins.bin_number','asc')
    						 ->get();
    		$bins = $this->toArray($bins);


    		for($i=0; $i<count($bins); $i++){

    			Cache::forget('bins-'.$bins[$i]['bin_id']);
    			Cache::forget('farm_holder-'.$bins[$i]['farm_id']);

    			$current_bin_amount_lbs = $this->currentBinCapacity($bins[$i]['bin_id']);
    			$last_update = $this->toArray($this->lastUpdate($bins[$i]['bin_id']));
    			$last_update_user = $this->toArray($this->lastUpdateUser($bins[$i]['bin_id']));
    			$up_hist[$i] = $this->toArray($this->lastUpdate_numpigs($bins[$i]['bin_id']));
          $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
    			$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'],$up_hist[$i][0]['budgeted_amount']);
    			$delivery = $this->nextDel_($bins[$i]['farm_id'],$bins[$i]['bin_id']);
    			$last_delivery = $this->lastDelivery($bins[$i]['farm_id'],$bins[$i]['bin_id'],$last_update);


    				// rebuild cache data
    				$bins_items = array(
    					'bin_s'										=>  $this->getmyBinSize($bins[$i]['bin_size']),
    					'bin_id'									=>	$bins[$i]['bin_id'],
    					'bin_number'							=>	$bins[$i]['bin_number'],
    					'alias'										=>	$bins[$i]['alias'],
    					'total_number_of_pigs'		=>	$total_number_of_pigs,
    					'num_of_pigs'							=>	$bins[$i]['num_of_pigs'],
    					'default_amount'					=>	$this->displayDefaultAmountofBin($bins[$i]['amount'], $up_hist[$i][0]['amount']),
    					'bin_size'								=>	$bins[$i]['bin_size'],
    					'bin_size_name'						=>	$bins[$i]['bin_size_name'],
    					'feed_type_name'					=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->description,
    					'feed_type_name_orig'			=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->name,
    					'feed_type_id'						=>	$up_hist[$i][0]['feed_type'],
    					'budgeted_amount'					=>	$budgeted_,
    					'current_bin_amount_tons'	=>	$up_hist[$i][0]['amount'],
    					'current_bin_amount_lbs'	=>	(int)$current_bin_amount_lbs,
    					'days_to_empty'						=>	$this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs),
    					'empty_date'							=>	$this->emptyDate($this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs)),
    					'next_delivery'						=>	$delivery['name'],
    					'medication'							=>	$this->getMedDesc($up_hist[$i][0]['medication']),
    					'medication_name'					=>	$this->getMedName($up_hist[$i][0]['medication']),
    					'medication_id'						=>	$up_hist[$i][0]['medication'],
    					'last_update'							=>	$last_update_user[0]['update_date'],
    					'next_deliverydd'					=>  $last_delivery,
    					'delivery_amount'					=>  $delivery['amount'],
              'default_val'							=>  $this->animalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']),
    					'graph_data'							=>	NULL,
    					'num_of_update'						=>  NULL,
    					'average_variance'				=>	NULL,
    					'average_actual'					=>	NULL,
    					'username'								=>	$this->usernames($last_update_user[0]['user_id']),
    					'last_manual_update'			=>	$this->lastManualUpdate($bins[$i]['bin_id'])
    				);

    				Cache::forever('bins-'.$bins[$i]['bin_id'],$bins_items);

    		}

    		$this->forecastingDataCache();
    		return true;

  	}



    /*
  	*	forecastingDataCache()
  	*	Cache data Builder for forecasting page
  	* Method to use for cron job
  	*/
  	public function forecastingDataCache()
  	{

    		if(Storage::exists('forecasting_data_low_bins.txt')){
    			//Storage::delete('forecasting_data_low_bins.txt');
    		}

    		if(Storage::exists('forecasting_data_a_to_z.txt')){
    			//Storage::delete('forecasting_data_a_to_z.txt');
    		}

    		$farms = $this->enabledFarms();
    		$forecastingData = array();

    		for($i=0; $i<count($farms); $i++){
    			Cache::forget('farm_holder-'.$farms[$i]['id']);
    			if(Cache::has('farm_holder-'.$farms[$i]['id'])) {

    				 $forecastingData[] = Cache::get('farm_holder-'.$farms[$i]['id'])[$i];

    			} else {

    				$forecastingData[] = array(
    					'farm_id'					=>	$farms[$i]['id'],
    					'name'						=>	$farms[$i]['name'],
    					'farm_type'				=>	$farms[$i]['farm_type'],
    					'delivery_status'	=>	$this->pendingDeliveryItems($farms[$i]['id']),
    					'address'					=>	$farms[$i]['address'],
    					'bins'						=> 	$this->binsDataFirstLoad($farms[$i]['id'],$farms[$i]['update_notification']) + array('notes'=>$farms[$i]['notes'])
    				);

    				Cache::forever('farm_holder-'.$farms[$i]['id'],$forecastingData);

    			}

    		}
    		// cache data via sort type low bins
    		usort($forecastingData, function($a,$b){
    			if($a['bins'][0]['empty_bins'] == $b['bins'][0]['empty_bins'])
    			return ($a['bins'][0]['first_list_days_to_empty'] > $b['bins'][0]['first_list_days_to_empty']);
    			return ($a['bins'][0]['empty_bins'] < $b['bins'][0]['empty_bins'])?1:-1;
    		});
    		Storage::put('forecasting_data_low_bins.txt',json_encode($forecastingData));

    		// cache data via sort type a-z farms
    		usort($forecastingData, function($a,$b){
    			return strcasecmp($a["name"], $b["name"]);
    		});
    		Storage::put('forecasting_data_a_to_z.txt',json_encode($forecastingData));

    		return "done caching";

  	}



    /*
  	* enabledFarms()
  	* Get all the enabled farms
  	*/
  	private function enabledFarms()
  	{

    		$farms = Farms::select(
    								DB::raw('DISTINCT(feeds_farms.id) as id'),
    								DB::raw('feeds_farms.name as name'),
    								DB::raw('feeds_farms.farm_type as farm_type'),
    								DB::raw('feeds_farms.address as address'),
    								DB::raw('feeds_farms.notes as notes'),
    								DB::raw('feeds_farms.update_notification as update_notification')
    								)
    								->rightJoin('feeds_bins','feeds_farms.id','=','feeds_bins.farm_id')
    								->where('status',1)
    								->get()->toArray();

    		return $farms;

  	}



    /*
  	*	Pending delivery items
  	*/
  	private function pendingDeliveryItems($farmId)
  	{

    		$farm_schedule = FarmSchedule::where('farm_id',$farmId)->where('status',0)->where('date_of_delivery','>',date('Y-m-d'))->count();

    		if($farm_schedule > 0) {
    			return $farm_schedule;
    		}

    		$deliveries = Deliveries::where('farm_id',$farmId)->where('delivered',0)->where('delivery_label','active')->where('delivery_date','>',date('Y-m-d H:i:s'))->count();

        return $deliveries;

  	}



    /*
  	*	Bins forecating Data first load
  	*/
  	private function binsDataFirstLoad($farm_id,$update_notification)
    {

    		$bins = DB::table('feeds_bins')
                         ->select('feeds_bins.*',
    					 			'feeds_bin_sizes.name AS bin_size_name',
    								'feeds_feed_types.name AS feed_type_name',
    								'feeds_feed_types.budgeted_amount')
    					 ->leftJoin('feeds_bin_sizes','feeds_bin_sizes.size_id', '=', 'feeds_bins.bin_size')
    					 ->leftJoin('feeds_feed_types','feeds_feed_types.type_id', '=', 'feeds_bins.feed_type')
               ->where('farm_id', '=', $farm_id)
    					 ->orderBy('feeds_bins.bin_number','asc')
                         ->get();


    		$bins = json_decode(json_encode($bins),true);

    		$binsData = array();
    		$binAmounts = array();
    		$update_type = 0;

    		$binsCount = count($bins) - 1;
    		for($i=0;$i<=$binsCount;$i++){
    			//Cache::forget('farm_holder_bins_data-'.$bins[$i]['bin_id']);
    			 if(Cache::has('farm_holder_bins_data-'.$bins[$i]['bin_id'])) {

    					$binsData[] = Cache::store('file')->get('farm_holder_bins_data-'.$bins[$i]['bin_id'])[$i];

    			 } else {

    				 	$yesterday_update[$i] = $this->getYesterdayUpdate($bins[$i]['bin_id']);
    					$up_hist[$i] = json_decode(json_encode($this->lastUpdate_numpigs($bins[$i]['bin_id'])), true);
    					$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'], $up_hist[$i][0]['budgeted_amount']);
              $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
    					$update_type = $this->updateTypeCounter($up_hist[$i][0]['update_type'],$yesterday_update[$i],$up_hist[$i][0]['num_of_pigs'],$bins[$i]['bin_id']);

    					$binsData[] = array(
    						'bin_id'									=>	$bins[$i]['bin_id'],
    						'current_bin_capacity'		=>	$this->currentBinCapacity($bins[$i]['bin_id']),
    						'days_to_empty'						=>	$this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs),
    						'empty_date'							=>	$this->emptyDate($this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs)),
    						'update_type'							=>	$update_type,
    						'last_manual_update'			=>	$this->lastManualUpdate($bins[$i]['bin_id']),
    					);

    					$binAmounts[] = $up_hist[$i][0]['amount'] == NULL ? 0 : $up_hist[$i][0]['amount'];

    	 				Cache::forever('farm_holder_bins_data-'.$bins[$i]['bin_id'],$binsData);

    	 		 }

    		}

    		$sorted_bins = $binsData;
    		usort($sorted_bins, function($a,$b){
    			if($a['days_to_empty'] == $b['days_to_empty']) return 0;
    			return ($a['days_to_empty'] < $b['days_to_empty'])?-1:1;
    		});

    		$days_to_empty_first = array(
    			'first_list_days_to_empty'	=>	!empty($sorted_bins[0]['days_to_empty']) ? $sorted_bins[0]['days_to_empty'] : 0
    		);

    		$sorted_bins = $binsData;
    		usort($sorted_bins, function($a,$b){
    			if($a['last_manual_update'] == $b['last_manual_update']) return 0;
    			return ($a['last_manual_update'] < $b['last_manual_update'])?-1:1;
    		});

    		$last_manual_update = array(
    			'last_manual_update'	=>	$sorted_bins[0]['last_manual_update']
    		);

    		$empty_bins = array(
    			'empty_bins'	=>	$this->countEmptyBins($binsData)
    		);

    		$lowest_amount_bin = array(
    			'lowest_amount_bin'	=> $binAmounts != NULL ?	min($binAmounts) : 0
    		);

    		$low_bins = array();
    		for($i=0; $i < count($binsData); $i++){

    			if($binsData[$i]['days_to_empty'] <= 2){
    				$low_bins[] = array(
    					'lowBins'	=> $binsData[$i]['days_to_empty']
    				);
    			}

    		}

    		$low_bins_counter = array('lowBins'	=> count($low_bins));

    		$update_types = array();
    		for($i=0; $i < count($binsData); $i++){
    			if($binsData[$i]['update_type'] == 1){
    				//$update_types[] = array(
    					//'update_type'	=> ""
    				//);
    			} else {
    				$update_types[] = array(
    					'update_type'	=> $binsData[$i]['update_type']
    				);
    			}
    			$binsDataFinal[] = $empty_bins+$days_to_empty_first+$binsData[$i];
    		}

    		// disabled update notifications
    		if($update_notification == "disable"){
    			$update_types = "";
    		}

    		$update_types_counter = array('update_type'	=> $update_types);

    		return $binsDataFinal+$low_bins_counter+$update_types_counter+$last_manual_update+$lowest_amount_bin;

  	}



    /*
  	*	yesterday update
  	*/
  	public function getYesterdayUpdate($bin_id)
  	{

    		$date_yesterday = date('Y-m-d',strtotime('-1 day'));
    		$output = BinsHistory::select('actual_amount_tons','num_of_pigs')
    							->where('update_date','LIKE',$date_yesterday."%")
    							->where('bin_id',$bin_id)
    							->get()->toArray();

    		return $output;

  	}



    /*
  	*	Update type counter
  	*/
  	private function updateTypeCounter($update_type,$yesterday_update,$total_number_of_pigs,$bin_id)
  	{
    		$output = $bin_id;

    		$date_today = date("Y-m-d") . " 12:00:00";
    		$time_today = date("H:i:s");
    		$time_to_display = date("H:i:s",strtotime($date_today));

    		if($yesterday_update != NULL){

    			if($total_number_of_pigs != 0){

    				if($update_type == 'Manual Update Bin Forecasting Admin' || $update_type == 'Manual Update Mobile Farmer' || $update_type == 'Delivery Manual Update Admin'){
    					$output = 1;
    				}

    			} else {

    				$output = 1;

    			}

    		}

    		$date_one = date("Y-m-d H:i a");
    		$date_two = date("Y-m-d") . " 12:00 pm";
    		$date_three = date("Y-m-d") . " 11:59 pm";
    		if(strtotime($date_one) > strtotime($date_two) && strtotime($date_one) < strtotime($date_three)){
    			$day = date("l");
    			if($day == "Tuesday"){
    				$output = $output;
    			}else if($day == "Thursday"){
    				$output = $output;
    			}else{
    				$output = 1;
    			}
    		} else {
    			$output = 1;
    		}


    		return $output;
  	}



    /*
  	*	Clear bins cache
  	*/
  	public function clearBinsCache($bin_id)
    {

    		Cache::forget('bins-'.$bin_id);
    		Cache::forget('farm_holder_bins_data-'.$bin_id);

    		if($this->binDataRebuildCache($bin_id)){
    			return "cache clear for bin: ".$bin_id;
    		}

    		$this->farmHolderBinClearCache($bin_id);

    		return "Something went wrong";

  	}



    /*
  	* farmHolderBinClearCache
  	* Farm holder clear cache
  	*/
  	public function farmHolderBinClearCache($farm_id)
  	{

    		$bins = Bins::where('farm_id',$farm_id)->get()->toArray();
    		for($i=0;$i<count($bins);$i++){
    			Cache::forget('farm_holder_bins_data-'.$bins[$i]['bin_id']);
    		}

  	}



    /*
  	*	Insert the number of pigs on the bin history
  	*/
  	public function updatePigsAPI($farm_id,$bin,$numpigs,$animal_unique_id,$user_id)
  	{

    		$updateBin = array();
    		foreach($numpigs as $k => $v){
    			$updateBin[] = $this->fetchBinAnimalGroupAPI($animal_unique_id[$k],$v,$farm_id,$bin,$user_id);
    		}

    		$output = array();

    		$update = $this->multiToOne($updateBin);

    		foreach($update as $k => $v){

    			if($v['daysto'] > 3) {

    				$color = "success";

    			} elseif($v['daysto'] < 3) {
    				$color = "danger";
    			} else {
    				$color = "warning";
    			}

    			if($v['daysto'] > 5) {
    				$text = $v['daysto'] . " Days";
    			} else {
    				$text = $v['daysto'] . " Days";
    			}

    			$perc = ($v['daysto'] <=5 ? (($v['daysto']*2)*10) : 100 );


    			$output[] = array(
    				'bin'	=>	$v['bin'],
    				'msg' => "Bin was successfully Updated!",
    				'empty' => $this->emptyDate($this->daysOfBins($this->currentBinCapacity($v['bin']),$v['budgeted_'],$v['total_number_of_pigs'])),
    				'daystoemp' => $v['daysto'],
    				'numofpigs' => $v['numofpigs_'],
    				'percentage' => $perc,
    				'color' => $color,
    				'text' => $text,
    				'tdy' => date('M d'),
    				'unique_id'	=>	$v['animal_unique_id'],
    				'total_number_of_pigs'	=>	$v['total_number_of_pigs']
    			);

    		}

    		$counter = count($output) - 1;

    		return array(0=>$output[$counter]);

  	}



    /*
  	*	get the bins in Animal Group for farrowing
  	*/
  	private function fetchBinAnimalGroupAPI($unique_id,$number_of_pigs,$farm_id,$bin_id,$user_id)
  	{

      		// check the farm type
      		$type = $this->farmTypes($farm_id);

      		if($type != NULL){
            DB::table('feeds_movement_groups_bins')
              ->where('unique_id',$unique_id)
              ->where('bin_id',$bin_id)
              ->update(['number_of_pigs'=>$number_of_pigs]);
      		} else {
      			return NULL;
      		}

      		$update = array();
          $update[] = $this->updateBinsHistoryNumberOfPigsAPI($number_of_pigs,$bin_id,$unique_id,$user_id);

      		return $update;

  	}



    /*
  	*	Update the bin history for update number of pigs
  	*/
  	private function updateBinsHistoryNumberOfPigsAPI($number_of_pigs,$bin_id,$unique_id,$user_id)
  	{

      		$bininfo = $this->getBinDefaultInfo($bin_id);
      		$lastupdate  = $this->getLastHistory($bininfo);

      		if(!empty($lastupdate)){
      			$update_date = date("Y-m-d",strtotime($lastupdate[0]->update_date));

      			if($update_date == date("Y-m-d")){
      				$variance = $lastupdate[0]->variance;
      				$consumption = $lastupdate[0]->consumption;
      			}else{
      				$variance = 0;
      				$consumption = 0;
      			}
      		}

      		// get the total number of pigs based on the animal group total number of pigs
      		//$total_number_of_pigs = $this->totalNumberOfPigsAnimalGroup($bin_id,$bininfo[0]->farm_id); //$number_of_pigs;
          $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bin_id,$bininfo[0]->farm_id);

      		$budgeted_amount = $this->daysCounterbudgetedAmount($bininfo[0]->farm_id,$bin_id,$lastupdate[0]->feed_type,date("Y-m-d H:i:s"));

      		$data = array(
      				'update_date' => date("Y-m-d H:i:s"),
      				'bin_id' => $bin_id,
      				'farm_id' => $bininfo[0]->farm_id,
      				'num_of_pigs' => $total_number_of_pigs,
      				'user_id' => $user_id,
      				'amount' => $lastupdate[0]->amount,
      				'update_type' => 'Manual Update Number of Pigs Forecasting Admin',
      				'created_at' => date("Y-m-d H:i:s"),
      				'updated_at' => date("Y-m-d H:i:s"),
      				'budgeted_amount' => $budgeted_amount,//$lastupdate[0]->budgeted_amount,
      				'remaining_amount' => $lastupdate[0]->remaining_amount,
      				'sub_amount' => $lastupdate[0]->sub_amount,
      				'variance' => $variance,
      				'consumption' => $consumption,
      				'admin' => $user_id,
      				'medication' => !empty($lastupdate[0]->medication) ? $lastupdate[0]->medication : 0,
      				'feed_type' => $lastupdate[0]->feed_type,
      				'unique_id'	=> !empty($lastupdate[0]->unique_id) ? $lastupdate[0]->unique_id : "none"
      			);

      		BinsHistory::where('bin_id', '=', $bin_id)
      			->where('update_date',"LIKE", date("Y-m-d") . "%")
      			->delete();

      		BinsHistory::insert($data);

      		// $notification = new CloudMessaging;
      		// $farmer_data = array(
      		// 	'farm_id'		=> 	$bininfo[0]->farm_id,
      		// 	'bin_id'		=> 	$bin_id,
      		// 	'num_of_pigs'	=> 	$number_of_pigs
      		// 	);
      		// $notification->updatePigsMessaging($farmer_data);
      		// unset($notification);

      		$numofpigs_ = $this->displayDefaultNumberOfPigs($bininfo[0]->num_of_pigs, $number_of_pigs);
      		$budgeted_ = $budgeted_amount;//$this->getmyBudgetedAmount($lastupdate[0]->budgeted_amount, $bininfo[0]->feed_type);
      		$daysto = $this->daysOfBins($this->currentBinCapacity($bin_id),$budgeted_,$numofpigs_);

      		Cache::forget('bins-'.$bin_id);

      		return array(
      			'bin'					=>	$bin_id,
      			'numofpigs_'			=>	$number_of_pigs,
      			'total_number_of_pigs'	=>	$total_number_of_pigs,
      			'budgeted_'				=>	$budgeted_,
      			'daysto'				=>	$daysto,
      			'animal_unique_id'		=>	$unique_id
      		);

  	}



    /**
  	** Gets the Default Values of a certain Bin
  	** int bin_id Primary key
  	** return array Object 2-19-2016
  	**/
  	public function getBinDefaultInfo($bin_id) {

      		$output = DB::table('feeds_bins')
      					->select('bin_id','farm_id','num_of_pigs','amount', 'feed_type', 'bin_size')
      					->where('bin_id','=',$bin_id)
      					->get();

      		return $output;

  	}


    /**
  	** Gets last values from Update History
  	** bininfo array Object
  	** return array Object 2-19-2016
  	**/
  	public  function getLastHistory($bininfo) {

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
      				'consumption' => 0,
      				'update_date'	=>	date("Y-m-d"),
      				'feed_type'	=>	0

      			);

      		}

      		return $output;

  	}



    public function getBudgetedAmount($feedtype) {

    		$output = DB::table('feeds_feed_types')
    					->select('budgeted_amount')
    					->where('type_id','=',$feedtype)
    					->get();

    		return !empty($output[0]->budgeted_amount) ? $output[0]->budgeted_amount : 0;

  	}



    /*
  	*	Multidimentional Array to One Dimentional output
  	*/
  	private function multiToOne($updateBin)
  	{

    		$update = array();
    		foreach($updateBin as $k => $v){

    			foreach($v as $key => $val){

    				$update[] = array(
    					"bin"					=>	$val['bin'],
    					"numofpigs_" 			=>	$val['numofpigs_'],
    					"budgeted_" 			=>	$val['budgeted_'],
    					"daysto"				=>	$val['daysto'],
    					"animal_unique_id"		=>	$val['animal_unique_id'],
    					'total_number_of_pigs'	=>	$val['total_number_of_pigs']
    				);

    			}

    		}

    		return $update;

  	}



    /*
  	*	Unique ID generator
  	*/
  	public function generator(){

    		$unique = uniqid(rand());
    		$dateToday = date('ymdhms');

    		$unique_id = FarmSchedule::where('unique_id','=',$unique)->exists();

    		$output = ($unique_id == true ? $unique.$dateToday : $unique );

    		return $output;

  	}



    /*
  	*	get deliveries information
  	*/
  	public function loadBreakdownAPI($unique_id)
  	{
    		$data = array();
    		$delivery = $this->getDeliveries($unique_id);
    		for($i=0;$i<count($delivery);$i++){
    			$data[] = array(
    				'farm'	=>	$this->getDeliveriesFarmName($delivery[$i]['farm_id']),
    				'feed_type'	=>	$this->getDeliveriesFeedType($delivery[$i]['feeds_type_id']),
    				'medication'	=>	$this->getDeliveriesMedication($delivery[$i]['medication_id']),
    				'amount'	=>	$delivery[$i]['amount'],
    				'bins'	=>	$this->getDeliveriesSpecificBinName($delivery[$i]['bin_id']),
    				'compartment'	=>	$delivery[$i]['compartment_number']
    			);
    		}

    		return $data;
  	}



    /*
  	*	Delievries Farm Name
  	*/
  	public function getDeliveriesFarmName($farm_id)
  	{
    		$farm_name = Farms::select('name')->where('id',$farm_id)->get()->toArray();

    		return !empty($farm_name[0]['name']) ? $farm_name[0]['name'] : "";
  	}

  	/*
  	*	Deliveries Feed Types
  	*/
  	public function getDeliveriesFeedType($feed_type_id)
  	{
    		$feed_type = FeedTypes::select('name')
    					->where('type_id','=',$feed_type_id)
    					->get()->toArray();
    		return !empty($feed_type[0]['name']) ? $feed_type[0]['name'] : "-";
  	}

  	/*
  	*	Deliveries Medication
  	*/
  	public function getDeliveriesMedication($med_id)
  	{
    		$medications = Medication::select('med_name')
    						->where('med_id',$med_id)
    						->get()->toArray();
    		return !empty($medications[0]['med_name']) ? $medications[0]['med_name'] : "-";
  	}

  	/*
  	*	Deliveries Bin Name
  	*/
  	public function getDeliveriesSpecificBinName($bin_id)
  	{
    		$bin_name = Bins::select('alias')
    					->where('bin_id',$bin_id)
    					->get()->toArray();
    		return !empty($bin_name[0]['alias']) ? $bin_name[0]['alias'] : "-";
  	}



    /*
  	*	Mark as delivered
  	*/
  	public function markDeliveredAPI($unique_id,$user){

    		$user = $user == NULL ? 1 : $user;
    		$undone_deliveries = Deliveries::select(DB::raw("sum(amount) as amount"),"farm_id","feeds_type_id","medication_id","bin_id","driver_id","truck_id","unique_id","unload_by","status")
    										->where('unique_id',$unique_id)
    										->whereIn('status',[0,1,2])
    										->groupBy('bin_id')
    										->get()->toArray();

    		$farm_ids = array();
    		$data_to_update = array();
    		$data_to_insert = array();
    		for($i=0;$i<count($undone_deliveries);$i++){

    			$bin_id = $undone_deliveries[$i]['bin_id'];
    			$farm_id = $undone_deliveries[$i]['farm_id'];

    			Cache::forget('bins-'.$bin_id);

    			// last update
    			$data = $this->feedsHistoryData($bin_id,$farm_id);
    			$update_date = $data != NULL ? $data[0]['update_date'] : date("Y-m-d",strtotime(date("Y-m-d")."+ 1 day"));
    			$update_date = date("Y-m-d",strtotime($update_date));
    			$date_today = date("Y-m-d H:i:s");
    			$bins_data = Bins::where('bin_id',$bin_id)->take(1)->get()->toArray();
    			$num_of_pigs = $data != NULL ? $data[0]['num_of_pigs'] : $bins_data[0]['num_of_pigs'];
    			$amount = $data != NULL ? $data[0]['amount'] : 0;
    			$medication = $data != NULL ? $data[0]['medication'] : 0;
    			$feed_type = $data != NULL ? $data[0]['feed_type'] : $bins_data[0]['feed_type'];
    			$if_date_today = date("Y-m-d",strtotime($date_today));
    			$medication_id = $undone_deliveries[$i]['medication_id'] != NULL ? $undone_deliveries[$i]['medication_id'] : $data[0]['medication'];
    			$feed_type_id = $undone_deliveries[$i]['feeds_type_id'] != NULL ? $undone_deliveries[$i]['feeds_type_id'] : $data[0]['feed_type'];

    			// update
    			if($update_date === $if_date_today){

    				$budgeted_amount = $this->budgetedAmountUpdater($data[0]['feed_type'],$undone_deliveries[$i]['feeds_type_id'],$farm_id,$bin_id,$date_today);

    				$data_to_update = array(
    					'update_date'						=>	$date_today,
    					'amount'								=>	$data[0]['amount'] + $undone_deliveries[$i]['amount'],
    					'budgeted_amount_tons'	=>	$data[0]['budgeted_amount_tons'] + $undone_deliveries[$i]['amount'],
    					'actual_amount_tons'		=>	$data[0]['actual_amount_tons'] + $undone_deliveries[$i]['amount'],
    					'bin_id'								=>	$bin_id,
    					'farm_id'								=>	$farm_id,
    					'num_of_pigs'						=>	$num_of_pigs,
    					'user_id'								=> 	$user,
    					'update_type'						=>	'Delivery Manual Update Admin',
    					'admin'									=>	1,
    					'created_at'						=>	$date_today,
    					'updated_at'						=>	$date_today,
    					'budgeted_amount'				=>	$budgeted_amount,
    					'remaining_amount'			=>	0,
    					'sub_amount'						=>	0,
    					'variance'							=>	$data[0]['variance'],
    					'consumption'						=>	$data[0]['consumption'],
    					'medication'						=>	$medication_id,
    					'feed_type'							=>	$feed_type_id,
    					'unique_id'							=>	$unique_id
    				);

    				if($undone_deliveries[$i]['unload_by'] == "admin"){
    					$this->updateFeedsHistoryDataAPI($data_to_update);
    					$this->markAsdeliveredBinsAcceptedLoad($data_to_update);
    					//$this->sendNotificationMarkAsDelivered($data_to_update['unique_id'],$undone_deliveries[$i]['driver_id']);
    				}

    				// if($undone_deliveries[$i]['status'] == 2){
    				// 	$this->sendNotificationMarkAsDelivered($data_to_update['unique_id'],$undone_deliveries[$i]['driver_id']);
    				// }

    			// insert
    			} else {

    				$budgeted_amount = $this->budgetedAmountUpdater($data[0]['feed_type'],$undone_deliveries[$i]['feeds_type_id'],$farm_id,$bin_id,$date_today);

    				$data_to_insert = array(
    					'update_date'				=>	$date_today,
    					'bin_id'						=>	$bin_id,
    					'farm_id'						=>	$farm_id,
    					'num_of_pigs'				=>	$num_of_pigs,
    					'user_id'						=>	$user,
    					'amount'						=>	$amount + $undone_deliveries[$i]['amount'],
    					'update_type'				=>	'Delivery Manual Update Admin',
    					'created_at'				=>	$date_today,
    					'updated_at'				=>	$date_today,
    					'budgeted_amount'		=>	$budgeted_amount,
    					'remaining_amount'	=>	0,
    					'sub_amount'				=>	0,
    					'variance'					=>	0,
    					'consumption'				=>	0,
    					'admin'							=>	1,
    					'medication'				=>	$medication_id,
    					'feed_type'					=>	$feed_type_id,
    					'unique_id'					=>	$unique_id
    				);

    				if($undone_deliveries[$i]['unload_by'] == "admin"){
    					$this->saveFeedsHistoryData($data_to_insert);
    					$this->markAsdeliveredBinsAcceptedLoad($data_to_insert);
    					//$this->sendNotificationMarkAsDelivered($data_to_insert['unique_id'],$undone_deliveries[$i]['driver_id']);
    				}

    				// if($undone_deliveries[$i]['status'] == 2){
    				// 	$this->sendNotificationMarkAsDelivered($data_to_insert['unique_id'],$undone_deliveries[$i]['driver_id']);
    				// }

    			}

    			// for bins_data_first_load
    			Cache::forget('farm_holder_bins_data-'.$bin_id);
    			Cache::forget('farm_holder-'.$farm_id);

    		}

    		SchedTool::where('delivery_unique_id',$unique_id)->update(['status'=>'delivered']);
    		$update = Deliveries::where('unique_id',$unique_id)
    				->update(['status'=>3,'delivered'=>1,'compartment_status'=>3]);

    		// update feeds_mobile_notification
    		DB::table('feeds_mobile_notification')->where('unique_id',$unique_id)->update(['is_readred'=>'true']);

    		//$this->forecastingDataCache();

    		return $update;
  	}



    /*
  	*	feeds_bin_history data to update
  	*/
  	private function feedsHistoryData($bin_id,$farm_id)
    {
    		$date_today = date("Y-m-d");
    		$update_data = BinsHistory::where('update_date','LIKE',$date_today.'%')
    										->where('bin_id','=',$bin_id)
    										->where('farm_id','=',$farm_id)
    										->orderBy('update_date','desc')
    										->take(1)->get()
    										->toArray();

    		if($update_data == NULL){
    			$update_data = BinsHistory::where('update_date','<=',$date_today)
    										->where('bin_id','=',$bin_id)
    										->where('farm_id','=',$farm_id)
    										->orderBy('update_date','desc')
    										->take(1)->get()
    										->toArray();
    		}

    		return $update_data;
  	}



    /*
    *	budgeted amount updater
    */
    private function budgetedAmountUpdater($last_feed_type,$current_feed_type,$farm_id,$bin_id,$date_today)
    {
        $budgeted_amount = 0;
        if($current_feed_type != $last_feed_type){
          // insert data to feeds_budgeted_amount_counter
          $budgeted_amount = $this->budgetedAmountCounterUpdater($farm_id,$bin_id,$current_feed_type);
        }else {
          // get the days counted for the auto update budgeted amount
          // feeds_feed_type_budgeted_amount_per_day
          // get the last date inserted on the feeds_budgeted_amount_counter and count it on today's date then get the day column for that budgeted amount
          // if the day column has 0 get the last day column where it has a value that is not equal to zero
          $budgeted_amount = $this->daysCounterbudgetedAmount($farm_id,$bin_id,$current_feed_type,$date_today);
        }

        return $budgeted_amount;
    }


    /*
  	* insert the new feed type to the budgeted amount counter
  	*/
  	public function budgetedAmountCounterUpdater($farm_id,$bin_id,$feed_type_id)
  	{
    		$data = array(
    			'farm_id'				=>	$farm_id,
    			'bin_id'				=>	$bin_id,
    			'update_date'		=>	date('Y-m-d'),
    			'feed_type_id'	=>	$feed_type_id
    		);

    		// insert the changed feed type
    		DB::table('feeds_budgeted_amount_counter')->insert($data);

    		// check if the feed_type has different budgeted amount
    		$budgeted_amount_counter = DB::table('feeds_budgeted_amount_counter')
    																	->where('farm_id',$farm_id)
    																	->where('bin_id',$bin_id)
    																	->orderBy('id','desc')
    																	->first();

    		// get the budgeted amount from feed types table
    		$feed_type = FeedTypes::where('type_id',$budgeted_amount_counter->feed_type_id)->first();

    		// check if the feed type has per day budgeted amount
    		if($feed_type->total_days != 0){
    			// get the day one budgeted amount
    			$day_one_counter = DB::table('feeds_feed_type_budgeted_amount_per_day')
    													->select('day_1')
    													->where('feed_type_id',$feed_type_id)->first();
    			return $day_one_counter->day_1;
    		}

    		return $feed_type->budgeted_amount;

  	}



    /*
  	*	Update the feeds_bin_history for mark as delivered item for admin
  	*/
  	private function updateFeedsHistoryDataAPI($data_to_update){

      		if($data_to_update != NULL){

      				$data = BinsHistory::where('update_date','LIKE',date("Y-m-d",strtotime($data_to_update['update_date'])).'%')
      													->where('bin_id','=',$data_to_update['bin_id'])
      													->where('farm_id','=',$data_to_update['farm_id'])
      													->first();

      				// /$data = BinsHistory::findOrFail($data->history_id);

      				$data->update_date						=	$data_to_update['update_date'];
      				$data->amount									=	$data_to_update['amount'];
      				$data->budgeted_amount_tons		=	$data_to_update['budgeted_amount_tons'];
      				$data->actual_amount_tons			=	$data_to_update['actual_amount_tons'];
      				$data->bin_id									=	$data_to_update['bin_id'];
      				$data->farm_id								=	$data_to_update['farm_id'];
      				$data->num_of_pigs						=	$data_to_update['num_of_pigs'];
      				$data->user_id								= $data_to_update['user_id'];
      				$data->update_type						= $data_to_update['update_type'];
      				$data->admin									=	$data_to_update['admin'];
      				$data->created_at							=	$data_to_update['created_at'];
      				$data->updated_at							=	$data_to_update['updated_at'];
      				$data->budgeted_amount				=	$data_to_update['budgeted_amount'];
      				$data->remaining_amount				=	$data_to_update['remaining_amount'];
      				$data->sub_amount							=	$data_to_update['sub_amount'];
      				$data->variance								=	$data_to_update['variance'];
      				$data->consumption						=	$data_to_update['consumption'];
      				$data->medication							=	$data_to_update['medication'];
      				$data->feed_type							=	$data_to_update['feed_type'];
      				$data->unique_id							=	$data_to_update['unique_id'];

      				//$data->save();

      				Event::fire(new CallBinsHistory($data));

      		}

  	}



    /*
  	*	Mark as delivered Mobile bins accepted load
  	*/
  	private function markAsdeliveredBinsAcceptedLoad($data)
    {

    		if($data['medication'] == 8){
    			$data['medication'] = 0;
    		}

    		$bin_number = Bins::where('bin_id',$data['bin_id'])->first()->toArray();
    		$bin_size = BinSize::where('size_id',$bin_number['bin_size'])->first()->toArray();
    		$med_name = Medication::where('med_id',$data['medication'])->first()->toArray();
    		$feed_name = FeedTypes::where('type_id',$data['feed_type'])->first()->toArray();

  	}



    /*
  	*	Insert the feeds_bin_history for mark as delivered item for admin
  	*/
  	private function saveFeedsHistoryData($data)
    {
    		if($data != NULL){
    			BinsHistory::insert($data);
    		}
  	}




}
