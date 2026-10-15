<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeResearchBatchItem extends Model
{
    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KnowledgeResearchBatch::class, 'knowledge_research_batch_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function decisionCase(): BelongsTo
    {
        return $this->belongsTo(DecisionCase::class);
    }
}
