<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionNodeEvidence extends Model
{
    protected $table = 'decision_node_evidence';

    protected $guarded = [];

    public function event(): BelongsTo
    {
        return $this->belongsTo(ConversationEvent::class, 'conversation_event_id');
    }
}
