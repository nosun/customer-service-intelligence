<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationEventAnalysis extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_noise' => 'boolean',
            'matched_rules' => 'array',
            'order_candidates' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }
}
