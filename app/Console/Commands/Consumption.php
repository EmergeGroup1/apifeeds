<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Consumption extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'consumption';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the consumptions of the feed bins';

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
        return $this->forecastingDataCacheBuilder();
    }


    /*
  	*	Cache forecasting data builder
  	*/
  	public function forecastingDataCacheBuilder(){

  		// create curl resource
      $ch = curl_init();

      // set url
      curl_setopt($ch, CURLOPT_URL, 'http://apifeeds.carrierinsite.com/conautoupdate');

      //return the transfer as a string
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

      // $output contains the output string
      $output = curl_exec($ch);
			echo $output;
      // close curl resource to free up system resources
      curl_close($ch);

  	}
}
