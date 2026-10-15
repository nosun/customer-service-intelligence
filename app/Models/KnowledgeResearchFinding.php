<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeResearchFinding extends Model
{
    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KnowledgeResearchBatch::class, 'knowledge_research_batch_id');
    }

    protected function casts(): array
    {
        return [
            'inclusion_rules' => 'array',
            'exclusion_rules' => 'array',
            'required_facts' => 'array',
            'decision_tree' => 'array',
            'reply_strategy' => 'array',
            'reply_templates' => 'array',
            'required_actions' => 'array',
            'prohibited_claims' => 'array',
            'uncertainties' => 'array',
            'evidence_decision_node_ids' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
