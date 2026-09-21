<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('school:notifications')->everyMinute()->withoutOverlapping(10);
Schedule::command('school:monthly-invoices')->dailyAt('00:10')->withoutOverlapping(10);
Schedule::command('school:late-fees')->dailyAt('00:30')->withoutOverlapping(10);
Schedule::command('school:backup')->dailyAt('02:00')->withoutOverlapping(30);
Schedule::command('school:payroll')->dailyAt('00:40')->withoutOverlapping(10);
Schedule::command('platform:generate-invoices')->dailyAt('00:10')->withoutOverlapping(10);
Schedule::command('platform:mark-overdue-invoices')->dailyAt('00:20')->withoutOverlapping(10);
Schedule::command('platform:prune-exports')->dailyAt('01:10')->withoutOverlapping(10);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
