<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationAnalysis extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category_counts' => 'array',
            'problem_type_counts' => 'array',
            'needs_review' => 'boolean',
            'analyzed_at' => 'datetime',
        ];
    }
}
