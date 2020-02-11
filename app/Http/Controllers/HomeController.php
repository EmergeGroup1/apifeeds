<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Cache;
use DB;
use Carbon;
use App\Farms;
use App\FarmSchedule;
use App\BinsHistory;
use App\User;
use App\FeedTypes;
use App\Deliveries;
use App\MobileBinsAcceptedLoad;

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
    				->select(DB::raw('sum(amount) as sum'))
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

        $data =Medication::where('med_id','=',$medId)
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
  					->get();

  		if($output == NULL) {

  			$output = array(
  						array(
  							'med_description' => 'No Medication'
  						)
  					  );

  		}

  		$output = json_decode(json_encode($output),true);
  		return $output[0]['med_description'];

  	}


    /*
  	*	Get the medication name
  	*/
  	public function getMedName($medid) {

  		$output = DB::table('feeds_medication')
  					->select('med_name')
  					->where('med_id','=',$medid)
  					->get();

  		if($output == NULL) {

  			$output = array(
  						array(
  							'med_name' => 'No Medication'
  						)
  			);

  		}

  		$output = json_decode(json_encode($output),true);
  		return $output[0]['med_name'];

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

}
