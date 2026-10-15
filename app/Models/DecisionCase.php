<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DecisionCase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(DecisionNode::class)->orderBy('sequence');
    }

    public function researchBatchItems(): HasMany
    {
        return $this->hasMany(KnowledgeResearchBatchItem::class);
    }
}
