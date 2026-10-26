<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductQuestion extends Model
{
    protected $guarded = [];

    /** @return array<string,mixed>|null */
    public function draftGuidance(): ?array
    {
        $text = trim((string) $this->draft_guidance);

        return $text === '' ? null : [
            'text' => $text,
            'source' => 'admin_supplement',
            'scope' => 'current_question_only',
            'updated_by' => $this->draft_guidance_updated_by,
            'updated_at' => $this->draft_guidance_updated_at?->toIso8601String(),
        ];
    }

    public function scopeWithoutReplay(Builder $query): Builder
    {
        return $query->where('name', '!=', '__CSI_N8N_REPLAY__');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ProductQuestionAnalysis::class);
    }

    public function analysisRuns(): HasMany
    {
        return $this->hasMany(ProductQuestionAnalysisRun::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ProductQuestionDraft::class);
    }

    public function draftRequests(): HasMany
    {
        return $this->hasMany(ProductQuestionDraftRequest::class);
    }

    public function draftFeedback(): HasMany
    {
        return $this->hasMany(ProductQuestionDraftFeedback::class);
    }

    protected function casts(): array
    {
        return [
            'product' => 'array',
            'images' => 'array',
            'raw_payload' => 'array',
            'tags' => 'array',
            'reply_operator' => 'array',
            'close_operator' => 'array',
            'needs_reply' => 'boolean',
            'visible' => 'boolean',
            'remote_present' => 'boolean',
            'remote_created_at' => 'datetime',
            'remote_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'replied_at' => 'datetime',
            'draft_guidance_updated_at' => 'datetime',
        ];
    }
}
