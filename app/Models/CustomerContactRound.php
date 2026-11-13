<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerContactRound extends Model
{
    protected $guarded = [];

    public function effects(): HasMany
    {
        return $this->hasMany(CustomerContactEffect::class, 'round_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerContactEvent::class, 'round_id');
    }

    protected function casts(): array
    {
        return ['contact_ids' => 'array', 'trigger_snapshot' => 'array', 'source_snapshot' => 'array',
            'communication_checkpoint' => 'array', 'assessment' => 'array',
            'sent_at' => 'immutable_datetime', 'wait_until' => 'immutable_datetime', 'human_hold_at' => 'immutable_datetime'];
    }
}
