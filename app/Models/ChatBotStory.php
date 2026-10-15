<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatBotStory extends Model
{
    protected $table = 'chatbot_stories';

    protected $guarded = [];

    public function chats(): HasMany
    {
        return $this->hasMany(ChatBotChat::class, 'chatbot_story_id');
    }

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'integrations' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
