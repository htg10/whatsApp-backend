<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publish scheduled Facebook/Instagram posts when their time arrives.
// Requires Hostinger cron: * * * * * php /path/artisan schedule:run
Schedule::command('social:publish-due')->everyMinute()->withoutOverlapping();
