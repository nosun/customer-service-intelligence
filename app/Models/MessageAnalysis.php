<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageAnalysis extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'matched_rules' => 'array',
            'order_candidates' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }
}
