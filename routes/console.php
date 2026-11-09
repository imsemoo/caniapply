<?php

use Illuminate\Support\Facades\Schedule;

$timezone = config('hunter.timezone');

$jobs = [
    'hunter:discover' => 'discover',
    'hunter:digest' => 'digest',
    'hunter:recheck' => 'recheck',
    'hunter:weekly-report' => 'weekly_report',
    'hunter:backup' => 'backup',
    'hunter:fx-refresh' => 'fx',
];

foreach ($jobs as $command => $key) {
    Schedule::command($command)
        ->cron(config("hunter.schedule.{$key}"))
        ->timezone($timezone)
        ->withoutOverlapping(60)
        ->onOneServer();
}

Schedule::command('hunter:telegram-poll')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Picks up anything a crash left unscored.
Schedule::command('hunter:reevaluate')->hourly()->withoutOverlapping(30)->onOneServer();

Schedule::command('queue:prune-failed --hours=168')->daily();
