<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('privacy:erase-expired-participants')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('security:prune-audit-logs')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('security:prune-participant-access')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=24')->dailyAt('02:45')->withoutOverlapping();
Schedule::command('queue:prune-batches --hours=24 --unfinished=72 --cancelled=72')->dailyAt('02:50')->withoutOverlapping();
Schedule::command('security:prune-email-deliveries')->dailyAt('03:00')->withoutOverlapping();
