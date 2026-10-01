<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBotStory extends Model
{
    protected $table = 'chatbot_stories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'integrations' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
