<?php

namespace App\Services\CustomerContacts;

use App\Models\Conversation;
use Carbon\CarbonImmutable;

class HelpDeskCommunicationSource
{
    public function capture(array $identity, CarbonImmutable $since): array
    {
        // Cached events provide positive evidence only. The deployed client cannot prove a fresh,
        // complete inbox search plus current colleague activity/drafts. Never infer absence from cache.
        $tickets = Conversation::where('requester_email', $identity['email'] ?? '')
            ->whereIn('site_key', [$identity['site_key'], $identity['site_key'].'.com'])
            ->with(['events' => fn ($q) => $q->where('occurred_at', '>=', $since)])->get();
        $messages = [];
        foreach ($tickets as $ticket) {
            foreach ($ticket->events as $event) {
                $messages[] = ['id' => 'helpdesk:'.$event->external_id, 'kind' => 'ambiguous', 'ticket_id' => $ticket->external_id];
            }
        }

        return ['complete' => false, 'messages' => $messages, 'human_takeover' => $tickets->contains(fn ($t) => filled(data_get($t->assignment_snapshot, 'agent.ID'))),
            'checkpoint' => ['ticket_ids' => $tickets->pluck('external_id')->all(), 'reason' => 'fresh_helpdesk_search_and_occupancy_contract_missing']];
    }
}
