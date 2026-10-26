<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('po:check-missing-pi-alerts')->dailyAt('09:00')->timezone('Asia/Dhaka');
// TnA tasks from auto-apply templates first, so the reminder that follows counts them.
Schedule::command('tna:apply')->dailyAt('08:15')->timezone('Asia/Dhaka');
Schedule::command('followups:remind')->dailyAt('08:30')->timezone('Asia/Dhaka');
