<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatBotTicketLink extends Model
{
    protected $table = 'chatbot_ticket_links';

    protected $guarded = [];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(ChatBotChat::class, 'chatbot_chat_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
