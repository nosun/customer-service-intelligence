<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBotChatEvent extends Model
{
    protected $table = 'chatbot_chat_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'score' => 'decimal:4',
            'raw_payload' => 'array',
        ];
    }
}
