<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBotChat extends Model
{
    protected $table = 'chatbot_chats';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_snapshot' => 'array',
            'attributes' => 'array',
            'started_at' => 'datetime',
            'updated_at_external' => 'datetime',
            'purge_after' => 'datetime',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
