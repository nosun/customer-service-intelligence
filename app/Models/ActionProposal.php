<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionProposal extends Model
{
    public const STATUSES = ['proposed', 'approved', 'rejected', 'executing', 'succeeded', 'failed', 'cancelled'];

    protected $guarded = [];

    public function customerCase(): BelongsTo
    {
        return $this->belongsTo(CustomerCase::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'evidence' => 'array',
            'execution_result' => 'array',
            'reviewed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
