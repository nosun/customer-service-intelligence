<?php

namespace App\Jobs;

use App\Models\WorkItem;
use App\Services\Agents\TicketAgentWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunTicketAgent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 630;

    public int $tries = 1;

    public function __construct(public int $workItemId) {}

    public function handle(TicketAgentWorkflow $workflow): void
    {
        $item = WorkItem::findOrFail($this->workItemId);
        if ($item->status === 'queued') {
            $workflow->run($item);
        }
    }

    public function failed(?Throwable $exception): void
    {
        WorkItem::whereKey($this->workItemId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error_message' => 'Ticket Agent worker failed or timed out. Regenerate manually.', 'finished_at' => now(),
        ]);
    }
}
