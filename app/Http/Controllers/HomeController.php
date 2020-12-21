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
use App\Bins;
use App\FarmSchedule;
use App\BinsHistory;
use App\User;
use App\FeedTypes;
use App\Deliveries;
use App\MobileBinsAcceptedLoad;
use App\SchedTool;
use App\BinSize;

class HomeController extends Controller
{

	  /**
		 * Create a new controller instance.
		 *
		 * @return void
		 */
		public function __construct()
		{

			$this->middleware('auth',['except' => ['forecastingDataCacheBuilder','forecastingDataCache','forecastingDataOutput','binsDataCacheBuilder','clearBinsCache','conAutoUpdate','cacheBinHistoryLatest']]);

		}

		/**
		 * Show the forecasting data to the user.
		 *
		 * @return Response
		 */
		public function forecastingDataOutput()
		{

			return Storage::get('forecasting_data_low_bins.txt');

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
		*	forecastingDataCache()
		*	Cache data Builder for forecasting page
		* Method to use for cron job
		*/
		public function forecastingDataCache()
		{

				$farms = $this->enabledFarms();
				$forecastingData = $this->farmsDataBuilder($farms);

				// cache data via sort type low bins
				usort($forecastingData, function($a,$b){
					if($a['bins'][0]['empty_bins'] == $b['bins'][0]['empty_bins'])
					return ($a['bins'][0]['first_list_days_to_empty'] > $b['bins'][0]['first_list_days_to_empty']);
					return ($a['bins'][0]['empty_bins'] < $b['bins'][0]['empty_bins'])?1:-1;
				});
				Storage::put('forecasting_data_low_bins.txt',NULL);
				Storage::put('forecasting_data_low_bins.txt',json_encode($forecastingData));

				// cache data via sort type a-z farms
				usort($forecastingData, function($a,$b){
					return strcasecmp($a["name"], $b["name"]);
				});
				Storage::put('forecasting_data_a_to_z.txt',NULL);
				Storage::put('forecasting_data_a_to_z.txt',json_encode($forecastingData));

				// execute the farrowing farms data cache builder
				$this->forecastingFarrowingDataCache();

				return $forecastingData;//"done caching";

		}

		/*
		*		forecastingFarrowingDataCache
		*		Building farms forecasting data exclusive for farrowing farms only
		*/
		private function forecastingFarrowingDataCache()
		{

				$farms = Farms::where('farm_type','farrowing')->where('status', 1)->orderBy('name')->get();
				$forecastingData = $this->farmsDataBuilder($farms);

				// cache data via sort type low bins
				usort($forecastingData, function($a,$b){
					if($a['bins'][0]['empty_bins'] == $b['bins'][0]['empty_bins'])
					return ($a['bins'][0]['first_list_days_to_empty'] > $b['bins'][0]['first_list_days_to_empty']);
					return ($a['bins'][0]['empty_bins'] < $b['bins'][0]['empty_bins'])?1:-1;
				});
				Storage::put('forecasting_farrowing_data_low_bins.txt',json_encode($forecastingData));

				// cache data via sort type a-z farms
				usort($forecastingData, function($a,$b){
					return strcasecmp($a["name"], $b["name"]);
				});
				Storage::put('forecasting_farrowing_data_a_to_z.txt',json_encode($forecastingData));

				return "done caching farrowing<br/>";

		}

		/*
		*		farmsDataBuilder
		*		farms databuilder for forecasting cache
		*		and building farms data exclusive for forecasting only
		*/
		private function farmsDataBuilder($farms)
		{
				$forecastingData = array();
				$bins_data = array();

				for($i=0; $i<count($farms); $i++){
					Cache::forget('farm_holder-'.$farms[$i]['id']);
					if(Cache::has('farm_holder-'.$farms[$i]['id'])) {

						 $forecastingData[] = Cache::get('farm_holder-'.$farms[$i]['id'])[$i];

					} else {
						if($this->binsDataFirstLoadv2($farms[$i]['id'],$farms[$i]['update_notification']) != NULL){
							$bins_data = $this->binsDataFirstLoadv2($farms[$i]['id'],$farms[$i]['update_notification']) + array('notes'=>$farms[$i]['notes']);
						} else {
							$bins_data = NULL;
						}

						$forecastingData[] = array(
							'farm_id'					=>	$farms[$i]['id'],
							'name'						=>	$farms[$i]['name'],
							'farm_type'				=>	$farms[$i]['farm_type'],
							'delivery_status'	=>	$this->pendingDeliveryItems($farms[$i]['id']),
							'address'					=>	$farms[$i]['address'],
							'bins'						=> 	$bins_data
						);

						Cache::forever('farm_holder-'.$farms[$i]['id'],$forecastingData);

					}

				}

				return $forecastingData;
		}

	/*
	*	curLForecastingData()
	* curl method for forecasting data cache
	*/
	function curLForecastingData()
	{
		// create curl resource
      $ch = curl_init();

      // set url
      curl_setopt($ch, CURLOPT_URL, 'http://'.env('APP_DOMAIN')."/forecastingdatacache");

      //return the transfer as a string
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

      // $output contains the output string
      $output = curl_exec($ch);
      echo $output;
      // close curl resource to free up system resources
      curl_close($ch);
	}


	/*
	*	Load more
	*/
	public function farmsLoadMore()
	{
		$counter = Input::get('counter') + 1;
		$sort_type = Cache::store('file')->get('sort_type');

		// get the cached data
		if($sort_type == NULL || $sort_type == 1){
			$forecastingDataCache = Cache::store('file')->get('forecastingData_1');
		} else {
			$forecastingDataCache = Cache::store('file')->get('forecastingData_2');
		}

		$forecastingData = array_chunk($forecastingDataCache,10,true);

		$chunk = count($forecastingData);

		if($counter == $chunk){
			return NULL;
		}

		$forecastingData = $forecastingData[$counter];

		return view('home.ajax.farms',compact("forecastingData","counter","chunk"));

	}

	/*
	*	Truck update for Pending deilveries
	*/
	public function updateTruckPending(){

		$truck_id = Input::get('truck_id');

		$pending_delivries = PendingDeliveries::get()->toArray();

		if($pending_delivries != NULL){

			foreach($pending_delivries as $k => $v){
				PendingDeliveries::where('farm_id',$v['farm_id'])->update(['truck_id'=>$truck_id]);
			}

			$output = 1;

		} else {

			$output = 0;

		}

		return $output;
	}


	/*
	*	Cache forecasting data builder
	*/
	public function forecastingDataCacheBuilder($sort_type = NULL){

		Cache::forget('forecastingData_1');
		Cache::forget('forecastingData_2');
		$forecastingData = NULL;


		// cache data via sort type low bins
		$sort_type = Cache::store('file')->get('sort_type');
		if($sort_type == NULL || $sort_type == 1) {

			if(Cache::forever('forecastingData_1',$forecastingData)){
				echo "Cached";
			}

		// cache data via sort type a-z farms
		} else {

			if(Cache::forever('forecastingData_2',$forecastingData)){
				echo "Cached";
			}
		}

		echo "finished";

		//$this->curlBinsCache();

	}

	/*
	*	forecasting bins
	*/
	public function forecastingBins(){
		$farm_id = Input::get('farm_id');

		$farm_data = Farms::where('id','=',$farm_id)->get()->toArray();
		$farms_list = $this->farmsLists();
		$bins_data = $this->binsData($farm_id);

		return view("home.ajax.bins",compact("bins_data","farm_data","farm_id","farms_list"));

	}


	/*
	*	farms lists for add batch
	*/
	private function farmsLists(){
		Cache::forget('farms_lists');
		$farms_lists_cache = Cache::store('file')->get('farms_lists');

		if($farms_lists_cache == NULL){
			$farms_lists = DB::table('feeds_farms')->orderBy('name','asc')->lists('name','id');
			$output = array(''=>'Please Select') + $farms_lists;
			Cache::forever('farms_lists',$output);
			$farms_lists_cache = Cache::store('file')->get('farms_lists');
		}

		return $farms_lists_cache;
	}

	/*
	*	bins lists for add batch
	*/
	private function binsLists(){
		Cache::forget('bins_lists');
		$bins_lists_cache = Cache::store('file')->get('bins_lists');

		if($bins_lists_cache == NULL){
			$bins_lists = DB::table('feeds_bins')->lists('bin_number','bin_id');
			$output = array(''=>'Please Select') + $bins_lists;
			Cache::forever('bins_lists',$output);
			$bins_lists_cache = Cache::store('file')->get('bins_lists');
		}

		return $bins_lists_cache;
	}

	/*
	*	filetered bins lists
	*/
	public function binsListsFiltered(){

		$farm_id = Input::get('farmID');
		$output = DB::table('feeds_bins')
					->select(DB::raw("CONCAT(bin_number,'-',alias) as bin_number"),'bin_id')
					->where('farm_id','=',$farm_id)
					->lists('bin_number','bin_id');

		$bin_id = Bins::select('bin_id')->where('farm_id',$farm_id)->take(1)->get()->toArray();

		if($output == NULL){
			return $output = array(''=>'Please Select');
		}

		return $output = array(
							'bins'		=>	$output,
							'bin_id'	=>	$bin_id[0]['bin_id']
							);
	}

	/**
	**
	** filetered feeds lists
	**
	**/
	public function feedsListsFiltered() {

		$bin_id = Input::get('binID');

		if($bin_id == NULL){
			return  array(''=>'Please Select');
		}

		// get the feed type then fetch the feed type and build the feeds lists
		$feed_type_default = Bins::select('feed_type')
					->where('bin_id','=',$bin_id)
					->first();

		$feed_type_history = BinsHistory::select('feed_type')
										->where('bin_id',$bin_id)
										->orderBy('update_date','desc')
										->first();

		$feed_type = !empty($feed_type_history->feed_type) ? $feed_type_history->feed_type : $feed_type_default->feed_type;

		$feed_types_lists = FeedTypes::lists('name','type_id');



		return  array(
							'feeds'		=>	$feed_types_lists,
							'feed_id'	=>	$feed_type
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
	*	Insert the number of pigs on the bin history
	*/
	public function insertHistoryPigs()
	{

		$bin = Input::get('bin');
		$farm_id = Input::get('farm_id');
		$numpigs = Input::get('numpigs');
		$animal_unique_id = Input::get('animal_unique_id');

		$updateBin = array();

		foreach($numpigs as $k => $v){

			$updateBin[] = $this->fetchBinAnimalGroup($animal_unique_id[$k],$v,$farm_id,$bin);

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
	*	Insert the number of pigs on the bin history
	*/
	public function updateRoomPigsAPI($farm_id,$room,$numpigs,$animal_unique_id,$user_id)
	{


		$updateBin = array();
		$sum_pigs = 0;
		foreach($numpigs as $k => $v){
			$sum_pigs = $sum_pigs + $v;
			$updateBin[] = $this->fetchRoomAnimalGroupAPI($animal_unique_id[$k],$v,$farm_id,$room,$user_id);
		}

		$output = array();
		foreach($updateBin as $k => $v){

			$output[] = array(
				'bin'	=>	$v[0]->room_id,
				'msg' => "Room was successfully Updated!",
				'empty' => "",
				'daystoemp' => 0,
				'numofpigs' => 0,
				'percentage' => 0,
				'color' => "",
				'text' => "",
				'tdy' => date('M d'),
				'unique_id'	=>	$v[0]->unique_id,
				'total_number_of_pigs'	=>	$sum_pigs
			);

		}

		return array($output[0]);

	}



	/*
	*	get the bins in Animal Group for farrowing
	*/
	private function fetchRoomAnimalGroupAPI($unique_id,$number_of_pigs,$farm_id,$room_id,$user_id)
	{

      DB::table('feeds_movement_groups_bins')
        ->where('unique_id',$unique_id)
        ->where('room_id',$room_id)
        ->update(['number_of_pigs'=>$number_of_pigs]);

		$groups = DB::table('feeds_movement_groups_bins')
								->where('unique_id',$unique_id)
								->where('room_id',$room_id)
								->get();


		return $groups;

	}


	/*
	* Empty date
	*/
	public function emptyDateAPI($d_date,$bin_id,$num_of_pigs,$budgeted_amount,$amount)
	{
		$bin_capacity = $this->currentBinCapacity($bin_id) + ($amount * 2000);
		$days_of_bins = $this->daysOfBinsAPI($bin_capacity,$budgeted_amount,$num_of_pigs);
		$empty_date = $this->emptyDateForAPI($days_of_bins,$d_date);

		return $empty_date;
	}

	/*
	*	daysOfBins()
	*	Current Bin Amount / budgeted amount
	*/
	public function daysOfBinsAPI($currentBinAmount,$budgetedAmount,$numOfPigs){

		$currentBinAmount = (int)$currentBinAmount;
		if($currentBinAmount != NULL && $budgetedAmount != NULL){
			$result_one = ($budgetedAmount*$numOfPigs);
			$daysOfBins = @($currentBinAmount/$result_one);
			$daysOfBins = (int)round($daysOfBins,0);
		} else {
			$daysOfBins = 0;
		}

		// $output = array(
		// 	'c_b_amount'	=> $currentBinAmount,
		// 	'b_amount'	=> $budgetedAmount,
		// 	'heads'	=> $numOfPigs,
		// 	'ba_and_heads' => $result_one,
		// 	'days_of_bins'	=>  $daysOfBins,
		// );
		//
		// return $output;

		return $daysOfBins;
	}

	/*
	*	Empty date
	*/
	public function emptyDateForAPI($days,$d_date){

		$now = time(); // or your date as well
		$your_date = strtotime($d_date);
		$datediff = $your_date - $now;

		if($your_date > $now){
			$days_btw = round($datediff / (60 * 60 * 24));
			$days = ($days + (int)$days_btw);
		}

		$emptyDate = Carbon::now()->addDays($days)->format('m-d-Y');
		$soon = Carbon::now()->addDays($days)->format('M d Y');

		if($emptyDate == Carbon::now()->format('m-d-Y')){
			$output = "Empty";
		} else {
			$output = $soon;
		}

		return $output;
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
	*	Update Animal Group Farrowing
	*/
	private function updateAnimalGroupFarrowing($number_of_pigs,$unique_id,$bin_id)
	{

		DB::table('feeds_movement_farrowing_bins')
			->where('unique_id',$unique_id)
			->where('bin_id',$bin_id)
			->update(['number_of_pigs'=>$number_of_pigs]);

	}

	/*
	*	Update Animal Group Nursery
	*/
	private function updateAnimalGroupNursery($number_of_pigs,$unique_id,$bin_id)
	{

		DB::table('feeds_movement_nursery_bins')
			->where('unique_id',$unique_id)
			->where('bin_id',$bin_id)
			->update(['number_of_pigs'=>$number_of_pigs]);

	}

	/*
	*	Update Animal Group Finisher
	*/
	private function updateAnimalGroupFinisher($number_of_pigs,$unique_id,$bin_id)
	{

		DB::table('feeds_movement_finisher_bins')
			->where('unique_id',$unique_id)
			->where('bin_id',$bin_id)
			->update(['number_of_pigs'=>$number_of_pigs]);

	}


	/*
	*	get the bins in Animal Group for farrowing
	*/
	private function fetchBinAnimalGroup($unique_id,$number_of_pigs,$farm_id,$bin_id)
	{

		// check the farm type
		$type = $this->farmTypes($farm_id);

		if($type == 'farrowing'){
			$bin = $this->fetchBinAnimalGroupFarrowing($unique_id,$bin_id);
			// update the animal group
			$this->updateAnimalGroupFarrowing($number_of_pigs,$unique_id,$bin_id);
		} elseif ($type == 'nursery') {
			$bin = $this->fetchBinAnimalGroupNursery($unique_id);
			// update the animal group
			$this->updateAnimalGroupNursery($number_of_pigs,$unique_id,$bin_id);
		} elseif ($type == 'finisher') {
			$bin = $this->fetchBinAnimalGroupFinisher($unique_id);
			// update the animal group
			$this->updateAnimalGroupFinisher($number_of_pigs,$unique_id,$bin_id);
		} else {
			$bin = NULL;
			return NULL;
		}

		$update = array();
		//foreach($bin as $k => $v){
			// update the bin history
			//$update[] = $this->updateBinsHistoryNumberOfPigs($number_of_pigs,$v->bin_id,$unique_id);
		//}
		$update[] = $this->updateBinsHistoryNumberOfPigs($number_of_pigs,$bin_id,$unique_id);

		return $update;

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
	*	get the bins in Animal Group for farrowing
	*/
	private function fetchBinAnimalGroupFarrowing($unique_id,$bin_id)
	{
		Cache::forget('bin-'.$bin_id);
		$bin = DB::table('feeds_movement_farrowing_bins')
			->where('unique_id',$unique_id)
			->where('bin_id',$bin_id)
			->get();

		return $bin;
	}

	/*
	*	get the bins in Animal Group for nursery
	*/
	private function fetchBinAnimalGroupNursery($unique_id)
	{
		$bin = DB::table('feeds_movement_nursery_bins')
			->where('unique_id',$unique_id)
			->get();

		return $bin;
	}

	/*
	*	get the bins in Animal Group for farrowing
	*/
	private function fetchBinAnimalGroupFinisher($unique_id)
	{
		$bin = DB::table('feeds_movement_finisher_bins')
			->where('unique_id',$unique_id)
			->get();

		return $bin;
	}

	/*
	*	Update the bin history for update number of pigs
	*/
	private function updateBinsHistoryNumberOfPigs($number_of_pigs,$bin_id,$unique_id)
	{

		$bininfo = $this->getBinDefaultInfo($bin_id);
		$lastupdate = $this->getLastHistory($bininfo);

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
    $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bin_id,$bininfo[0]->farm_id); //$number_of_pigs;

		$budgeted_amount = $this->daysCounterbudgetedAmount($bininfo[0]->farm_id,$bin_id,$lastupdate[0]->feed_type,date("Y-m-d H:i:s"));

		$data = array(
				'update_date' => date("Y-m-d H:i:s"),
				'bin_id' => $bin_id,
				'farm_id' => $bininfo[0]->farm_id,
				'num_of_pigs' => $total_number_of_pigs,
				'user_id' => Auth::id(),
				'amount' => $lastupdate[0]->amount,
				'update_type' => 'Manual Update Number of Pigs Forecasting Admin',
				'created_at' => date("Y-m-d H:i:s"),
				'updated_at' => date("Y-m-d H:i:s"),
				'budgeted_amount' => $budgeted_amount,//$lastupdate[0]->budgeted_amount,
				'remaining_amount' => $lastupdate[0]->remaining_amount,
				'sub_amount' => $lastupdate[0]->sub_amount,
				'variance' => $variance,
				'consumption' => $consumption,
				'admin' => Auth::id(),
				'medication' => !empty($lastupdate[0]->medication) ? $lastupdate[0]->medication : 0,
				'feed_type' => $lastupdate[0]->feed_type,
				'unique_id'	=> !empty($lastupdate[0]->unique_id) ? $lastupdate[0]->unique_id : "none"
			);

		BinsHistory::insert($data);

		// groups consumption
		$this->updateGroupsConsumption($bin_id,$lastupdate[0]->amount,"manual");

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

		// groups consumption
		$this->updateGroupsConsumption($bin_id,$lastupdate[0]->amount,"manual");

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
		// remove cache on forecasting 1st data load
		Cache::forget('farm_holder_bins_data-'.$v->bin_id);

		return array(
			'bin'					=>	$bin_id,
			'numofpigs_'			=>	$number_of_pigs,
			'total_number_of_pigs'	=>	$total_number_of_pigs,
			'budgeted_'				=>	$budgeted_,
			'daysto'				=>	$daysto,
			'animal_unique_id'		=>	$unique_id
		);

	}

	/*
	*	totalNumberOfPigsAnimalGroup
	*	get the total number of pigs based on the animal groups bin
	*/
	private function totalNumberOfPigsAnimalGroup($bin_id,$farm_id)
	{
		// check the farm type
		$type = $this->farmTypes($farm_id);
		$total_pigs = 0;

		if($type == 'farrowing'){

			$unique_id = $this->activeGroups('feeds_movement_farrowing_group');
			if($unique_id != NULL){
				$total_pigs = $this->farrowingTotalNumberOfPigs($bin_id,$unique_id);
			}

		} elseif ($type == 'nursery') {

			$unique_id = $this->activeGroups('feeds_movement_nursery_group');
			if($unique_id != NULL){
				$total_pigs = $this->nurseryTotalNumberOfPigs($bin_id,$unique_id);
			}

		} elseif ($type == 'finisher') {

			$unique_id = $this->activeGroups('feeds_movement_finisher_group');
			if($unique_id != NULL){
				$total_pigs = $this->finisherTotalNumberOfPigs($bin_id,$unique_id);
			}

		} else {
			return $total_pigs;
		}

		return $total_pigs != NULL ? $total_pigs : 0;

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
											->where('created_at','!=','0000-00-00 00:00:00')
											->get();
		$active_groups = $this->toArray($active_groups);

		if($active_groups != NULL){
			return $active_groups;
		}

		return $active_groups;
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
	*	farrowingTotalNumberOfPigsAnimalGroup
	*	get the total number of pigs based on the animal groups bin
	*/
	private function farrowingTotalNumberOfPigs($bin_id,$unique_id)
	{
		$sum = DB::table('feeds_movement_farrowing_bins')
						->where('bin_id',$bin_id)
						->whereIn('unique_id',$unique_id)
						->sum('number_of_pigs');

		return $sum;
	}

	/*
	*	nurseryTotalNumberOfPigsAnimalGroup
	*	get the total number of pigs based on the animal groups bin
	*/
	private function nurseryTotalNumberOfPigs($bin_id,$unique_id)
	{
		$sum = DB::table('feeds_movement_nursery_bins')
						->where('bin_id',$bin_id)
						->whereIn('unique_id',$unique_id)
						->sum('number_of_pigs');

		return $sum;
	}

	/*
	*	finisherTotalNumberOfPigsAnimalGroup
	*	get the total number of pigs based on the animal groups bin
	*/
	private function finisherTotalNumberOfPigs($bin_id,$unique_id)
	{
		$sum = DB::table('feeds_movement_finisher_bins')
						->where('bin_id',$bin_id)
						->whereIn('unique_id',$unique_id)
						->sum('number_of_pigs');

		return $sum;
	}

	/*
	*	insertBinConsumptionHistory
	*	record the consumption history of the feeds bin amounts
	*/
	private function insertBinConsumptionHistory($bin_id,$farm_id,$feed_type,$amount)
	{
		$unique_id = $this->generator();

		$bin_consumption_history = array(
			'update_date'			=>	date('Y-m-d'),
			'farm_id'					=>	$farm_id,
			'bin_id'					=>	$bin_id,
			'feed_type'				=>	$feed_type,
			'amount'					=>	$amount,
			'unique_id'				=>	$unique_id
		);

		$group_data = array();
		//$group = $this->animalGroup($bin_id,$farm_id);
    $group = $this->animalGroupAPI($bin_id,$farm_id);
		$pigs = 0;
		if($group != NULL){
			foreach($group as $k=>$v){
				$pigs = $pigs + $v['number_of_pigs'];
				$group_data[] = array(
					'group_id'					=>	$v['group_id'],
					'type'							=>	$v['type'],
					'group_unique_id'		=>	$v['unique_id'],
					'unique_id'					=>	$unique_id
				);
			}

			if($pigs != 0){
				DB::table('feeds_bin_consumption_groups')->insert($group_data);
				DB::table('feeds_bin_consumption_history')->insert($bin_consumption_history);
			}
		}

	}

	/*
	*	Update Bin
	*	update the current bin based on yesterday or today's update on forecasting
	*/
	public function insertHistoryBin() {

		$msg = "OK";
		$yesterday = 0;

		// update today
		$lastupdate = $this->todayBinUpdate($_POST['bin']);
		//$amount = $lastupdate != NULL ? $lastupdate[0]['amount'] : 0;
		//$this->insertBinConsumptionHistory($_POST['bin'],$lastupdate[0]['farm_id'],$lastupdate[0]['feed_type'],$amount);
		$amount = $lastupdate[0]['amount'] - $_POST['amount'];
		if($lastupdate[0]['amount'] < $_POST['amount']){
				$amount = str_replace("-","",$amount);
		} else {
				$amount = "-".$amount;
		}
		//$this->insertBinConsumptionHistory($_POST['bin'],$lastupdate[0]['farm_id'],$lastupdate[0]['feed_type'],$amount);

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
				'update_date' => date("Y-m-d H:i:s"),
				'bin_id' => $_POST['bin'],
				'farm_id' => $lastupdate[0]['farm_id'],
				'num_of_pigs' => $lastupdate[0]['num_of_pigs'],
				'user_id' => Auth::id(),
				'amount' => $_POST['amount'],
				'update_type' => 'Manual Update Bin Forecasting Admin',
				'created_at' => date("Y-m-d H:i:s"),
				'budgeted_amount' => $budgeted_amount,//$feeds[0]['budgeted_amount'],//$lastupdate[0]['budgeted_amount'],
				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
				'actual_amount_tons'	=>	$_POST['amount'],
				'remaining_amount' => $lastupdate[0]['remaining_amount'],
				'sub_amount' => $lastupdate[0]['sub_amount'],
				'variance' => $variance,
				'consumption' => $actual_consumption_per_pig,
				'admin' => Auth::id(),
				'feed_type'	=>	!empty($lastupdate[0]['feed_type']) ? $lastupdate[0]['feed_type'] : 51,
				'unique_id'	=>	!empty($lastupdate[0]['unique_id']) ? $lastupdate[0]['unique_id'] : 'none'
		);

		if($yesterday == 0){
			BinsHistory::where('history_id','=',$lastupdate[0]['history_id'])->update($bin_history_data);
		}else{
			BinsHistory::insert($bin_history_data);
		}

		// groups consumption
		$this->updateGroupsConsumption($_POST['bin'],$_POST['amount'],"manual");


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
		$budgeted_ = $lastupdate[0]['budgeted_amount'] != NULL ? $lastupdate[0]['budgeted_amount'] : $feeds[0]['budgeted_amount'];
		if($numofpigs_ != 0){
			$daysto = round($_POST['amount'] * 2000 / ($numofpigs_ * $budgeted_),0);
		} else {
			$daysto = 0;
		}

		// send mobile notification
		// $mobile_data = array(
		// 	'bin_id'			=>	!empty($bins[0]['bin_number']) ? $bins[0]['bin_number'] : 0,  //bin number
		// 	'farm_id'			=>	$bin_history_data['farm_id'],
		// 	'user_id'			=>	0,
		// 	'current_amount'	=>	$bin_history_data['amount'],
		// 	'created_at'		=>	date('Y-m-d H:i:s'),
		// 	'budgeted_amount'	=>	$budgeted_amount,//$bin_history_data['budgeted_amount'],
		// 	'actual_amount'		=>	$bin_history_data['amount'],
		// 	'bin_size'			=>	$bin_size[0]['ring'],
		// 	'variance'			=>	$variance,
		// 	'consumption'		=>	$actual_consumption_per_pig,
		// 	'feed_type'			=>	$bin_history_data['feed_type'],
		// 	'medication'		=>	!empty($medication[0]['med_id']) ? $medication[0]['med_id'] : 0,
		// 	'med_name'			=>	!empty($medication[0]['med_name']) ? $medication[0]['med_name'] : 'No Medicaiton',
		// 	'feed_name'			=>	!empty($feeds[0]['name']) ? $feeds[0]['name'] : '-',
		// 	'user_created_at'	=>	date('Y-m-d H:i:s'),
		// 	'num_of_pigs'		=>	$bin_history_data['num_of_pigs'],
		// 	'bin_no_id'			=>	$bin_history_data['bin_id'], // bin id
		// 	'status'			=>	2,
		// 	'unique_id'			=>	!empty($bin_history_data['unique_id']) ? $bin_history_data['unique_id'] : "none"
		// );


		$history_id = !empty($lastupdate[0]['history_id']) ? $lastupdate[0]['history_id'] : NULL;
		// $this->mobileSaveAccepted($mobile_data);
		//
		// $notification = new CloudMessaging;
		// $farmer_data = array(
		// 	'update_date'		=>	date('Y-m-d H:i:s'),
		// 	'bin_id'			=>	$mobile_data['bin_id'],
		// 	'farm_id'			=>	$mobile_data['farm_id'],
		// 	'num_of_pigs'		=>	$mobile_data['num_of_pigs'],
		// 	'user_id'			=>	$mobile_data['user_id'],
		// 	'amount'			=>	$mobile_data['current_amount'],
		// 	'update_type'		=>	"Manual Update Bin Forecasting Admin",
		// 	'created_at'		=>	$mobile_data['created_at'],
		// 	'updated_at'		=>	date('Y-m-d H:i:s'),
		// 	'budgeted_amount'	=>	$budgeted_amount,//$mobile_data['budgeted_amount'],
		// 	'remaining_amount'	=>	$lastupdate[0]['remaining_amount'],
		// 	'sub_amount'		=>	$lastupdate[0]['sub_amount'],
		// 	'variance'			=>	round($mobile_data['variance'],2),
		// 	'consumption'		=>	round($mobile_data['consumption'],2),
		// 	'admin'				=>	2,
		// 	'medication'		=>	$mobile_data['medication'],
		// 	'feed_type'			=>	$mobile_data['feed_type']
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

		$this->binDataRebuildCache($_POST['bin']);
		$this->clearBinsCache($_POST['bin']);
		$this->farmHolderBinClearCache($_POST['bin']);


		return json_encode(array(

			'msg' 				=> 	$msg,
			'empty' 			=> 	$this->emptyDate($daysto),
			'daystoemp' 	=> 	$daysto,
			'percentage' 	=> 	$perc,
			'color' 			=> 	$color,
			'text' 				=> 	$text,
			'tdy' 				=> 	date('M d'),
			//'avg_variance'	=>	$avg_variance,
			//'avg_actual'	=>	$avg_actual,
			'lastUpdate'	=>	date("M d"),
			//'user'				=> Auth::user()->username,
			'farm_id'			=> $bin_history_data['farm_id']
		));


	}

	/*
	*	Update Bin
	*	update the current bin based on yesterday or today's update on forecasting
	*/
	public function updateBin() {

		$msg = "OK";
		$yesterday = 0;

		// update today
		$lastupdate = $this->todayBinUpdate($_POST['bin']);
		//$amount = $lastupdate != NULL ? $lastupdate[0]['amount'] : 0;
		//$this->insertBinConsumptionHistory($_POST['bin'],$lastupdate[0]['farm_id'],$lastupdate[0]['feed_type'],$amount);
		$amount = $lastupdate[0]['amount'] - $_POST['amount'];
		if($lastupdate[0]['amount'] < $_POST['amount']){
				$amount = str_replace("-","",$amount);
		} else {
				$amount = "-".$amount;
		}
		$this->insertBinConsumptionHistory($_POST['bin'],$lastupdate[0]['farm_id'],$lastupdate[0]['feed_type'],$amount);

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
				'update_date' => date("Y-m-d H:i:s"),
				'bin_id' => $_POST['bin'],
				'farm_id' => $lastupdate[0]['farm_id'],
				'num_of_pigs' => $lastupdate[0]['num_of_pigs'],
				'user_id' => Auth::id(),
				'amount' => $_POST['amount'],
				'update_type' => 'Manual Update Bin Forecasting Admin',
				'created_at' => date("Y-m-d H:i:s"),
				'budgeted_amount' => $budgeted_amount,//$feeds[0]['budgeted_amount'],//$lastupdate[0]['budgeted_amount'],
				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
				'actual_amount_tons'	=>	$_POST['amount'],
				'remaining_amount' => $lastupdate[0]['remaining_amount'],
				'sub_amount' => $lastupdate[0]['sub_amount'],
				'variance' => $variance,
				'consumption' => $actual_consumption_per_pig,
				'admin' => Auth::id(),
				'feed_type'	=>	!empty($lastupdate[0]['feed_type']) ? $lastupdate[0]['feed_type'] : 51,
				'unique_id'	=>	!empty($lastupdate[0]['unique_id']) ? $lastupdate[0]['unique_id'] : 'none'
		);

		if($yesterday == 0){
			BinsHistory::where('history_id','=',$lastupdate[0]['history_id'])->update($bin_history_data);
		}else{
			BinsHistory::insert($bin_history_data);
		}

		// groups consumption
		$this->updateGroupsConsumption($_POST['bin'],$_POST['amount'],"manual");


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
		$budgeted_ = $lastupdate[0]['budgeted_amount'] != NULL ? $lastupdate[0]['budgeted_amount'] : $feeds[0]['budgeted_amount'];
		if($numofpigs_ != 0){
			$daysto = round($_POST['amount'] * 2000 / ($numofpigs_ * $budgeted_),0);
		} else {
			$daysto = 0;
		}

		// send mobile notification
		// $mobile_data = array(
		// 	'bin_id'			=>	!empty($bins[0]['bin_number']) ? $bins[0]['bin_number'] : 0,  //bin number
		// 	'farm_id'			=>	$bin_history_data['farm_id'],
		// 	'user_id'			=>	0,
		// 	'current_amount'	=>	$bin_history_data['amount'],
		// 	'created_at'		=>	date('Y-m-d H:i:s'),
		// 	'budgeted_amount'	=>	$budgeted_amount,//$bin_history_data['budgeted_amount'],
		// 	'actual_amount'		=>	$bin_history_data['amount'],
		// 	'bin_size'			=>	$bin_size[0]['ring'],
		// 	'variance'			=>	$variance,
		// 	'consumption'		=>	$actual_consumption_per_pig,
		// 	'feed_type'			=>	$bin_history_data['feed_type'],
		// 	'medication'		=>	!empty($medication[0]['med_id']) ? $medication[0]['med_id'] : 0,
		// 	'med_name'			=>	!empty($medication[0]['med_name']) ? $medication[0]['med_name'] : 'No Medicaiton',
		// 	'feed_name'			=>	!empty($feeds[0]['name']) ? $feeds[0]['name'] : '-',
		// 	'user_created_at'	=>	date('Y-m-d H:i:s'),
		// 	'num_of_pigs'		=>	$bin_history_data['num_of_pigs'],
		// 	'bin_no_id'			=>	$bin_history_data['bin_id'], // bin id
		// 	'status'			=>	2,
		// 	'unique_id'			=>	!empty($bin_history_data['unique_id']) ? $bin_history_data['unique_id'] : "none"
		// );


		$history_id = !empty($lastupdate[0]['history_id']) ? $lastupdate[0]['history_id'] : NULL;
		// $this->mobileSaveAccepted($mobile_data);

		// $notification = new CloudMessaging;
		// $farmer_data = array(
		// 	'update_date'		=>	date('Y-m-d H:i:s'),
		// 	'bin_id'			=>	$mobile_data['bin_id'],
		// 	'farm_id'			=>	$mobile_data['farm_id'],
		// 	'num_of_pigs'		=>	$mobile_data['num_of_pigs'],
		// 	'user_id'			=>	$mobile_data['user_id'],
		// 	'amount'			=>	$mobile_data['current_amount'],
		// 	'update_type'		=>	"Manual Update Bin Forecasting Admin",
		// 	'created_at'		=>	$mobile_data['created_at'],
		// 	'updated_at'		=>	date('Y-m-d H:i:s'),
		// 	'budgeted_amount'	=>	$budgeted_amount,//$mobile_data['budgeted_amount'],
		// 	'remaining_amount'	=>	$lastupdate[0]['remaining_amount'],
		// 	'sub_amount'		=>	$lastupdate[0]['sub_amount'],
		// 	'variance'			=>	round($mobile_data['variance'],2),
		// 	'consumption'		=>	round($mobile_data['consumption'],2),
		// 	'admin'				=>	2,
		// 	'medication'		=>	$mobile_data['medication'],
		// 	'feed_type'			=>	$mobile_data['feed_type']
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

		$this->binDataRebuildCache($_POST['bin']);
		$this->clearBinsCache($_POST['bin']);
		$this->farmHolderBinClearCache($_POST['bin']);


		return json_encode(array(

			'msg' 				=> 	$msg,
			'empty' 			=> 	$this->emptyDate($daysto),
			'daystoemp' 	=> 	$daysto,
			'percentage' 	=> 	$perc,
			'color' 			=> 	$color,
			'text' 				=> 	$text,
			'tdy' 				=> 	date('M d'),
			//'avg_variance'	=>	$avg_variance,
			//'avg_actual'	=>	$avg_actual,
			'lastUpdate'	=>	date("M d"),
			//'user'				=> Auth::user()->username,
			'farm_id'			=> $bin_history_data['farm_id']
		));


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
				'budgeted_amount' 			=> 	$budgeted_amount,//$feeds[0]['budgeted_amount'],//$lastupdate[0]['budgeted_amount'],
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

		// groups consumption
		$this->updateGroupsConsumption($_POST['bin'],$_POST['amount'],"manual");

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

		$history_id = !empty($lastupdate[0]['history_id']) ? $lastupdate[0]['history_id'] : NULL;

		Cache::forget('bin-'.$_POST['bin']);
		Cache::forget('bins-'.$_POST['bin']);

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
	*	Update Groups Consumption
	*	update the current groups number of pigs based on yesterday
	* or today's update on forecasting
	*/
	public function updateGroupsConsumption($bin_id,$amount,$type) {

		// get the groups
		$groups = DB::table("feeds_movement_groups_bins")
							->where("bin_id",$bin_id)
							->select("unique_id")
							->distinct()
							->get();

		if(count($groups) <= 0){
			return "No Consumption";
		}

		$amount = $amount * 2000;


		for($i=0; $i < count($groups); $i++){

				$g_data = DB::table("feeds_movement_groups")
								->where("unique_id",$groups[$i]->unique_id)
								->whereIn("status",["entered","created"])
								->where("created_at","!=","0000-00-00 00:00:00")
								->select("unique_id","group_id")
								->first();

				if(count($this->toArray($g_data)) <= 0){
					return "No Consumption";
				}

				// get the total number of pigs per group inside the group bin
				$total_pigs = DB::table("feeds_movement_groups_bins")
												->where("unique_id",$g_data->unique_id)
												->sum("number_of_pigs");

				// if the bin_history is empty fetch the default feed type on the feed type table else fetch
				// the feed type on bin_history
				$bin_history = BinsHistory::where("bin_id",$bin_id)
																	->orderBy("update_date","desc")
																	->select("budgeted_amount","feed_type")
																	->first();



				if($total_pigs == 0){
					$budgeted_amount_lbs = 0;
				}	else {
					$budgeted_amount_lbs = $amount - ($bin_history->budgeted_amount * $total_pigs);
					if($budgeted_amount_lbs < 0) {
						$budgeted_amount_lbs = 0;
					}
				}

				$cons_lbs = $bin_history->budgeted_amount * $total_pigs;

				$groups_consumption_data[] = array(
					'update_date'	=>	date("Y-m-d"),
					'group_id'	=>	$g_data->group_id,
					'feed_type'	=>	$bin_history->feed_type,
					'budgeted_amount'	=>	$bin_history->budgeted_amount,
					'budgeted_consumption_lbs' => $cons_lbs,
					'budgeted_amount_tons'	=>	round($cons_lbs / 2000,2),
					// 'consumption_lbs_remaining'	=>	round($budgeted_amount_lbs,2),
					// 'consumption_tons_remaining'	=>	round($budgeted_amount_lbs / 2000,2),
					'actual_consumption_lbs'	=>	$cons_lbs,
					'actual_amount_tons'	=>	round($cons_lbs / 2000,2),
					'total_pigs'	=>	$total_pigs
				);

				$amount = $budgeted_amount_lbs;

		}



		for($i=0; $i < count($groups_consumption_data); $i++){
			$groups_cons_history = DB::table("feeds_movement_groups_consumption")
																->where("group_id",$groups_consumption_data[$i]['group_id'])
																->where("update_date",date("Y-m-d"))
																->get();

			// manual (insert) select the previous data and build the new data to insert
			if($type == "manual"){
				$budgeted_consumption_lbs = $groups_cons_history[0]->budgeted_consumption_lbs;
				$budgeted_amount_tons = $groups_cons_history[0]->budgeted_amount_tons;
			} else {
				$budgeted_consumption_lbs = round($groups_consumption_data[$i]['budgeted_consumption_lbs'],2);
				$budgeted_amount_tons = $groups_consumption_data[$i]['budgeted_amount_tons'];
			}

			$d_insert = array(
				'update_date'	=>	date("Y-m-d"),
				'group_id'	=>	$groups_consumption_data[$i]['group_id'],
				'feed_type'	=>	$groups_consumption_data[$i]['feed_type'],
				'budgeted_consumption_lbs'	=>	$budgeted_consumption_lbs,
				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
				'actual_consumption_lbs'	=>	$groups_consumption_data[$i]['actual_consumption_lbs'],
				'actual_amount_tons'	=>	$groups_consumption_data[$i]['actual_amount_tons'],
			);

			$dtest_insert[] = array(
				'update_date'	=>	date("Y-m-d"),
				'group_id'	=>	$groups_consumption_data[$i]['group_id'],
				'feed_type'	=>	$groups_consumption_data[$i]['feed_type'],
				'budgeted_consumption_lbs'	=>	$budgeted_consumption_lbs,
				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
				'actual_consumption_lbs'	=>	$groups_consumption_data[$i]['actual_consumption_lbs'],
				'actual_amount_tons'	=>	$groups_consumption_data[$i]['actual_amount_tons']
			);

			$insert = DB::table("feeds_movement_groups_consumption");

			// if groups consumption is existed {delete the group consumption today and insert}
			// else {insert}
			if(count($groups_cons_history) > 0){
				// delete and insert
				DB::table("feeds_movement_groups_consumption")
					->where("group_id",$groups_consumption_data[$i]['group_id'])
					->where("update_date",date("Y-m-d"))
					->delete();

			}

			$insert->insert($d_insert);
		}

		return $dtest_insert;




			// all of the consumption computation will happen here.
			$gs = DB::table("feeds_movement_groups_consumption");
			$gs_query = $gs->where("group_id",$groups[$i]->group_id);
			$gs_count = $gs_query->count();

			$data = array(
				'update_date'	=>	date("Y-m-d"),
				'group_id'		=>	$groups[$i]->group_id,
				'feed_type'		=>	$feed_type,
				'consumption'	=>	$consumption
			);



	}



	/*
	*	Update Bin
	*	update the current bin based on yesterday or today's update on forecasting
	*/
	public function updateSowAPI($data) {

		$msg = "OK";
		$yesterday = 0;

		// update today
		$lastupdate = $this->todayBinUpdate($data['binID']);

		$amount = $lastupdate[0]['amount'] - $data['amount'];
		if($lastupdate[0]['amount'] < $data['amount']){
				$amount = str_replace("-","",$amount);
		} else {
				$amount = "-".$amount;
		}

		// update yesterdays
		if(empty($lastupdate)){
			$lastupdate = $this->yesterdayBinUpdate($data['binID']);
			$yesterday = 1;
		}

		$budgeted_amount_tons = 0;

		if($data['amount'] > $lastupdate[0]['amount']){
			$variance = $lastupdate[0]['variance'];
			$actual_consumption_per_pig = $lastupdate[0]['consumption'];
			$budgeted_amount_tons = $lastupdate[0]['budgeted_amount_tons'];
		} else {
			$new_amount = round(($lastupdate[0]['amount'] - $data['amount'])*2000,2);
			if($lastupdate[0]['num_of_pigs'] == 0){
					$actual_consumption_per_pig = $new_amount;
			} else {
					$actual_consumption_per_pig = $new_amount / $lastupdate[0]['num_of_sow_pigs'];
			}
			$variance = round($actual_consumption_per_pig - $lastupdate[0]['budgeted_amount'],2);
			$update_type = $lastupdate[0]['update_type'];
			if($update_type == 'Manual Update Bin Forecasting Admin' || $update_type == 'Manual Update Mobile Farmer' || $update_type == 'Delivery Manual Update Admin'){
				$budgeted_amount_tons = $lastupdate[0]['budgeted_amount_tons'];
			} else {
				$budgeted_amount_tons = $lastupdate[0]['amount'];
			}
		}

		$budgeted_amount_tons = $budgeted_amount_tons*2000 - ($lastupdate[0]['budgeted_amount'] * $lastupdate[0]['num_of_sow_pigs']);
		$budgeted_amount_tons = $budgeted_amount_tons/2000;

		$budgeted_amount = $this->daysCounterbudgetedAmount($lastupdate[0]['farm_id'],$_POST['bin'],$lastupdate[0]['feed_type'],date("Y-m-d H:i:s"));

		$currentAmount = $this->currentBinCapacity($_POST['bin']);

		//feeds
		$feeds = FeedTypes::where('type_id','=',$lastupdate[0]['feed_type'])->get()->toArray();

		// data to insert
		$bin_history_data = array(
				'update_date' 					=> 	date("Y-m-d H:i:s"),
				'bin_id' 								=> 	$data['binID'],
				'farm_id' 							=> 	$lastupdate[0]['farm_id'],
				'num_of_pigs' 					=> 	$lastupdate[0]['num_of_pigs'],
				'num_of_sow_pigs'				=>	$lastupdate[0]['num_of_sow_pigs'],
				'user_id' 							=> 	$data['userID'],
				'amount' 								=> 	$data['amount'],
				'update_type' 					=> 	'Manual Update Bin Forecasting Admin',
				'created_at' 						=> 	date("Y-m-d H:i:s"),
				'budgeted_amount' 			=> 	$budgeted_amount,//$feeds[0]['budgeted_amount'],//$lastupdate[0]['budgeted_amount'],
				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
				'actual_amount_tons'		=>	$data['amount'],
				'remaining_amount' 			=> 	$lastupdate[0]['remaining_amount'],
				'sub_amount' 						=> 	$lastupdate[0]['sub_amount'],
				'variance' 							=> 	$variance,
				'consumption' 					=> 	$actual_consumption_per_pig,
				'admin' 								=> 	$data['userID'],
				'feed_type'							=>	!empty($lastupdate[0]['feed_type']) ? $lastupdate[0]['feed_type'] : 51,
				'unique_id'							=>	!empty($lastupdate[0]['unique_id']) ? $lastupdate[0]['unique_id'] : 'none'
		);

		if($yesterday == 0){
			BinsHistory::where('history_id','=',$lastupdate[0]['history_id'])->update($bin_history_data);
		}else{
			BinsHistory::insert($bin_history_data);
		}


		if($data['amount'] > $lastupdate[0]['amount']){
			$avg_variance = 0;
			$avg_actual = 0;
		}else{
			//calculate average variance and actual consumption based on last 6 days
			$avg_variance = round(($this->averageVariancelast6days($data['binID'])/$this->getNumberOfUpdates($data['binID'])),2);
			$avg_actual = round(($this->averageActuallast6days($data['binID'])/$this->getNumberOfUpdates($data['binID'])),2);
		}

		//bins
		$bins = Bins::where('bin_id','=',$data['binID'])->get()->toArray();
		//bin Size
		$bin_size = BinSize::where('size_id','=',$bins[0]['bin_size'])->get()->toArray();
		//medication
		$medication = Medication::where('med_id','=',$lastupdate[0]['medication'])->get()->toArray();


		$numofpigs_ = $lastupdate[0]['num_of_sow_pigs'] != NULL ? $lastupdate[0]['num_of_sow_pigs'] : $bins[0]['num_of_sow_pigs'];
		if(!empty($lastupdate)){
			$budgeted_ = $lastupdate[0]['budgeted_amount'] != NULL ? $lastupdate[0]['budgeted_amount'] : $feeds[0]['budgeted_amount'];
		} else {
			$budgeted_ = 0;
		}

		if($budgeted_ != 0.0){
			if($numofpigs_ != 0){
				$daysto = round($data['amount'] * 2000 / ($numofpigs_ * $budgeted_),0);
			} else {
				$daysto = 0;
			}
		} else {
			$daysto = 0;
		}


		$history_id = !empty($lastupdate[0]['history_id']) ? $lastupdate[0]['history_id'] : NULL;

		Cache::forget('bin-'.$data['binID']);

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

		$user = User::where('id',$data['userID'])->first();

		return json_encode(array(

			'msg' 				=> 	$msg,
			'empty' 			=> 	$this->emptyDate($daysto),
			'daystoemp' 	=> 	$daysto,
			'percentage' 	=> 	$perc,
			'color' 			=> 	$color,
			'text' 				=> 	$text,
			'tdy' 				=> 	date('M d'),
			'ringAmount'	=>	$ring,
			//'avg_variance'	=>	$avg_variance,
			//'avg_actual'	=>	$avg_actual,
			'lastUpdate'	=>	date("M d"),
			'user'				=> $user->username,
			'farm_id'			=> $bin_history_data['farm_id']
		));


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
	*	get the update bin history yesterday
	*/
	private function todayBinUpdate($bin_id){
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
	private function yesterdayBinUpdate($bin_id){
		$date_yesterday = date("Y-m-d", time() - 60 * 60 * 24);
		$output = BinsHistory::where('bin_id','=',$bin_id)
					->where('update_date','<=',$date_yesterday.' 23:59:59')
					->orderBy('update_date','desc')
					->take(1)->get()->toArray();
		return $output;
	}

	/*
	*	Graph Query
	*/
	private function graphQuery($bin_id){

		$output = DB::table('feeds_bin_history')
					->select('history_id','update_date','amount','consumption','variance','update_type', 'budgeted_amount','budgeted_amount_tons')
					->where('bin_id','=',$bin_id)
					->orderBy('update_date', 'DESC')
					->get();

		return $output;

	}

	/*
	*	Graph Query
	*/
	private function graphQuery2($bin_id){


		$output = DB::table('feeds_bin_history')
					->select('history_id','update_date','amount','consumption','variance','update_type', 'budgeted_amount','budgeted_amount_tons','actual_amount_tons','num_of_pigs')
					->where('bin_id','=',$bin_id)
					->orderBy('update_date', 'DESC')
					->take(31)->get();

		return $output;

	}

	private function graphQuery3($bin_id){


		$output = DB::table('feeds_bin_history')
					->select('history_id','update_date','amount','consumption','variance','update_type', 'budgeted_amount')
					->where('bin_id','=',$bin_id)
					->orderBy('update_date', 'DESC')
					->take(6)->get();

		return $output;

	}

	/*
	*	Graph Query
	*/
	private function graphQuery4($bin_id,$d){

		$d = date("Y-m-d", strtotime($d));

		$output = DB::table('feeds_bins_accepted_load')
					->select('current_amount')
					->where('bin_no_id','=',$bin_id)
					->whereBetween('created_at', array($d . " 00:00:00", $d . " 23:59:59"))
					->where('status','=',1)
					->orderBy('id', 'DESC')
					->take(1)->get();

		return $output;

	}

	/*
	*	Graph Reloader
	*/
	public function graphReloader(){
		$bin_id = Input::get('bin_id');
		$num_of_pigs = Input::get('num_of_pigs');
		$graph_data = $this->graphData($bin_id,$num_of_pigs);

		return view("home.ajax.graph", compact("graph_data","bin_id"));
	}


	/*
	*	Graph Data
	*	last 6 updates of actual and budgeted amounts
	*/
	public function graphData($bin_id, $pigs){

		$actual = $this->graphQuery2($bin_id);

		$actualData = json_decode(json_encode($actual), true);

		$actualData = array_reverse($actualData);

		$output = array();


		$mybudgeted = 0;
		$actualconsumpt = 0;

		foreach($actualData as $k => $v){

			if($v['update_type'] == 'Manual Update Bin Forecasting Admin' || $v['update_type'] == 'Manual Update Mobile Farmer' || $v['update_type'] == 'Delivery Manual Update Admin') {

				$mybudgeted = $v['amount']*2000;
				//$actualconsumpt = $v['amount']*2000;
				$actualconsumpt = $v['actual_amount_tons']*2000;
				if($v['amount'] < $v['budgeted_amount_tons']){
					$mybudgeted = $v['budgeted_amount_tons']*2000;
				} else{
					$mybudgeted = $mybudgeted - ($v['budgeted_amount']*$pigs);
				}
				//$actualconsumpt = $actualconsumpt - ($v['consumption']*$pigs);

			} elseif($v['update_type'] == 'Automatic Update Admin' && $v['budgeted_amount_tons'] != 0){
				$mybudgeted = $v['budgeted_amount_tons']*2000;
				$mybudgeted = $mybudgeted - ($v['budgeted_amount']*$pigs);
				//$actualconsumpt = $v['amount']*2000;
				$actualconsumpt = $v['actual_amount_tons']*2000;
			} else {

				/*
				$actual2 = $this->graphQuery4($bin_id,$v['update_date']);


				if(count($actual2) == 0) {

					$mybudgeted = $mybudgeted - ($v['budgeted_amount']*$pigs);
					//$actualconsumpt = $actualconsumpt - ($v['consumption']*$pigs);
					$actualconsumpt = $v['actual_amount_tons']*2000;

				} else {

					$mybudgeted = $v['amount']*2000;
					$mybudgeted = $mybudgeted - ($v['budgeted_amount']*$pigs);
					//$actualconsumpt = $v['amount']*2000;
					$actualconsumpt = $v['actual_amount_tons']*2000;

				}
				*/

				$mybudgeted = $v['amount']*2000;
				$mybudgeted = $mybudgeted - ($v['budgeted_amount']*$pigs);
				//$actualconsumpt = $v['amount']*2000;
				$actualconsumpt = $v['actual_amount_tons']*2000;

				//$mybudgeted = $v['amount']*2000;
				//$actualconsumpt = $v['amount']*2000;
				//$mybudgeted = $v['budgeted_amount'];
				//$actualconsumpt = $actualconsumpt - ($v['consumption']*$pigs);
				if($v['num_of_pigs'] == 0){
					$mybudgeted = 0;
				}
			}

			if($mybudgeted < 0) {

				$mybudgeted = 0;

			}


			if($actualconsumpt < 0) {

				$actualconsumpt = 0;

			}

			if($v['num_of_pigs'] == 0){
				$mybudgeted = 0;
			}



				$output[] = array(
					'update_date'		=>	$v['update_date'],
					'amount'			=>	$v['amount'],
					'consumption'		=>	$v['consumption'],
					'variance'			=>	$this->variance(round($v['consumption']/2000,2),$this->budgetedAmount($bin_id)),
					'update_type' 		=> 	$v['update_type'],
					'budgeted_amount'	=> 	$mybudgeted/2000,
					'actual'			=> 	$actualconsumpt/2000
				);

		}

		if(count($output) <= 5){
			return $output;
		}

		$minusit = count($output);
		return array_slice($output, ($minusit-6));

	}

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

	private function averageVariancelast6days($bin_id) {

		$output = DB::select(DB::raw('SELECT SUM(variance) AS variance
									FROM (
									SELECT variance AS variance
									FROM  `feeds_bin_history` WHERE bin_id = "'. $bin_id.'"
									ORDER BY history_id DESC
									LIMIT 6
									)x'));

		return $output[0]->variance;

	}


	private function averageActuallast6days($bin_id) {


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
	*	average variance
	*/
	private function averageVariance($bin_id){

		$actual = $this->graphQuery($bin_id);

		$actualData = json_decode(json_encode($actual), true);

		$output = 0;
		foreach($actualData as $k => $v){

				$output	+= $this->variance(round($v['consumption']/2000,2),$this->budgetedAmount($bin_id));

		}

		$average_variance = $output/count($output);

		return $average_variance;
	}

	/*
	*	Update pending batch
	*/
	public function updatePending(){

		$delivery_id = Input::get('del_id');

		$pending = PendingDeliveries::find($delivery_id);

		$feed_id = !empty($pending['feeds_type_id']) ? $pending['feeds_type_id'] : 'None';
		$medication_id = !empty($pending['medication_id']) ? $pending['medication_id'] : 'None';

		$amount_selected = !empty($pending['amount']) ? $pending['amount'] : 0;
		$feeds_selected = $this->feedNamePending($feed_id);
		$medication_selected = $this->medicationNamePending($medication_id);

		$feeds = $this->feedTypes();
		$amount = $this->capacity();
		$medication = $this->medication();

		return view('home.ajax.updatepending',compact("delivery_id","feeds","medication","amount","feed_id","feeds_selected","medication_id","medication_selected","amount_selected"));

	}

	/*
	*	save the updated pending batch
	*/
	public function updateSavePending(){

		$data = Input::all();

		$pending_data = PendingDeliveries::all()->sum();

		$truck_capacity = Truck::where('truck_id','=',PendingDeliveries::first()->truck_id)->first()->capacity;

		$total_amount = PendingDeliveries::where('delivery_id','!=',$data['delivery_id'])->where('user_id',Auth::id())->sum('amount') + $data['amount'];

		$data_to_update = array(
					'feeds_type_id' => 	$data['feed_type_id'],
					'medication_id'	=>	$data['medication_id'],
					'amount'		=>	$data['amount']
					);

		//dd(array('truck_capacity'=>$truck_capacity,'total_amount'=>$total_amount));

		if($total_amount > $truck_capacity){
			$output = array(
				'status'	=>	'fail',
				'message'	=>	"<div class='alert alert-danger' role='alert'>The total amount of the batch is greater than the truck capacity</div>"
			);

		} else {

			PendingDeliveries::where('delivery_id', $data['delivery_id'])
				            ->update($data_to_update);

			return $this->pendingDeliveries();
		}

		return $output;

	}

	/*
	*	average actual consumption
	*/
	private function averageActual($bin_id){

		$actual = $this->graphQuery2($bin_id);

		$actualData = json_decode(json_encode($actual), true);

		$output = 0;

		foreach($actualData as $k => $v){

			$output	+= round($v['consumption']/2000,2);

		}

		$average_actual = $output/count($output);

		return $average_actual;
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
	* Cache query
	*/
	public function cacheQuery($key, $sql, $timeout = 30) {
		return Cache::remember($key, $timeout, function() use ($sql) {
			return DB::select(DB::raw($sql));
		});
	}

	/*
	*	binsDataCacheBuilder
	*/
	public function binsDataCacheBuilder($farm_id = NULL){

		Cache::forever('cachebuilder-status',"true");

		$this->cacheBinHistoryLatest();

		if($farm_id == NULL){

			$bins = Bins::select('bin_id')->get()->toArray();
			foreach($bins as $k => $v){
				// clear the cache for all bins
				Cache::forget('bins-'.$v['bin_id']);
				Cache::forget('farm_holder_bins_data-'.$v['bin_id']);
			}

			$farms = Farms::select('id')->get()->toArray();
			foreach($farms as $k => $v){
				Cache::forget('farm_holder-'.$v['id']);
				$this->binsDataAutoUpdate($v['id']);
				echo "farm_id: " . $v['id'] . " done caching<br/>";

			}

		} else {

			// clear the cache
			Cache::forget('bins-'.$farm_id);
			$this->binsDataAutoUpdate($farm_id);
			echo "farm_id: ".$farm_id." done caching<br/>";

		}

		Cache::forever('cachebuilder-status',"false");

	}

	/*
	*	binsDataCacheBuilder
	*/
	private function binsCacheBuilder($farm_id,$bin_id){

		// clear the cache for specific bin
		Cache::forget('bins-'.$bin_id);
		$this->binsData($farm_id);
		return "done";

	}

	/*
	*	Clear bins cache
	*/
	public function clearBinsCache($bin_id){

		Cache::forget('bins-'.$bin_id);
		Cache::forget('farm_holder_bins_data-'.$bin_id);

		if($this->binDataRebuildCache($bin_id)){
			return "cache clear for bin: ".$bin_id;
		}

		$this->farmHolderBinClearCache($bin_id);

		return "Something went wrong";
	}

	/*
	*	Bins forecating Data based on bin id
	*/
	private function binDataRebuildCache($bin_id)
	{


		$bins = DB::table('feeds_bins')
						 ->select('feeds_bins.*',
									'feeds_bin_sizes.name AS bin_size_name')
									//'feeds_feed_types.name AS feed_type_name',
									//'feeds_feed_types.budgeted_amount')
						 ->leftJoin('feeds_bin_sizes','feeds_bin_sizes.size_id', '=', 'feeds_bins.bin_size')
						 //->leftJoin('feeds_feed_types','feeds_feed_types.type_id', '=', 'feeds_bins.feed_type')
						 ->where('bin_id',$bin_id)
						 ->orderBy('feeds_bins.bin_number','asc')
						 ->get();
		$bins = $this->toArray($bins);


		//$farm_id = $bins[0]['farm_id'];

		//Cache::forget('bins-'.$farm_id);
		//$this->binsData($farm_id);

		//$binsData = array();
		for($i=0; $i<count($bins); $i++){

			Cache::forget('bins-'.$bins[$i]['bin_id']);
			Cache::forget('farm_holder-'.$bins[$i]['farm_id']);

			$current_bin_amount_lbs = $this->currentBinCapacity($bins[$i]['bin_id']);
			$last_update = $this->toArray($this->lastUpdate($bins[$i]['bin_id']));
			$last_update_user = $this->toArray($this->lastUpdateUser($bins[$i]['bin_id']));
			$up_hist[$i] = $this->lastUpdate_numpigs($bins[$i]['bin_id']);
			//$numofpigs_ = $this->displayDefaultNumberOfPigs($bins[$i]['num_of_pigs'], $up_hist[$i][0]['num_of_pigs']);
			//$total_number_of_pigs = $this->totalNumberOfPigsAnimalGroup($bins[$i]['bin_id'],$bins[$i]['farm_id']);
      $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
			$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'],$up_hist[$i][0]['budgeted_amount']);
			$delivery = $this->nextDel_($bins[$i]['farm_id'],$bins[$i]['bin_id']);
			$last_delivery = $this->lastDelivery($bins[$i]['farm_id'],$bins[$i]['bin_id'],$last_update);


			$farms_data = Farms::where('id', $bins[$i]['farm_id'])->first();

			if($farms_data->farm_type == "farrowing"){
				$total_number_of_pigs = $bins[$i]['num_of_sow_pigs'];
			}

			//$bins_items = Cache::store('file')->get('bins-'.$bins[$i]['bin_id']);
			//if($bins_items == NULL){
				// rebuild cache data
				$bins_items = array(
					'bin_s'										=>  $this->getmyBinSize($bins[$i]['bin_size']),
					'bin_id'									=>	$bins[$i]['bin_id'],
					'bin_number'							=>	$bins[$i]['bin_number'],
					'alias'										=>	$bins[$i]['alias'],
					'num_of_sow_pigs'					=>	$bins[$i]['num_of_sow_pigs'],
					'total_number_of_pigs'		=>	$total_number_of_pigs,
					'num_of_pigs'							=>	$bins[$i]['num_of_pigs'],
					'default_amount'					=>	$this->displayDefaultAmountofBin($bins[$i]['amount'], $up_hist[$i][0]['amount']),
					//'hex_color'								=>	$bins[$i]['hex_color'],
					'bin_size'								=>	$bins[$i]['bin_size'],
					'bin_size_name'						=>	$bins[$i]['bin_size_name'],
					'feed_type_name'					=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->description,
					'feed_type_name_orig'			=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->name,
					'feed_type_id'						=>	$up_hist[$i][0]['feed_type'],
					'budgeted_amount'					=>	$budgeted_,
					'current_bin_amount_tons'	=>	$up_hist[$i][0]['amount'],
					'current_bin_amount_lbs'	=>	(int)$current_bin_amount_lbs,
					'days_to_empty'						=>	$this->daysOfBins($current_bin_amount_lbs,$budgeted_,$total_number_of_pigs),
					'empty_date'							=>	$this->emptyDate($this->daysOfBins($current_bin_amount_lbs,$budgeted_,$total_number_of_pigs)),
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
			//}

			//$binsData[] = $bins_items;

		}
		/*
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

		Storage::put('bins_data'.$farm_id.'.txt',json_encode($binsDataFinal));
		*/

		$this->forecastingDataCache();
		return true;

	}

	/*
	*	Get the user
	*/
	private function usernames($user_id)
	{
		$user = User::where('id',$user_id)->first();

		$output = $user != NULL ? $user->username : "System Auto Update";

		return $output;
	}

	/*
	*	Bins forecating Data
	*/
	public function binsData($farm_id) {

		// get thecache value
		$binsDataFinal = NULL; // Cache::store('file')->get('bins-'.$farm_id);

		if($binsDataFinal == NULL){

			//Cache::forget('bins-'.$farm_id);

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

				$bins_items = Cache::store('file')->get('bins-'.$bins[$i]['bin_id']);
				if($bins_items == NULL){

					$current_bin_amount_lbs = $this->currentBinCapacity($bins[$i]['bin_id']);
					$last_update = json_decode(json_encode($this->lastUpdate($bins[$i]['bin_id'])), true);
					$last_update_user = json_decode(json_encode($this->lastUpdateUser($bins[$i]['bin_id'])), true);
					$up_hist[$i] = $this->lastUpdate_numpigs($bins[$i]['bin_id']);
					$numofpigs_ = $this->displayDefaultNumberOfPigs($bins[$i]['num_of_pigs'], $up_hist[$i][0]['num_of_pigs']);
	        $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
					$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'], $up_hist[$i][0]['budgeted_amount']);
					$delivery = $this->nextDel_($farm_id,$bins[$i]['bin_id']);
					$last_delivery = $this->lastDelivery($farm_id,$bins[$i]['bin_id'],$last_update);
					$feed_type_update = $this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']));

					$farms_data = Farms::where('id', $farm_id)->first();

					if($farms_data->farm_type == "farrowing"){
						$total_number_of_pigs = $bins[$i]['num_of_sow_pigs'];
					}


					// rebuild cache data
					$bins_items = array(
						'bin_s'										=>  $this->getmyBinSize($bins[$i]['bin_size']),
						'bin_id'									=>	$bins[$i]['bin_id'],
						'bin_number'							=>	$bins[$i]['bin_number'],
						'alias'										=>	$bins[$i]['alias'],
						'num_of_pigs'							=>	$bins[$i]['num_of_pigs'],
						'num_of_sow_pigs'					=>	$bins[$i]['num_of_sow_pigs'],
						'total_number_of_pigs'		=>	$total_number_of_pigs,
						'default_amount'					=>	$this->displayDefaultAmountofBin($bins[$i]['amount'], $up_hist[$i][0]['amount']),
						'hex_color'								=>	$bins[$i]['hex_color'],
						'bin_size'								=>	$bins[$i]['bin_size'],
						'bin_size_name'						=>	$bins[$i]['bin_size_name'],
						'feed_type_name'					=>	$feed_type_update->description,
						'feed_type_name_orig'			=>	$feed_type_update->name,
						// 'feed_type_name'					=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->description,
						// 'feed_type_name_orig'			=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->name,
						'feed_type_id'						=>	$up_hist[$i][0]['feed_type'],
						'budgeted_amount'					=>	$budgeted_,
						'current_bin_amount_tons'	=>	$up_hist[$i][0]['amount'],
						'current_bin_amount_lbs'	=>	(int)$current_bin_amount_lbs,
						'days_to_empty'						=>	$this->daysOfBins($current_bin_amount_lbs,$budgeted_,$total_number_of_pigs),
						'empty_date'							=>	$this->emptyDate($this->daysOfBins($current_bin_amount_lbs,$budgeted_,$total_number_of_pigs)),
						// 'days_to_empty'						=>	$this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs),
						// 'empty_date'							=>	$this->emptyDate($this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs)),
						'next_delivery'						=>	$delivery['name'],
						'medication'							=>	$this->getMedDesc($up_hist[$i][0]['medication']),
						'medication_name'					=>	$this->getMedName($up_hist[$i][0]['medication']),
						'medication_id'						=>	$up_hist[$i][0]['medication'],
						'last_update'							=>	$last_update_user[0]['update_date'],
						'next_deliverydd'					=>  $last_delivery,
						'delivery_amount'					=>  $delivery['amount'],
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


		} else {
			$binsDataFinal = $binsDataFinal ;
		}

		Storage::put('bins_data'.$farm_id.'.txt',json_encode($binsDataFinal));
		$binsDataFinal = Storage::get('bins_data'.$farm_id.'.txt');

		return $binsDataFinal;

	}





	/*
	*	Bins forecating Data
	*/
	public function binsDataAutoUpdate($farm_id) {

		// get thecache value
		$binsDataFinal = NULL; // Cache::store('file')->get('bins-'.$farm_id);

		if($binsDataFinal == NULL){

			//Cache::forget('bins-'.$farm_id);

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

				$bins_items = Cache::store('file')->get('bins-'.$bins[$i]['bin_id']);
				if($bins_items == NULL){
					$ch_amount = Cache::store('file')->get('bins_history_latest_'.$bins[$i]['bin_id']);
					$current_bin_amount_lbs = round($ch_amount[$bins[$i]['bin_id']]->amount * 2000,0);
					$last_update = json_decode(json_encode($this->lastUpdate($bins[$i]['bin_id'])), true);
					$last_update_user = json_decode(json_encode($this->lastUpdateUser($bins[$i]['bin_id'])), true);
					$up_hist[$i] = $this->lastUpdate_numpigs($bins[$i]['bin_id']);
					$numofpigs_ = $this->displayDefaultNumberOfPigs($bins[$i]['num_of_pigs'], $up_hist[$i][0]['num_of_pigs']);
	        $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
					$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'], $up_hist[$i][0]['budgeted_amount']);
					$delivery = $this->nextDel_($farm_id,$bins[$i]['bin_id']);
					$last_delivery = $this->lastDelivery($farm_id,$bins[$i]['bin_id'],$last_update);
					$feed_type_update = $this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']));

					$farms_data = Farms::where('id', $farm_id)->first();

					if($farms_data->farm_type == "farrowing"){
						$total_number_of_pigs = $bins[$i]['num_of_sow_pigs'];
					}


					// rebuild cache data
					$bins_items = array(
						'bin_s'										=>  $this->getmyBinSize($bins[$i]['bin_size']),
						'bin_id'									=>	$bins[$i]['bin_id'],
						'bin_number'							=>	$bins[$i]['bin_number'],
						'alias'										=>	$bins[$i]['alias'],
						'num_of_pigs'							=>	$bins[$i]['num_of_pigs'],
						'num_of_sow_pigs'					=>	$bins[$i]['num_of_sow_pigs'],
						'total_number_of_pigs'		=>	$total_number_of_pigs,
						'default_amount'					=>	$this->displayDefaultAmountofBin($bins[$i]['amount'], $up_hist[$i][0]['amount']),
						'hex_color'								=>	$bins[$i]['hex_color'],
						'bin_size'								=>	$bins[$i]['bin_size'],
						'bin_size_name'						=>	$bins[$i]['bin_size_name'],
						'feed_type_name'					=>	$feed_type_update->description,
						'feed_type_name_orig'			=>	$feed_type_update->name,
						// 'feed_type_name'					=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->description,
						// 'feed_type_name_orig'			=>	$this->feedName($this->getFeedTypeUpdate($up_hist[$i][0]['feed_type'],$bins[$i]['feed_type']))->name,
						'feed_type_id'						=>	$up_hist[$i][0]['feed_type'],
						'budgeted_amount'					=>	$budgeted_,
						'current_bin_amount_tons'	=>	$up_hist[$i][0]['amount'],
						'current_bin_amount_lbs'	=>	(int)$current_bin_amount_lbs,
						'days_to_empty'						=>	$this->daysOfBins($current_bin_amount_lbs,$budgeted_,$total_number_of_pigs),
						'empty_date'							=>	$this->emptyDate($this->daysOfBins($current_bin_amount_lbs,$budgeted_,$total_number_of_pigs)),
						// 'days_to_empty'						=>	$this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs),
						// 'empty_date'							=>	$this->emptyDate($this->daysOfBins($this->currentBinCapacity($bins[$i]['bin_id']),$budgeted_,$total_number_of_pigs)),
						'next_delivery'						=>	$delivery['name'],
						'medication'							=>	$this->getMedDesc($up_hist[$i][0]['medication']),
						'medication_name'					=>	$this->getMedName($up_hist[$i][0]['medication']),
						'medication_id'						=>	$up_hist[$i][0]['medication'],
						'last_update'							=>	$last_update_user[0]['update_date'],
						'next_deliverydd'					=>  $last_delivery,
						'delivery_amount'					=>  $delivery['amount'],
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


		} else {
			$binsDataFinal = $binsDataFinal ;
		}

		Storage::put('bins_data'.$farm_id.'.txt',json_encode($binsDataFinal));
		$binsDataFinal = Storage::get('bins_data'.$farm_id.'.txt');

		return $binsDataFinal;

	}




	/**
   * cache all the amounts from bin history
   *
   * @return Response
   */
  public function cacheBinHistoryLatest()
  {
			// clear the cache first

			$output = array();
			$bins = DB::table("feeds_bins")->select('bin_id')->get();

			for($i=0; $i<count($bins); $i++){

				$bh = DB::table('feeds_bin_history')
								->where('bin_id',$bins[$i]->bin_id)
								->orderBy('created_at','desc')
								->first();

				$output[$bins[$i]->bin_id] = $bh;

				Cache::forever('bins_history_latest_'.$bins[$i]->bin_id,$output);
			}

			// save the new cache data
			//$r = Cache::store('file')->get('bins_history_amount');

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

	/*
	*	animalGroup()
	*/
	private function animalGroup($bin_id,$farm_id)
	{
		// check the farm type
		$type = $this->farmTypes($farm_id);

		if($type == 'farrowing'){

			$output = $this->farrowingBins($bin_id);

		} elseif ($type == 'nursery') {

			$output = $this->nurseryBins($bin_id);

		} elseif ($type == 'finisher') {

			$output = $this->finisherBins($bin_id);

		} else {

			$output = NULL;

		}

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
	*	farmTypes()
	*/
	public function farmTypes($farm_id)
	{
		$type = Farms::where('id',$farm_id)->select('farm_type')->first();

		return $type != NULL ? $type->farm_type : NULL;
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
		$farrowing = DB::table('feeds_movement_groups')
									->where('status','!=','removed')
									->where('created_at','!=','0000-00-00 00:00:00')
									->where('unique_id',$unique_id)->get();

		$farrowing = $this->toArray($farrowing);

		return $farrowing != NULL ? $farrowing[0] : NULL;
	}

	/*
	*	farrowingBins()
	*/
	private function farrowingBins($bin_id)
	{
		$farrow_bins = DB::table('feeds_movement_farrowing_bins')->where('bin_id',$bin_id)->get();
		$total_pigs_per_bins = DB::table('feeds_movement_farrowing_bins')->where('bin_id',$bin_id)->sum('number_of_pigs');
		$farrow_bins = $this->toArray($farrow_bins);

		if($farrow_bins == NULL){
			return NULL;
		}

		$data = array();
		foreach($farrow_bins as $k => $v){
			$farrowing_groups = $this->animalGroupsFarrowing($v['unique_id']);
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
	private function animalGroupsFarrowing($unique_id)
	{
		$farrowing = DB::table('feeds_movement_farrowing_group')->where('status','!=','removed')->where('unique_id',$unique_id)->get();
		$farrowing = $this->toArray($farrowing);

		return $farrowing != NULL ? $farrowing[0] : NULL;
	}


	/*
	*	nurseryBins()
	*/
	private function nurseryBins($bin_id)
	{
		$nursery_bins = DB::table('feeds_movement_nursery_bins')->where('bin_id',$bin_id)->get();
		$total_pigs_per_bins = DB::table('feeds_movement_nursery_bins')->where('bin_id',$bin_id)->sum('number_of_pigs');
		$nursery_bins = $this->toArray($nursery_bins);

		if($nursery_bins == NULL){
			return NULL;
		}

		$data = array();
		foreach($nursery_bins as $k => $v){
			$nursery_groups = $this->animalGroupsNursery($v['unique_id']);
			if($nursery_groups['group_name'] != NULL){
				$data[] = array(
					'type'					=>	'nursery',
					'group_name'		=>	$nursery_groups['group_name'],
					'group_id'			=>	$nursery_groups['group_id'],
					'farm_id'			=>	$nursery_groups['farm_id'],
					'number_of_pigs'	=>	$total_pigs_per_bins, //$v['number_of_pigs'],
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
	*	animalGroupsNursery()
	*	get the group info of the nursery groups
	*/
	private function animalGroupsNursery($unique_id)
	{
		$nursery = DB::table('feeds_movement_nursery_group')->where('status','!=','removed')->where('unique_id',$unique_id)->get();
		$nursery = $this->toArray($nursery);

		return $nursery != NULL ? $nursery[0] : NULL;
	}

	/*
	*	finisherBins()
	*/
	private function finisherBins($bin_id)
	{
		$finisher_bins = DB::table('feeds_movement_finisher_bins')->where('bin_id',$bin_id)->get();
		$total_pigs_per_bins = DB::table('feeds_movement_finisher_bins')->where('bin_id',$bin_id)->sum('number_of_pigs');
		$finisher_bins = $this->toArray($finisher_bins);

		if($finisher_bins == NULL){
			return NULL;
		}

		$data = array();
		foreach($finisher_bins as $k => $v){
			$finisher_groups = $this->animalGroupsFinisher($v['unique_id']);
			if($finisher_groups['group_name'] != NULL){
				$data[] = array(
					'type'					=>	'finisher',
					'group_name'		=>	$finisher_groups['group_name'],
					'group_id'			=>	$finisher_groups['group_id'],
					'farm_id'			=>	$finisher_groups['farm_id'],
					'number_of_pigs'	=>	$total_pigs_per_bins, //$v['number_of_pigs'],
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
	*	animalGroupsFinisher()
	*	get the group info of the finisher groups
	*/
	private function animalGroupsFinisher($unique_id)
	{
		$finisher = DB::table('feeds_movement_finisher_group')->where('status','!=','removed')->where('unique_id',$unique_id)->get();
		$finisher = $this->toArray($finisher);

		return $finisher != NULL ? $finisher[0] : NULL;
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
					->first();

		return !empty($output) ? $output->budgeted_amount : 0;

	}

	/*
	*	Bins forecating Data first load
	*/
	/*
	*	Bins forecating Data first load
	*/
	private function binsDataFirstLoad($farm_id,$update_notification) {

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


				$b_data = Bins::where('farm_id', $farm_id)->first();

		 		if($b_data == NULL){
		 			return NULL;
		 		}

				$bins = json_decode(json_encode($bins),true);

				$binsData = array();
				$binAmounts = array();
				$update_type = 0;

				$binsCount = count($bins) - 1;
				for($i=0;$i<=$binsCount;$i++){
					Cache::forget('farm_holder_bins_data-'.$bins[$i]['bin_id']);
					 if(Cache::has('farm_holder_bins_data-'.$bins[$i]['bin_id'])) {

							// $binsData[] = Cache::store('file')->get('farm_holder_bins_data-'.$bins[$i]['bin_id'])[$i];
							$binsData[] = Cache::store('file')->get('farm_holder_bins_data-'.$bins[$i]['bin_id']);

					 } else {

						 	$yesterday_update[$i] = $this->getYesterdayUpdate($bins[$i]['bin_id']);
							$up_hist[$i] = json_decode(json_encode($this->lastUpdate_numpigs($bins[$i]['bin_id'])), true);
							$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'], $up_hist[$i][0]['budgeted_amount']);
							//$total_number_of_pigs = $this->totalNumberOfPigsAnimalGroup($bins[$i]['bin_id'],$bins[$i]['farm_id']);
		          $total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
							$update_type = $this->updateTypeCounter($up_hist[$i][0]['update_type'],$yesterday_update[$i],$up_hist[$i][0]['num_of_pigs'],$bins[$i]['bin_id']);
							$current_bin_cap = $this->currentBinCapacity($bins[$i]['bin_id']);

							$farms_data = Farms::where('id', $farm_id)->first();

							if($farms_data->farm_type == "farrowing"){
								$total_number_of_pigs = $bins[$i]['num_of_sow_pigs'];
							}

							$binsData[] = array(
								'bin_id'									=>	$bins[$i]['bin_id'],
								'current_bin_capacity'		=>	$current_bin_cap,
								'days_to_empty'						=>	$this->daysOfBins($current_bin_cap,$budgeted_,$total_number_of_pigs),
								'empty_date'							=>	$this->emptyDate($this->daysOfBins($current_bin_cap,$budgeted_,$total_number_of_pigs)),
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
					//$binsDataFinal[] = $empty_bins+$days_to_empty_first+$binsData[$i];
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
	*	lowestAmountBin()
	*	Get the lowest amount
	* $bins (array)
	*/
	private function lowestAmountBin($bins)
	{

	}


	/*
	*	Bins forecating Data first load
	*/
	private function binsDataFirstLoadV2($farm_id,$update_notification) {

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


				$b_data = Bins::where('farm_id', $farm_id)->first();
				if($b_data == NULL){
					return NULL;
				}

				$bins = json_decode(json_encode($bins),true);

				$binsData = array();
				$binAmounts = array();
				$update_type = 0;

				for($i=0; $i<count($bins); $i++){
					// if($farm_id == 155){
							Cache::forget('farm_holder_bins_data-'.$bins[$i]['bin_id']);
					// }

					 if(Cache::has('farm_holder_bins_data-'.$bins[$i]['bin_id']) && $bins[$i]['bin_id'] != 0) {

							$binsData[$i] = Cache::store('file')->get('farm_holder_bins_data-'.$bins[$i]['bin_id']);

					 } else {

							$yesterday_update[$i] = $this->getYesterdayUpdate($bins[$i]['bin_id']);
							$up_hist[$i] = json_decode(json_encode($this->lastUpdate_numpigs($bins[$i]['bin_id'])), true);
							$budgeted_ = $this->getmyBudgetedAmountTwo($up_hist[$i][0]['feed_type'], $bins[$i]['feed_type'], $up_hist[$i][0]['budgeted_amount']);
							$total_number_of_pigs = $this->totalNumberOfPigsAnimalGroupAPI($bins[$i]['bin_id'],$bins[$i]['farm_id']);
							$update_type = $this->updateTypeCounter($up_hist[$i][0]['update_type'],$yesterday_update[$i],$up_hist[$i][0]['num_of_pigs'],$bins[$i]['bin_id']);
							$current_bin_cap = $this->currentBinCapacity($bins[$i]['bin_id']);

							$farms_data = Farms::where('id', $farm_id)->first();

							if($farms_data->farm_type == "farrowing"){
								$total_number_of_pigs = $bins[$i]['num_of_sow_pigs'];
							}

							$binsData[$i] = array(
								'bin_id'									=>	$bins[$i]['bin_id'],
								'current_bin_capacity'		=>	$current_bin_cap,
								'days_to_empty'						=>	$this->daysOfBins($current_bin_cap,$budgeted_,$total_number_of_pigs),
								'empty_date'							=>	$this->emptyDate($this->daysOfBins($current_bin_cap,$budgeted_,$total_number_of_pigs)),
								'update_type'							=>	$update_type,
								'last_manual_update'			=>	$this->lastManualUpdate($bins[$i]['bin_id']),
								'num_of_pigs'							=>	$total_number_of_pigs
							);

							$binAmounts[] = $up_hist[$i][0]['amount'] == NULL ? 0 : $up_hist[$i][0]['amount'];

							Cache::forever('farm_holder_bins_data-'.$bins[$i]['bin_id'],$binsData[$i]);

					 }

					 // sleep(10); // this should halt for 60 seconds for every loop

				}

				$sorted_bins = $binsData;

				usort($sorted_bins, function($a,$b){
					if(isset($a['days_to_empty'])){
						if($a['days_to_empty'] == $b['days_to_empty']) return 0;
						return ($a['days_to_empty'] < $b['days_to_empty'])?-1:1;
					}
				});


				$days_to_empty_first = array(
					'first_list_days_to_empty'	=>	$sorted_bins[0]['days_to_empty']
				);

				$sorted_bins = $binsData;
				usort($sorted_bins, function($a,$b){
					if(isset($a['last_manual_update'])){
						if($a['last_manual_update'] == $b['last_manual_update']) return 0;
						return ($a['last_manual_update'] < $b['last_manual_update'])?-1:1;
					}
				});

				$last_manual_update = array(
					'last_manual_update'	=>	isset($sorted_bins[0]['last_manual_update']) ? $sorted_bins[0]['last_manual_update'] : 0
				);

				$empty_bins = array(
					'empty_bins'	=>	$this->countEmptyBins($binsData)
				);

				$lowest_amount_bin = array(
					'lowest_amount_bin'	=> $binAmounts != NULL ?	min($binAmounts) : 0
				);

				$low_bins = array();
				for($i=0; $i < count($binsData); $i++){

					if(isset($binsData[$i]['days_to_empty']) && $binsData[$i]['num_of_pigs'] != 0){

						if($binsData[$i]['days_to_empty'] <= 2){
							$low_bins[] = array(
								'lowBins'	=> $binsData[$i]['days_to_empty']
							);
						}

					}

				}

				$low_bins_counter = array('lowBins'	=> count($low_bins));

				$update_types = array();

				for($i=0; $i < count($binsData); $i++){

					if(isset($binsData[$i]['update_type'])){

						if($binsData[$i]['update_type'] != 1){
							$update_types[] = array(
								'update_type'	=> $binsData[$i]['update_type']
							);
						}

					}

					$binsDataFinal[0] = $empty_bins+$days_to_empty_first+$binsData;
				}

				// disabled update notifications
				if($update_notification == "disable"){
					$update_types = "";
				}

				$update_types_counter = array('update_type'	=> $update_types);
				$num_of_bins = array("bins_count" => count($binsDataFinal[count($binsDataFinal) -1]) - 2);

				return $binsDataFinal+$num_of_bins+$low_bins_counter+$update_types_counter+$last_manual_update+$lowest_amount_bin;


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
		/*
		$dates = array(
			0 => date("Y-m-d") . " 06:00 am",
			1 => date("Y-m-d") . " 07:00 am",
			2 => date("Y-m-d") . " 08:00 am",
			3 => date("Y-m-d") . " 09:00 am",
			4 => date("Y-m-d") . " 10:00 am",
			5 => date("Y-m-d") . " 11:00 am",
			6 => date("Y-m-d") . " 12:00 pm",
			7 => date("Y-m-d") . " 01:00 pm",
			8 => date("Y-m-d") . " 02:00 pm",
			9 => date("Y-m-d") . " 03:00 pm"
		);
		*/
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
		//$output = $this->perHourIntervalUpdate($dates,$date_one,$output);

		return $output;
	}

	// tester for per hour update interval
	private function perHourIntervalUpdate($dates,$date_today,$output)
	{

		if(strtotime($date_today) > strtotime($dates[0]) && strtotime($date_today) < strtotime($dates[1])){
			$output = $output;
		} else if(strtotime($date_today) > strtotime($dates[2]) && strtotime($date_today) < strtotime($dates[3])){
			$output = $output;
		} else if(strtotime($date_today) > strtotime($dates[4]) && strtotime($date_today) < strtotime($dates[5])){
			$output = $output;
		} else if(strtotime($date_today) > strtotime($dates[6]) && strtotime($date_today) < strtotime($dates[7])){
			$output = $output;
		} else if(strtotime($date_today) > strtotime($dates[8]) && strtotime($date_today) < strtotime($dates[9])){
			$output = $output;
		} else {
			$output = 1;
		}

		return $output;

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
	*	empty bins counter
	*/
	private function countEmptyBins($bins){
		$counter = 0;

		for($i=0; $i < count($bins); $i++){
			if(isset($bins[$i]['days_to_empty'])){
				if($bins[$i]['days_to_empty'] == 0 || $bins[$i]['days_to_empty'] == 1 || $bins[$i]['days_to_empty'] == 2){
					$counter++;
				}
			}
		}

		return $counter;
	}

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



	public function getmyBudgetedAmount($lastupd_budgeted, $feed_type) {

			$a = $lastupd_budgeted;

			if($lastupd_budgeted == 0) {

			  	$a = $this->getBudgetedAmount($feed_type);

			}

			return $a;

	}

	/*
	*	Deliveries Feed Name
	*/
	public function deliveriesFeedName($bin_id){

		$deliveries = Deliveries::where('bin_id','=',$bin_id)
						->select('feeds_type_id')
						->first();

		$feedTypes = FeedTypes::where('type_id','=',$deliveries['feeds_type_id'])
						->select('name')
						->first();
		$feedName = ($feedTypes['name'] != NULL ? $feedTypes['name'] : 'No feed yet' );

		return $feedName;
	}

	/*
	*	low bins
	*/
	private function lowBins($bins){

		$output = array();
		foreach($bins as $k => $v){
			if($v['days_to_empty'] <= 2){
				$output[] = array(
					'lowBins'	=> $v['days_to_empty']
				);
			}
		}

		return count($output);
	}

	/*
	*	Get the last updated
	*/
	private function lastUpdate($bin_id){

		$output = BinsHistory::select('update_date','unique_id','user_id')
					->where('bin_id','=',$bin_id)
					->where('update_date','<=',date("Y-m-d")." 23:59:59")
					->orderBy('update_date','DESC')
					->first();
		return $r = array(0=>$this->toArray($output));

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
					->first();
		return $r = array(0=>$this->toArray($output));

	}


	private function lastUpdate_numpigs($bin_id){

		// date today
		/*$output = BinsHistory::select('num_of_pigs','amount','budgeted_amount','medication', 'feed_type')
					->where('bin_id','=',$bin_id)
					->where('update_date','LIKE',date('Y-m-d')."%")
					->orderBy('update_date','desc')
					->take(1)->get()->toArray();*/
		$output = BinsHistory::select('num_of_pigs','amount','budgeted_amount','medication', 'feed_type','update_type')
					->where('bin_id','=',$bin_id)
					//->where('update_date','<=',date('Y-m-d')." 23:59:59")
					->orderBy('created_at','desc')
					->first();

		$output = array(0=>$this->toArray($output));

		// date yesterday
		/*if(empty($output)){
			$date_yesterday = date("Y-m-d", time() - 60 * 60 * 24);
			$output = BinsHistory::select('num_of_pigs','amount','budgeted_amount','medication', 'feed_type')
						->where('bin_id','=',$bin_id)
						->where('update_date','LIKE',$date_yesterday.'%')
						->orderBy('update_date','desc')
						->take(1)->get()->toArray();
		}*/

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



	/*
	*	saveSchedule()
	*	save schedule data
	*/
	public function saveSchedule(){
		$data = Input::all();
		if($data['timeOfTheDaySched'] == 'AM'){
		 	$time =  ' 00:00:00';
		} elseif($data['timeOfTheDaySched'] == 'PM') {
			$time =  ' 12:00:10';
		}

		 $farmId = $data['farmId'];
  		 $binId = $data['binId'];
  		 $farmName = $data['farmName'];
  		 $binNumber = $data['binNumber'];
  		 $medicationId = $data['medicationId'];
  		 $feedTypeId = $data['feedTypeId'];
  		 $feedAmount = $data['feedAmount'];
		 $schedDate = date("Y-m-d H:i:s", strtotime($data['dateSched'] . $time));
		 $truckId = $data['truckId'];
         $driverId = $data['driverId'];

		 $pending_unique = 'tempo_holder_1';

		 Cache::forget('bins-'.$binId);

		$batch = array(
						'delivery_date'			=>	$schedDate,
						'truck_id'				=>	$truckId,
						'farm_id'				=>	$farmId,
						'feeds_type_id'			=>	$feedTypeId,
						'medication_id'			=>	$medicationId,
						'driver_id'				=>	$driverId,
						'amount'				=>	$feedAmount,
						'bin_id'				=>	$binId,
						'unique_id'				=>	$pending_unique,
						'user_id'				=>	Auth::id()
					);

		$schedExists = PendingDeliveries::where('delivery_date','=',$schedDate)
					->where('truck_id','=',$batch['truck_id'])
					->where('farm_id','=',$batch['farm_id'])
					->where('bin_id','=',$batch['bin_id'])
					->where('user_id','=',$batch['user_id'])
					->first();

		//Cache::forget('farm_holder_bins_data-'.$binId);
		//Cache::forget('farm_holder-'.$batch['farm_id']);

		//$this->forecastingDataCache();

		if($schedExists === NULL){
			PendingDeliveries::insert($batch);
			$output = $this->pendingDeliveries();
		} else {
			$output = "failed";
		}



		return $output;
	}

	/*
	*	Pending Deliveries
	*/
	public function pendingDeliveries(){

		$pending = PendingDeliveries::where('user_id',Auth::id())->orderBy('delivery_id','asc')->get()->toArray();

		$counter = count($pending)-1;
		for($i=0; $i<=$counter; $i++){

		Cache::forget('farm_holder_bins_data-'.$pending[$i]['bin_id']);
		Cache::forget('farm_holder-'.$pending[$i]['farm_id']);

			$pendingData[] = array(
				'delivery_id'		=>	$pending[$i]['delivery_id'],
				'delivery_date'		=>	date('M d, A',strtotime($pending[$i]['delivery_date'])),
				'amount'			=>	$pending[$i]['amount'],
				'truck_capacity'	=>	$this->truckCapacityPending($pending[$i]['truck_id']),
				'farm_name'			=>	$this->farmNamePending($pending[$i]['farm_id']),
				'bin_name'			=>	$this->binNamePending($pending[$i]['bin_id']),
				'feed_name'			=>	$this->feedNamePending($pending[$i]['feeds_type_id']),
				'medication_name'	=>	$this->medicationNamePending($pending[$i]['medication_id']),
				'user_id'			=>	$pending[$i]['user_id'],
				'farm_id'			=>	$pending[$i]['farm_id']
			);
		}

		$totalAmount = (isset($pendingData) ? $this->totalPendingAmount($pendingData) : 0);
		$pendingData = (isset($pendingData) ? $pendingData : 0);

		$delivery_date = !empty($pending[$counter]['delivery_date']) ? date('M d, A', strtotime($pending[$counter]['delivery_date'])) : "";
		$truck_id = !empty($pending[$counter]['truck_id']) ? $pending[$counter]['truck_id'] : "";
		$driver_id = !empty($pending[$counter]['driver_id']) ? $pending[$counter]['driver_id'] : "";
		$truck_capacity = !empty($pendingData[$counter]['truck_capacity']) ? $pendingData[$counter]['truck_capacity'] : "";

		//$this->forecastingDataCache();

		if(!empty($pendingData)) {
			return view('home.ajax.pending',compact("pendingData","totalAmount","truck_capacity","delivery_date","truck_id","driver_id"));
		} else {
			return "<small></small>";
		}
	}

	/*
	*	delete pending deliveries

	*/
	public function deleteBatch(){
		$data = Input::all();
		PendingDeliveries::destroy($data['delivery_id']);
	}

	/*
	*	Schedule Delivery
	*/
	public function scheduleDelivery(){

		$data = PendingDeliveries::where('user_id',Auth::id())->orderBy('delivery_id','asc')->get()->toArray();
		$insertData = $this->keyRemover($data);

		if(FarmSchedule::insert($insertData)){
			$this->deletePendingDeliveries($data);
			$output = "success";
		} else {
			$output = "failed";
		}
		$this->forecastingDataCache();
		// remove the scheduling cache data
		Cache::forget('scheduling_data_1st_load_ajax');

		// get the medications medication()
		$schedule_controller = new ScheduleController;
		$schedule_controller->scheduleCache();
		unset($schedule_controller);

		return $output;

	}

	/*
	*	Array key remover
	*/
	private function keyRemover($data){

		$unique_id = $this->generator();

		$output = array();
		$counter = count($data) - 1;

		$delivery_date = $data[$counter]['delivery_date'];
		$truck_id = $data[$counter]['truck_id'];
		$driver_id = $data[$counter]['driver_id'];

		for( $i=0; $i <= $counter; $i++ ){
			$output[] = array(
				'date_of_delivery'	=>	$delivery_date,
				'truck_id'			=>	$truck_id,
				'farm_id'			=>	$data[$i]['farm_id'],
				'feeds_type_id'		=>	$data[$i]['feeds_type_id'],
				'medication_id'		=>	$data[$i]['medication_id'],
				'driver_id'			=>	$driver_id,
				'amount'			=>	$data[$i]['amount'],
				'bin_id'			=>	$data[$i]['bin_id'],
				'user_id'			=>	Auth::id(),
				'unique_id'			=>	$unique_id
			);
			Cache::forget('bins-'.$data[$i]['bin_id']);
			//Cache::forget('farm_holder_bins_data-'.$data[$i]['bin_id']);
			//Cache::forget('farm_holder-'.$data[$i]['farm_id']);
			$this->farmHolderBinClearCache($data[$i]['farm_id']);
		}


		return $output;
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
	*	remove pending deliveries
	*/
	private function deletePendingDeliveries($data){

		$id = array();

		$counter = count($data) - 1;
		for($i = 0; $i <= $counter; $i++ ){
			$id[] = $data[$i]['delivery_id'];
		}

		PendingDeliveries::destroy($id);
	}

	/*
	*	totalPendingAmount
	*	total amount of pending delivery
	*/
	private function totalPendingAmount($data){

		$total = 0;

		$counter = count($data)-1;
		for($i=0; $i<=$counter; $i++){
			$total = $total + $data[$i]['amount'];
		}

		return $total;
	}

	/*
	*	truck capacity
	*/
	private function truckCapacityPending($truck_id){
		$truckCapacity = DB::table('feeds_truck')
							->select('capacity')
							->where('truck_id','=',$truck_id)
							->first();
		return $truckCapacity->capacity;
	}

	/*
	*	farm name
	*/
	private function farmNamePending($farm_id){
		$farmName = DB::table('feeds_farms')
					->select('name')
					->where('id','=',$farm_id)
					->first();
		return $farmName->name;
	}

	/*
	*	bin name
	*/
	private function binNamePending($bin_id){
		$binName = DB::table('feeds_bins')
					->select('alias')
					->where('bin_id','=',$bin_id)
					->first();
		return $binName->alias;
	}

	/*
	*	feed name
	*/
	private function feedNamePending($feed_id){

		if($feed_id != 0 || $feed_id != NULL){
			$feedName = DB::table('feeds_feed_types')
						->select('name')
						->where('type_id','=',$feed_id)
						->first();
			if($feedName != NULL){
				$output = $feedName->name;
			} else {
				$output = "-";
			}
		} else {
			$output = "-";
		}

		return $output;
	}

	/*
	*	medication name
	*/
	private function medicationNamePending($medication_id){

		if($medication_id != 0 || $medication_id != NULL){
			$medicationName = DB::table('feeds_medication')
						->select('med_name')
						->where('med_id','=',$medication_id)
						->first();
			$output = !empty($medicationName->med_name) ? $medicationName->med_name : '-';
		} else {
			$output = "-";
		}

		return $output;
	}


	/*
	*	Medication
	*/
	public function medication(){
    $medication_cache = Cache::store('file')->get('medications');

    if($medication_cache == NULL){
			$meds = DB::table('feeds_medication')
					->where('med_name','!=','No Medication')
					->orderBy('med_name')
					->select('med_name','med_id')->get();

			$r = array();
			for($i=0; $i<count($meds); $i++){
				$r[$meds[$i]->med_id] = $meds[$i]->med_name;
			}

      $medication = array(''=>'Please Select')+$r;

      Cache::forever('medications',$medication);
      $medication_cache = Cache::store('file')->get('medications');

    }

    return $medication_cache;
	}

	/*
	*	Feed Types
	*/
	public function feedTypes(){

		$feed_types_cache = Cache::store('file')->get('feed_types');

		if($feed_types_cache == NULL){
			$feeds = DB::table('feeds_feed_types')
					->where('name','!=','None')
					->orderBy('name')
					->lists('name','type_id');
			$feeds = array(''=>'Please Select') + $feeds;

			Cache::forever('feed_types',$feeds);
			$feed_types_cache = Cache::store('file')->get('feed_types');
		}
		return $feed_types_cache;
	}

	/*
	*	Feed Types
	*/
	public function feedTypesAPI(){

			$feeds =   DB::table('feeds_feed_types')
										->where('name','!=','None')
										->where('status','show')
										->orderBy('name','asc')
										->select('description','type_id')
										->get();

			$r = array();
			for($i=0; $i<count($feeds); $i++){
				$r[$feeds[$i]->type_id] = $feeds[$i]->description;
			}

			$out = array(''=>'Please Select')+$r;

      return $out;
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
	*	Capacity
	*/
	private function capacity()
	{
		/*$data = array();
		for($i=1;$i<=20;$i+=0.50){
			$amount = strval($i) . " Tons";
			if($i == 1){
				$amount = strval($i) . " Ton";
				$data[$amount] = $i . " Ton";
			} else {
				$data[$amount] = $i . " Tons";
			}
		}*/
		/*for($i=1;$i<=20;$i+=1){
			$amount = strval($i) . " Tons";
			if($i == 1){
				$amount = strval($i) . " Ton";
				$data[$amount] = $i . " Ton";
			} else {
				$data[$amount] = $i . " Tons";
			}
		}*/

		$data = array(
			'0.5'	=>	'0.5 Ton',
			'1.0'	=>	'1.0 Ton',
			'1.5' 	=> 	'1.5 Tons',
			'2.0'  	=>	'2.0 Tons',
			'2.5'	=>	'2.5 Tons',
			'3.0'	=>	'3.0 Tons',
			'3.5'	=>	'3.5 Tons',
			'4.0'	=>	'4.0 Tons',
			'4.5'	=>	'4.5 Tons',
			'5.0'	=>	'5.0 Tons',
			'5.5'	=>	'5.5 Tons',
			'6.0'	=>	'6.0 Tons',
			'6.5'	=>	'6.5 Tons',
			'7.0'	=>	'7.0 Tons',
			'7.5'	=>	'7.5 Tons',
			'8.0'	=>	'8.0 Tons',
			'8.5'	=>	'8.5 Tons',
			'9.0'	=>	'9.0 Tons',
			'9.5'	=>	'9.5 Tons',
			'10.0'	=>	'10.0 Tons',
			'10.5'	=>	'10.5 Tons',
			'11.0'	=>	'11.0 Tons',
			'11.5'	=>	'11.5 Tons',
			'12.0'	=>	'12.0 Tons',
			'12.5'	=>	'12.5 Tons',
			'13.0'	=>	'13.0 Tons',
			'13.5'	=>	'13.5 Tons',
			'14.0'	=>	'14.0 Tons',
			'14.5'	=>	'14.5 Tons',
			'15.0'	=>	'15.0 Tons',
			'15.5'	=>	'15.5 Tons',
			'16.0'	=>	'16.0 Tons',
			'16.5'	=>	'16.5 Tons',
			'17.0'	=>	'17.0 Tons',
			'17.5'	=>	'17.5 Tons',
			'18.0'	=>	'18.0 Tons',
			'18.5'	=>	'18.5 Tons',
			'19.0'	=>	'19.0 Tons',
			'19.5'	=>	'19.5 Tons',
			'20.0'	=>	'20.0 Tons',
			'20.5'	=>	'20.5 Tons',
			'21.0'	=>	'21.0 Tons',
			'21.5'	=>	'21.5 Tons',
			'22.0'	=>	'22.0 Tons',
			'22.5'	=>	'22.5 Tons',
			'23.0'	=>	'23.0 Tons',
			'23.5'	=>	'23.5 Tons',
			'24.0'	=>	'24.0 Tons',
			'24.5'	=>	'24.5 Tons',
			'25.0'	=>	'25.0 Tons',
			'25.5'	=>	'25.5 Tons',
			'26.0'	=>	'26.0 Tons',
			'26.5'	=>	'26.5 Tons',
			'27.0'	=>	'27.0 Tons',
			'27.5'	=>	'27.5 Tons',
			'28.0'	=>	'28.0 Tons',
			'28.5'	=>	'28.5 Tons',
			'29.0'	=>	'29.0 Tons',
			'29.5'	=>	'29.5 Tons',
			'30.0'	=>	'30.0 Tons',
			'30.5'	=>	'30.5 Tons',
			'31.0'	=>	'31.0 Tons',
			'31.5'	=>	'31.5 Tons',
			'32.0'	=>	'32.0 Tons',
			'32.5'	=>	'32.5 Tons',
			'33.0'	=>	'33.0 Tons',
			'33.5'	=>	'33.5 Tons',
			'34.0'	=>	'34.0 Tons',
			'34.5'	=>	'34.5 Tons',
			'35.0'	=>	'35.0 Tons',
			'35.5'	=>	'35.5 Tons',
			'36.0'	=>	'36.0 Tons',
		);

		return array($data);
	}

	/*
	*	Bin Sizes
	*/
	private function binSizes(){

		$data = array(
			'0' 	=> 	'Empty 			-	 0 Ton',
			'0.5' 	=> 	'1/4 Cone  		-	 0.5 Ton',
			'1'		=>	'1/2 Cone		-	 1 Ton',
			'1.5' 	=> 	'3/4 Cone		-	 1.5 Ton',
			'2'  	=>	'Full Cone		-	 2 Tons',
			'2.5'	=>	'1/4 Ring		-	 2.5 Tons',
			'3'		=>	'1/2 Ring		-	 3 Tons',
			'3.5'	=>	'3/4 Ring		-	 3.5 Tons',
			'4'		=>	'1 Ring			-	 4 Tons',
			'4.5'	=>	'1 1/4 Ring		-	 4.5 Tons',
			'5'		=>	'1 1/2 Ring		-	 5 Tons',
			'5.5'	=>	'1 3/4 Ring		-	 5.5 Tons',
			'6'		=>	'2 Rings 		-	6 Tons',
			'6.5'	=>	'2 1/4 Rings 	-	6.5 Tons',
			'7'		=>	'2 1/2 Rings 	-	7 Tons',
			'7.5'	=>	'2 3/4 Rings 	-	7.5 Tons',
			'8'		=>	'3 Rings 		-	8 Tons',
			'8.5'	=>	'3 1/4 Rings 	-	8.5 Tons',
			'9'		=>	'3 1/2 Rings 	-	9 Tons',
			'9.5'	=>	'3 3/4 Rings 	-	9.5 Tons',
			'10'	=>	'4 Rings 		-	10 Tons',
			'10.5'	=>	'4 1/4 Rings 	-	10.5 Tons',
			'11'	=>	'4 1/2 Rings 	-	11 Tons',
			'11.5'	=>	'4 3/4 Rings 	-	11.5 Tons',
			'12'	=>	'5 Rings 		-	12 Tons'
		);

		return $data;

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


	/*
	*	binsAMount
	*/
	public function binAmount()
	{
		$data = $this->binSizes();

		/*$data = array();

		for($i=0.50;$i<=10;$i+=0.50){
			$rings = $i/2;
			$amount = strval($i);
			if($i <= 1){
				$amount = strval($i);
				$data[$amount] = "{$rings} Ring  ". $i . " Ton";
			} else {
				$data[$amount] = "{$rings} Rings  ". $i . " Tons";
			}
		}*/

		return array($data);
	}

	/*
	*	tons amount
	*/
	public function tonsAmount(){
		$data = array();
		for($i=0.50;$i<=20;$i+=0.50){
			$amount = strval($i) . " Tons";
			if($i == 1){
				$amount = strval($i) . " Ton";
				$data[$amount] = $i . " Ton";
			} else {
				$data[$amount] = $i . " Tons";
			}
		}

		return array($data);
	}

	public function amountsBins(){

		$rings = $this->binAmount();
		$tons = $this->tonsAmount();

		$output = $rings + $tons;

		return $output;
	}

	/**
	* driver
	*
	* @return Response
	*/
	public function driver()
	{
				$users = DB::table('feeds_user_accounts')
						->select('id','username')
						->where('type_id','=',2)
						->orderBy('username')
						->get();

				$u = array();
				$counter = count($users);
				for($i=0; $i<$counter; $i++){
					$u[$users[$i]->id] = $users[$i]->username;
				}


	      return $u+array(''=>'-');
	}

	/*
	*	Trucks
	*/
	public function trucks()
	{
		$trucks_cache = Cache::store('file')->get('trucks');

		if($trucks_cache == NULL){
			$trucks = Truck::lists('name','truck_id');
			Cache::forever('trucks',$trucks);
			$trucks_cache = Cache::store('file')->get('trucks');
		}
		return $trucks_cache;
	}

	/*
	*	deliveries
	*/
	public function deliveriesListAPI($data){

		$deliveries = $this->defaultDeliveriesAPI($data);
		//$drivers = $this->driver();
		//$farms = $this->farmsLists();
		//return array('deliveries'=>$deliveries,'drivers'=>$drivers,'farms'=>$farms);
		return $deliveries;
	}

	/*
	*	get deliveries information
	*/
	private function defaultDeliveriesAPI($data)
	{
		$data['delivery_number'] = str_replace("#","",$data['delivery_number']);
		/*
		if($data['farm_id'] != "0" && $data['farm_id'] != 0 && $data['driver'] != "0" && $data['driver'] != 0 && $data['delivery_number'] != "0" && $data['delivery_number'] != 0){
			// farm_id,driver_id,delivery_number
			$deliveries = $this->searchFarmDriverDeliveryNumberDeliveriesAPI($data);
		} elseif($data['farm_id'] != "0" && $data['farm_id'] != 0 && $data['driver'] != "0" && $data['driver'] != 0){
			// farm_id,driver_id
			$deliveries = $this->searchFarmDriverDeliveriesAPI($data);
		} elseif($data['driver'] != "0" && $data['driver'] != 0 && $data['delivery_number'] != "0" && $data['delivery_number'] != 0){
			// driver_id,delivery_number
			$deliveries = $this->searchDriverDeliveryNumberDeliveriesAPI($data);
		} elseif($data['farm_id'] != "0" && $data['farm_id'] != 0 && $data['delivery_number'] != "0" && $data['delivery_number'] != 0){
			// farm_id,delivery_number
			$deliveries = $this->searchFarmDeliveriesAPI($data);
		} else */

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
				'delivery_time'	=>	date("h:i a",strtotime($deliveries[$i]->delivery_date)),
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

	/*
	*	get deliveries information
	*/
	private function loadInformationAPI($delivery_date,$truck_id,$driver_id)
	{
		$data = array(
			'date'	=>	date('M d, A',strtotime($delivery_date)),
			'truck'	=>	$this->getDeliveriesTruck($truck_id),
			'delivery_time'	=>	date('H:i A',strtotime($delivery_date)),
			'driver'	=>	$this->getDeliveriesDriver($driver_id)
		);

		return $data;
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
	*	get deliveries information
	*/
	private function statusDeliveriesAPI($status)
	{

	}

	/*
	*	deliveries
	*/
	public function deliveriesPage(){
		if(Input::all() != NULL){
				$load_more = "false";
				$date = date("Y-m-d",strtotime(Input::get('date')));
				$farm = Input::get('farm');
				$driver = Input::get('driver');
				$unique_id = str_replace("","#",Input::get('delivery_number'));
				if(Input::get('date') != NULL && Input::get('date') != ""){
					$deliveries = $this->searchDateDeliveries($date);
				} elseif($farm != "please_select" && $farm != NULL){
					$deliveries = $this->searchFarmDeliveries($farm);
				} elseif(Input::get('driver') != "please_select" && Input::get('driver') != NULL){
					$deliveries = $this->searchDriverDeliveries($driver);
				} elseif($unique_id != "" && $unique_id != NULL){
					$deliveries = $this->searchUniqueIdDeliveries($unique_id);
				}  else {
					$load_more = "true";
					$deliveries = $this->defaultDeliveries();
				}
		} else {
			$load_more = "true";
			$deliveries = $this->defaultDeliveries();
		}

		$ctrl = new HomeController;

		$farms_lists = DB::table('feeds_farms')->orderBy('name','asc')->lists('name','id');
		$output = array('please_select'=>'Select Farm') + $farms_lists;
		$farms_list = $output;

		$drivers = DB::table('feeds_user_accounts')
				->where('type_id','=',2)
				->orderBy('username')
				->lists('username','id');
		$drivers = array('please_select'=>'Select Driver') + $drivers;
		$driver = $drivers;
		/*
		$deliveries_number = DB::table('feeds_deliveries')
													->select(DB::raw('DISTINCT(LEFT(feeds_deliveries.unique_id,7)) AS unique_id'))
													->get();
		$deliveries_number = $this->toArray($deliveries_number); */
		$deliveries_number = array("please_select"=>"Select Delivery Number");

		$choose = array(
			'please_select'=>'Please Select',
			'date'=>'Date',
			'farm'=>'Farm',
			'driver'=>'Driver',
			'delivery_number'=>'Delivery Number'
		);

		return view('home.deliveries', compact("deliveries","ctrl","farms_list","driver","deliveries_number","load_more","choose"));
	}

	/*
	*	search by date
	*/
	private function searchDateDeliveries($date)
	{
		$deliveries = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(feeds_deliveries.unique_id) AS unique_id'),
							'feeds_deliveries.status','feeds_deliveries.delivery_date',
							DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
							'feeds_truck.name AS truck_name',
							'feeds_user_accounts.username AS driver')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		return $deliveries;
	}

	/*
	*	search by farm
	*/
	private function searchFarmDeliveries($farm)
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
							->Where('feeds_deliveries.farm_id',$farm)
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->take(100)
							->get();

		return $deliveries;
	}

	/*
	*	search by farm
	*/
	private function searchFarmDriverDeliveryNumberDeliveriesAPI($data)
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
							->Where('feeds_deliveries.farm_id',$data['farm_id'])
							->Where('feeds_deliveries.driver_id',$data['driver'])
							->Where(DB::raw('LEFT(feeds_deliveries.unique_id,7)'),$data['delivery_number'])
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->take(100)
							->get();

		return $deliveries;
	}

	/*
	*	search by farm
	*/
	private function searchFarmDriverDeliveriesAPI($data)
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
							->Where('feeds_deliveries.farm_id',$data['farm_id'])
							->Where('feeds_deliveries.driver_id',$data['driver'])
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->take(100)
							->get();

		return $deliveries;
	}

	/*
	*	search by farm
	*/
	private function searchFarmDeliveryNumberDeliveriesAPI($data)
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
							->Where('feeds_deliveries.farm_id',$data['farm_id'])
							->Where('feeds_deliveries.driver_id',$data['driver'])
							->Where(DB::raw('LEFT(feeds_deliveries.unique_id,7)'),$data['delivery_number'])
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->take(100)
							->get();

		return $deliveries;
	}

	/*
	*	search by farm
	*/
	private function searchDriverDeliveryNumberDeliveriesAPI($data)
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
							->Where('feeds_deliveries.driver_id',$data['driver'])
							->Where(DB::raw('LEFT(feeds_deliveries.unique_id,7)'),$data['delivery_number'])
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->take(100)
							->get();

		return $deliveries;
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
	private function searchDriverDeliveries($driver)
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
							->Where('feeds_deliveries.driver_id',$driver)
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
	private function searchUniqueIdDeliveries($unique_id)
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
	private function defaultDeliveries()
	{
		$deliveries = DB::table('feeds_deliveries')
									->select(DB::raw('DISTINCT(feeds_deliveries.unique_id) AS unique_id'),
									'feeds_deliveries.status','feeds_deliveries.delivery_date',
									DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
									'feeds_truck.name AS truck_name',
									'feeds_user_accounts.username AS driver')
									->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
									->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
									->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
									->where('feeds_deliveries.delivery_label','!=','deleted')
									->groupBy('feeds_deliveries.unique_id')
									->orderBy('feeds_deliveries.delivery_date','DESC')
									->orderBy('feeds_deliveries.delivery_id','DESC')
									->take(10)
									->get();

		return $deliveries;
	}

	/*
	*	get the farms from the deliveries search query
	*/
	public function farmsDelivered()
	{
		$date = date("Y-m-d",strtotime(Input::get('delivery_date')));

		$deliveries = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(feeds_farms.name) as farm_name'),'feeds_farms.id as farm_id')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							//->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		return $deliveries;

	}

	/*
	*	get the drivers from the deliveries search query
	*/
	public function driversDelivered()
	{
		$date = date("Y-m-d",strtotime(Input::get('delivery_date')));

		$deliveries = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(feeds_user_accounts.username) as driver'),'feeds_user_accounts.id AS driver_id')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		return $deliveries;

	}

	/*
	*	get the delivery numbers from the deliveries search query
	*/
	public function deliveryNumberDelivered()
	{
		$date = date("Y-m-d",strtotime(Input::get('delivery_date')));

		$deliveries = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		return $deliveries;

	}

	/*
	*	get the drivers from the deliveries search query
	*/
	public function farmSelectDriverDelivered()
	{
		$date = date("Y-m-d",strtotime(Input::get('delivery_date')));
		$farm_id = Input::get('farm_id');

		$drivers = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(feeds_user_accounts.username) as driver'),'feeds_user_accounts.id AS driver_id')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.farm_id',$farm_id)
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		$unique_id = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.farm_id',$farm_id)
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		return array('drivers'=>$drivers,'unique_id'=>$unique_id);

	}

	/*
	*	get the drivers from the deliveries search query
	*/
	public function driverSelectDriverDelivered()
	{
		$date = date("Y-m-d",strtotime(Input::get('delivery_date')));
		$farm_id = Input::get('farm_id');
		$driver_id = Input::get('driver_id');


		$deliveries = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(CONCAT("#",LEFT(feeds_deliveries.unique_id,7))) AS delivery_number'),'feeds_deliveries.unique_id')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('feeds_deliveries.delivery_label','!=','deleted')
							->where('feeds_deliveries.farm_id',$farm_id)
							->where('feeds_deliveries.driver_id',$driver_id)
							->where('feeds_deliveries.delivery_date','LIKE',"%".$date."%")
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->get();

		return $deliveries;

	}

	/*
	*	get deliveries information
	*/
	public function getDeliveriesBinInfo($unique_id)
	{
	    	$data = "";
			$bins_info = Deliveries::select('bin_id','amount')->where('unique_id',$unique_id)->groupBy('bin_id')->get()->toArray();

			foreach($bins_info as $k => $v){
				$amount = Deliveries::where('bin_id',$v['bin_id'])->where('unique_id',$unique_id)->sum('amount');
				$bin_and_farm_name = $this->getDeliveriesBinName($v['bin_id']);
				$data .= $bin_and_farm_name . " <strong class='ton_vw_sched_kb'>(" . $amount . " Tons)</strong>" . "<br/>";
			}

			$data .= '<button type="button" class="btn btn-default btn-xs btn-modal'.$unique_id.'" data-toggle="modal" data-target="#delivery-modal'.$unique_id.'">View Info</button>';

			return $data;
	}

	/*
	*	Get Deliveries Information
	*/
	public function getDeliveries($unique_id)
	{
		$delivery = Deliveries::where('unique_id',$unique_id)->get()->toArray();

		return $delivery;
	}

	/*
	*	Delievries Bin name
	*/
	private function getDeliveriesBinName($bin_id)
	{
		$bin_name = Bins::select('alias','farm_id')->where('bin_id',$bin_id)->get()->toArray();

		$binName = !empty($bin_name[0]['alias']) ? $bin_name[0]['alias'] : "";
		$farmID = !empty($bin_name[0]['farm_id']) ? $bin_name[0]['farm_id'] : "";
		$farmName = $this->getDeliveriesFarmName($farmID);

		return $farmName . " - " . "<strong>".$binName."</strong>";
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
	*	Deliveries Page Driver
	*/
	public function getDeliveriesDriver($user_id)
	{
		$drivers = User::select('username')
					->where('type_id','=',2)
					->where('id',$user_id)
					->get()->toArray();

		return !empty($drivers[0]['username']) ? $drivers[0]['username'] : "-";
	}

	/*
	*	Deliveries Page Truck
	*/
	public function getDeliveriesTruck($truck_id)
	{
		$truck = Truck::select('name')
					->where('truck_id',$truck_id)
					->get()->toArray();

		return !empty($truck[0]['name']) ? $truck[0]['name'] : "-";
	}


	/*
	*	deliveries status counter
	*/
	public function deliveriesStatus($unique_id){

		$status = "";

		$loads  = Deliveries::where('unique_id','=',$unique_id)->count();

		$on_going = Deliveries::where('unique_id','=',$unique_id)->where('status','=',1)->count();

		$on_going_red = Deliveries::where('unique_id','=',$unique_id)->where('status','=',2)->count();

		$delivered = Deliveries::where('unique_id','=',$unique_id)->where('status','=',3)->count();

		$pending = Deliveries::where('unique_id','=',$unique_id)->where('status','=',0)->count();

		if($delivered == $loads){
			$status = "delivery_delivered";
		}elseif($on_going == $loads){
			$status = "delivery_ongoing_green";
		}elseif($on_going_red == $loads){
			$status = "delivery_ongoing_red";
		}elseif($pending == $loads){
			$status = "delivery_pending";
		}elseif($on_going_red == 1){
			$status = "delivery_ongoing_red";
		}else{
			$status = "delivery_ongoing_green";
		}

		return $status;
	}


	/*
	*	Delivery Status
	*/
	public function status($status){
		$status = ($status == 0 ? 'Pending' : 'Delivered');
		return $status;
	}


	/*
	*	Deliveries Load more
	*/
	public function deliveriesLoadMore()
	{
		$skip_items = Input::get('items_load');

		$deliveries = DB::table('feeds_deliveries')
							->select(DB::raw('DISTINCT(feeds_deliveries.unique_id) AS unique_id'),
							'feeds_deliveries.status','feeds_deliveries.delivery_date',
							DB::raw('GROUP_CONCAT(DISTINCT(feeds_farms.name)) as farm_names'),
							'feeds_truck.name AS truck_name',
							'feeds_user_accounts.username AS driver')
							->leftJoin('feeds_farms','feeds_farms.id','=','feeds_deliveries.farm_id')
							->leftJoin('feeds_truck','feeds_truck.truck_id','=','feeds_deliveries.truck_id')
							->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_deliveries.driver_id')
							->where('delivery_label','!=','deleted')
							->groupBy('feeds_deliveries.unique_id')
							->orderBy('feeds_deliveries.delivery_date','DESC')
							->orderBy('feeds_deliveries.delivery_id','DESC')
							->take(10)->skip($skip_items)
							->get();

		$ctrl = new HomeController;

		return view('home.ajax.deliveries', compact("deliveries","ctrl"));

	}

	/*
	*	get farm names
	*/
	private function farmNames($farms){
		$farm = explode(",",(string)$farms);
		$farm_name = DB::table('feeds_farms')
					->select(DB::raw('GROUP_CONCAT(name) as farm_name'))
					->whereIn('id',$farm)
					->first();
		return 	$farm_name->farm_name;
	}

	/*
	*	date format
	*/
	public function dateFormat($date){
		return date('m-d-Y h:i a',strtotime($date));
	}

	/*
	*	Bins
	*/
	public function getBins($farm_id)
    {
		$bins = DB::table('feeds_bins')
                     ->select('feeds_bins.*','feeds_bin_sizes.name AS bin_size_name', 'feeds_feed_types.name AS feed_type_name', 'feeds_feed_types.budgeted_amount')
					 ->leftJoin('feeds_bin_sizes','feeds_bin_sizes.size_id', '=', 'feeds_bins.bin_size')
					 ->leftJoin('feeds_feed_types','feeds_feed_types.type_id', '=', 'feeds_bins.feed_type')
                     ->where('farm_id', '=', $farm_id)
                     ->get();
        return $bins;
    }

	/*
	*	Get bins history
	*/
	public function getBinsUpdateHistory(){

		$graph_data = DB::table('feeds_bin_hgistory')
						->select('feeds_bin_hgistory.*')
						->get();
		return $graph_data;

	}

	/*
	*	Farmer
	*/
	public function getFarmer($farm_id)
	{
		$farmer = DB::table('feeds_farm_users')
					->select('feeds_farm_users.*','feeds_user_accounts.username', 'feeds_user_accounts.no_hash')
					->leftJoin('feeds_user_accounts','feeds_user_accounts.id','=','feeds_farm_users.user_id')
					->where('feeds_farm_users.farm_id','=',$farm_id)
					->count();
		$output = "";
		if($farmer == 0) {
			$output = "No Farmer";
		} else if($farmer == 1){
			$output = $farmer." Farmer";
		} else {
			$output = $farmer." Farmers";
		}

		return $output;
	}

	/*
	*	Bin Consumption
	*/
	public function binConsumption($bin_id){

		/*$data = DB::table('feeds_deliveries')
				->select('feeds_deliveries.*',
						'feeds_feed_types.budgeted_amount',
						'feeds_feed_types.name AS feed_name',
						'feeds_bins.num_of_pigs')
				->leftJoin('feeds_feed_types','feeds_feed_types.type_id','=','feeds_deliveries.feeds_type_id')
				->leftJoin('feeds_bins','feeds_bins.bin_id','=','feeds_deliveries.bin_id')
				->where('feeds_deliveries.bin_id','=',$bin_id)
				->orderBy('feeds_deliveries.delivery_date','desc')
				->get();*/

		$data = DB::table('feeds_bin_history')
				->select('feeds_bin_history.update_date',
						DB::raw('feeds_bin_history.amount * 2000 AS bin_level'),
						'feeds_bin_history.num_of_pigs')
				->where('feeds_bin_history.bin_id','=',$bin_id)
				->orderBy('feeds_bin_history.update_date','desc')
				->take(5)
				->get();

		return $data;
	}

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
	*	Next Delivery
	*/
	public function nextDelivery($farm_id,$bin_id){

		$data = Deliveries::where('farm_id','=',$farm_id)
				->select('*')
				->where('bin_id','=',$bin_id)
				->where('delivery_date','>',date('y-m-d h:m:s'))
				->where('delivery_label','active')
				->orderBy('delivery_date','desc')
				->first();


		// feeds_id
		$feed = $this->feedName($data['feeds_type_id']);

		// med_id
		$med = $this->medName($data['medication_id']);

		//return Carbon::createFromTimeStamp(strtotime($data['delivery_date']))->diffForHumans()." - ".date('jS \o\f F, Y g:i a',strtotime($data['delivery_date']));
		if($data['feeds_type_id'] != NULL){
			$output = $feed['name'] . ", " . $med['med_name'] .", ". date('m-d-Y',strtotime($data['delivery_date']));
		} else {
			$output = 'No delivery yet';
		}

		return $output;

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
							->first();

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
			$feed = $this->feedName($data->feeds_type_id);

			// med_id
			$med = $this->medName($data->medication_id);

			if($data[0]['feeds_type_id'] != NULL){
				$output = $feed['name'] . ", " . $med['med_name'] .", ". date('m-d-Y',strtotime($data->date_of_delivery));
			}


			// the amount is base on last update, because this delivery is not delivered yet
			$amount = BinsHistory::where('farm_id',$farm_id)
								->where('bin_id',$bin_id)
								->orderBy('history_id','desc')
								->orderBy('update_date','desc')
								->first();

			//$final = array('name'=> $output, 'amount' => $data != NULL ? $data[0]['amount'] . " T" : $amount[0]['amount'] . " T");
			$final = array('name'=> $output, 'amount' => $data != NULL ? $amount_final . " T" : $amount->amount . " T");

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
		$data =Medication::where('med_id','=',$medId)
				->select('*')
				->first();

		/*$output = "";

		foreach($data as $k => $v){
			$output = $v->med_name;
		}*/

		return $data;
	}

	/*
	*	Current Bin Capacity converted to pounds
	*/
	public function currentBinCapacity($bin_id){

		$data =  DB::table('feeds_bin_history')
				//->select(DB::raw('round(feeds_bin_history.amount * 2000,0) AS amount'))
				->select('amount')
				->where('bin_id',$bin_id)
				->orderBy('created_at','desc')
				->first();

		$r = $data == NULL ? 0 : round($data->amount * 2000,0);

		return $r;

	}



	/*
	*	Current Bin Capacity with extract value of tons
	*/
	public function currentBinCapExtract($bin_id){


		$data =  DB::table('feeds_bin_history')
				->select('feeds_bin_history.amount')
				->where('feeds_bin_history.update_date','<=',date('y-m-d h:m:s'))
				->where('feeds_bin_history.bin_id','=',$bin_id)
				->orderBy('feeds_bin_history.update_date','desc')
				->take(1)->get();

		if($data == NULL){

			$data = 0;

		} else {

			$data = json_decode(json_encode($data), true);

			foreach($data as $k => $v){
				$data = $v['amount'];
			}

		}

		return $data . "Tons";
	}

	/*
	*	Delivered Bin Capacity
	*/
	public function deliveredBinCapacity($farm_id,$bin_id){
		$data = Deliveries::where('farm_id','=',$farm_id)
				->select('delivery_date')
				->where('bin_id','=',$bin_id)
				->where('delivery_date','<=',date('y-m-d h:m:s'))
				->orderBy('delivery_date','desc')
				->take(1)->get();
		return $data;
	}


	/*
	*	Default Bin Capacity
	*/
	public function defaultBinCapacity($farm_id,$bin_id){

		$currentBinCapacity = $this->currentBinCapacity($bin_id);

		//$deliveredBinCapacity = $this->defaultBinCapacity($farm_id,$bin_id);

		//$defaultBinCapacity = $currentBinCapacity['amount'] + $deliveredBinCapacity;
		//dd($currentBinCapacity->amount);
		$currentBinCapacity = (!isset($currentBinCapacity) || $currentBinCapacity == NULL) ? 0 : $currentBinCapacity;

		return $currentBinCapacity;

	}

	/*
	*	totalConsumption()
	*	binlevel - currentbinamount
	*/
	public function totalConsumption($binlevel,$currentBinAmount){
		$totalConsumption = $binlevel - $currentBinAmount;
		return $totalConsumption;
	}

	/*
	*	consumptionRatePerPig()
	*	totalConsumption - number of pigs
	*/
	public function consumptionRatePerPig($totalConsumption,$numberOfPigs){
		if($numberOfPigs == 0){
			$consumptionRatePerPigs = 0;
		} else {
			$consumptionRatePerPigs = round($totalConsumption/$numberOfPigs,2);
		}
		return $consumptionRatePerPigs;
	}

	/*
	*	variance()
	*	consumptionRatePerPig - budgeted amount
	*/
	public function variance($consumptionRatePerPig,$budgetedAmount){
		$variance = $consumptionRatePerPig - $budgetedAmount;
		return $variance;
	}

	/*
	*	averageConsumption()
	*	number of pigs * budgeted amount
	*/
	public function averageConsumption($numberOfPigs,$budgetedAmount){
		$avgConsumption = $numberOfPigs * $budgetedAmount;
		return $avgConsumption;
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
	*	budgeted amount
	*/
	public function budgetedAmount($bin_id){

		$deliveries = Deliveries::where('bin_id','=',$bin_id)
						->select('feeds_type_id')
						->first();

		$feedTypes = FeedTypes::where('type_id','=',$deliveries['feeds_type_id'])
						->select('budgeted_amount')
						->first();
		$budgetedAmount = ($feedTypes['budgeted_amount'] != NULL ? $feedTypes['budgeted_amount'] : 'No budgeted amount yet' );

		return $budgetedAmount;
	}

	/*
	*	Medication
	*/
	public function medications($bin_id){

		$deliveries = Deliveries::where('bin_id','=',$bin_id)
						->select('medication_id')
						->first();

		$medTypes = DB::table('feeds_medication')
						->select('med_name')
						->where('med_id','=',$deliveries['medication_id'])
						->get();

		$pota = "";
		foreach($medTypes as $k => $v){
			$pota = $v->med_name;
		}

		$medications = ( $pota != NULL ? $pota : 'No medication yet' );

		return $medications;
	}

	/*
	* Test Cron
	*/
	public function conAutoUpdate(){

		Cache::forever('cachebuilder-status',"true");

		$farms = Farms::where('status',1)->get()->toArray();

		$forecastingData = array();
		$farms_count = count($farms)-1;
		for($i=0; $i<=$farms_count; $i++){
			$forecastingData[] = array(
				'farm_id'	=>	$farms[$i]['id'],
				'name'		=>	$farms[$i]['name'],
				'address'	=>	$farms[$i]['address'],
				'bins'		=> 	$this->testBinsData($farms[$i]['id'])
			);

		}

		$farmBins = $forecastingData;

		foreach($farmBins as $k => $v){

			$counter = count($v['bins'])-1;
			for($i=0; $i<=$counter; $i++){
				$this->updateBinHistory($v['bins'][$i]['bin_id'],NULL);
			}

		}

		// update the cache for forecasting
		//$this->forecastingDataCacheBuilder();

		/*
		$first = $forecastingData[0]['bins'][0]['bin_id'];

		$test = $this->testLastUpdateHistory(120);

		dd($test[0]['update_date']);
		*/
	}


	/*
	*	Get all the bins id via farm id
	*/
	private function testBinsData($farm_id){

		$bins = Bins::where('farm_id','=',$farm_id)
					->select('bin_id')
					->get()->toArray();

		return $bins;

	}

	/*
	*	build the update data
	*/
	private function testUpdate($data,$date_today){

		$update_date = date('Y-m-d',strtotime($data[0]['update_date']));

		//if($update_date != date('Y-m-d')){

			// get the days counted for the auto update budgeted amount
			// feeds_feed_type_budgeted_amount_per_day
			// get the last date inserted on the feeds_budgeted_amount_counter and count it on today's date then get the day column for that budgeted amount
			// if the day column has 0 get the last day column where it has a value that is not equal to zero
			$budgeted_amount = $this->daysCounterbudgetedAmount($data[0]['farm_id'],$data[0]['bin_id'],$data[0]['feed_type'],$date_today);


			//$amount = $this->calculateBin($data[0]['num_of_pigs'],$data[0]['budgeted_amount'],$data[0]['amount']);
			//$consumption = $this->calculateConPerPig($data[0]['num_of_pigs'],$data[0]['budgeted_amount'],$data[0]['amount']);

			$farms_data = Farms::where('id', $data[0]['farm_id'])->first();
			$pigs = $data[0]['num_of_pigs'];
			if($farms_data->farm_type == "farrowing"){
				$pigs = $data[0]['num_of_sow_pigs'];
			}

			$amount = $this->calculateBin($pigs,$budgeted_amount,$data[0]['amount']);
			$consumption = $this->calculateConPerPig($pigs,$budgeted_amount,$data[0]['amount']);

			$amount = $amount < 0 ? 0 : $amount;

			//feeds
			$feeds = FeedTypes::where('type_id','=',$data[0]['feed_type'])->get()->toArray();
			$update_type = $data[0]['update_type'];
			$budgeted_amount_tons =  0;
			if($update_type == 'Manual Update Bin Forecasting Admin' || $update_type == 'Manual Update Mobile Farmer' || $update_type == 'Delivery Manual Update Admin'){
				$budgeted_amount_tons = $data[0]['budgeted_amount_tons'];
				//$budgeted_amount_tons = ($budgeted_amount_tons*2000) - ($feeds[0]['budgeted_amount'] * $data[0]['num_of_pigs']);
				//$budgeted_amount_tons = $budgeted_amount_tons/2000;
			}



			$update_data = array(
				'update_date'		=>	$date_today,//date('Y-m-d H:i:s'),
				'bin_id'			=>	$data[0]['bin_id'],
				'farm_id'			=>	$data[0]['farm_id'],
				'num_of_pigs'		=>	$data[0]['num_of_pigs'],
				'num_of_sow_pigs'	=>	$data[0]['num_of_sow_pigs'],
				'user_id'			=>	1,//$data[0]['user_id'],
				'amount'			=>	$amount,
				'update_type'		=>	'Automatic Update Admin',
				'created_at'		=>	date('Y-m-d H:i:s'),
				'updated_at'		=>	date('Y-m-d H:i:s'),
				'budgeted_amount'	=>	$budgeted_amount,//$feeds[0]['budgeted_amount'],
				'budgeted_amount_tons'	=>	$budgeted_amount_tons,
				'remaining_amount'	=>	$data[0]['remaining_amount'],
				'sub_amount'		=>	$data[0]['sub_amount'],
				'variance'			=>	0,
				'consumption'		=>	$consumption,
				'admin'				=>	1,
				'medication'		=>	!empty($data[0]['medication']) ? $data[0]['medication'] : 8,
				'feed_type'			=>	$data[0]['feed_type'],
				'unique_id'			=>	!empty($data[0]['unique_id']) ? $data[0]['unique_id'] : "none"
				);

			$this->testUpdateSave($update_data,$data[0]['history_id']);

			$bin_size = Bins::where('bin_id','=',$data[0]['bin_id'])->first();
			$med_name = Medication::where('med_id','=',$data[0]['medication'])->first();
			$feed_name = FeedTypes::where('type_id','=',$data[0]['feed_type'])->first();

			// groups consumption
			$this->updateGroupsConsumption($data[0]['bin_id'],$amount,"automatic");

			// Mobile created date
			/*$time = date('H:i:s',strtotime($data[0]['update_date'])+60*60);
			$date = date('Y-m-d');
			$created_at = date('Y-m-d H:i:s', strtotime($date.$time));
			$user_created_at = date('Y-m-d H:i:s', strtotime($created_at)+60*60);*/

			// $mobile_data = array(
			// 	'bin_id'			=>	!empty($bin_size->bin_number) ? $bin_size->bin_number : 0,
			// 	'farm_id'			=>	$data[0]['farm_id'],
			// 	'user_id'			=>	1,//$data[0]['user_id'],
			// 	'current_amount'	=>	$amount,
			// 	'created_at'		=>	date('Y-m-d H:i:s'),
			// 	'budgeted_amount'	=>	$budgeted_amount,//$feeds[0]['budgeted_amount'],
			// 	'actual_amount'		=>	$amount,
			// 	'bin_size'			=>	!empty($bin_size->size_id) ? $bin_size->size_id : 0,
			// 	'variance'			=>	0,
			// 	'consumption'		=>	$consumption,
			// 	'feed_type'			=>	$data[0]['feed_type'],
			// 	'medication'		=>	!empty($data[0]['medication']) ? $data[0]['medication'] : 8,
			// 	'med_name'			=>	!empty($med_name->med_name) ? $med_name->med_name : 0,
			// 	'feed_name'			=>	!empty($feed_name->name) ? $feed_name->name : '-',
			// 	'user_created_at'	=>	date('Y-m-d H:i:s'),
			// 	'num_of_pigs'		=>	$data[0]['num_of_pigs'],
			// 	'bin_no_id'			=>	$data[0]['bin_id'],
			// 	'status'			=>	2,
			// 	'unique_id'			=>	!empty($data[0]['unique_id']) ? $data[0]['unique_id'] : "none"
			// );
			//
			// $this->mobileSaveAccepted($mobile_data);

		//}

	}

	private function testUpdateNoConsumption($data,$date_today){

		// get the days counted for the auto update budgeted amount
		// feeds_feed_type_budgeted_amount_per_day
		// get the last date inserted on the feeds_budgeted_amount_counter and count it on today's date then get the day column for that budgeted amount
		// if the day column has 0 get the last day column where it has a value that is not equal to zero
		$budgeted_amount = $this->daysCounterbudgetedAmount($data[0]['farm_id'],$data[0]['bin_id'],$data[0]['feed_type'],$date_today);

		//$amount = $this->calculateBin($data[0]['num_of_pigs'],$data[0]['budgeted_amount'],$data[0]['amount']);
		//$consumption = $this->calculateConPerPig($data[0]['num_of_pigs'],$data[0]['budgeted_amount'],$data[0]['amount']);

		$farms_data = Farms::where('id', $data[0]['farm_id'])->first();
		$pigs = $data[0]['num_of_pigs'];
		if($farms_data->farm_type == "farrowing"){
			$pigs = $data[0]['num_of_sow_pigs'];
		}


		$amount = $this->calculateBin($pigs,$budgeted_amount,$data[0]['amount']);
		$consumption = $this->calculateConPerPig($pigs,$budgeted_amount,$data[0]['amount']);

		$amount = $amount < 0 ? 0 : $amount;

		//feeds
		$feeds = FeedTypes::where('type_id','=',$data[0]['feed_type'])->get()->toArray();

		$update_data = array(
			'update_date'		=>	$date_today,//date('Y-m-d H:i:s'),
			'bin_id'			=>	$data[0]['bin_id'],
			'farm_id'			=>	$data[0]['farm_id'],
			'num_of_pigs'		=>	$data[0]['num_of_pigs'],
			'num_of_sow_pigs'	=>	$data[0]['num_of_sow_pigs'],
			'user_id'			=>	1,//$data[0]['user_id'],
			'amount'			=>	$amount,
			'update_type'		=>	'Automatic Update Admin',
			'created_at'		=>	date('Y-m-d H:i:s'),
			'updated_at'		=>	date('Y-m-d H:i:s'),
			'budgeted_amount'	=>	$budgeted_amount,//$feeds[0]['budgeted_amount'],
			'remaining_amount'	=>	$data[0]['remaining_amount'],
			'sub_amount'		=>	$data[0]['sub_amount'],
			'variance'			=>	0,
			'consumption'		=>	$consumption,
			'admin'				=>	1,
			'medication'		=>	!empty($data[0]['medication']) ? $data[0]['medication'] : 8,
			'feed_type'			=>	$data[0]['feed_type'],
			'unique_id'			=>	!empty($data[0]['unique_id']) ? $data[0]['unique_id'] : "none"
			);
		BinsHistory::where('history_id','=',$data[0]['history_id'])->update($update_data);

		$bin_size = Bins::where('bin_id','=',$data[0]['bin_id'])->first();
		$med_name = Medication::where('med_id','=',$data[0]['medication'])->first();
		$feed_name = FeedTypes::where('type_id','=',$data[0]['feed_type'])->first();

		// Mobile created date
		/*$time = date('H:i:s',strtotime($data[0]['update_date'])+60*60);
		$date = date('Y-m-d');
		$created_at = date('Y-m-d H:i:s', strtotime($date.$time));
		$user_created_at = date('Y-m-d H:i:s', strtotime($created_at)+60*60);*/

		// $mobile_data = array(
		// 	'bin_id'			=>	!empty($bin_size->bin_number) ? $bin_size->bin_number : 0,
		// 	'farm_id'			=>	$data[0]['farm_id'],
		// 	'user_id'			=>	1,//$data[0]['user_id'],
		// 	'current_amount'	=>	$amount,
		// 	'created_at'		=>	date('Y-m-d H:i:s'),
		// 	'budgeted_amount'	=>	$budgeted_amount,//$feeds[0]['budgeted_amount'],
		// 	'actual_amount'		=>	$amount,
		// 	'bin_size'			=>	!empty($bin_size->size_id) ? $bin_size->size_id : 0,
		// 	'variance'			=>	0,
		// 	'consumption'		=>	$consumption,
		// 	'feed_type'			=>	$data[0]['feed_type'],
		// 	'medication'		=>	!empty($data[0]['medication']) ? $data[0]['medication'] : 8,
		// 	'med_name'			=>	!empty($med_name->med_name) ? $med_name->med_name : 0,
		// 	'feed_name'			=>	!empty($feed_name->name) ? $feed_name->name : '-',
		// 	'user_created_at'	=>	date('Y-m-d H:i:s'),
		// 	'num_of_pigs'		=>	$data[0]['num_of_pigs'],
		// 	'bin_no_id'			=>	$data[0]['bin_id'],
		// 	'status'			=>	2,
		// 	'unique_id'			=>	!empty($data[0]['unique_id']) ? $data[0]['unique_id'] : "none"
		// );
		//
		// $this->mobileSaveAccepted($mobile_data);
		// $notification = new CloudMessaging;
		//
		// $notification->autoUpdateMessaging($update_data,$data[0]['history_id']);
		//
		// unset($notification);

		echo "0 Consumption update<br/>";
	}

	/*
	*	calculate the amount of the bin
	*/
	private function calculateBin($number_of_pigs,$budgeted_amount,$current_amount){
		$total_consumption = $number_of_pigs * $budgeted_amount;
		$current_bin_amount = round($current_amount - ($total_consumption/2000),2);
		return $current_bin_amount;
	}

	/*
	*	calculate consumption per pig
	*/
	private function calculateConPerPig($number_of_pigs,$budgeted_amount,$current_amount){

		if($number_of_pigs == 0){
			$consumption_per_pig = 0;
		} else{
			$total_consumption = $number_of_pigs * $budgeted_amount;
			$consumption_per_pig = ($total_consumption/$number_of_pigs);
		}

		return $consumption_per_pig;
	}

	/*
	*	save the automatic update data
	*/
	private function testUpdateSave($data,$history_id){

		if(BinsHistory::insert($data)){
			// $notification = new CloudMessaging;
			//
			// $notification->autoUpdateMessaging($data,$history_id);
			//
			// unset($notification);

			echo "Updated Successfully<br/>";
		} else {
			echo "Something went wrong<br/>";
		}

	}

	/*
	*	save the automaticv update data in the mobile bins accepted load
	*/
	private function mobileSaveAccepted($data){

		MobileBinsAcceptedLoad::insert($data);

	}

	/*
	*	History Updater
	*/
	public function updateBinHistory($bin_id,$date_to_update){

		if($date_to_update == NULL){
			$date_today = date("Y-m-d H:i:s");
			$date_yesterday = date("Y-m-d", time() - 60 * 60 * 24);//date("Y-m-d", time() - 60 * 60 * 24);
		} else {
			$date_today = date("Y-m-d", strtotime($date_to_update));
			$date_yesterday = date("Y-m-d", strtotime($date_today." -1 day"));
		}

		$previous_auto_update_data = BinsHistory::where('update_date','LIKE',$date_today.'%')
										->where('bin_id','=',$bin_id)
										->where('update_type','=','Automatic Update Admin')
										->orderBy('update_date','desc')
										->get()
										->toArray();

		// check if there's already automatic update
		if(empty($previous_auto_update_data)){

			// if update not exists, update data based on 0 consumption update
			$history = $this->historyFinder($date_today,$bin_id);
			if($history != NULL){
				if($history[0]['consumption'] == 0.0){
					$this->testUpdateNoConsumption($history,$date_today);
				}
			// update based on yesterday's update
			} else {
				$history = $this->historyFinder($date_yesterday,$bin_id);
				if($history != NULL){
					$this->testUpdate($history,$date_today);
				}
			}

		} else {
			echo "Nothing to update<br/>";

		}
	}

	/*
	*	Update finder query
	*/
	private function historyFinder($date,$bin_id){
		$date = date("Y-m-d",strtotime($date));
		$data = BinsHistory::where('update_date','LIKE',$date.'%')
					->where('bin_id',$bin_id)
					->orderBy('update_date','desc')
					->get()
					->toArray();
		return $data;
	}

	/*
	*	Farms and Bins Getter
	*/
	public function farmBinsGetter(){
		$farm_id = Input::get('farmId');
		$bin_id = Input::get('binId');

		$bin_size = Bins::select('bin_size')->where('bin_id','=',$bin_id)->first();

		$amounts = $this->getmyBinSize($bin_size->bin_size);

		$farms = Farms::where('id','=',$farm_id)->orderBy('name','asc')->lists('name','id')->toArray() + Farms::where('id','!=',$farm_id)->orderBy('name','asc')->lists('name','id')->toArray();

		$bins = Bins::select(DB::raw("CONCAT(bin_number,'-',alias) as bin_number"),'bin_id')->where('farm_id','=',$farm_id)->lists('bin_number','bin_id')->toArray();

		$output = array(
			'farms'	=>	$this->farmsArranged(), //$farms,
			'bins'	=>	$bins,
			'amounts'	=>	$amounts
		);

		return $output;

	}

	private function farmsArranged()
	{
		$farms = Farms::select('name','id')->orderBy('name','asc')->get();
		$data = array();

		foreach($farms as $k => $v){
			$data[$v->id] = $v->name;
		}

		return $data;
	}

	/*
	*	Amounts Lists
	*/
	public function amountsLists(){
		$bin_id = Input::get('binID');

		$bin_size = Bins::select('bin_size')->where('bin_id','=',$bin_id)->first();

		$amounts = $this->getmyBinSize($bin_size->bin_size);

		return $amounts;
	}

	/*
	*	Forecasting data sorter
	*/
	public function sortForecast(){

		$sort_type = Input::get('value');

		// clear the cache
		Cache::forget('sort_type');
		// save the cache
		Cache::forever('sort_type',$sort_type);
		// get thecache value
		$value = Cache::store('file')->get('sort_type');

		//$this->forecastingDataCacheBuilder($value);

		return $value;

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

			$farms_data = Farms::where('id', $farm_id)->first();
			$num_of_sow_pigs = $data[0]['num_of_sow_pigs'];
			if($farms_data->farm_type == "farrowing"){
				$num_of_sow_pigs = $data != NULL ? $data[0]['num_of_sow_pigs'] : $bins_data[0]['num_of_sow_pigs'];
			}

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
					'num_of_sow_pigs'				=>	$num_of_sow_pigs,
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

				// groups consumption
				$this->updateGroupsConsumption($bin_id,$data[0]['amount'] + $undone_deliveries[$i]['amount'],"manual");

			// insert
			} else {

				$budgeted_amount = $this->budgetedAmountUpdater($data[0]['feed_type'],$undone_deliveries[$i]['feeds_type_id'],$farm_id,$bin_id,$date_today);

				$data_to_insert = array(
					'update_date'				=>	$date_today,
					'bin_id'						=>	$bin_id,
					'farm_id'						=>	$farm_id,
					'num_of_pigs'				=>	$num_of_pigs,
					'num_of_sow_pigs'		=>	$num_of_sow_pigs,
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
					// $this->sendNotificationMarkAsDelivered($data_to_insert['unique_id'],$undone_deliveries[$i]['driver_id']);
				}

				// groups consumptions
				$this->updateGroupsConsumption($bin_id,$data[0]['amount'] + $undone_deliveries[$i]['amount'],"manual");

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
	*	Mark as delivered
	*/
	public function markDelivered(){

		$unique_id = Input::get('unique_id');

		$undone_deliveries = Deliveries::select(DB::raw("sum(amount) as amount"),"farm_id","feeds_type_id","medication_id","bin_id","driver_id","truck_id","unique_id","unload_by","status")
										->where('unique_id',$unique_id)
										->whereIn('status',[0,1,2])
										->groupBy('bin_id')
										->get()->toArray();

		foreach($undone_deliveries as $k => $v){

			$bin_id = $v['bin_id'];
			$farm_id = $v['farm_id'];

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
			// update
			if($update_date === $if_date_today){

				$feed_type = $v['feeds_type_id'] != NULL ? $v['feeds_type_id'] : $data[0]['feed_type'];

				$budgeted_amount = FeedTypes::where('type_id','=',$feed_type)->get()->toArray();

				// for the update budgeted
				if($v['feeds_type_id'] != $data[0]['feed_type']){
					// insert data to feeds_budgeted_amount_counter
					$budgeted_amount = $this->budgetedAmountCounterUpdater($farm_id,$bin_id,$v['feeds_type_id']);
				}else {
					// get the days counted for the auto update budgeted amount
					// feeds_feed_type_budgeted_amount_per_day
					// get the last date inserted on the feeds_budgeted_amount_counter and count it on today's date then get the day column for that budgeted amount
					// if the day column has 0 get the last day column where it has a value that is not equal to zero
					$budgeted_amount = $this->daysCounterbudgetedAmount($farm_id,$bin_id,$v['feeds_type_id'],$date_today);
				}

				$data_to_update = array(
					'update_date'		=>	$date_today,
					'amount'			=>	$data[0]['amount'] + $v['amount'],
					'budgeted_amount_tons'	=>	$data[0]['budgeted_amount_tons'] + $v['amount'],
					'actual_amount_tons'	=>	$data[0]['actual_amount_tons'] + $v['amount'],
					'bin_id'			=>	$bin_id,
					'farm_id'			=>	$farm_id,
					'num_of_pigs'		=>	$num_of_pigs,
					'user_id'			=> Auth::id(),
					'update_type'		=>	'Delivery Manual Update Admin',
					'admin'				=>	1,
					'created_at'		=>	$date_today,
					'updated_at'		=>	$date_today,
					'budgeted_amount'	=>	$budgeted_amount, //$budgeted_amount[0]['budgeted_amount'],
					'remaining_amount'	=>	0,
					'sub_amount'		=>	0,
					'variance'			=>	$data[0]['variance'],
					'consumption'		=>	$data[0]['consumption'],
					'medication'		=>	$v['medication_id'] != NULL ? $v['medication_id'] : $data[0]['medication'],
					'feed_type'			=>	$v['feeds_type_id'] != NULL ? $v['feeds_type_id'] : $data[0]['feed_type'],
					'unique_id'			=>	$v['unique_id']
				);

				if($v['unload_by'] == "admin"){
					$this->updateFeedsHistoryData($data_to_update);
					$this->markAsdeliveredBinsAcceptedLoad($data_to_update);
					// $this->sendNotificationMarkAsDelivered($data_to_update['unique_id'],$v['driver_id']);
				}

				// if($v['status'] == 2){
				// 	//$this->markAsdeliveredBinsAcceptedLoad($data_to_update);
				// 	$this->sendNotificationMarkAsDelivered($data_to_update['unique_id'],$v['driver_id']);
				// }

			// insert
			} else {

				$feed_type = $v['feeds_type_id'] != NULL ? $v['feeds_type_id'] : $data[0]['feed_type'];

				$budgeted_amount = FeedTypes::where('type_id','=',$feed_type)->get()->toArray();

				// for the update budgeted
				if($v['feeds_type_id'] != $data[0]['feed_type']){
					// insert data to feeds_budgeted_amount_counter
					$budgeted_amount = $this->budgetedAmountCounterUpdater($farm_id,$bin_id,$v['feeds_type_id']);
				}else {
					// get the days counted for the auto update budgeted amount
					// feeds_feed_type_budgeted_amount_per_day
					// get the last date inserted on the feeds_budgeted_amount_counter and count it on today's date then get the day column for that budgeted amount
					// if the day column has 0 get the last day column where it has a value that is not equal to zero
					$budgeted_amount = $this->daysCounterbudgetedAmount($farm_id,$bin_id,$v['feeds_type_id'],$date_today);
				}

				$data_to_insert = array(
					'update_date'		=>	$date_today,
					'bin_id'			=>	$bin_id,
					'farm_id'			=>	$farm_id,
					'num_of_pigs'		=>	$num_of_pigs,
					'user_id'			=>	Auth::id(),
					'amount'			=>	$amount + $v['amount'],
					'update_type'		=>	'Delivery Manual Update Admin',
					'created_at'		=>	$date_today,
					'updated_at'		=>	$date_today,
					'budgeted_amount'	=>	$budgeted_amount, //$budgeted_amount[0]['budgeted_amount'],
					'remaining_amount'	=>	0,
					'sub_amount'		=>	0,
					'variance'			=>	0,
					'consumption'		=>	0,
					'admin'				=>	1,
					'medication'		=>	$v['medication_id'] != NULL ? $v['medication_id'] : $medication,
					'feed_type'			=>	$v['feeds_type_id'] != NULL ? $v['feeds_type_id'] : $feed_type,
					'unique_id'			=>	$v['unique_id']
				);



				if($v['unload_by'] == "admin"){
					$this->saveFeedsHistoryData($data_to_insert);
					//$this->sendNotificationMarkAsDelivered($data_to_insert['unique_id'],$v['driver_id']);
					$this->markAsdeliveredBinsAcceptedLoad($data_to_insert);
				}

				// if($v['status'] == 2){
				// 	//$this->markAsdeliveredBinsAcceptedLoad($data_to_update);
				// 	$this->sendNotificationMarkAsDelivered($data_to_update['unique_id'],$v['driver_id']);
				// }

			}

			//$this->binsDataCacheBuilder($farm_id);
			$this->farmHolderBinClearCache($bin_id);

		}

		SchedTool::where('delivery_unique_id',$unique_id)->update(['status'=>'delivered']);
		$update = Deliveries::where('unique_id',$unique_id)
				->update(['status'=>3,'delivered'=>1,'compartment_status'=>3]);

		// update feeds_mobile_notification
		DB::table('feeds_mobile_notification')->where('unique_id',$unique_id)->update(['is_readred'=>'true']);

		$del_ = Deliveries::where('unique_id',$unique_id)->get()->toArray();
		for($i=0;$i<count($del_);$i++){
			$this->clearBinsCache($del_[$i]['bin_id']);
			$this->farmHolderBinClearCache($del_[$i]['bin_id']);
		}

		// mark delivered driver stats tons
		//$this->markDeliveredDriverStats($undone_deliveries[0]['driver_id'],$undone_deliveries[0]['farm_id'],$unique_id);



		$this->forecastingDataCache();

		return $update;
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
	*	Cache the feeds_feed_type_budgeted_amount_per_day
	*/
	private function cachedBudgetedAmountPerDay($feed_type_id)
	{
		// get the days counted column
		$days = DB::table('feeds_feed_type_budgeted_amount_per_day')
							->where('feed_type_id',$feed_type_id)
							->orderBy('id','desc')
							->first();
		$days = $this->toArray($days);
		Cache::forever("budgeted_amount_per_day_".$feed_type_id,$days);

	}

	/*
	*	Cache the feeds_feed_type_budgeted_amount_per_day
	*/
	public function createCacheBudgetedAmountPerDay()
	{
		$feed_types = FeedTypes::select('type_id')->get()->toArray();
		for($i=0;$i<count($feed_types);$i++){
			$this->cachedBudgetedAmountPerDay($feed_types[$i]['type_id']);
		}
	}


	/*
	* mark delivered feeds_driver_stats_delivery_time
	* for tons delivered in driver stats.
	*/
	private function markDeliveredDriverStats($driver_id,$farm_id,$unique_id)
	{

		$driver_stats = DB::table('feeds_driver_stats_time_at_farm')->where('deliveries_unique_id',$unique_id)->get();
		$date_today = date("Y-m-d H:i:s");

		if($driver_stats != NULL){

			foreach($driver_stats as $k => $v){
				if($v->start_time == "0000-00-00 00:00:00" && $v->end_time == "0000-00-00 00:00:00"){
					// update the start time and end time
					DB::table('feeds_driver_stats_time_at_farm')->where('id',$v->id)->update(array('start_time'=>$date_today,'end_time'=>$date_today));
				} else if($v->start_time != "0000-00-00 00:00:00" && $v->end_time == "0000-00-00 00:00:00"){
					// update the end time
					DB::table('feeds_driver_stats_time_at_farm')->where('id',$v->id)->update(array('end_time'=>$date_today));
				} else {
					// none
				}
			}

		} else {
			// tons delivered
			$tons_delivered = Deliveries::where('unique_id',$unique_id)->sum('amount');
			// insert to feeds_driver_stats
			$driver_stats = array(
				'date'	=>	date('Y-m-d'),
				'driver_id'	=>	$driver_id,
				'tons_delivered'	=>	$tons_delivered,
				'deliveries_unique_id'	=>	$unique_id
			);

			DB::table('feeds_driver_stats')->insert($driver_stats);

			DB::table('feeds_driver_stats_time_at_farm')->insert(array('farm_id'=>$farm_id,'start_time'=>$date_today,'end_time'=>$date_today,'deliveries_unique_id'=>$unique_id));
		}

	}

	/*
	*	Delete delivered item
	*/
	public function deleteDeliveredAPI($unique_id)
	{

		$deliveries = Deliveries::where('unique_id',$unique_id)->get()->toArray();

		$farm_sched = FarmSchedule::select('unique_id')->where('delivery_unique_id',$unique_id)->first()->toArray();
		if($farm_sched != NULL){
			DB::table('feeds_batch')->where('unique_id',$farm_sched['unique_id'])->delete();
		}

		// delete driver stats
		$this->deleteDriverStats($unique_id);

		// $notification = new CloudMessaging;
		//
		// 	$notification_data_driver = array(
		// 		'unique_id'		=> 	$deliveries[0]['unique_id'],
		// 		'driver_id'		=> 	$deliveries[0]['driver_id']
		// 		);
		//
		// 	$notification->deleteDeliveryNotifier($notification_data_driver);

			foreach($deliveries as $k => $v){

				Cache::forget('bins-'.$v['bin_id']);
				Cache::forget('farm_holder_bins_data-'.$v['bin_id']);
				Cache::forget('farm_holder-'.$v['farm_id']);

				// $notification_data_farmer = array(
				// 	'farm_id'		=> 	$v['farm_id'],
				// 	'unique_id'		=> 	$v['unique_id']
				// 	);
				//
				// $notification->deleteDeliveryNotifier($notification_data_farmer);

			}

		// unset($notification);
		SchedTool::where('delivery_unique_id',$unique_id)->delete();
		FarmSchedule::where('delivery_unique_id',$unique_id)->delete();
		if(Deliveries::where('unique_id',$unique_id)->update(['delivery_label'=>'deleted'])){
			$this->forecastingDataCache();
			return "deleted";
		}

		return "failed to delete";
	}

	/*
	*	Delete delivered item
	*/
	public function deleteDelivered()
	{
		$unique_id = Input::get('unique_id');

		$deliveries = Deliveries::where('unique_id',$unique_id)->get()->toArray();

		// delete driver stats
		// $this->deleteDriverStats($unique_id);
		//
		// $notification = new CloudMessaging;
		//
		// 	$notification_data_driver = array(
		// 		'unique_id'		=> 	$deliveries[0]['unique_id'],
		// 		'driver_id'		=> 	$deliveries[0]['driver_id']
		// 		);
		//
		// 	$notification->deleteDeliveryNotifier($notification_data_driver);

			foreach($deliveries as $k => $v){

				Cache::forget('bins-'.$v['bin_id']);
				Cache::forget('farm_holder_bins_data-'.$v['bin_id']);
				Cache::forget('farm_holder-'.$v['farm_id']);

				// $notification_data_farmer = array(
				// 	'farm_id'		=> 	$v['farm_id'],
				// 	'unique_id'		=> 	$v['unique_id']
				// 	);
				//
				// $notification->deleteDeliveryNotifier($notification_data_farmer);

			}

		// unset($notification);
		SchedTool::where('delivery_unique_id',$unique_id)->delete();
		FarmSchedule::where('delivery_unique_id',$unique_id)->delete();
		if(Deliveries::where('unique_id',$unique_id)->update(['delivery_label'=>'deleted'])){
			$this->forecastingDataCache();
			return "deleted";
		}

		return "failed to delete";
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
		DB::table('feeds_driver_stats_total_miles')->where('deliveries_unique_id',$unique_id)->delete();
		DB::table('feeds_mobile_notification')->where('unique_id',$unique_id)->delete();
	}

	/*
	*	Mark as delivered Mobile bins accepted load
	*/
	private function markAsdeliveredBinsAcceptedLoad($data){

		if($data['medication'] == 8){
			$data['medication'] = 0;
		}

		$bin_number = Bins::where('bin_id',$data['bin_id'])->first()->toArray();
		$bin_size = BinSize::where('size_id',$bin_number['bin_size'])->first()->toArray();
		$med_name = Medication::where('med_id',$data['medication'])->first()->toArray();
		$feed_name = FeedTypes::where('type_id',$data['feed_type'])->first()->toArray();

		// send mobile notification
		// $mobile_data = array(
		// 	'bin_id'					=>	$bin_number['bin_number'],  //bin number
		// 	'farm_id'					=>	$data['farm_id'],
		// 	'user_id'					=>	1,
		// 	'current_amount'	=>	$data['amount'],
		// 	'created_at'			=>	date('Y-m-d H:i:s'),
		// 	'budgeted_amount'	=>	$data['budgeted_amount'],
		// 	'actual_amount'		=>	$data['amount'],
		// 	'bin_size'				=>	$bin_size['ring'], // ring
		// 	'variance'				=>	$data['variance'],
		// 	'consumption'			=>	$data['consumption'],
		// 	'feed_type'				=>	$data['feed_type'],
		// 	'medication'			=>	$data['medication'],
		// 	'med_name'				=>	!empty($med_name['med_name']) ? $med_name['med_name'] : 0,
		// 	'feed_name'				=>	$feed_name['name'],
		// 	'user_created_at'	=>	date('Y-m-d H:i:s'),
		// 	'num_of_pigs'			=>	$data['num_of_pigs'],
		// 	'bin_no_id'				=>	$data['bin_id'], // bin id
		// 	'status'					=>	2,
		// 	'unique_id'				=>	$data['unique_id']
		// );
		//
		// $this->mobileSaveAccepted($mobile_data);

	}

	/*
	*	Mobile notification for mark as delivered
	*
	*/
	// private function sendNotificationMarkAsDelivered($unique_id,$driver_id){
	//
	// 	$mobile_data = Deliveries::select('unique_id','driver_id')
	// 													->where('driver_id',$driver_id)
	// 													->where('unique_id',$unique_id)
	// 													->first();
	// 	$mobile_data = array(
	// 		'unique_id'	=>	$mobile_data->unique_id,
	// 		'driver_id'	=>	$mobile_data->driver_id
	// 	);
	//
	// 	Cache::forever('mobile_data',$mobile_data);
	//
	// 	event(new MarkDelivered());
	//
	// 	//Cache::forget('mobile_data');
	// 	/*
	//
	// 	$this->sendNotificationMarkAsDeliveredFarmer($unique_id);
	//
	// 	$mobile = new CloudMessaging;
	//
	// 	$mobile->markasDelivered($mobile_data);
	//
	// 	unset($mobile);
	// 	*/
	//
	// }

	/*
	*	Mobile notification for mark as delivered
	*
	*/
	// private function sendNotificationMarkAsDeliveredFarmer($unique_id){
	//
	// 	/*
	// 	$deliveries = Deliveries::where('unique_id',$unique_id)->get()->toArray();
	//
	// 	$mobile = new CloudMessaging;
	//
	// 	foreach($deliveries as $k => $v){
	//
	// 		$bins = Bins::where('bin_id',$v['bin_id'])->first()->toArray();
	//
	// 		$data = array(
	// 			'farm_id'			=>	$v['farm_id'],
	// 			'bin_no'			=>	$bins['bin_number'],
	// 			'compartment'		=>	$v['compartment_number'],
	// 			'bin_id'			=>	$v['bin_id'],
	// 			'unique_id'			=>	$v['unique_id'],
	// 			'date_of_delivery'	=>	$v['delivery_date'],
	// 			'delivery_status'	=>	'unload'
	// 		);
	//
	// 		$mobile->markasDeliveredFarmer($data);
	//
	// 	}
	//
	// 	unset($mobile);
	// 	*/
	// }


	/*
	*	feeds_bin_history data to update
	*/
	private function feedsHistoryData($bin_id,$farm_id){
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
	*	Insert the feeds_bin_history for mark as delivered item for admin
	*/
	private function saveFeedsHistoryData($data){
		if($data != NULL){
			BinsHistory::insert($data);
		}
	}

	/*
	*	Update the feeds_bin_history for mark as delivered item for admin
	*/
	private function updateFeedsHistoryData($data){
		if($data != NULL){
			BinsHistory::where('update_date','LIKE',date("Y-m-d",strtotime($data['update_date'])).'%')
						->where('bin_id','=',$data['bin_id'])
						->where('farm_id','=',$data['farm_id'])
						->update($data);
		}
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

				$data->update();

				//Event::fire(new CallBinsHistory($data));

		}

	}

	/*
	*	Call cache builder for bins
	*/
	public function curlBinsCache(){
		// create curl resource
        $ch = curl_init();

        // set url
        curl_setopt($ch, CURLOPT_URL, url()."/binscachebuilder");

        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // $output contains the output string
        $output = curl_exec($ch);
		echo $output;
        // close curl resource to free up system resources
        curl_close($ch);
	}

	/*
	*	Pending delivery items
	*/
	private function pendingDeliveryItems($farmId)
	{
		$farm_schedule = FarmSchedule::where('farm_id',$farmId)->select('status')->where('status',0)->where('date_of_delivery','>',date('Y-m-d'))->count();

		if($farm_schedule > 0) {
			return $farm_schedule;
		}

		$deliveries = Deliveries::where('farm_id',$farmId)->select('delivered')->where('delivered',0)->where('delivery_label','active')->where('delivery_date','>',date('Y-m-d H:i:s'))->count();
		return $deliveries;
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

}
