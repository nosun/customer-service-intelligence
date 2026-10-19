<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('helpdesk:sync-incremental --silo=tickets')
    ->everyTenMinutes()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(10);

Schedule::command('helpdesk:sync-incremental')
    ->hourlyAt(5)
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(30);

Schedule::command('helpdesk:analyze')
    ->dailyAt('02:30')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(60);

Schedule::command('chatbot:sync --chat-limit=80')
    ->cron('17 */6 * * *')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(30);

Schedule::command('product-questions:reap-draft-requests')
    ->everyMinute()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(5);

Schedule::command('tickets:reap-draft-work-items')
    ->everyMinute()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(5);

Schedule::command('tickets:draft-workflow-health --hours=1 --fail-on-unhealthy')
    ->everyFiveMinutes()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(5)
    ->onFailure(fn () => Log::critical('Ticket n8n Draft workflow health check failed.'));

Schedule::command('product-questions:draft-workflow-health --hours=1 --fail-on-unhealthy')
    ->everyFiveMinutes()
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(5)
    ->onFailure(fn () => Log::critical('Product question n8n Draft workflow health check failed.'));
