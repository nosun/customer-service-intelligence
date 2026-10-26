<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Collection;
use RuntimeException;

class TicketRuntimeContext
{
    public function triggerEvent(Conversation $conversation): ConversationEvent
    {
        $event = $conversation->events()
            ->where('event_type', 'message')
            ->where('is_private', false)
            ->whereIn('author_type', ['client', 'customer', 'agent'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($event === null) {
            throw new RuntimeException('The latest public Ticket message is not from the customer.');
        }
        if ($event->author_type === 'agent') {
            $guidance = $conversation->draftGuidance();
            if ($guidance === null || $conversation->draft_guidance_updated_at === null
                || ($event->occurred_at !== null && $conversation->draft_guidance_updated_at->lte($event->occurred_at))) {
                throw new RuntimeException('No new operator guidance is available after the last public agent reply.');
            }
        }
        if (trim((string) $event->body_text) === '') {
            throw new RuntimeException('The latest customer message has no text to answer.');
        }

        return $event;
    }

    /** @return array<string,mixed> */
    public function build(Conversation $conversation, ConversationEvent $trigger, bool $investigation = false): array
    {
        if ($trigger->conversation_id !== $conversation->id
            || $trigger->is_private
            || ! in_array($trigger->author_type, ['client', 'customer', 'agent'], true)
        ) {
            throw new RuntimeException('The Ticket Draft trigger is not a public customer message from this Ticket.');
        }

        $events = $this->stageEvents($conversation, $trigger);

        return [
            'communication_mode' => $trigger->author_type === 'agent' ? 'followup' : 'reply',
            'site_key' => $conversation->site_key,
            'subject' => $this->redactForConversation((string) $conversation->subject, $conversation),
            'trigger_event_id' => $trigger->id,
            'triggered_at' => $trigger->occurred_at?->toIso8601String(),
            'messages' => $events->map(fn (ConversationEvent $event): array => [
                'event_id' => $event->id,
                'role' => in_array($event->author_type, ['client', 'customer'], true) ? 'customer' : 'agent',
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'text' => $this->redactForConversation((string) $event->body_text, $conversation),
                ...($investigation ? $this->messageParts($event, $conversation) : []),
                'attachments' => $event->attachments->map(fn ($attachment): array => [
                    'filename' => $this->redact((string) $attachment->filename),
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                ])->values()->all(),
            ])->values()->all(),
            ...($investigation ? $this->relatedHistory($conversation, $trigger) : []),
        ];
    }

    /** @return Collection<int,ConversationEvent> */
    public function stageEvents(Conversation $conversation, ConversationEvent $trigger): Collection
    {
        return $conversation->events()
            ->with('attachments')
            ->where('event_type', 'message')
            ->where('is_private', false)
            ->whereIn('author_type', ['client', 'customer', 'agent'])
            ->where(function ($query) use ($trigger): void {
                if ($trigger->occurred_at === null) {
                    $query->where('id', '<=', $trigger->id);

                    return;
                }
                $query->where('occurred_at', '<', $trigger->occurred_at)
                    ->orWhere(function ($query) use ($trigger): void {
                        $query->where('occurred_at', $trigger->occurred_at)
                            ->where('id', '<=', $trigger->id);
                    });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /** Related messages are evidence, never current operator authority. */
    public function relatedHistory(Conversation $conversation, ConversationEvent $trigger): array
    {
        $tickets = collect();
        if (filled($conversation->linked_order_number) && filled($conversation->requester_email)
            && filled($conversation->site_key) && $conversation->source_type !== 'replay'
            && $conversation->channelAccount?->channel !== 'replay') {
            $tickets = Conversation::query()->where('channel_account_id', $conversation->channel_account_id)
                ->where('site_key', $conversation->site_key)->where('requester_email', $conversation->requester_email)
                ->where('linked_order_number', $conversation->linked_order_number)->where('id', '!=', $conversation->id)
                ->orderBy('id')->limit(21)->get();
        }
        $incomplete = $tickets->count() > 20;
        $tickets = $tickets->take(20);
        $events = ConversationEvent::query()->with('attachments')->whereIn('conversation_id', $tickets->pluck('id'))
            ->where('is_private', false)->where('event_type', 'message')->whereIn('author_type', ['client', 'customer', 'agent'])
            ->where(function ($query) use ($trigger): void {
                if ($trigger->occurred_at === null) {
                    $query->where('id', '<=', $trigger->id);
                } else {
                    $query->where('occurred_at', '<', $trigger->occurred_at)->orWhere(function ($q) use ($trigger): void {
                        $q->where('occurred_at', $trigger->occurred_at)->where('id', '<=', $trigger->id);
                    });
                }
            })->orderBy('occurred_at')->orderBy('id')->limit(501)->get();
        $incomplete = $incomplete || $events->count() > 500;

        return [
            'history_incomplete' => $incomplete,
            'related_conversations' => $tickets->map(fn ($ticket): array => [
                'conversation_id' => $ticket->id,
                'latest_public_event' => (array) $ticket->events()->where('is_private', false)->whereIn('event_type', ['message', 'attachments'])
                    ->orderByDesc('occurred_at')->orderByDesc('id')->toBase()->first(['id', 'content_hash', 'updated_at']),
                'historical_guidance' => $ticket->draft_guidance_updated_at !== null
                    && $trigger->occurred_at !== null && $ticket->draft_guidance_updated_at->lte($trigger->occurred_at)
                    ? $this->redactForConversation((string) $ticket->draft_guidance, $ticket) : null,
                'guidance_updated_at' => $ticket->draft_guidance_updated_at?->toIso8601String(),
            ])->values()->all(),
            'related_messages' => $events->take(500)->map(function ($event) use ($tickets): array {
                $ticket = $tickets->firstWhere('id', $event->conversation_id);

                return [
                    'event_id' => $event->id, 'conversation_id' => $event->conversation_id,
                    'role' => in_array($event->author_type, ['client', 'customer'], true) ? 'customer' : 'agent',
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'text' => $this->redactForConversation((string) $event->body_text, $ticket),
                    ...$this->messageParts($event, $ticket),
                    'attachments' => $event->attachments->map(fn ($a): array => [
                        'filename' => $this->redact((string) $a->filename), 'mime_type' => $a->mime_type,
                        'size_bytes' => $a->size_bytes,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function messageParts(ConversationEvent $event, Conversation $conversation): array
    {
        $text = $this->redactForConversation((string) $event->body_text, $conversation);
        $parts = preg_split('/(?:^|\n)[ \t]*(?:-{2,}\s*(?:Original Message|Message d[’\']origine|Forwarded message|Message transmis|Message transféré)\s*-{2,}|On [^\n]{1,250} wrote:)[ \t]*/iu', $text, 2);

        return ['conversation_id' => $conversation->id, 'current_text' => trim($parts[0] ?? $text),
            'quoted_text' => $parts[1] ?? null];
    }

    public function redact(string $text): string
    {
        $text = preg_replace('/\b[A-Z]{2,12}\d{8}-\d{3,12}\b/i', '[ORDER_NUMBER]', $text) ?? $text;
        $text = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[EMAIL]', $text) ?? $text;
        $text = preg_replace('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/', '[PHONE]', $text) ?? $text;

        return trim($text);
    }

    public function redactForConversation(string $text, Conversation $conversation): string
    {
        $text = $this->redact($text);
        $snapshot = (array) $conversation->requester_snapshot;
        $names = collect([
            data_get($snapshot, 'name'),
            data_get($snapshot, 'first_name'),
            data_get($snapshot, 'last_name'),
            data_get($snapshot, 'display_name'),
        ])->filter(fn ($name): bool => is_string($name) && mb_strlen(trim($name)) >= 2)
            ->map(fn ($name): string => trim($name))->unique();
        foreach ($names as $name) {
            $text = str_ireplace($name, '[CUSTOMER_NAME]', $text);
        }
        $text = preg_replace('/\b(hi|hello|dear)\s+[A-Z][\p{L}\'\-]{1,30}\b/iu', '$1 [CUSTOMER_NAME]', $text) ?? $text;

        return trim($text);
    }
}
