<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class schedulingcache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedulingcache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the scheduling cache data';

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
      //Artisan::command('queue:work');
      // create curl resource
      $ch = curl_init();

      // set url
      curl_setopt($ch, CURLOPT_URL, 'http://apifeeds.carrierinsite.com/schedulingcache');

      //return the transfer as a string
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

      // $output contains the output string
      $output = curl_exec($ch);
      echo $output;
      // close curl resource to free up system resources
      curl_close($ch);
    }
}
