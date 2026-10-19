<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\CustomerCase;
use App\Services\Work\CustomerCaseManager;

class TicketRuntimeCaseManager
{
    public function __construct(private readonly CustomerCaseManager $cases) {}

    public function forConversation(Conversation $conversation, int $triggerEventId): CustomerCase
    {
        $conversation->loadMissing('analysis', 'channelAccount');
        $case = CustomerCase::query()->firstOrNew([
            'case_key' => "helpdesk:{$conversation->channel_account_id}:{$conversation->external_id}",
        ]);
        $case->fill([
            'site_key' => $conversation->site_key,
            'case_type' => 'ticket',
            'title' => mb_substr(trim((string) $conversation->subject) ?: "Ticket {$conversation->external_short_id}", 0, 255),
            'status' => 'open',
            'risk_level' => $this->risk($conversation),
            'priority' => $conversation->analysis?->urgency === 'urgent' ? 'high' : 'normal',
            'queue' => 'tickets',
            'sla_due_at' => $case->sla_due_at ?? now()->addDay(),
            'resolved_at' => null,
            'metadata' => [
                'external_ticket_id' => $conversation->external_id,
                'external_short_id' => $conversation->external_short_id,
                'problem_type' => $conversation->analysis?->primary_problem_type,
            ],
        ]);
        $case->save();
        $this->cases->linkSource(
            $case,
            'conversation',
            (string) $conversation->id,
            [
                'external_id' => $conversation->external_id,
                'status' => $conversation->status,
                'silo' => $conversation->silo,
                'trigger_event_id' => $triggerEventId,
            ],
            $conversation->requester_email,
        );

        return $case;
    }

    private function risk(Conversation $conversation): string
    {
        return in_array($conversation->analysis?->primary_problem_type, [
            'production_cancellation',
            'after_sales_return_refund',
            'after_sales_dispute',
        ], true) ? 'high' : 'normal';
    }
}
