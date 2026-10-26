<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAssistanceFeedback extends Model
{
    protected $table = 'ticket_assistance_feedback';

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'knowledge_candidate' => 'boolean'];
    }
}
