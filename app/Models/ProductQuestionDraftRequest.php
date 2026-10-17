<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestionDraftRequest extends Model
{
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
            'input_snapshot' => 'array',
            'force_regeneration' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
