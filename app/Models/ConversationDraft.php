<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationDraft extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'metadata' => 'array',
            'requires_human_review' => 'boolean',
            'reviewed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
