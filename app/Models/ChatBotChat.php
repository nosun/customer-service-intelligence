<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatBotChat extends Model
{
    protected $table = 'chatbot_chats';

    protected $guarded = [];

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(ChatBotStory::class, 'chatbot_story_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatBotChatEvent::class, 'chatbot_chat_id');
    }

    public function ticketLinks(): HasMany
    {
        return $this->hasMany(ChatBotTicketLink::class, 'chatbot_chat_id');
    }

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
