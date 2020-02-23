<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ForecastingCache extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'forecastingdatacache';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Build the cache for the forecasting';

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
    // create curl resource
    $ch = curl_init();

    // set url
    curl_setopt($ch, CURLOPT_URL, 'http://apifeeds.carrierinsite.com/forecastdata');

    //return the transfer as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // $output contains the output string
    $output = curl_exec($ch);
    echo $output;
    // close curl resource to free up system resources
    curl_close($ch);
  }
}
