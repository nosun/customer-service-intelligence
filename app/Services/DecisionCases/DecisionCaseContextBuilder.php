<?php

namespace App\Services\DecisionCases;

use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Models\ConversationEventAnalysis;

class DecisionCaseContextBuilder
{
    private const MAX_EVENT_CHARACTERS = 30000;

    /**
     * @return array{context: array<string, mixed>, input_hash: string, event_ids: list<int>, event_authors: array<int, string>}
     */
    public function build(Conversation $conversation): array
    {
        $analysis = ConversationAnalysis::where('conversation_id', $conversation->id)->first();
        $events = ConversationEvent::query()
            ->where('conversation_id', $conversation->id)
            ->where('event_type', 'message')
            ->where('is_private', false)
            ->whereIn('author_type', ['client', 'agent'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $eventAnalyses = ConversationEventAnalysis::query()
            ->whereIn('conversation_event_id', $events->pluck('id'))
            ->get()
            ->keyBy('conversation_event_id');

        $messages = [];
        foreach ($events as $event) {
            $body = trim((string) ($event->body_text ?: strip_tags((string) $event->rich_text_html)));
            if ($body === '') {
                continue;
            }
            if (mb_strlen($body) > self::MAX_EVENT_CHARACTERS) {
                $body = mb_substr($body, 0, self::MAX_EVENT_CHARACTERS).' [TRUNCATED]';
            }

            $eventAnalysis = $eventAnalyses->get($event->id);
            $messages[] = [
                'event_id' => $event->id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'author_type' => $event->author_type,
                'text' => $body,
                'rule_hint' => $eventAnalysis === null ? null : [
                    'category' => $eventAnalysis->category,
                    'problem_type' => $eventAnalysis->problem_type,
                    'urgency' => $eventAnalysis->urgency,
                ],
            ];
        }

        $context = [
            'ticket' => [
                'conversation_id' => $conversation->id,
                'external_ticket_id' => $conversation->external_id,
                'site' => $conversation->site_key,
                'status' => $conversation->status,
                'silo' => $conversation->silo,
                'subject' => $conversation->subject,
                'created_at' => $conversation->created_at_external?->toIso8601String(),
                'updated_at' => $conversation->updated_at_external?->toIso8601String(),
            ],
            'existing_rule_analysis' => $analysis === null ? null : [
                'primary_category' => $analysis->primary_category,
                'primary_problem_type' => $analysis->primary_problem_type,
                'urgency' => $analysis->urgency,
                'needs_review' => $analysis->needs_review,
            ],
            'messages' => $messages,
        ];
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'context' => $context,
            'input_hash' => hash('sha256', $encoded),
            'event_ids' => array_values(array_map(static fn (array $message): int => $message['event_id'], $messages)),
            'event_authors' => array_column($messages, 'author_type', 'event_id'),
        ];
    }
}
