<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationAttachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_payload' => 'array'];
    }
}
