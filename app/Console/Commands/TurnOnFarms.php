<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use App\Farms;

class TurnOnFarms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'turnonfarms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Turn on the farms that has inactive state';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->turnOn();
    }

	/*
	*	turn on the farms
	*/
	private function turnOn(){

		$farms = Farms::where('status',0)->where('reactivation_date',date('Y-m-d'))->get()->toArray();

		foreach($farms as $k => $v){
			$this->updateFarm($v['id']);
		}

	}

	/*
	*	update farms
	*/
	private function updateFarm($farm_id){

		$update = Farms::where('id',$farm_id)
				->update(['reactivation_date'=>NULL,'status'=>1]);

		return $update;

	}

}
