<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:heartbeat')->everyMinute();
Schedule::command('security:relay-email-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('security:prune-participant-access')->hourly()->withoutOverlapping();
Schedule::command('privacy:erase-expired-participants')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('security:prune-email-deliveries')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('security:prune-audit-logs')->dailyAt('03:00')->withoutOverlapping();
