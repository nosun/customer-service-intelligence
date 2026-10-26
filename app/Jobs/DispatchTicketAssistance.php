<?php

namespace App\Jobs;

use App\Models\WorkItem;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchTicketAssistance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    public function __construct(public int $workItemId) {}

    public function uniqueId(): string
    {
        return (string) $this->workItemId;
    }

    public function handle(N8nTicketDraftWorkflowClient $client): void
    {
        $item = WorkItem::find($this->workItemId);
        if ($item !== null && $item->status === 'queued' && ! $item->deadline_at?->isPast()) {
            // The existing reaper recovers failed delivery; never create a second run here.
            $client->trigger($item);
        }
    }
}
