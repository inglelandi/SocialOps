<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reviews:remind')->hourly();
Schedule::command('slots:check')->weeklyOn(4, '09:00');
Schedule::command('inbox:pull')->hourlyAt(15);
Schedule::command('products:pull')->dailyAt('05:40'); // the shops' product feeds, before the day's planning
Schedule::command('claude:check-queue')->hourlyAt(20); // the Claude worker runs at :56

// Everything that talks to the platform APIs waits with the publishing flag (config/features.php)
if (config('features.publishing')) {
    Schedule::command('posts:publish-due')->everyMinute();
    Schedule::command('accounts:check-tokens')->dailyAt('08:00');
    Schedule::command('posts:preflight')->hourlyAt(30);
    Schedule::command('comments:fetch')->hourly();
    Schedule::command('posts:insights')->dailyAt('06:00');
    Schedule::command('reports:monthly')->monthlyOn(1, '07:00');
    Schedule::command('listening:report')->weeklyOn(1, '07:00');
}
