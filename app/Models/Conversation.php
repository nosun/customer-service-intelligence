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
            'requester_snapshot' => 'array',
            'assignment_snapshot' => 'array',
            'created_at_external' => 'datetime',
            'updated_at_external' => 'datetime',
            'last_message_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }
}
