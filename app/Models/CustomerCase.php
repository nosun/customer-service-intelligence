<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCase extends Model
{
    public const STATUSES = ['open', 'waiting', 'resolved', 'closed'];

    protected $guarded = [];

    public function sources(): HasMany
    {
        return $this->hasMany(CustomerCaseSource::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function actionProposals(): HasMany
    {
        return $this->hasMany(ActionProposal::class);
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(CustomerCaseHandoff::class);
    }

    protected function casts(): array
    {
        return [
            'outcome' => 'array',
            'metadata' => 'array',
            'sla_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
