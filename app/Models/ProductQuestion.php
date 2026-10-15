<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductQuestion extends Model
{
    protected $guarded = [];

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
        ];
    }
}
