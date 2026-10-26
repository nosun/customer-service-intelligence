<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncHelpDeskTickets implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3500;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        if (Artisan::call('helpdesk:sync-incremental') !== 0) {
            throw new RuntimeException('HelpDesk synchronization failed. Check the account sync error.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put('helpdesk:sync-state', ['status' => 'failed', 'finished_at' => now()->toIso8601String()], 3600);
    }
}
