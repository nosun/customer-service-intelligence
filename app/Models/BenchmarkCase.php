<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenchmarkCase extends Model
{
    protected $guarded = [];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function goldEmailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'gold_email_template_id');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(BenchmarkAnnotation::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(BenchmarkCaseStep::class);
    }

    protected function casts(): array
    {
        return [
            'gold_intents' => 'array',
            'gold_required_facts' => 'array',
            'gold_missing_information' => 'array',
            'gold_evidence_event_ids' => 'array',
            'selected_at' => 'datetime',
            'adjudicated_at' => 'datetime',
        ];
    }
}
