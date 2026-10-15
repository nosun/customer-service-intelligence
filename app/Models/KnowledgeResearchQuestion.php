<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeResearchQuestion extends Model
{
    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KnowledgeResearchBatch::class, 'knowledge_research_batch_id');
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'affected_scenario_keys' => 'array',
            'evidence_decision_node_ids' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
