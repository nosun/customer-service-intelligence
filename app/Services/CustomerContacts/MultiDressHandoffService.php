<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactRound;
use App\Models\FollowUp;
use App\Services\FollowUps\FollowUpManager;
use Illuminate\Support\Facades\DB;

class MultiDressHandoffService
{
    public function __construct(private readonly FollowUpManager $followUps) {}

    public function upsert(int $roundId, string $reason, array $evidence): FollowUp
    {
        return DB::transaction(function () use ($roundId, $reason, $evidence) {
            $round = CustomerContactRound::lockForUpdate()->findOrFail($roundId);
            $eventKey = 'handoff:'.hash('sha256', $reason);
            if ($round->follow_up_id) {
                $item = FollowUp::lockForUpdate()->findOrFail($round->follow_up_id);
                if (! $round->events()->where('event_key', $eventKey)->exists()) {
                    // Append progress without changing the colleague's status, owner, or schedule.
                    $item = $this->followUps->update($item->id, ['note' => $reason.'；请在HelpDesk或Console按现有流程接续。'], $item->version, 'nosun-agent');
                    $round->events()->create(['event_key' => $eventKey, 'event_type' => 'human_handoff_progress',
                        'actor' => 'nosun-agent', 'occurred_at' => now(), 'evidence' => ['reason' => $reason, 'refs' => $evidence]]);
                }

                return $item;
            }
            $item = $this->followUps->create(['title' => '多件确认：'.$round->order_number,
                'reference' => $round->order_number, 'owner' => '客服', 'note' => $reason.'；请在HelpDesk或Console按现有流程接续。',
                'next_follow_up_at' => now()->toIso8601String()], 'nosun-agent');
            $round->update(['follow_up_id' => $item->id]);
            $round->events()->firstOrCreate(['event_key' => $eventKey], ['event_type' => 'human_handoff',
                'actor' => 'nosun-agent', 'occurred_at' => now(), 'evidence' => ['reason' => $reason, 'refs' => $evidence]]);

            return $item;
        });
    }
}
