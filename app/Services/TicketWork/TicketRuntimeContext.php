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

        if ($event === null || ! in_array($event->author_type, ['client', 'customer'], true)) {
            throw new RuntimeException('The latest public Ticket message is not from the customer.');
        }
        if (trim((string) $event->body_text) === '') {
            throw new RuntimeException('The latest customer message has no text to answer.');
        }

        return $event;
    }

    /** @return array<string,mixed> */
    public function build(Conversation $conversation, ConversationEvent $trigger): array
    {
        if ($trigger->conversation_id !== $conversation->id
            || $trigger->is_private
            || ! in_array($trigger->author_type, ['client', 'customer'], true)
        ) {
            throw new RuntimeException('The Ticket Draft trigger is not a public customer message from this Ticket.');
        }

        $events = $this->stageEvents($conversation, $trigger);

        return [
            'site_key' => $conversation->site_key,
            'subject' => $this->redactForConversation((string) $conversation->subject, $conversation),
            'trigger_event_id' => $trigger->id,
            'triggered_at' => $trigger->occurred_at?->toIso8601String(),
            'messages' => $events->map(fn (ConversationEvent $event): array => [
                'event_id' => $event->id,
                'role' => in_array($event->author_type, ['client', 'customer'], true) ? 'customer' : 'agent',
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'text' => $this->redactForConversation((string) $event->body_text, $conversation),
                'attachments' => $event->attachments->map(fn ($attachment): array => [
                    'filename' => $this->redact((string) $attachment->filename),
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                ])->values()->all(),
            ])->values()->all(),
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

    public function redact(string $text): string
    {
        $text = preg_replace('/\b[A-Z]{2,12}\d{8}-\d{3,12}\b/i', '[ORDER_NUMBER]', $text) ?? $text;
        $text = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[EMAIL]', $text) ?? $text;
        $text = preg_replace('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/', '[PHONE]', $text) ?? $text;

        return trim($text);
    }

    private function redactForConversation(string $text, Conversation $conversation): string
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
