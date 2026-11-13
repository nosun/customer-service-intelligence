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
            $text = ($ticket->subject ?? '')."\n".$ticket->events->pluck('body_text')->implode("\n");
            preg_match_all('/\b(?:dad|ebd)\d{8}-\d+\b/i', $text, $matches);
            $orderNumbers = array_values(array_unique(array_map('strtolower', $matches[0])));
            $related = $orderNumbers === [strtolower($identity['order_number'] ?? '')];
            foreach ($ticket->events as $event) {
                if ($event->event_type !== 'message') {
                    continue;
                }
                $kind = $related && ! $event->is_private ? match ($event->author_type) {
                    'client' => 'customer_reply',
                    'agent' => 'prior_outbound',
                    default => 'ambiguous',
                } : 'ambiguous';
                $messages[] = ['id' => 'helpdesk:'.$ticket->external_id.':'.$event->external_id,
                    'kind' => $kind, 'ticket_id' => $ticket->external_id];
            }
        }

        return ['complete' => false, 'messages' => $messages, 'human_takeover' => $tickets->contains(fn ($t) => filled(data_get($t->assignment_snapshot, 'agent.ID'))),
            'checkpoint' => ['ticket_ids' => $tickets->pluck('external_id')->all(), 'reason' => 'fresh_helpdesk_search_and_occupancy_contract_missing']];
    }
}
