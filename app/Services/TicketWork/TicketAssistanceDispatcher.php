<?php

namespace App\Services\TicketWork;

use App\Jobs\DispatchTicketAssistance;
use App\Models\Conversation;
use App\Models\WorkItem;
use App\Services\N8n\WorkflowRegistry;
use RuntimeException;

class TicketAssistanceDispatcher
{
    public function __construct(private readonly TicketWorkItemManager $items, private readonly WorkflowRegistry $workflows) {}

    public function dispatch(Conversation $ticket, int $actor): WorkItem
    {
        if ($ticket->silo !== 'tickets' || ! in_array($ticket->status, ['open', 'pending', 'onhold'], true)) {
            throw new RuntimeException('Only unresolved Tickets can be investigated.');
        }
        if ($actor < 1) {
            throw new RuntimeException('A valid assistance administrator is required.');
        }
        $this->workflows->runnable(N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
        $item = $this->items->create($ticket, $actor, N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION, reuseCompleted: true);
        if ($item->wasRecentlyCreated) {
            DispatchTicketAssistance::dispatch($item->id)->afterCommit();
        }

        return $item;
    }
}
