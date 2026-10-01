<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('helpdesk:sync-incremental')
    ->hourly()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(30);

Schedule::command('helpdesk:analyze')
    ->dailyAt('02:30')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(60);
