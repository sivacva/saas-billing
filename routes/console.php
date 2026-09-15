<?php

use App\Jobs\GenerateInvoicesAtCycleEnd;
use App\Jobs\RollUpDailyUsage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rolls up yesterday's usage first, so invoices generated an hour later see
// complete totals for any period ending today.
Schedule::job(new RollUpDailyUsage)->dailyAt('01:00');
Schedule::job(new GenerateInvoicesAtCycleEnd)->dailyAt('02:00');
