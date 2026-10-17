<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestionDraft extends Model
{
    protected $guarded = [];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'product_question_id');
    }

    public function answer(): ?string
    {
        return filled($this->answer_localized) ? $this->answer_localized : $this->answer_en;
    }

    protected function casts(): array
    {
        return [
            'intents' => 'array',
            'knowledge_article_ids' => 'array',
            'facts' => 'array',
            'fact_sources' => 'array',
            'missing_facts' => 'array',
            'safety_flags' => 'array',
            'recommended_visible' => 'boolean',
            'requires_human_review' => 'boolean',
            'confidence' => 'float',
            'usage' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
