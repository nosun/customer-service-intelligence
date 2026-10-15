<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationEvent extends Model
{
    protected $guarded = [];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ConversationAttachment::class);
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_private' => 'boolean',
            'rich_text_payload' => 'array',
            'assignment_snapshot' => 'array',
            'source_snapshot' => 'array',
            'raw_payload' => 'array',
        ];
    }
}
