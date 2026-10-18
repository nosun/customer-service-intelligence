<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkflowReplayRun extends Model
{
    protected $guarded = [];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AiWorkflow::class, 'ai_workflow_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(AiWorkflowReplayCase::class);
    }

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'release_report' => 'array',
            'imported_at' => 'datetime',
            'gated_at' => 'datetime',
        ];
    }
}
