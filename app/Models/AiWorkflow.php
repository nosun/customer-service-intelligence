<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiWorkflow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed_fact_keys' => 'array',
            'allowed_actions' => 'array',
            'replay_metrics' => 'array',
            'published_at' => 'datetime',
            'deprecated_at' => 'datetime',
        ];
    }
}
