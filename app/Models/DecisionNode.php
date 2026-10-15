<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DecisionNode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observed_facts' => 'array',
            'missing_facts' => 'array',
            'intents' => 'array',
            'required_facts' => 'array',
            'required_actions' => 'array',
            'knowledge_gaps' => 'array',
            'prohibited_claims' => 'array',
            'uncertainties' => 'array',
            'is_exception' => 'boolean',
            'extraction_confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function decisionCase(): BelongsTo
    {
        return $this->belongsTo(DecisionCase::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DecisionNodeEvidence::class);
    }
}
