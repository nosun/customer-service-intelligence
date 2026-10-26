<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\TicketAssistanceFeedback;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketAssistanceFeedbackRecorder
{
    public function record(Conversation $ticket, int $actor, array $data, ?WorkItem $item = null, ?array $snapshot = null): TicketAssistanceFeedback
    {
        if ($item !== null && (int) data_get($item->input_snapshot, 'conversation_id') !== $ticket->id) {
            throw ValidationException::withMessages(['work_item_id' => '反馈目标不属于当前 Ticket。']);
        }

        return DB::transaction(function () use ($ticket, $actor, $data, $item, $snapshot): TicketAssistanceFeedback {
            $ticket = Conversation::lockForUpdate()->findOrFail($ticket->id);

            return TicketAssistanceFeedback::create([
                'conversation_id' => $ticket->id,
                'work_item_id' => $item?->id,
                'created_by' => $actor,
                'target' => $data['target'],
                'kind' => $data['kind'],
                'body' => trim($data['body']),
                'knowledge_candidate' => (bool) ($data['knowledge_candidate'] ?? false),
                'snapshot' => $snapshot ?? ['input_hash' => $item?->input_hash, 'result' => $item?->result],
            ]);
        });
    }

    public function guidance(Conversation $ticket, ?string $text, int $actor): void
    {
        DB::transaction(function () use ($ticket, $text, $actor): void {
            $locked = Conversation::lockForUpdate()->findOrFail($ticket->id);
            if ($locked->draft_guidance === $text) {
                return;
            }
            $this->record($locked, $actor, ['target' => 'guidance', 'kind' => 'replacement', 'body' => $text ?? ''], snapshot: [
                'before' => $locked->draft_guidance, 'after' => $text,
            ]);
            $locked->update(['draft_guidance' => $text, 'draft_guidance_updated_by' => $actor, 'draft_guidance_updated_at' => now()]);
        });
    }
}
