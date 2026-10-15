<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatBotChatEvent extends Model
{
    protected $table = 'chatbot_chat_events';

    protected $guarded = [];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(ChatBotChat::class, 'chatbot_chat_id');
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'score' => 'decimal:4',
            'raw_payload' => 'array',
        ];
    }
}
