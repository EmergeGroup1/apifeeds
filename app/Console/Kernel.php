<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        'App\Console\Commands\Consumption',
        'App\Console\Commands\TurnOnFarms',
    		'App\Console\Commands\BuildBinsCache',
    		'App\Console\Commands\ForecastingCache',
        'App\Console\Commands\SchedulingCache',
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')
        //          ->hourly();
        $schedule->command('forecastingdatacache')->everyMinute();
        $schedule->command('schedulingcache')->everyMinute();
        $schedule->command('consumption')->dailyAt(env('CN_TIME'));
        $schedule->command('turnonfarms')->dailyAt('00:30');
    	  $schedule->command('buildbinscache')->dailyAt('01:10');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
