<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItem extends Model
{
    public const STATUSES = ['queued', 'running', 'completed', 'failed', 'stale', 'cancelled'];

    protected $guarded = [];

    public function customerCase(): BelongsTo
    {
        return $this->belongsTo(CustomerCase::class);
    }

    public function actionProposals(): HasMany
    {
        return $this->hasMany(ActionProposal::class);
    }

    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'result' => 'array',
            'lease_expires_at' => 'datetime',
            'deadline_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
