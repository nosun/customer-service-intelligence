<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBotTicketLink extends Model
{
    protected $table = 'chatbot_ticket_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
