<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkflowReplayCase extends Model
{
    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowReplayRun::class, 'ai_workflow_replay_run_id');
    }

    public function isReviewed(): bool
    {
        return $this->fact_errors !== null && $this->policy_violations !== null;
    }

    protected function casts(): array
    {
        return [
            'expected_intents' => 'array',
            'must_block' => 'boolean',
            'prediction' => 'array',
            'accepted_as_is' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
