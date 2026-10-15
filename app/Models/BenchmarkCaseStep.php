<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkCaseStep extends Model
{
    protected $guarded = [];

    public function benchmarkCase(): BelongsTo
    {
        return $this->belongsTo(BenchmarkCase::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(BenchmarkAnnotation::class);
    }

    public function goldEmailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'gold_email_template_id');
    }

    protected function casts(): array
    {
        return [
            'observed_reply_event_ids' => 'array',
            'requires_response' => 'boolean',
            'gold_intents' => 'array',
            'gold_required_facts' => 'array',
            'gold_missing_information' => 'array',
            'gold_required_actions' => 'array',
            'gold_knowledge_article_ids' => 'array',
            'gold_knowledge_gaps' => 'array',
            'gold_prohibited_claims' => 'array',
            'gold_evidence_event_ids' => 'array',
            'adjudicated_at' => 'datetime',
        ];
    }
}
