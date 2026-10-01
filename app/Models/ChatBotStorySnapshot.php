<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBotStorySnapshot extends Model
{
    protected $table = 'chatbot_story_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'settings' => 'array',
            'state' => 'array',
            'history' => 'array',
            'diagram' => 'array',
            'raw_payload' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
