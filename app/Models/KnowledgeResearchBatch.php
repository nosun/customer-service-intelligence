<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeResearchBatch extends Model
{
    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeResearchBatchItem::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(KnowledgeResearchFinding::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(KnowledgeResearchQuestion::class);
    }

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'synthesis_output' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
