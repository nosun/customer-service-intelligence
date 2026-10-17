<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestionDraftFeedback extends Model
{
    protected $table = 'product_question_draft_feedback';

    protected $guarded = [];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'product_question_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProductQuestionDraft::class, 'product_question_draft_id');
    }

    protected function casts(): array
    {
        return [
            'final_visible' => 'boolean',
            'text_diff' => 'array',
            'similarity_percent' => 'float',
            'previewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
