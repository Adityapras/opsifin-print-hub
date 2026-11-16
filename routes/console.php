<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('hub:pull-sources')->everyTenSeconds()->withoutOverlapping(1);
Schedule::command('hub:process-callbacks')->everyTenSeconds()->withoutOverlapping(1);
Schedule::command('hub:release-expired-job-leases')->everyTenSeconds()->withoutOverlapping(1);
Schedule::command('hub:dispatch-refetch-retries')->everyMinute()->withoutOverlapping(1);
Schedule::command('hub:reconcile-agent-updates')->everyMinute()->withoutOverlapping(1);
Schedule::command('hub:sync-printer-statuses')->everyTenSeconds()->withoutOverlapping(1);
