<?php

use App\Jobs\CheckSlaTimers;
use App\Jobs\SendActionItemDigest;
use App\Jobs\SendRcaReminders;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (specification section 2). Run `php artisan schedule:work` locally, or the
| cron entry `* * * * * php artisan schedule:run` in production.
*/

Schedule::job(new CheckSlaTimers)->everyMinute()->name('check-sla-timers')->withoutOverlapping(5)->onOneServer();

// RCA reminders (specification section 8), at the start of the working day where the team is.
Schedule::job(new SendRcaReminders)->dailyAt('08:00')->timezone(config('incidents.display_timezone'))->name('send-rca-reminders')->onOneServer();
Schedule::job(new SendActionItemDigest)->weeklyOn(1, '08:00')->timezone(config('incidents.display_timezone'))->name('send-action-item-digest')->onOneServer();
