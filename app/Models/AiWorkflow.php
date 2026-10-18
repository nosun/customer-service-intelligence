<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkflow extends Model
{
    protected $guarded = [];

    public function replayRuns(): HasMany
    {
        return $this->hasMany(AiWorkflowReplayRun::class);
    }

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
