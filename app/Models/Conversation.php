<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $guarded = [];

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ConversationDraft::class);
    }

    public function runtimeCaseSource(): HasOne
    {
        return $this->hasOne(CustomerCaseSource::class, 'source_id')
            ->where('source_type', 'conversation');
    }

    public function assistanceFeedback(): HasMany
    {
        return $this->hasMany(TicketAssistanceFeedback::class);
    }

    public function businessContextSnapshots(): HasMany
    {
        return $this->hasMany(BusinessContextSnapshot::class);
    }

    public function latestOrderContext(): HasOne
    {
        return $this->hasOne(BusinessContextSnapshot::class)
            ->where('source', 'console_new')
            ->where('context_type', 'ticket_order')
            ->latestOfMany();
    }

    public function draftGuidance(): ?array
    {
        $text = trim((string) $this->draft_guidance);
        $feedback = $this->assistanceFeedback()->whereIn('kind', ['fact', 'decision', 'correction'])->orderByDesc('id')->limit(20)->get()->reverse();
        foreach ($feedback as $entry) {
            $label = ['fact' => '人工补充，尚需核实的个案事实', 'decision' => '当前个案决定，不代表已经执行', 'correction' => '当前个案纠正'][$entry->kind];
            $text .= "\n[{$label} #{$entry->id}] ".mb_substr($entry->body, 0, 4000);
        }
        $text = trim($text);

        return $text === '' ? null : [
            'text' => $text,
            'source' => 'admin_supplement',
            'scope' => 'current_ticket_only',
            'updated_at' => $this->draft_guidance_updated_at?->toIso8601String(),
        ];
    }

    public function latestCancellationContext(): HasOne
    {
        return $this->hasOne(BusinessContextSnapshot::class)
            ->where('source', 'console_new')
            ->where('context_type', 'cancellation')
            ->latestOfMany();
    }

    public function benchmarkCases(): HasMany
    {
        return $this->hasMany(BenchmarkCase::class);
    }

    public function decisionCase(): HasOne
    {
        return $this->hasOne(DecisionCase::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ConversationAnalysis::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(ConversationLabel::class);
    }

    protected function casts(): array
    {
        return [
            'draft_guidance_updated_at' => 'datetime',
            'requester_snapshot' => 'array',
            'assignment_snapshot' => 'array',
            'created_at_external' => 'datetime',
            'updated_at_external' => 'datetime',
            'last_message_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }
}
